<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'superadmin') {
    header("HTTP/1.1 403 Forbidden");
    exit("Access denied. Admins and Superadmins only.");
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// Include the file where log_action is defined
include 'includes/access_control.php';
$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_valid_csrf_token();
    $username = trim($conn->real_escape_string($_POST['username']));
    $password = $_POST['password'];
    $role = $conn->real_escape_string($_POST['role']);
    $full_name = trim($conn->real_escape_string($_POST['full_name']));

    // Validate inputs
    if (empty($username) || empty($password) || empty($full_name)) {
        $message = "All fields are required";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters";
    } else {
        // Check if username exists
        $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt_check->bind_param("s", $username);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0) {
            $message = "Username already taken";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            
            // Insert user
            $stmt_insert = $conn->prepare("INSERT INTO users (username, password, role, full_name) VALUES (?, ?, ?, ?)");
            $stmt_insert->bind_param("ssss", $username, $hashed_password, $role, $full_name);
            
            if ($stmt_insert->execute()) {
                $message = "User added successfully!";
                // Log user creation
                log_action($conn, $_SESSION['user_id'], 'CREATE', 'USER', $stmt_insert->insert_id, 'Created a new user with username: ' . $username);
                log_action($conn, $_SESSION['user_id'], 'ADD_USER', 'USER', $stmt_insert->insert_id, 'Added a new user with username: $username.');
                // Clear form on success
                $_POST = array();
            } else {
                $message = "Error adding user: " . $conn->error;
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - AMU SRMS</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/add_user.css">
</head>
<body>
    <div class="container">
        <div class="card fade-in">
            <div class="card-header">
                <img src="image/amulogo.png" alt="AMU Logo">
                <h2>Add New User</h2>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="alert <?= strpos($message, 'success') !== false ? 'alert-success' : 'alert-error' ?>">
                    <i class="fas <?= strpos($message, 'success') !== false ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="add_user.php">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-field">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" placeholder="Enter username" 
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-field">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="Enter password" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="role">Role</label>
                    <div class="input-field">
                        <i class="fas fa-user-tag"></i>
                        <select id="role" name="role" required>
                            <option value="staff" <?= ($_POST['role'] ?? '') === 'staff' ? 'selected' : '' ?>>Staff</option>
                            <option value="technician" <?= ($_POST['role'] ?? '') === 'technician' ? 'selected' : '' ?>>Technician</option>
                            <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <div class="input-field">
                        <i class="fas fa-id-card"></i>
                        <input type="text" id="full_name" name="full_name" placeholder="Enter full name" 
                               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
                    </div>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-user-plus"></i> Add User
                </button>
                
                <?php
                $back_link = ($_SESSION['role'] === 'superadmin') ? 'superadmin_dashboard.php?section=manage_users' : 'admin_dashboard.php?section=manage_users';
                ?>
                <a href="<?= $back_link ?>" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Manage Users
                </a>
            </form>
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
?>