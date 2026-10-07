<?php
ob_start(); // Start output buffering to prevent premature output

// Set custom session timeout
ini_set('session.gc_maxlifetime', 3600); // 1 hour (3600 seconds)

require_once __DIR__ . '/includes/session.php';

if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Redirect if not logged in as a valid user 
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

$user_id = $_SESSION['user_id'];
$section = $_GET['section'] ?? 'dashboard';

// Consolidated queries
$queries = [
    'dashboard' => [
        "SELECT COUNT(*) as total_requests FROM service_requests WHERE user_id = $user_id",
        "SELECT COUNT(*) as pending_requests FROM service_requests WHERE user_id = $user_id AND status = 'Pending'",
        "SELECT COUNT(*) as in_progress FROM service_requests WHERE user_id = $user_id AND status = 'In Progress'",
        "SELECT COUNT(*) as completed FROM service_requests WHERE user_id = $user_id AND status = 'Completed'",
        "SELECT * FROM service_requests WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5"
    ],
    'track_requests' => "SELECT * FROM service_requests WHERE user_id = $user_id ORDER BY created_at DESC",
    'submit_request' => "SELECT category_name FROM service_categories WHERE is_active = 1",
    'view_history' => "SELECT sr.*, f.rating, f.comment FROM service_requests sr LEFT JOIN feedback f ON sr.request_id = f.request_id WHERE sr.user_id = $user_id ORDER BY sr.created_at DESC",
    'profile' => "SELECT username, full_name, email, phone, department, role FROM users WHERE user_id = $user_id"
];

// Execute queries
$results = [];
foreach ($queries as $key => $query) {
    if ($key === $section || ($section === 'dashboard' && $key === 'dashboard')) {
        if (is_array($query)) {
            $results = array_map(fn($q) => $conn->query($q), $query);
        } else {
            $results = [$conn->query($query)];
        }
        break;
    }
}

// Handle profile update
$profile_message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile']) && $section == 'profile') {
    require_valid_csrf_token();
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
    $stmt->bind_param("sssi", $full_name, $email, $phone, $user_id);
    
    if ($stmt->execute()) {
        $profile_message = "Profile updated successfully!";
        $results[0] = $conn->query($queries['profile']);
    } else {
        $profile_message = "Error updating profile: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch profile data for dashboard
$profile_result = $conn->query($queries['profile']);
$profile = $profile_result ? $profile_result->fetch_assoc() : null;

include 'includes/access_control.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - AMU SRMS</title>
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
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            transform: translateX(0); /* Ensure sidebar is always visible */
            transition: none; /* Remove transition for visibility */
        }
        
        .sidebar-header {
            padding: 1.5rem;
            display: flex;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
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
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: all 0.3s;
        }
        
        /* Top Navigation */
        .top-nav {
            background-color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between; /* Align items to the left and right */
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        /* Toggle Sidebar Button */
        .toggle-sidebar {
            font-size: 1.5rem;
            color: var(--primary);
            cursor: pointer;
            display: block; /* Ensure it is visible */
            z-index: 1100; /* Higher than the sidebar */
            position: relative; /* Ensure it is above the sidebar */
        }
        
        .user-actions {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-left: auto; /* Push the profile avatar to the right */
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
        
        .btn-danger {
            background-color: var(--accent);
        }
        
        .btn-danger:hover {
            background-color: #e03e3e;
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
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .stat-icon.pending {
            background-color: rgba(255,193,7,0.1);
            color: var(--warning);
        }
        
        .stat-icon.in-progress {
            background-color: rgba(0,123,255,0.1);
            color: #007bff;
        }
        
        .stat-icon.completed {
            background-color: rgba(40,167,69,0.1);
            color: var(--success);
        }
        
        .stat-icon.total {
            background-color: rgba(108,117,125,0.1);
            color: #6c757d;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--primary);
        }
        
        .stat-label {
            color: var(--gray);
            font-size: 0.9rem;
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
            color: var (--success);
            border-left: 4px solid var(--success);
        }
        
        .alert-danger {
            background-color: rgba(255,68,68,0.1);
            color: var(--accent);
            border-left: 4px solid var(--accent);
        }
        
        .profile-info {
            margin-bottom: 2rem;
        }
        
        .profile-info p {
            margin-bottom: 0.5rem;
        }
        
        .profile-info strong {
            color: var(--primary);
            font-weight: 500;
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
        
        .badge-rejected {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        /* Request status timeline */
        .timeline {
            position: relative;
            padding-left: 1.5rem;
            margin: 1rem 0;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        
        .timeline-dot {
            position: absolute;
            left: -1.5rem;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.6rem;
        }
        
        .timeline-content {
            padding-left: 1rem;
        }
        
        .timeline-date {
            font-size: 0.8rem;
            color: var(--gray);
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
                position: fixed;
                z-index: 1001;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .toggle-sidebar {
                display: block;
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
            
            .stats-container {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 576px) {
            .stats-container {
                grid-template-columns: 1fr;
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
        
        /* Rating stars */
        .rating {
            display: inline-flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
        
        .rating input {
            display: none;
        }
        
        .rating label {
            color: #ddd;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0 0.1rem;
        }
        
        .rating input:checked ~ label {
            color: #ffc107;
        }
        
        .rating label:hover,
        .rating label:hover ~ label {
            color: #ffc107;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="image/amulogo.png" alt="AMU Logo">
            <h2>AMU SRMS</h2>
        </div>
        
        <div class="sidebar-menu">
            <a href="?section=dashboard" class="menu-item <?= $section === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
            <a href="?section=submit_request" class="menu-item <?= $section === 'submit_request' ? 'active' : '' ?>">
                <i class="fas fa-plus-circle"></i>
                <span>Submit Request</span>
            </a>
            <a href="?section=track_requests" class="menu-item <?= $section === 'track_requests' ? 'active' : '' ?>">
                <i class="fas fa-tasks"></i>
                <span>Track Requests</span>
            </a>
            <a href="?section=view_history" class="menu-item <?= $section === 'view_history' ? 'active' : '' ?>">
                <i class="fas fa-history"></i>
                <span>Request History</span>
            </a>
            <a href="?section=profile" class="menu-item <?= $section === 'profile' ? 'active' : '' ?>">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
            <a href="?section=submit_complaint" class="menu-item <?= $section === 'submit_complaint' ? 'active' : '' ?>">
                <i class="fas fa-exclamation-circle"></i>
                <span>Submit Complaint</span>
            </a>
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
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($profile['full_name'] ?? 'U', 0, 1)) ?>
                    </div>
                    <span><?= htmlspecialchars($profile['full_name'] ?? 'User') ?></span>
                    
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
            <?php if ($profile_message): ?>
                <div class="alert <?= strpos($profile_message, 'success') !== false ? 'alert-success' : 'alert-danger' ?> fade-in">
                    <i class="fas <?= strpos($profile_message, 'success') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= $profile_message ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['message'])): ?>
                <div class="alert alert-success fade-in">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($_GET['message']) ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger fade-in">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($_GET['error']) ?>
                </div>
            <?php endif; ?>
            
            <div class="card fade-in">
                <?php if ($section === 'dashboard'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-tachometer-alt"></i> Dashboard Overview</h2>
                        <button class="btn" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    
                    <div class="stats-container">
                        <div class="stat-card">
                            <div class="stat-icon total">
                                <i class="fas fa-list"></i>
                            </div>
                            <div class="stat-value"><?= $results[0]->fetch_assoc()['total_requests'] ?? 0 ?></div>
                            <div class="stat-label">Total Requests</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-value"><?= $results[1]->fetch_assoc()['pending_requests'] ?? 0 ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon in-progress">
                                <i class="fas fa-spinner"></i>
                            </div>
                            <div class="stat-value"><?= $results[2]->fetch_assoc()['in_progress'] ?? 0 ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon completed">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="stat-value"><?= $results[3]->fetch_assoc()['completed'] ?? 0 ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-history"></i> Recent Requests</h2>
                            <a href="?section=track_requests" class="btn">
                                <i class="fas fa-eye"></i> View All
                            </a>
                        </div>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Date Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($results[4] && $results[4]->num_rows > 0): ?>
                                    <?php while ($row = $results[4]->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?= $row['request_id'] ?></td>
                                            <td><?= $row['category'] ?></td>
                                            <td>
                                                <span class="badge badge-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                                                    <?= ucfirst($row['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= date('M d, Y H:i', strtotime($row['created_at'])) ?></td>
                                            <td>
                                                <?php if ($row['status'] === 'Completed'): ?>
                                                    <a href="?section=view_history#request-<?= $row['request_id'] ?>" class="btn">
                                                        <i class="fas fa-comment"></i> Feedback
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?section=track_requests#request-<?= $row['request_id'] ?>" class="btn">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center;">No recent requests found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                
                <?php elseif ($section === 'submit_request'): ?>
                    <div class="card fade-in">
                        <div class="card-header">
                            <h2><i class="fas fa-plus-circle"></i> Submit New Request</h2>
                        </div>
                        
                        <!-- Include the external submit form -->
                        <?php include('submit_request.php'); ?>
                    </div>
                
                <?php elseif ($section === 'track_requests'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-tasks"></i> Track Your Requests</h2>
                        <button class="btn" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Date Submitted</th>
                                <th>Assigned To</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($results[0] && $results[0]->num_rows > 0): ?>
                                <?php while ($row = $results[0]->fetch_assoc()): ?>
                                    <tr id="request-<?= $row['request_id'] ?>">
                                        <td>#<?= $row['request_id'] ?></td>
                                        <td><?= htmlspecialchars($row['category']) ?></td>
                                        <td><?= substr(htmlspecialchars($row['description']), 0, 50) ?>...</td>
                                        <td>
                                            <span class="badge badge-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                                                <?= ucfirst($row['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                                        <td>
                                            <?php 
                                            if ($row['assigned_to']) {
                                                $tech_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
                                                $tech_stmt->bind_param("i", $row['assigned_to']);
                                                $tech_stmt->execute();
                                                $tech_result = $tech_stmt->get_result();
                                                echo $tech_result->num_rows > 0 ? htmlspecialchars($tech_result->fetch_assoc()['full_name']) : 'Not assigned';
                                                $tech_stmt->close();
                                            } else {
                                                echo 'Not assigned';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="request_details.php?request_id=<?= $row['request_id'] ?>" class="btn">
                                                    <i class="fas fa-eye"></i> Details
                                                </a>
                                                <?php if ($row['status'] === 'Pending'): ?>
                                                    <a href="cancel_request.php?id=<?= $row['request_id'] ?>" class="btn btn-danger" 
                                                       onclick="return confirm('Are you sure you want to cancel this request?')">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </a>
                                                <?php elseif ($row['status'] === 'Awaiting User Confirmation'): ?>
                                                    <form method="POST" action="confirm_request.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="request_id" value="<?= (int) $row['request_id'] ?>">
                                                        <button type="submit" class="btn btn-success btn-sm">
                                                            <i class="fas fa-check"></i> Confirm
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center;">No service requests found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                
                <?php elseif ($section === 'view_history'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-history"></i> Request History</h2>
                    </div>
                    
                    <?php if ($results[0] && $results[0]->num_rows > 0): ?>
                        <?php while ($row = $results[0]->fetch_assoc()): ?>
                            <div class="card fade-in" id="request-<?= $row['request_id'] ?>" style="margin-bottom: 1.5rem;">
                                <div class="card-header">
                                    <h3>
                                        #<?= $row['request_id'] ?> - <?= htmlspecialchars($row['category']) ?>
                                        <span class="badge badge-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                                            <?= ucfirst($row['status']) ?>
                                        </span>
                                    </h3>
                                    <small><?= date('F j, Y \a\t g:i a', strtotime($row['created_at'])) ?></small>
                                </div>
                                
                                <div style="padding: 0 1.5rem 1.5rem;">
                                    <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($row['description'])) ?></p>
                                    <p><strong>Location:</strong> <?= htmlspecialchars($row['location']) ?></p>
                                    <p><strong>Urgency:</strong> <?= htmlspecialchars($row['urgency']) ?></p>
                                    
                                    <?php if ($row['assigned_to']): ?>
                                        <?php 
                                        $tech_stmt = $conn->prepare("SELECT full_name FROM users WHERE user_id = ?");
                                        $tech_stmt->bind_param("i", $row['assigned_to']);
                                        $tech_stmt->execute();
                                        $tech_result = $tech_stmt->get_result();
                                        $tech_name = $tech_result->num_rows > 0 ? $tech_result->fetch_assoc()['full_name'] : 'Unknown Technician';
                                        $tech_stmt->close();
                                        ?>
                                        <p><strong>Assigned To:</strong> <?= htmlspecialchars($tech_name) ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="timeline" style="margin-top: 1.5rem;">
                                        <div class="timeline-item">
                                            <div class="timeline-dot">
                                                <i class="fas fa-check"></i>
                                            </div>
                                            <div class="timeline-content">
                                                <p>Request Submitted</p>
                                                <small class="timeline-date"><?= date('M j, Y g:i a', strtotime($row['created_at'])) ?></small>
                                            </div>
                                        </div>
                                        
                                        <?php if ($row['status'] !== 'Pending'): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-dot">
                                                    <i class="fas fa-check"></i>
                                                </div>
                                                <div class="timeline-content">
                                                    <p>Request <?= $row['status'] === 'Rejected' ? 'Rejected' : 'Approved' ?></p>
                                                    <small class="timeline-date"><?= date('M j, Y g:i a', strtotime($row['updated_at'])) ?></small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($row['status'] === 'In Progress' || $row['status'] === 'Completed'): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-dot">
                                                    <i class="fas fa-user-cog"></i>
                                                </div>
                                                <div class="timeline-content">
                                                    <p>Assigned to Technician</p>
                                                    <small class="timeline-date"><?= date('M j, Y g:i a', strtotime($row['updated_at'])) ?></small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($row['status'] === 'Completed'): ?>
                                            <div class="timeline-item">
                                                <div class="timeline-dot">
                                                    <i class="fas fa-check-double"></i>
                                                </div>
                                                <div class="timeline-content">
                                                    <p>Request Completed</p>
                                                    <small class="timeline-date"><?= date('M j, Y g:i a', strtotime($row['completed_at'])) ?></small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($row['status'] === 'Completed'): ?>
                                        <div style="margin-top: 2rem; padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                                            <h4 style="margin-bottom: 1rem;">Feedback</h4>
                                            <?php if ($row['rating']): ?>
                                                <div style="margin-bottom: 1rem;">
                                                    <strong>Rating:</strong>
                                                    <div>
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="fas fa-star" style="color: <?= $i <= $row['rating'] ? '#ffc107' : '#ddd' ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                                <?php if ($row['comment']): ?>
                                                    <div>
                                                        <strong>Comment:</strong>
                                                        <p><?= nl2br(htmlspecialchars($row['comment'])) ?></p>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <p>No feedback provided yet.</p>
                                                <a href="provide_feedback.php?request_id=<?= $row['request_id'] ?>" class="btn" style="margin-top: 1rem;">
                                                    <i class="fas fa-comment"></i> Provide Feedback
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="card fade-in">
                            <div style="padding: 2rem; text-align: center;">
                                <i class="fas fa-history" style="font-size: 3rem; color: var(--gray); margin-bottom: 1rem;"></i>
                                <h3>No Request History Found</h3>
                                <p>You haven't submitted any service requests yet.</p>
                                <a href="?section=submit_request" class="btn" style="margin-top: 1rem;">
                                    <i class="fas fa-plus"></i> Submit New Request
                                </a>
                            </div>
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
                        <p><strong><i class="fas fa-user"></i> Full Name:</strong> <?= htmlspecialchars($profile['full_name']) ?></p>
                        <p><strong><i class="fas fa-user"></i> Username:</strong> <?= htmlspecialchars($profile['username']) ?></p>
                        <p><strong><i class="fas fa-user-tag"></i> Role:</strong> 
                            <span class="badge badge-<?= strtolower($profile['role']) ?>">
                                <?= ucfirst(htmlspecialchars($profile['role'])) ?>
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
                <?php elseif ($section === 'awaiting_confirmation'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-clock"></i> Awaiting Confirmation</h2>
                    </div>
                    
                    <?php if (!empty($awaiting_tasks) && is_array($awaiting_tasks)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th>Date Updated</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($awaiting_tasks as $task): ?>
                                    <tr>
                                        <td>#<?= $task['request_id'] ?></td>
                                        <td><?= htmlspecialchars($task['category']) ?></td>
                                        <td><?= htmlspecialchars($task['description']) ?></td>
                                        <td><?= date('M d, Y H:i', strtotime($task['updated_at'])) ?></td>
                                        <td>
                                            <a href="?section=track_requests#request-<?= $task['request_id'] ?>" class="btn">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div style="text-align: center; padding: 2rem;">
                            <i class="fas fa-clock" style="font-size: 3rem; color: var(--gray); margin-bottom: 1rem;"></i>
                            <h3>No Awaiting Confirmation Requests</h3>
                            <p>All your requests have been processed.</p>
                        </div>
                    <?php endif; ?>
                <?php elseif ($section === 'submit_complaint'): ?>
                    ?>
                    <div class="card fade-in">
                        <div class="card-header">
                            <h2><i class="fas fa-exclamation-circle"></i> Submit Complaint</h2>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="user_dashboard.php?section=submit_complaint">
                                <?= csrf_field() ?>
                                <div class="form-group">
                                    <label for="request_id">Select Request:</label>
                                    <select id="request_id" name="request_id" class="form-control" required>
                                        <option value="">-- Select a Request --</option>
                                        <?php
                                        $query = "SELECT request_id, category, status FROM service_requests WHERE user_id = ? AND status IN ('Pending', 'In Progress')";
                                        $stmt = $conn->prepare($query);
                                        $stmt->bind_param("i", $user_id);
                                        $stmt->execute();
                                        $result = $stmt->get_result();

                                        while ($row = $result->fetch_assoc()): ?>
                                            <option value="<?= $row['request_id'] ?>">
                                                Request #<?= $row['request_id'] ?> - <?= htmlspecialchars($row['category']) ?> (<?= $row['status'] ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="description">Complaint Description:</label>
                                    <textarea id="description" name="description" class="form-control" rows="5" required></textarea>
                                </div>

                                <button type="submit" name="submit_complaint" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Submit Complaint
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php

                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_complaint'])) {
                        require_valid_csrf_token();
                        $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
                        $description = trim((string) ($_POST['description'] ?? ''));
                        if (!$request_id || $description === '') {
                            http_response_code(400);
                            exit('Invalid complaint.');
                        }

                        $ownership = $conn->prepare("SELECT request_id FROM service_requests WHERE request_id = ? AND user_id = ?");
                        $ownership->bind_param("ii", $request_id, $user_id);
                        $ownership->execute();
                        $ownsRequest = $ownership->get_result()->num_rows === 1;
                        $ownership->close();
                        if (!$ownsRequest) {
                            http_response_code(403);
                            exit('You can only complain about your own requests.');
                        }

                        $query = "INSERT INTO complaint (request_id, user_id, description, created_at) VALUES (?, ?, ?, NOW())";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("iis", $request_id, $user_id, $description);

                        if ($stmt->execute()) {
                            echo '<div class="alert alert-success">Complaint submitted successfully!</div>';
                        } else {
                            echo '<div class="alert alert-danger">Failed to submit complaint. Please try again.</div>';
                        }

                        $stmt->close();
                    }
                endif; ?>
            </div>
        </div>
        
        <div class="footer">
            <p>Copyright © 2012 - <?= date('Y') ?> Arba Minch University | Service Request Management System</p>
            <p>Version 2.0.0</p>
        </div>
    </div>
    
    <script>
        // Toggle sidebar on mobile
        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.user-profile')) {
                document.querySelector('.user-dropdown').style.display = 'none';
            }
        });
        
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>
</body>
</html>