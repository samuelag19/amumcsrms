<?php
// Set custom session timeout
ini_set('session.gc_maxlifetime', 3600); // 1 hour (3600 seconds)

require_once __DIR__ . '/includes/session.php';

if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}

// Redirect if not logged in as technician
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
    header("Location: login.php");
    exit;
}

include 'includes/access_control.php';

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Default section to display (Assigned Requests)
$section = isset($_GET['section']) ? $_GET['section'] : 'assigned_requests';

// Fetch technician's name for the dashboard
$tech_query = "SELECT user_id, full_name, username, email, phone, role FROM users WHERE user_id = ?";
$tech_stmt = $conn->prepare($tech_query);
$tech_stmt->bind_param("i", $_SESSION['user_id']);
$tech_stmt->execute();
$tech_result = $tech_stmt->get_result();
$technician = $tech_result->fetch_assoc();

// Count new assignments (approved but not yet in progress)
$notification_query = "SELECT COUNT(*) as new_assignments FROM service_requests 
                      WHERE assigned_to = ? AND status = 'Approved'";
$notif_stmt = $conn->prepare($notification_query);
$notif_stmt->bind_param("i", $_SESSION['user_id']);
$notif_stmt->execute();
$notif_result = $notif_stmt->get_result();
$notifications = $notif_result->fetch_assoc();

// Fetch data based on the selected section
switch ($section) {
    case 'assigned_requests':
        $query = "SELECT * FROM service_requests 
                  WHERE assigned_to = ? AND status IN ('In Progress', 'Approved')
                  ORDER BY FIELD(urgency, 'high', 'medium', 'low'), created_at DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        break;

    case 'completed_requests':
        $query = "SELECT * FROM service_requests 
                 WHERE assigned_to = ? AND status = 'Completed'
                 ORDER BY completed_at DESC LIMIT 50";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        break;

    case 'profile':
        // Fetch technician's profile data
        $query = "SELECT full_name, email, phone FROM users WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $profile_result = $stmt->get_result();
        $profile_data = $profile_result->fetch_assoc();
        break;

    case 'awaiting_user_confirmation':
        $query = "SELECT sr.request_id, sr.category, sr.description, sr.updated_at, u.full_name AS user_name 
                  FROM service_requests sr 
                  JOIN users u ON sr.user_id = u.user_id 
                  WHERE sr.status = 'Awaiting User Confirmation' AND sr.assigned_to = ? 
                  ORDER BY sr.updated_at DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        break;

    default:
        $query = "SELECT * FROM service_requests 
                 WHERE assigned_to = ? AND status IN ('In Progress', 'Approved')
                 ORDER BY FIELD(urgency, 'high', 'medium', 'low'), created_at DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        break;
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile']) && $section == 'profile') {
    require_valid_csrf_token();
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $email = $conn->real_escape_string($_POST['email']);
    $phone = $conn->real_escape_string($_POST['phone']);
    
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("sssi", $full_name, $email, $phone, $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        $profile_message = "Profile updated successfully!";
        // Refresh the technician data
        $tech_query = "SELECT * FROM users WHERE user_id = ?";
        $tech_stmt = $conn->prepare($tech_query);
        $tech_stmt->bind_param("i", $_SESSION['user_id']);
        $tech_stmt->execute();
        $tech_result = $tech_stmt->get_result();
        $technician = $tech_result->fetch_assoc();
    } else {
        $profile_message = "Error updating profile: " . $stmt->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Dashboard - AMU SRMS</title>
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
            left: 0;
        }
        
        .sidebar.collapsed {
            width: 80px;
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
            white-space: nowrap;
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
            white-space: nowrap;
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
        
        .menu-item span {
            transition: opacity 0.3s;
        }
        
        .sidebar.collapsed .menu-item span {
            opacity: 0;
            width: 0;
            display: none;
        }
        
        .sidebar.collapsed .sidebar-header h2 {
            display: none;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            transition: all 0.3s;
        }
        
        .main-content.collapsed {
            margin-left: 80px;
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
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: var(--accent);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: bold;
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
        
        .btn-warning {
            background-color: var(--warning);
            color: var(--dark);
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .btn-success {
            background-color: var(--success);
        }
        
        .btn-success:hover {
            background-color: #218838;
        }
        
        .btn-info {
            background-color: var(--info);
        }
        
        .btn-info:hover {
            background-color: #138496;
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
        
        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
                width: 280px;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .main-content.active {
                margin-left: 280px;
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
                flex-direction: column;
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
        
        /* Footer */
        .footer {
            text-align: center;
            padding: 1.5rem;
            color: var(--gray);
            background-color: white;
            border-top: 1px solid rgba(0,0,0,0.05);
        }

        .profile-container {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
        }

        .profile-details, .profile-edit {
            flex: 1;
            min-width: 300px;
        }

        .profile-details h3, .profile-edit h3 {
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .profile-details p {
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .btn-primary:hover {
            background-color: var(--secondary);
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
            <a href="?section=assigned_requests" class="menu-item <?= $section === 'assigned_requests' ? 'active' : '' ?>">
                <i class="fas fa-tools"></i>
                <span>Assigned Requests</span>
                <?php if ($notifications['new_assignments'] > 0 && $section !== 'assigned_requests'): ?>
                    <span class="notification-badge" style="margin-left: auto;"><?= $notifications['new_assignments'] ?></span>
                <?php endif; ?>
            </a>
            <a href="?section=completed_requests" class="menu-item <?= $section === 'completed_requests' ? 'active' : '' ?>">
                <i class="fas fa-check-circle"></i>
                <span>Completed Requests</span>
            </a>
            <a href="?section=profile" class="menu-item <?= $section === 'profile' ? 'active' : '' ?>">
                <i class="fas fa-user"></i>
                <span>My Profile</span>
            </a>
            <a href="?section=awaiting_user_confirmation" class="menu-item <?= $section === 'awaiting_user_confirmation' ? 'active' : '' ?>">
                <i class="fas fa-clock"></i>
                <span>Awaiting User Confirmation</span>
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
            </div>
            
            <div class="user-actions">
                <div class="notification">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifications['new_assignments'] > 0): ?>
                        <span class="notification-badge"><?= $notifications['new_assignments'] ?></span>
                    <?php endif; ?>
                    
                    <div class="notification-panel">
                        <h4>New Assignments</h4>
                        <?php if ($notifications['new_assignments'] > 0): ?>
                            <?php
                            $new_req_query = "SELECT request_id, category FROM service_requests 
                                            WHERE assigned_to = ? AND status = 'Approved'
                                            ORDER BY created_at DESC LIMIT 5";
                            $new_req_stmt = $conn->prepare($new_req_query);
                            $new_req_stmt->bind_param("i", $_SESSION['user_id']);
                            $new_req_stmt->execute();
                            $new_req_result = $new_req_stmt->get_result();
                            while ($req = $new_req_result->fetch_assoc()): ?>
                                <div class="notification-item">
                                    <div><strong>New <?= $req['category'] ?> request</strong> (#<?= $req['request_id'] ?>)</div>
                                </div>
                            <?php endwhile; ?>
                            <div class="notification-item" style="text-align: center;">
                                <a href="?section=assigned_requests">View All</a>
                            </div>
                        <?php else: ?>
                            <div class="notification-item">No new assignments</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($technician['full_name'] ?? 'T', 0, 1)) ?>
                    </div>
                    <span><?= $technician['full_name'] ?? 'Technician' ?></span>
                    
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
            <div class="card fade-in">
                <?php if ($section === 'assigned_requests'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-tools"></i> Assigned Requests</h2>
                        <div class="btn-group">
                            <button class="btn" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Category</th>
                                <th>Urgency</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr class="request-row">
                                        <td>#<?= $row['request_id'] ?></td>
                                        <td><?= $row['category'] ?></td>
                                        <td><span class="badge badge-<?= strtolower($row['urgency']) ?>"><?= ucfirst($row['urgency']) ?></span></td>
                                        <td><?= $row['location'] ?></td>
                                        <td><span class="badge badge-<?= strtolower(str_replace(' ', '-', $row['status'])) ?>"><?= ucfirst($row['status']) ?></span></td>
                                        <td>
                                            <div class="btn-group">
                                                <?php if ($row['status'] === 'In Progress'): ?>
                                                    <form method="POST" action="mark_completed.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="request_id" value="<?= (int) $row['request_id'] ?>">
                                                        <button type="submit" class="btn btn-success">
                                                            <i class="fas fa-check"></i> Complete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <a href="request_details.php?request_id=<?= $row['request_id'] ?>" class="btn btn-info">
                                                    <i class="fas fa-info-circle"></i> Details
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center;">No assigned requests found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                
                <?php elseif ($section === 'completed_requests'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-check-circle"></i> Completed Requests</h2>
                        <div class="btn-group">
                            <button class="btn" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Category</th>
                                <th>Completed On</th>
                                <th>Location</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr class="request-row">
                                        <td>#<?= $row['request_id'] ?></td>
                                        <td><?= $row['category'] ?></td>
                                        <td><?= date('M d, Y', strtotime($row['completed_at'])) ?></td>
                                        <td><?= $row['location'] ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="request_details.php?request_id=<?= $row['request_id'] ?>" class="btn btn-info">
                                                    <i class="fas fa-info-circle"></i> Details
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center;">No completed requests found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                <?php elseif ($section === 'profile' && $technician): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-user"></i> My Profile</h2>
                    </div>
                    
                    <div class="card fade-in">
                        <div class="profile-container">
                            <div class="profile-details">
                                <h3><i class="fas fa-id-card"></i> Profile Information</h3>
                                <p><i class="fas fa-signature"></i> <strong>Full Name:</strong> <?= htmlspecialchars($technician['full_name']) ?></p>
                                <p><i class="fas fa-user"></i> <strong>Username:</strong> <?= htmlspecialchars($technician['username'] ?? 'N/A') ?></p>
                                <p><i class="fas fa-envelope"></i> <strong>Email:</strong> <?= htmlspecialchars($technician['email'] ?? 'N/A') ?></p>
                                <p><i class="fas fa-phone"></i> <strong>Phone:</strong> <?= htmlspecialchars($technician['phone'] ?? 'N/A') ?></p>
                                <p><i class="fas fa-user-tag"></i> <strong>Role:</strong> <?= ucfirst(htmlspecialchars($technician['role'])) ?></p>
                            </div>
                            
                            <div class="profile-edit">
                                <h3><i class="fas fa-edit"></i> Edit Profile</h3>
                                <form method="POST" action="?section=profile">
                                    <?= csrf_field() ?>
                                    <div class="form-group">
                                        <label for="full_name"><i class="fas fa-signature"></i> Full Name</label>
                                        <input type="text" id="full_name" name="full_name" class="form-control" 
                                               value="<?= htmlspecialchars($technician['full_name']) ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="email"><i class="fas fa-envelope"></i> Email</label>
                                        <input type="email" id="email" name="email" class="form-control" 
                                               value="<?= htmlspecialchars($technician['email'] ?? '') ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="phone"><i class="fas fa-phone"></i> Phone</label>
                                        <input type="tel" id="phone" name="phone" class="form-control" 
                                               value="<?= htmlspecialchars($technician['phone'] ?? '') ?>">
                                    </div>
                                    
                                    <button type="submit" name="update_profile" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php elseif ($section === 'awaiting_user_confirmation'): ?>
                    <div class="card">
                        <div class="card-header">
                            <h2><i class="fas fa-clock"></i> Awaiting User Confirmation</h2>
                        </div>
                        <?php
                        $query = "SELECT sr.request_id, sr.category, sr.description, sr.updated_at, u.full_name AS user_name 
                                  FROM service_requests sr 
                                  JOIN users u ON sr.user_id = u.user_id 
                                  WHERE sr.status = 'Awaiting User Confirmation' AND sr.assigned_to = ? 
                                  ORDER BY sr.updated_at DESC";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $_SESSION['user_id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        ?>
                        <?php if ($result->num_rows > 0): ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Category</th>
                                        <th>Description</th>
                                        <th>User</th>
                                        <th>Updated At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td>#<?= $row['request_id'] ?></td>
                                            <td><?= htmlspecialchars($row['category']) ?></td>
                                            <td><?= htmlspecialchars($row['description']) ?></td>
                                            <td><?= htmlspecialchars($row['user_name']) ?></td>
                                            <td><?= date('M d, Y H:i', strtotime($row['updated_at'])) ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>No requests awaiting user confirmation.</p>
                            </div>
                        <?php endif; ?>
                        <?php $stmt->close(); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer">
            <p>Copyright © 2012 - <?= date('Y') ?> Arba Minch University | Service Request Management System</p>
            <p>Version 2.1.0 | <?= date('l, F j, Y H:i:s') ?></p>
        </div>
        </div>
    </div>
    
    <script>
        // Toggle sidebar
        document.querySelector('.toggle-sidebar').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapsed');
            document.querySelector('.main-content').classList.toggle('collapsed');
        });
        
        // Close notifications when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.notification')) {
                document.querySelector('.notification-panel').style.display = 'none';
            }
            if (!e.target.closest('.user-profile')) {
                document.querySelector('.user-dropdown').style.display = 'none';
            }
        });
        
        // Auto-refresh notifications every 30 seconds
        setInterval(function() {
            fetch('get_tech_notifications.php?tech_id=<?= $_SESSION['user_id'] ?>')
                .then(response => response.json())
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
                            badge.style.display = 'flex';
                        }
                    } else if (badge) {
                        badge.style.display = 'none';
                    }
                });
        }, 30000);
    </script>
</body>
</html>
<?php
$conn->close();
?>