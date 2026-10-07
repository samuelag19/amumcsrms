<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied. Admins and Super Admins only.");
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Include the shared access control file
include 'includes/access_control.php';

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$user_data = [];
$message = "";

// Fetch user data
if ($user_id > 0) {
    $stmt = $conn->prepare("SELECT user_id, username, role, full_name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_valid_csrf_token();
    $user_id = intval($_POST['user_id']);
    $username = trim($conn->real_escape_string($_POST['username']));
    $role = $conn->real_escape_string($_POST['role']);
    $full_name = trim($conn->real_escape_string($_POST['full_name']));

    // Validate inputs
    if (empty($username) || empty($full_name)) {
        $message = "All fields are required";
    } else {
        // Check if username already exists (excluding current user)
        $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $stmt_check->bind_param("si", $username, $user_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0) {
            $message = "Username already taken";
        } else {
            // Update user
            $stmt_update = $conn->prepare("UPDATE users SET username = ?, role = ?, full_name = ? WHERE user_id = ?");
            $stmt_update->bind_param("sssi", $username, $role, $full_name, $user_id);
            
            if ($stmt_update->execute()) {
                $message = "User updated successfully!";
                // Log the action
                $details = "Updated user: $username, Role: $role, Full Name: $full_name";
                log_action($conn, $_SESSION['user_id'], 'EDIT_USER', 'USER', $user_id, $details);
                // Log user update
                log_action($conn, $_SESSION['user_id'], 'UPDATE', 'USER', $user_id, 'Updated user details for user ID: ' . $user_id);
                // Refresh user data
                $user_data['username'] = $username;
                $user_data['role'] = $role;
                $user_data['full_name'] = $full_name;
            } else {
                $message = "Error updating user: " . $conn->error;
            }
            $stmt_update->close();
        }
        $stmt_check->close();
    }
}

// Add a Reset Password button
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    require_valid_csrf_token();
    $new_password = bin2hex(random_bytes(4)); // Generate a random 8-character password
    $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
    $stmt->bind_param("si", $hashed_password, $user_id);

    if ($stmt->execute()) {
        $message = "Password reset successfully! New password: $new_password";
    } else {
        $message = "Error resetting password: " . $stmt->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - AMU SRMS</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/edit_user.css">
</head>
<body>
    <div class="card fade-in">
        <div class="card-header">
            <img src="image/amulogo.png" alt="AMU Logo">
            <h2>Edit User</h2>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert <?= strpos($message, 'success') !== false ? 'alert-success' : 'alert-error' ?>">
                <i class="fas <?= strpos($message, 'success') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="edit_user.php">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= htmlspecialchars($user_data['user_id'] ?? '') ?>">
            
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-field">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" placeholder="Enter username" 
                           value="<?= htmlspecialchars($user_data['username'] ?? '') ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="role">Role</label>
                <div class="input-field">
                    <i class="fas fa-user-tag"></i>
                    <select id="role" name="role" required>
                        <option value="staff" <?= ($user_data['role'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff</option>
                        <option value="technician" <?= ($user_data['role'] ?? '') === 'technician' ? 'selected' : '' ?>>Technician</option>
                        <option value="admin" <?= ($user_data['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <div class="input-field">
                    <i class="fas fa-id-card"></i>
                    <input type="text" id="full_name" name="full_name" placeholder="Enter full name" 
                           value="<?= htmlspecialchars($user_data['full_name'] ?? '') ?>" required>
                </div>
            </div>
            
            <button type="submit" class="btn">
                <i class="fas fa-save"></i> Save Changes
            </button>
            
            <button type="submit" name="reset_password" class="btn btn-warning">
                <i class="fas fa-key"></i> Reset Password
            </button>
            
            <a href="<?php echo ($_SESSION['role'] === 'superadmin') ? 'superadmin_dashboard.php?section=manage_users' : 'admin_dashboard.php?section=manage_users'; ?>" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Manage Users
            </a>
        </form>
    </div>
</body>
</html>
<?php
$conn->close();
?>