<?php
// Set custom session timeout
ini_set('session.gc_maxlifetime', 3600); // 1 hour (3600 seconds)

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';

if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Administrators and superadmins can assign technicians.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? null, ['admin', 'superadmin'], true)) {
    header("Location: login.php");
    exit;
}

// Ensure user_id exists in the session
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set default timezone
date_default_timezone_set('Africa/Addis_Ababa');

// Initialize variables
$section = isset($_GET['section']) ? $_GET['section'] : 'pending_requests';
if ($_SESSION['role'] === 'superadmin' && $section !== 'assign_technicians') {
    header("Location: superadmin_dashboard.php");
    exit;
}
$message = '';
$profile_message = '';
$results = [];
$profile = null;
$notification_count = 0;

// Main queries
$queries = [
    'manage_users' => "SELECT * FROM users WHERE role != 'superadmin' ORDER BY full_name",
    'pending_requests' => "SELECT sr.request_id, sr.category, sr.description, u.full_name AS requester, sr.created_at FROM service_requests sr LEFT JOIN users u ON sr.user_id = u.user_id WHERE sr.status = 'Pending' ORDER BY sr.created_at DESC",
    'assign_technicians' => [
        "SELECT request_id, category, description, created_at FROM service_requests WHERE status = 'Pending' AND assigned_to IS NULL ORDER BY created_at ASC",
        "SELECT user_id, full_name FROM users WHERE role = 'technician' AND is_active = 1 ORDER BY full_name"
    ],
    'technician_workload' => "SELECT u.full_name, COUNT(sr.request_id) AS task_count 
                             FROM users u 
                             LEFT JOIN service_requests sr ON u.user_id = sr.assigned_to 
                             WHERE u.role = 'technician' AND sr.status = 'In Progress'
                             GROUP BY u.user_id",
    'notification_count' => "SELECT COUNT(*) as new_requests FROM service_requests WHERE status = 'Pending'",
    'profile' => "SELECT * FROM users WHERE user_id = ?",
    'generate_report' => "SELECT status, request_id, category, description, created_at FROM service_requests ORDER BY status, created_at DESC",
    'view_complaints' => "SELECT c.complaint_id, c.request_id, c.user_id, u.full_name, c.description, sr.status AS request_status, c.status AS complaint_status, c.created_at FROM complaint c LEFT JOIN users u ON c.user_id = u.user_id LEFT JOIN service_requests sr ON c.request_id = sr.request_id ORDER BY c.created_at DESC"
];

// Get notification count
$notification_result = $conn->query($queries['notification_count']);
if ($notification_result) {
    $notification_count = $notification_result->fetch_assoc()['new_requests'];
    $notification_result->free();
}

// Get profile data
$profile_stmt = $conn->prepare($queries['profile']);
if ($profile_stmt) {
    $profile_stmt->bind_param("i", $_SESSION['user_id']);
    if ($profile_stmt->execute()) {
        $profile_result = $profile_stmt->get_result();
        $profile = $profile_result->fetch_assoc();
    }
    $profile_stmt->close();
}

// Execute section-specific queries
if (array_key_exists($section, $queries)) {
    if ($section === 'profile') {
        // Handle the profile query with a prepared statement
        $profile_stmt = $conn->prepare($queries['profile']);
        if ($profile_stmt) {
            $profile_stmt->bind_param("i", $_SESSION['user_id']);
            if ($profile_stmt->execute()) {
                $profile_result = $profile_stmt->get_result();
                $profile = $profile_result->fetch_assoc();
            } else {
                $message = "Error loading profile: " . $profile_stmt->error;
            }
            $profile_stmt->close();
        } else {
            $message = "Error preparing profile query: " . $conn->error;
        }
    } elseif (is_array($queries[$section])) {
        // Handle array queries (e.g., assign_technicians)
        foreach ($queries[$section] as $query) {
            $result = $conn->query($query);
            if ($result) {
                $results[] = $result;
            } else {
                $message = "Error loading data: " . $conn->error;
            }
        }
    } else {
        // Handle other queries
        $result = $conn->query($queries[$section]);
        if ($result) {
            $results[] = $result;
        } else {
            $message = "Error loading data: " . $conn->error;
        }
    }
}

// Add a new section for generating reports
if ($section === 'generate_report') {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $category = isset($_GET['category']) ? $_GET['category'] : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;

    $query = "SELECT status, request_id, category, description, created_at FROM service_requests WHERE 1=1";

    if ($start_date) {
        $query .= " AND created_at >= '" . $conn->real_escape_string($start_date) . "'";
    }
    if ($end_date) {
        $query .= " AND created_at <= '" . $conn->real_escape_string($end_date) . "'";
    }
    if ($category) {
        $query .= " AND category = '" . $conn->real_escape_string($category) . "'";
    }
    if ($status) {
        $query .= " AND status = '" . $conn->real_escape_string($status) . "'";
    }

    $query .= " ORDER BY status, created_at DESC";

    $result = $conn->query($query);
    if ($result) {
        $report_data = [];
        while ($row = $result->fetch_assoc()) {
            $report_data[$row['status']][] = $row;
        }
    } else {
        $message = "Error loading report data: " . $conn->error;
    }

    // Handle export options
    if (isset($_GET['export'])) {
        $export_type = $_GET['export'];
        if ($export_type === 'pdf') {
            // Export as PDF
            require_once 'includes/fpdf.php';
            $pdf = new FPDF();
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'Service Report', 0, 1, 'C');
            foreach ($report_data as $status => $requests) {
                $pdf->SetFont('Arial', 'B', 10);
                $pdf->Cell(0, 10, ucfirst($status) . ' Requests', 0, 1);
                $pdf->SetFont('Arial', '', 10);
                foreach ($requests as $request) {
                    $pdf->Cell(0, 10, "#{$request['request_id']} - {$request['category']} - {$request['description']}", 0, 1);
                }
            }
            $pdf->Output('D', 'service_report.pdf');
            exit;
        } elseif ($export_type === 'excel') {
            // Export as Excel
            header('Content-Type: application/vnd.ms-excel');
            header('Content-Disposition: attachment; filename="service_report.xls"');
            echo "Status\tRequest ID\tCategory\tDescription\tSubmitted\n";
            foreach ($report_data as $status => $requests) {
                foreach ($requests as $request) {
                    echo "{$status}\t{$request['request_id']}\t{$request['category']}\t{$request['description']}\t{$request['created_at']}\n";
                }
            }
            exit;
        }
    }
}

// Handle technician assignment
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['request_id'], $_POST['technician_id']) && $section === 'assign_technicians') {
    require_valid_csrf_token();
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $technician_id = filter_input(INPUT_POST, 'technician_id', FILTER_VALIDATE_INT);

    if (!$request_id || !$technician_id) {
        http_response_code(400);
        exit('Invalid request or technician.');
    }
    
    // Verify technician exists
    $tech_check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'technician' AND is_active = 1");
    if ($tech_check) {
        $tech_check->bind_param("i", $technician_id);
        $tech_check->execute();
        $tech_result = $tech_check->get_result();
        
        if ($tech_result->num_rows > 0) {
            // Assign the request
            $stmt = $conn->prepare("UPDATE service_requests 
                                   SET assigned_to = ?, status = 'In Progress', updated_at = NOW() 
                                   WHERE request_id = ? AND assigned_to IS NULL AND status = 'Pending'");
            if ($stmt) {
                $stmt->bind_param("ii", $technician_id, $request_id);
                
                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $message = "Request #$request_id assigned successfully!";
                        $results[0] = $conn->query($queries['assign_technicians'][0]);
                    } else {
                        $message = "The request is no longer pending and unassigned.";
                    }
                } else {
                    $message = "Error assigning request: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = "Database error: " . $conn->error;
            }
        } else {
            $message = "Invalid technician selected";
        }
        $tech_check->close();
    } else {
        $message = "Database error: " . $conn->error;
    }
}

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile']) && $section == 'profile') {
    $full_name = $conn->real_escape_string(trim($_POST['full_name']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $phone = $conn->real_escape_string(trim($_POST['phone']));

    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("sssi", $full_name, $email, $phone, $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $profile_message = "Profile updated successfully!";
            // Refresh profile data
            $profile_stmt = $conn->prepare($queries['profile']);
            if ($profile_stmt) {
                $profile_stmt->bind_param("i", $_SESSION['user_id']);
                $profile_stmt->execute();
                $profile_result = $profile_stmt->get_result();
                $profile = $profile_result->fetch_assoc();
                $profile_stmt->close();
            }
        } else {
            $profile_message = "Error updating profile: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $profile_message = "Database error: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - AMU SRMS</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2a2185;
            --secondary: #1a1666;
            --accent: #ff4444;
            --light: #f5f5f5;
            --dark: #222;
            --gray: #999;
            --success: #28a745;
            --warning: #ffc107;
            --info: #17a2b8;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Ubuntu', sans-serif;
        }
        
        body {
            background-color: var(--light);
            color: var(--dark);
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background-color: var(--primary);
            color: white;
            transition: all 0.3s;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transform: translateX(-100%);
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar-header {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-close {
            margin-left: auto;
            border: 0;
            background: none;
            color: inherit;
            font-size: 1.25rem;
            cursor: pointer;
        }
        
        .sidebar-header img {
            height: 40px;
            margin-right: 10px;
        }
        
        .sidebar-header h2 {
            font-size: 1.2rem;
        }
        
        .sidebar-menu {
            padding: 1rem 0;
        }
        
        .menu-item {
            padding: 0.8rem 1.5rem;
            display: flex;
            align-items: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        
        .menu-item:hover, 
        .menu-item.active {
            background-color: rgba(255,255,255,0.1);
            border-left-color: var(--accent);
        }
        
        .menu-item i {
            margin-right: 10px;
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }
        
        .notification-badge {
            display: inline-block;
            background-color: var(--accent);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            text-align: center;
            font-size: 0.7rem;
            font-weight: bold;
            margin-left: auto;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 0;
            transition: all 0.3s;
        }
        
        .main-content.active {
            margin-left: 280px;
        }
        
        /* Top Navigation */
        .top-nav {
            background-color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .toggle-sidebar {
            font-size: 1.5rem;
            color: var(--primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .user-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }
        
        .notification {
            position: relative;
            cursor: pointer;
        }
        
        .notification-panel {
            position: absolute;
            top: 40px;
            right: 0;
            width: 300px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            padding: 1rem;
            display: none;
            z-index: 100;
        }
        
        .notification:hover .notification-panel {
            display: block;
        }
        
        .notification-item {
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            cursor: pointer;
            position: relative;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .user-dropdown {
            position: absolute;
            top: 50px;
            right: 0;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            width: 200px;
            padding: 0.5rem 0;
            display: none;
            z-index: 100;
        }
        
        .user-profile:hover .user-dropdown {
            display: block;
        }
        
        .dropdown-item {
            padding: 0.5rem 1rem;
            color: var(--dark);
            text-decoration: none;
            display: block;
            transition: all 0.3s;
        }
        
        .dropdown-item:hover {
            background-color: var(--light);
            color: var(--primary);
        }
        
        /* Dashboard Content */
        .content {
            padding: 2rem;
        }
        
        .card {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .card-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.6rem 1.2rem;
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            gap: 0.5rem;
        }
        
        .btn:hover {
            background-color: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }
        
        .btn-danger {
            background-color: var(--accent);
        }
        
        .btn-danger:hover {
            background-color: #e03e3e;
        }
        
        .btn-success {
            background-color: var(--success);
        }
        
        .btn-warning {
            background-color: var(--warning);
            color: var(--dark);
        }
        
        .btn-info {
            background-color: var(--info);
        }
        
        .btn-group {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn i {
            font-size: 0.9rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        th {
            background-color: var(--light);
            color: var(--primary);
            font-weight: 500;
        }
        
        tr:hover {
            background-color: rgba(42,33,133,0.03);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--gray);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary);
            opacity: 0.5;
        }
        
        .empty-state p {
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }
        
        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(42,33,133,0.1);
            outline: none;
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .alert i {
            font-size: 1.2rem;
        }
        
        .alert-success {
            background-color: rgba(40,167,69,0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .alert-danger {
            background-color: rgba(255,68,68,0.1);
            color: var (--accent);
            border-left: 4px solid var,--accent);
        }
        
        .alert-info {
            background-color: rgba(23,162,184,0.1);
            color: var(--info);
            border-left: 4px solid var,--info);
        }
        
        .profile-info {
            margin-bottom: 2rem;
        }
        
        .profile-info p {
            margin-bottom: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .profile-info strong {
            color: var(--primary);
            font-weight: 500;
            min-width: 120px;
            display: inline-block;
        }
        
        /* Status badges */
        .badge {
            display: inline-block;
            padding: 0.35rem 0.65rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .badge-approved {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-in-progress {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .badge-completed {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .badge-high {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .badge-medium {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .badge-low {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-admin {
            background-color: #d6d8f9;
            color: var(--primary);
        }
        
        .badge-technician {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        
        .badge-user {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        /* Workload indicator */
        .workload-bar {
            background: #f0f0f0;
            border-radius: 10px;
            height: 10px;
            width: 100%;
            overflow: hidden;
        }
        
        .workload-progress {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s;
        }
        
        .workload-low {
            background-color: var(--success);
            width: 33%;
        }
        
        .workload-medium {
            background-color: var(--warning);
            width: 66%;
        }
        
        .workload-high {
            background-color: var(--accent);
            width: 100%;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            padding: 1.5rem;
            color: var(--gray);
            background-color: white;
            border-top: 1px solid rgba(0,0,0,0.05);
        }
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content.active {
                margin-left: 0;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: 1rem;
            }
            
            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            
            .btn-group {
                flex-wrap: wrap;
            }
            
            .btn {
                flex: 1 0 auto;
                margin-bottom: 0.5rem;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }

        /* Dashboard specific styles */
        .dashboard {
            padding: 20px;
        }
        .dashboard-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .dashboard-cards {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
            text-align: center;
            flex: 1;
            min-width: 200px;
        }
        .card h2 {
            margin-bottom: 10px;
            font-size: 1.2rem;
        }
        .card .count {
            font-size: 2rem;
            font-weight: bold;
            color: #2a2185;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="image/amulogo.png" alt="AMU Logo">
            <h2>AMU SRMS</h2>
            <button type="button" class="sidebar-close" aria-label="Close menu">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="sidebar-menu">
            <a href="?section=dashboard" class="menu-item <?= $section === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>
            <a href="?section=pending_requests" class="menu-item <?= $section === 'pending_requests' ? 'active' : '' ?>">
                <i class="fas fa-list"></i>
                <span>Pending Requests</span>
                <?php if ($notification_count > 0 && $section !== 'pending_requests'): ?>
                    <span class="notification-badge"><?= $notification_count ?></span>
                <?php endif; ?>
            </a>
            <a href="?section=assign_technicians" class="menu-item <?= $section === 'assign_technicians' ? 'active' : '' ?>">
                <i class="fas fa-user-cog"></i>
                <span>Assign Technicians</span>
            </a>
            <a href="?section=technician_workload" class="menu-item <?= $section === 'technician_workload' ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i>
                <span>Workload Overview</span>
            </a>
            <a href="?section=generate_report" class="menu-item <?= $section === 'generate_report' ? 'active' : '' ?>">
                <i class="fas fa-file-alt"></i>
                <span>Generate Report</span>
            </a>
            <a href="?section=profile" class="menu-item <?= $section === 'profile' ? 'active' : '' ?>">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
            <a href="?section=view_feedback" class="menu-item <?= $section === 'view_feedback' ? 'active' : '' ?>">
                <i class="fas fa-comments"></i>
                <span>View User Feedback</span>
            </a>
            <a href="?section=view_complaints" class="menu-item <?= $section === 'view_complaints' ? 'active' : '' ?>">
                <i class="fas fa-exclamation-circle"></i>
                <span>View Complaints</span>
            </a>
            <form method="POST" action="logout.php">
                <?= csrf_field() ?>
                <button type="submit" class="menu-item" style="background:none;border:0;width:100%;cursor:pointer;text-align:left;font:inherit">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navigation -->
        <div class="top-nav">
            <div class="toggle-sidebar">
                <i class="fas fa-bars"></i>
                <span>Menu</span>
            </div>
            
            <div class="user-actions">
                <div class="notification">
                    <i class="fas fa-bell"></i>
                    <?php if ($notification_count > 0): ?>
                        <span class="notification-badge"><?= $notification_count ?></span>
                    <?php endif; ?>
                    
                    <div class="notification-panel">
                        <h4>Recent Requests</h4>
                        <?php if ($notification_count > 0): ?>
                            <?php
                            $recent_stmt = $conn->prepare("SELECT request_id, category, created_at FROM service_requests WHERE status = 'Pending' ORDER BY created_at DESC LIMIT 5");
                            if ($recent_stmt) {
                                $recent_stmt->execute();
                                $recent_requests = $recent_stmt->get_result();
                                while ($req = $recent_requests->fetch_assoc()): ?>
                                    <div class="notification-item">
                                        <div><strong><?= htmlspecialchars($req['category']) ?> Request</strong></div>
                                        <small>#<?= $req['request_id'] ?> • <?= date('M j, H:i', strtotime($req['created_at'])) ?></small>
                                    </div>
                                <?php endwhile;
                                $recent_stmt->close();
                            }
                            ?>
                            <div class="notification-item" style="text-align: center;">
                                <a href="?section=pending_requests" class="btn btn-sm">View All</a>
                            </div>
                        <?php else: ?>
                            <div class="notification-item">No pending requests</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($profile['full_name'] ?? 'A', 0, 1)) ?>
                    </div>
                    <span><?= htmlspecialchars($profile['full_name'] ?? 'Admin') ?></span>
                    
                    <div class="user-dropdown">
                        <a href="?section=profile" class="dropdown-item">
                            <i class="fas fa-user"></i> My Profile
                        </a>
                        <a href="change_password.php" class="dropdown-item">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                        <form method="POST" action="logout.php">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown-item" style="width:100%;border:0;background:none;text-align:left;font:inherit;cursor:pointer">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dashboard Content -->
        <div class="content">
            <?php if (!empty($message)): ?>
                <div class="alert <?= strpos($message, 'success') !== false ? 'alert-success' : 'alert-danger' ?> fade-in">
                    <i class="fas <?= strpos($message, 'success') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($profile_message)): ?>
                <div class="alert <?= strpos($profile_message, 'success') !== false ? 'alert-success' : 'alert-danger' ?> fade-in">
                    <i class="fas <?= strpos($profile_message, 'success') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($profile_message) ?>
                </div>
            <?php endif; ?>
            
            <div class="card fade-in">
                <?php if ($section === 'pending_requests'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-list"></i> Pending Service Requests</h2>
                        <div class="btn-group">
                            <button class="btn" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    
                    <?php if (isset($results[0]) && $results[0]->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th>Requester</th>
                                    <th>Submitted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $results[0]->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?= $row['request_id'] ?></td>
                                        <td><?= htmlspecialchars($row['category']) ?></td>
                                        <td><?= htmlspecialchars(substr($row['description'], 0, 50)) ?><?= strlen($row['description']) > 50 ? '...' : '' ?></td>
                                        <td><?= htmlspecialchars($row['requester'] ?? 'N/A') ?></td>
                                        <td><?= date('M j, H:i', strtotime($row['created_at'])) ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <form method="POST" action="approve_request.php">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="request_id" value="<?= (int) $row['request_id'] ?>">
                                                    <button type="submit" class="btn btn-success btn-sm">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                </form>
                                                <form method="POST" action="reject_request.php" onsubmit="return confirm('Reject this request?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="request_id" value="<?= (int) $row['request_id'] ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                </form>
                                                <a href="request_details.php?request_id=<?= $row['request_id'] ?>" class="btn btn-info btn-sm">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No pending service requests</p>
                            <p>All requests have been processed</p>
                        </div>
                    <?php endif; ?>
                
                <?php elseif ($section === 'assign_technicians'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-user-cog"></i> Assign Technicians</h2>
                        <button class="btn" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    
                    <?php if (isset($results[0]) && $results[0]->num_rows > 0 && isset($results[1]) && $results[1]->num_rows > 0): ?>
                        <form method="POST" action="?section=assign_technicians" class="fade-in">
                            <?= csrf_field() ?>
                            
                            <div class="form-group">
                                <label><i class="fas fa-tasks"></i> Select Request</label>
                                <select name="request_id" class="form-control" required>
                                    <?php while ($row = $results[0]->fetch_assoc()): ?>
                                        <option value="<?= $row['request_id'] ?>">
                                            #<?= $row['request_id'] ?> - <?= htmlspecialchars($row['category']) ?> 
                                            (<?= date('M j', strtotime($row['created_at'] ?? 'now')) ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-user-tie"></i> Select Technician</label>
                                <select name="technician_id" class="form-control" required>
                                    <?php while ($row = $results[1]->fetch_assoc()): ?>
                                        <option value="<?= $row['user_id'] ?>">
                                            <?= htmlspecialchars($row['full_name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn">
                                <i class="fas fa-user-check"></i> Assign Technician
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="empty-state">
                            <?php if (!isset($results[0]) || $results[0]->num_rows === 0): ?>
                                <i class="fas fa-check-circle"></i>
                                <p>All requests have been assigned!</p>
                                <p>No unassigned requests available</p>
                            <?php else: ?>
                                <i class="fas fa-user-times"></i>
                                <p>No active technicians available</p>
                                <p>Please activate technicians before assignment</p>
                                <a href="?section=manage_users" class="btn">
                                    <i class="fas fa-users-cog"></i> Manage Technicians
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                
                <?php elseif ($section === 'technician_workload'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-chart-bar"></i> Technician Workload</h2>
                        <button class="btn" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    
                    <?php if (isset($results[0]) && $results[0]->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Technician</th>
                                    <th>Active Tasks</th>
                                    <th>Workload</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $results[0]->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td><?= $row['task_count'] ?></td>
                                        <td>
                                            <div class="workload-bar">
                                                <div class="workload-progress 
                                                    <?= $row['task_count'] > 5 ? 'workload-high' : 
                                                       ($row['task_count'] > 2 ? 'workload-medium' : 'workload-low') ?>">
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-clock"></i>
                            <p>No technicians currently have active tasks</p>
                        </div>
                    <?php endif; ?>
                
                <?php elseif ($section === 'profile' && $profile): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-user"></i> My Profile</h2>
                    </div>
                    
                    <div class="profile-info fade-in">
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <p><strong><i class="fas fa-id-card"></i> User ID:</strong> <?= $_SESSION['user_id'] ?></p>
                        <?php endif; ?>
                        <p><strong><i class="fas fa-user"></i> Username:</strong> <?= htmlspecialchars($profile['username']) ?></p>
                        <p><strong><i class="fas fa-user-tag"></i> Role:</strong> 
                            <span class="badge badge-<?= strtolower($profile['role']) ?>">
                                <?= ucfirst($profile['role']) ?>
                            </span>
                        </p>
                        <p><strong><i class="fas fa-envelope"></i> Email:</strong> <?= htmlspecialchars($profile['email'] ?? 'N/A') ?></p>
                        <p><strong><i class="fas fa-phone"></i> Phone:</strong> <?= htmlspecialchars($profile['phone'] ?? 'N/A') ?></p>
                    </div>
                    
                    <form method="POST" action="?section=profile" class="fade-in">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="full_name"><i class="fas fa-signature"></i> Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" 
                                   value="<?= htmlspecialchars($profile['full_name']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="email" name="email" class="form-control" 
                                   value="<?= htmlspecialchars($profile['email'] ?? '') ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone"><i class="fas fa-phone"></i> Phone</label>
                            <input type="tel" id="phone" name="phone" class="form-control" 
                                   value="<?= htmlspecialchars($profile['phone'] ?? '') ?>">
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </form>
                <?php elseif ($section === 'generate_report'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-file-alt"></i> Generate Report</h2>
                        <div class="btn-group">
                            <button class="btn" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                            <a href="?section=generate_report&export=pdf" class="btn btn-info">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </a>
                            <a href="?section=generate_report&export=excel" class="btn btn-info">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                        </div>
                    </div>
                    
                    <form method="GET" action="?section=generate_report" class="fade-in">
                        <input type="hidden" name="section" value="generate_report">
                        
                        <div class="form-group">
                            <label for="start_date"><i class="fas fa-calendar-alt"></i> Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date"><i class="fas fa-calendar-alt"></i> End Date</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="category"><i class="fas fa-tags"></i> Category</label>
                            <input type="text" id="category" name="category" class="form-control" value="<?= htmlspecialchars($_GET['category'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="status"><i class="fas fa-info-circle"></i> Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="">All</option>
                                <option value="Pending" <?= isset($_GET['status']) && $_GET['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Approved" <?= isset($_GET['status']) && $_GET['status'] === 'Approved' ? 'selected' : '' ?>>Approved</option>
                                <option value="In Progress" <?= isset($_GET['status']) && $_GET['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="Completed" <?= isset($_GET['status']) && $_GET['status'] === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </form>
                    
                    <?php if (!empty($report_data)): ?>
                        <?php foreach ($report_data as $status => $requests): ?>
                            <h3><?= ucfirst($status) ?> Requests</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $request): ?>
                                        <tr>
                                            <td>#<?= $request['request_id'] ?></td>
                                            <td><?= htmlspecialchars($request['category']) ?></td>
                                            <td><?= htmlspecialchars(substr($request['description'], 0, 50)) ?><?= strlen($request['description']) > 50 ? '...' : '' ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <p>No service requests found</p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Include Chart.js library -->
                    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

                    <!-- Add a canvas element for the chart -->
                    <div class="card fade-in">
                        <div class="card-header">
                            <h2><i class="fas fa-chart-pie"></i> Graphical Insights</h2>
                        </div>
                        <canvas id="reportChart" width="400" height="200"></canvas>
                    </div>

                    <script>
                        // Prepare data for the chart
                        const reportData = {
                            labels: ["Pending", "Approved", "In Progress", "Completed"], // Example labels
                            datasets: [{
                                label: 'Requests by Status',
                                data: [
                                    <?= isset($report_data['Pending']) ? count($report_data['Pending']) : 0 ?>,
                                    <?= isset($report_data['Approved']) ? count($report_data['Approved']) : 0 ?>,
                                    <?= isset($report_data['In Progress']) ? count($report_data['In Progress']) : 0 ?>,
                                    <?= isset($report_data['Completed']) ? count($report_data['Completed']) : 0 ?>
                                ],
                                backgroundColor: [
                                    'rgba(255, 99, 132, 0.2)',
                                    'rgba(54, 162, 235, 0.2)',
                                    'rgba(255, 206, 86, 0.2)',
                                    'rgba(75, 192, 192, 0.2)'
                                ],
                                borderColor: [
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 206, 86, 1)',
                                    'rgba(75, 192, 192, 1)'
                                ],
                                borderWidth: 1
                            }]
                        };

                        // Render the chart
                        const ctx = document.getElementById('reportChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'bar', // Change to 'pie' or 'line' for different chart types
                            data: reportData,
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'top',
                                    },
                                    title: {
                                        display: true,
                                        text: 'Service Requests Overview'
                                    }
                                }
                            }
                        });
                    </script>
                <?php elseif ($section === 'dashboard'): ?>
                    <?php
                    // Fetch data for the dashboard
                    $total_requests = $conn->query("SELECT COUNT(*) AS count FROM service_requests")->fetch_assoc()['count'];
                    $total_users = $conn->query("SELECT COUNT(*) AS count FROM users")->fetch_assoc()['count'];
                    $total_technicians = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role = 'technician'")->fetch_assoc()['count'];
                    $total_admins = $conn->query("SELECT COUNT(*) AS count FROM users WHERE role = 'admin'")->fetch_assoc()['count'];
                    ?>

                    <div class="dashboard">
                        <div class="dashboard-header">
                            <h1>System Overview</h1>
                        </div>
                        <div class="dashboard-cards">
                            <div class="card">
                                <h2>Total Requests</h2>
                                <p id="total-requests" class="count">0</p>
                            </div>
                            <div class="card">
                                <h2>Total Users</h2>
                                <p id="total-users" class="count">0</p>
                            </div>
                            <div class="card">
                                <h2>Total Technicians</h2>
                                <p id="total-technicians" class="count">0</p>
                            </div>
                            <div class="card">
                                <h2>Total Admins</h2>
                                <p id="total-admins" class="count">0</p>
                            </div>
                        </div>
                        <canvas id="dashboardChart" width="100" height="50"></canvas>
                    </div>

                    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                    <script>
                        // Animate count up
                        function animateCount(id, target) {
                            let count = 0;
                            const interval = setInterval(() => {
                                count += Math.ceil(target / 100);
                                if (count >= target) {
                                    count = target;
                                    clearInterval(interval);
                                }
                                document.getElementById(id).textContent = count;
                            }, 20);
                        }

                        // Animate counts
                        animateCount('total-requests', <?= $total_requests ?>);
                        animateCount('total-users', <?= $total_users ?>);
                        animateCount('total-technicians', <?= $total_technicians ?>);
                        animateCount('total-admins', <?= $total_admins ?>);

                        // Chart data
                        const ctx = document.getElementById('dashboardChart').getContext('2d');
                        new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: ['Requests', 'Users', 'Technicians', 'Admins'],
                                datasets: [{
                                    data: [<?= $total_requests ?>, <?= $total_users ?>, <?= $total_technicians ?>, <?= $total_admins ?>],
                                    backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0']
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: {
                                        position: 'top',
                                    },
                                    title: {
                                        display: true,
                                        text: 'System Data Overview'
                                    }
                                }
                            }
                        });
                    </script>
                <?php elseif ($section === 'view_feedback'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-comments"></i> User Feedback</h2>
                    </div>
                    <?php
                    $feedback_query = "SELECT f.feedback_id, sr.user_id, f.request_id, f.comments, f.submitted_at
                                       FROM feedback f
                                       JOIN service_requests sr ON f.request_id = sr.request_id
                                       ORDER BY f.submitted_at DESC";
                    $feedback_result = $conn->query($feedback_query);
                    if ($feedback_result): ?>
                        <?php if ($feedback_result->num_rows > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>User ID</th>
                                        <th>Feedback</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $feedback_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?= $row['feedback_id'] ?></td>
                                            <td><?= htmlspecialchars($row['user_id']) ?></td>
                                            <td><?= htmlspecialchars($row['comments']) ?></td>
                                            <td><?= date('M j, H:i', strtotime($row['submitted_at'])) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-comments"></i>
                                <p>No feedback available</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> Error loading feedback: <?= $conn->error ?>
                        </div>
                    <?php endif; ?>
                <?php elseif ($section === 'view_complaints'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-exclamation-circle"></i> User Complaints</h2>
                        <button class="btn" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    <?php if (isset($results[0]) && $results[0]->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Request ID</th>
                                    <th>User</th>
                                    <th>Description</th>
                                    <th>Request Status</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($row = $results[0]->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?= $row['complaint_id'] ?></td>
                                        <td>#<?= isset($row['request_id']) && $row['request_id'] !== '' ? $row['request_id'] : 'N/A' ?></td>
                                        <td><?= htmlspecialchars($row['full_name'] ?? 'User #'.$row['user_id']) ?></td>
                                        <td><?= htmlspecialchars($row['description']) ?></td>
                                        <td><?= htmlspecialchars($row['request_status']) ?></td>
                                        <td><?= date('M j, H:i', strtotime($row['created_at'])) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-comment-slash"></i>
                            <p>No complaints submitted</p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer">
            <p>Copyright © 2012 - <?= date('Y') ?> Arba Minch University | Service Request Management System</p>
            <p>Version 2.1.0 | <?= date('l, F j, Y H:i:s') ?></p>
        </div>
    </div>

    <script>
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');
        const desktopLayout = window.matchMedia('(min-width: 993px)');

        function setSidebarOpen(isOpen) {
            sidebar.classList.toggle('active', isOpen);
            mainContent.classList.toggle('active', isOpen && desktopLayout.matches);
        }

        setSidebarOpen(desktopLayout.matches);

        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            setSidebarOpen(!sidebar.classList.contains('active'));
        });

        document.querySelector('.sidebar-close').addEventListener('click', function() {
            setSidebarOpen(false);
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.notification')) {
                document.querySelector('.notification-panel').style.display = 'none';
            }
            if (!e.target.closest('.user-profile')) {
                document.querySelector('.user-dropdown').style.display = 'none';
            }
        });
        
        // Auto-refresh notifications every 30 seconds
        function refreshNotifications() {
            fetch('get_notifications.php')
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    const badge = document.querySelector('.notification-badge');
                    if (data.count > 0) {
                        if (!badge) {
                            const newBadge = document.createElement('span');
                            newBadge.className = 'notification-badge';
                            newBadge.textContent = data.count;
                            document.querySelector('.notification').appendChild(newBadge);
                        } else {
                            badge.textContent = data.count;
                        }
                    } else if (badge) {
                        badge.remove();
                    }
                })
                .catch(error => console.error('Error refreshing notifications:', error));
        }
        
        setInterval(refreshNotifications, 30000);
        
        // Initialize with a refresh
        document.addEventListener('DOMContentLoaded', refreshNotifications);
    </script>
</body>
</html>
<?php
// Close all database connections
foreach ($results as $result) {
    if ($result instanceof mysqli_result) {
        $result->free();
    }
}
$conn->close();