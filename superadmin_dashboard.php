<?php
ini_set('session.gc_maxlifetime', 3600); // 1 hour
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';

// Redirect if not logged in as superadmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'superadmin') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$section = $_GET['section'] ?? 'system_settings';
$message = '';
$profile_message = '';
$results = [];
$profile = null;
$notification_count = 0;

// Super Admin specific queries
$queries = [
    'system_settings' => "SELECT * FROM system_config ORDER BY config_key",
    'manage_admins' => "SELECT u.*, creator.full_name as created_by_name 
                       FROM users u
                       LEFT JOIN users creator ON u.created_by = creator.user_id
                       WHERE u.role = 'admin'
                       ORDER BY u.full_name",
    'audit_logs' => "SELECT al.*, u.username, u.full_name, al.action, al.entity_type, al.entity_id, al.details, al.timestamp, al.ip_address 
                    FROM audit_logs al
                    JOIN users u ON al.user_id = u.user_id
                    ORDER BY al.timestamp DESC LIMIT 100",
    'user_activity' => "SELECT user_id, username, full_name, role, 
                       last_login, is_active, is_locked
                       FROM users ORDER BY last_login DESC",
    'backup_management' => "SELECT table_name, table_rows 
                           FROM information_schema.tables 
                           WHERE table_schema = '$db_name'",
    'profile' => "SELECT * FROM users WHERE user_id = ?",
    'manage_users' => "SELECT * FROM users WHERE role != 'superadmin' ORDER BY full_name"
];
// Handle system settings update
if ($_SERVER["REQUEST_METHOD"] == "POST" && $section == 'system_settings') {
    require_valid_csrf_token();
    foreach ($_POST['config'] as $config_id => $config_value) {
        $stmt = $conn->prepare("UPDATE system_config SET 
                               config_value = ?, 
                               modified_by = ?,
                               last_modified = NOW()
                               WHERE config_id = ?");
        $stmt->bind_param("sii", $config_value, $_SESSION['user_id'], $config_id);
        if (!$stmt->execute()) {
            $message = "Error updating settings: " . $stmt->error;
        }
        $stmt->close();
    }
    $message = "System settings updated successfully!";
    
    // Log the action after updating system settings
    $details = "Updated system settings";
    log_action($conn, $_SESSION['user_id'], 'UPDATE_SETTINGS', 'SYSTEM_CONFIG', null, $details);
}

// Handle admin creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && $section == 'create_admin') {
    require_valid_csrf_token();
    $username = $conn->real_escape_string(trim($_POST['username']));
    $full_name = $conn->real_escape_string(trim($_POST['full_name']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $role = in_array($_POST['role'], ['admin', 'staff', 'technician']) ? $_POST['role'] : 'staff';
    $temp_password = bin2hex(random_bytes(4)); // Temporary password
    $hashed_password = password_hash($temp_password, PASSWORD_BCRYPT);
    
    $stmt = $conn->prepare("INSERT INTO users 
                           (username, password, role, full_name, email, created_by)
                           VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssi", $username, $hashed_password, $role, 
                     $full_name, $email, $_SESSION['user_id']);
    
    if ($stmt->execute()) {
        // Log this action
        $conn->query("INSERT INTO audit_logs
                     (user_id, action, entity_type, entity_id, ip_address)
                     VALUES ({$_SESSION['user_id']}, 'CREATE_USER', 'USER', {$stmt->insert_id}, 
                     '{$_SERVER['REMOTE_ADDR']}')");
        
        $message = "User created successfully! Temporary password: $temp_password";
    } else {
        $message = "Error creating user: " . $stmt->error;
    }
    $stmt->close();
}

// Get data for current section
if (array_key_exists($section, $queries)) {
    if ($section === 'profile') {
        $profile_stmt = $conn->prepare($queries['profile']);
        $profile_stmt->bind_param("i", $_SESSION['user_id']);
        $profile_stmt->execute();
        $profile = $profile_stmt->get_result()->fetch_assoc();
        $profile_stmt->close();
    } else {
        $result = $conn->query($queries[$section]);
        if ($result) {
            $results = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $message = "Error loading data: " . $conn->error;
        }
    }
}

if ($section === 'manage_users') {
    // Handle bulk actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
        require_valid_csrf_token();
        $action = $_POST['bulk_action'];
        $selected_users = $_POST['selected_users'] ?? [];

        if (!is_array($selected_users)) {
            http_response_code(400);
            exit('Invalid user selection.');
        }
        $selected_ids = array_filter(
            array_map(static fn($id) => filter_var($id, FILTER_VALIDATE_INT), $selected_users),
            static fn($id) => $id !== false && $id > 0 && $id !== (int) $_SESSION['user_id']
        );
        if ($selected_ids) {
            $user_ids = implode(',', array_unique($selected_ids));

            if ($action === 'activate') {
                $conn->query("UPDATE users SET is_active = 1 WHERE user_id IN ($user_ids)");
            } elseif ($action === 'deactivate') {
                $conn->query("UPDATE users SET is_active = 0 WHERE user_id IN ($user_ids)");
            } elseif ($action === 'delete') {
                $conn->query("DELETE FROM users WHERE user_id IN ($user_ids)");
            }
        }
    }

    // Handle search and filter
    $search_query = $conn->real_escape_string(trim((string) ($_GET['search'] ?? '')));
    $role_filter = $_GET['role'] ?? '';
    $role_filter = in_array($role_filter, ['admin', 'staff', 'technician'], true) ? $role_filter : '';
    $status_filter = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';

    $query = "SELECT * FROM users WHERE 1=1";
    if (!empty($search_query)) {
        $query .= " AND (username LIKE '%$search_query%' OR full_name LIKE '%$search_query%')";
    }
    if (!empty($role_filter)) {
        $query .= " AND role = '$role_filter'";
    }
    if (!empty($status_filter)) {
        $query .= " AND is_active = " . ($status_filter === 'active' ? 1 : 0);
    }
    $result = $conn->query($query);
}

if ($section === 'audit_logs') {
    $query = "SELECT al.*, u.username, u.full_name, al.action, al.entity_type, al.entity_id, al.details, al.timestamp, al.ip_address 
              FROM audit_logs al
              JOIN users u ON al.user_id = u.user_id
              ORDER BY al.timestamp DESC LIMIT 100";
    $result = $conn->query($query);

    if ($result) {
        $audit_logs = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $message = "Error loading audit logs: " . $conn->error;
    }
}

// Fetch notifications for the superadmin
$notifications_query = "SELECT message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10";
$stmt = $conn->prepare($notifications_query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$notifications_result = $stmt->get_result();
$notifications = $notifications_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - AMU SRMS</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* [Use the same CSS as admin_dashboard.php] */
        /* Add some additional styles for superadmin specific elements */
        .config-form {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .config-item {
            display: flex;
            margin-bottom: 1rem;
            padding: 1rem;
            background: #f9f9f9;
            border-radius: 5px;
        }
        
        .config-key {
            flex: 0 0 200px;
            font-weight: bold;
            color: var(--primary);
        }
        
        .config-value {
            flex: 1;
        }
        
        .config-desc {
            font-size: 0.9rem;
            color: var(--gray);
            margin-top: 0.5rem;
        }
        
        .audit-log-item {
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .audit-log-item:hover {
            background: #f5f5f5;
        }

        /* Notification styles */
        .notification {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: red;
            color: white;
            border-radius: 50%;
            padding: 5px 10px;
            font-size: 12px;
        }

        .notification-panel {
            display: none;
            position: absolute;
            top: 30px;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            width: 300px;
            z-index: 1000;
        }

        .notification:hover .notification-panel {
            display: block;
        }

        .notification-item {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item p {
            margin: 0;
            font-size: 14px;
        }

        .notification-item small {
            color: #888;
            font-size: 12px;
        }

        .user-actions {
            display: flex;
            align-items: center;
            gap: 20px; /* Added spacing between notification and profile */
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
            <a href="?section=system_settings" class="menu-item <?= $section === 'system_settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i>
                <span>System Settings</span>
            </a>
            <a href="?section=manage_admins" class="menu-item <?= $section === 'manage_admins' ? 'active' : '' ?>">
                <i class="fas fa-users-cog"></i>
                <span>Manage Admins</span>
            </a>
            <a href="?section=manage_users" class="menu-item <?= $section === 'manage_users' ? 'active' : '' ?>">
                <i class="fas fa-users"></i>
                <span>Manage Users</span>
            </a>
            <a href="admin_dashboard.php?section=assign_technicians" class="menu-item">
                <i class="fas fa-user-cog"></i>
                <span>Assign Technicians</span>
            </a>
            <a href="?section=audit_logs" class="menu-item <?= $section === 'audit_logs' ? 'active' : '' ?>">
                <i class="fas fa-clipboard-list"></i>
                <span>Audit Logs</span>
            </a>
            <a href="?section=user_activity" class="menu-item <?= $section === 'user_activity' ? 'active' : '' ?>">
                <i class="fas fa-chart-line"></i>
                <span>User Activity</span>
            </a>
            <a href="?section=backup_management" class="menu-item <?= $section === 'backup_management' ? 'active' : '' ?>">
                <i class="fas fa-database"></i>
                <span>Backup Management</span>
            </a>
            <a href="?section=profile" class="menu-item <?= $section === 'profile' ? 'active' : '' ?>">
                <i class="fas fa-user-shield"></i>
                <span>My Profile</span>
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
                <!-- Notification Icon -->
                <div class="notification">
                    <i class="fas fa-bell"></i>
                    <?php if (!empty($notifications)): ?>
                        <span class="notification-badge"><?= count($notifications) ?></span>
                    <?php endif; ?>
                    <div class="notification-panel">
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item">
                                    <p><?= htmlspecialchars($notification['message']) ?></p>
                                    <small><?= date('M j, Y H:i', strtotime($notification['created_at'])) ?></small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No new notifications</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($profile['full_name'] ?? 'SA', 0, 1)) ?>
                    </div>
                    <span>Super Admin</span>
                    
                    <div class="user-dropdown">
                        <a href="?section=profile" class="dropdown-item">
                            <i class="fas fa-user-shield"></i> My Profile
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
            
            <div class="card fade-in">
                <?php if ($section === 'system_settings'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-cog"></i> System Configuration</h2>
                        <p>Manage key system-wide settings such as site name, language, timezone, and date format.</p>
                    </div>

                    <form method="POST" action="?section=system_settings" class="config-form">
                        <?= csrf_field() ?>
                        <div class="config-item">
                            <div class="config-key">Site Name</div>
                            <div class="config-value">
                                <input type="text" name="config[site_name]" value="<?= htmlspecialchars($results['site_name'] ?? 'AMU SRMS') ?>" class="form-control">
                            </div>
                        </div>

                        <div class="config-item">
                            <div class="config-key">Default Language</div>
                            <div class="config-value">
                                <select name="config[default_language]" class="form-control">
                                    <option value="en" <?= ($results['default_language'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option>
                                    <option value="am" <?= ($results['default_language'] ?? '') === 'am' ? 'selected' : '' ?>>Amharic</option>
                                </select>
                            </div>
                        </div>

                        <div class="config-item">
                            <div class="config-key">Timezone</div>
                            <div class="config-value">
                                <select name="config[timezone]" class="form-control">
                                    <option value="Africa/Addis_Ababa" <?= ($results['timezone'] ?? 'Africa/Addis_Ababa') === 'Africa/Addis_Ababa' ? 'selected' : '' ?>>Africa/Addis_Ababa</option>
                                    <option value="UTC" <?= ($results['timezone'] ?? '') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                                </select>
                            </div>
                        </div>

                        <div class="config-item">
                            <div class="config-key">Date and Time Format</div>
                            <div class="config-value">
                                <select name="config[date_time_format]" class="form-control">
                                    <option value="Y-m-d H:i:s" <?= ($results['date_time_format'] ?? 'Y-m-d H:i:s') === 'Y-m-d H:i:s' ? 'selected' : '' ?>>YYYY-MM-DD HH:MM:SS</option>
                                    <option value="d-m-Y H:i:s" <?= ($results['date_time_format'] ?? '') === 'd-m-Y H:i:s' ? 'selected' : '' ?>>DD-MM-YYYY HH:MM:SS</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group" style="text-align: center; margin-top: 2rem;">
                            <button type="submit" class="btn">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </div>
                    </form>
                
                <?php elseif ($section === 'manage_admins'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-users-cog"></i> Manage Administrators</h2>
                        <a href="?section=create_admin" class="btn">
                            <i class="fas fa-plus"></i> Add Admin
                        </a>
                    </div>
                    
                    <?php if (!empty($results)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Name</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Created By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $user): ?>
                                    <tr>
                                        <td>#<?= $user['user_id'] ?></td>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                        <td><?= htmlspecialchars($user['full_name']) ?></td>
                                        <td>
                                            <span class="badge badge-<?= strtolower($user['role']) ?>">
                                                <?= ucfirst($user['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $user['is_active'] ? 
                                                '<span class="badge badge-success">Active</span>' : 
                                                '<span class="badge badge-danger">Inactive</span>' ?>
                                            <?= $user['is_locked'] ? 
                                                '<span class="badge badge-warning">Locked</span>' : '' ?>
                                        </td>
                                        <td><?= htmlspecialchars($user['created_by_name'] ?? 'System') ?></td>
                                        <td>
                                            <div class="btn-group">
                                                <a href="edit_user.php?id=<?= $user['user_id'] ?>" class="btn btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                                    <form method="POST" action="deactivate_user.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                                                        <button type="submit" class="btn btn-<?= $user['is_active'] ? 'danger' : 'success' ?> btn-sm">
                                                            <i class="fas fa-<?= $user['is_active'] ? 'times' : 'check' ?>"></i>
                                                            <?= $user['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                        </button>
                                                    </form>
                                                    <?php if ($user['is_locked']): ?>
                                                        <a href="toggle_user.php?id=<?= $user['user_id'] ?>&action=unlock" 
                                                           class="btn btn-warning btn-sm">
                                                            <i class="fas fa-unlock"></i> Unlock
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-slash"></i>
                            <p>No administrators found</p>
                            <a href="?section=create_admin" class="btn">
                                <i class="fas fa-plus"></i> Add First Admin
                            </a>
                        </div>
                    <?php endif; ?>
                
                <?php elseif ($section === 'create_admin'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-user-plus"></i> Create New Administrator</h2>
                        <a href="?section=manage_admins" class="btn">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                    
                    <form method="POST" action="?section=create_admin" class="fade-in">
                        <?= csrf_field() ?>
                        <div class="form-group">
                            <label for="username"><i class="fas fa-user"></i> Username</label>
                            <input type="text" id="username" name="username" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="full_name"><i class="fas fa-signature"></i> Full Name</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email"><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="email" name="email" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="role"><i class="fas fa-user-tag"></i> Role</label>
                            <select id="role" name="role" class="form-control" required>
                                <option value="admin">Administrator</option>
                                <option value="staff">Staff</option>
                                <option value="technician">Technician</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn">
                            <i class="fas fa-user-plus"></i> Create User
                        </button>
                    </form>
                
                <?php elseif ($section === 'audit_logs'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-clipboard-list"></i> Audit Logs</h2>
                        <button class="btn" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($audit_logs)): ?>
                        <div style="max-height: 600px; overflow-y: auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Action</th>
                                        <th>Entity</th>
                                        <th>Details</th>
                                        <th>IP Address</th>
                                        <th>Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($audit_logs as $log): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($log['full_name']); ?></strong>
                                                <div style="font-size: 0.9em; color: var(--gray);">
                                                    @<?php echo htmlspecialchars($log['username']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['action']); ?></td>
                                            <td>
                                                <?php if ($log['entity_type']): ?>
                                                    <?php echo htmlspecialchars($log['entity_type']); ?> #<?php echo $log['entity_id']; ?>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['details']); ?></td>
                                            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                            <td><?php echo date('M j, Y H:i:s', strtotime($log['timestamp'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard"></i>
                            <p>No audit logs found</p>
                        </div>
                    <?php endif; ?>
                
                <?php elseif ($section === 'user_activity'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-chart-line"></i> User Activity</h2>
                        <button class="btn" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                    
                    <?php if (!empty($results)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Last Login</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $user): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                                            <div style="font-size: 0.9em; color: var(--gray);">
                                                @<?= htmlspecialchars($user['username']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= strtolower($user['role']) ?>">
                                                <?= ucfirst($user['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?= $user['last_login'] ? 
                                                date('M j, H:i', strtotime($user['last_login'])) : 
                                                'Never logged in' ?>
                                        </td>
                                        <td>
                                            <?= $user['is_active'] ? 
                                                '<span class="badge badge-success">Active</span>' : 
                                                '<span class="badge badge-danger">Inactive</span>' ?>
                                            <?= $user['is_locked'] ? 
                                                '<span class="badge badge-warning">Locked</span>' : '' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-clock"></i>
                            <p>No user activity data available</p>
                        </div>
                    <?php endif; ?>
                
                <?php elseif ($section === 'backup_management'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-database"></i> Database Backup</h2>
                    </div>
                    
                    <div class="fade-in">
                        <div class="form-group">
                            <label><i class="fas fa-table"></i> Database Tables</label>
                            <div style="background: white; border-radius: 5px; padding: 1rem;">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Table Name</th>
                                            <th>Rows</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results as $table): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($table['table_name']) ?></td>
                                                <td><?= number_format($table['table_rows']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="form-group" style="text-align: center;">
                            <a href="backup_db.php" class="btn" onclick="return confirm('Generate database backup?')">
                                <i class="fas fa-file-export"></i> Export Full Backup
                            </a>
                            <button class="btn btn-info" onclick="alert('Feature coming soon')">
                                <i class="fas fa-cloud-upload-alt"></i> Cloud Backup
                            </button>
                        </div>
                    </div>
                
                <?php elseif ($section === 'profile' && $profile): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-user-shield"></i> Super Admin Profile</h2>
                    </div>
                    
                    <div class="profile-info fade-in">
                        <p><strong><i class="fas fa-id-card"></i> User ID:</strong> <?= $_SESSION['user_id'] ?></p>
                        <p><strong><i class="fas fa-user-shield"></i> Username:</strong> <?= htmlspecialchars($profile['username']) ?></p>
                        <p><strong><i class="fas fa-user-tag"></i> Role:</strong> 
                            <span class="badge badge-primary">
                                Super Admin
                            </span>
                        </p>
                        <p><strong><i class="fas fa-envelope"></i> Email:</strong> <?= htmlspecialchars($profile['email'] ?? 'N/A') ?></p>
                        <p><strong><i class="fas fa-phone"></i> Phone:</strong> <?= htmlspecialchars($profile['phone'] ?? 'N/A') ?></p>
                        <p><strong><i class="fas fa-calendar-alt"></i> Account Created:</strong> 
                            <?= date('M j, Y', strtotime($profile['created_at'])) ?>
                        </p>
                        <p><strong><i class="fas fa-clock"></i> Last Login:</strong> 
                            <?= $profile['last_login'] ? 
                                date('M j, H:i', strtotime($profile['last_login'])) : 
                                'Never logged in' ?>
                        </p>
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
                
                <?php elseif ($section === 'manage_users'): ?>
                    <div class="card-header">
                        <h2><i class="fas fa-users"></i> Manage Users</h2>
                        <a href="add_user.php" class="btn">
                            <i class="fas fa-user-plus"></i> Add New User
                        </a>
                    </div>

                    <!-- Add search and filter form -->
                    <form method="GET" style="margin-bottom: 20px;">
                        <input type="hidden" name="section" value="manage_users">
                        <input type="text" name="search" placeholder="Search by name or username" value="<?php echo htmlspecialchars($search_query); ?>">
                        <select name="role">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="staff" <?php echo $role_filter === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="technician" <?php echo $role_filter === 'technician' ? 'selected' : ''; ?>>Technician</option>
                        </select>
                        <select name="status">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit">Filter</button>
                    </form>

                    <!-- Add bulk actions form -->
                    <form id="bulk-user-actions" method="POST">
                        <?= csrf_field() ?>
                        <select name="bulk_action" required>
                            <option value="">Bulk Actions</option>
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                            <option value="delete">Delete</option>
                        </select>
                        <button type="submit">Apply</button>
                    </form>

                    <table>
                            <tr>
                                <th><input type="checkbox" id="select_all"></th>
                                <th>User ID</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Full Name</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><input type="checkbox" form="bulk-user-actions" name="selected_users[]" value="<?php echo $row['user_id']; ?>"></td>
                                    <td><?php echo $row['user_id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(ucfirst($row['role']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?></td>
                                    <td>
                                        <a href="edit_user.php?user_id=<?php echo (int) $row['user_id']; ?>">Edit</a> |
                                        <form method="POST" action="delete_user.php" style="display:inline" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                                            <button type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                    </table>

                    <script>
                        // Select/Deselect all checkboxes
                        document.getElementById('select_all').addEventListener('change', function() {
                            const checkboxes = document.querySelectorAll('input[name="selected_users[]"]');
                            checkboxes.forEach(checkbox => checkbox.checked = this.checked);
                        });
                    </script>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="footer">
            <p>Copyright © 2012 - <?= date('Y') ?> Arba Minch University | Service Request Management System</p>
            <p>Version 2.1.0 | <?= date('l, F j, Y H:i:s') ?></p>
        </div>
    </div>

    <script>
        // [Same JavaScript as admin_dashboard.php]
    </script>
</body>
</html>
<?php
$conn->close();
?>