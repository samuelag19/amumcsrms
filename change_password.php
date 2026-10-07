<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

// Include the file where log_action is defined
include 'includes/access_control.php';

// Handle password change
$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    require_valid_csrf_token();
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($new_password !== $confirm_password) {
        $message = "New passwords don't match!";
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (password_verify($old_password, $result['password'])) {
            $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $update_stmt->bind_param("si", $new_hashed_password, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
                $message = "Password changed successfully!";
                log_action($conn, $_SESSION['user_id'], 'CHANGE_PASSWORD', 'USER', $_SESSION['user_id'], 'User changed their password.');
                if ($_SESSION['role'] === 'admin') {
                    header("Location: admin_dashboard.php?message=password_changed");
                } elseif ($_SESSION['role'] === 'staff') {
                    header("Location: user_dashboard.php?message=password_changed");
                } elseif ($_SESSION['role'] === 'technician') {
                    header("Location: technician_dashboard.php?message=password_changed");
                } elseif ($_SESSION['role'] === 'superadmin') {
                    header("Location: superadmin_dashboard.php?message=password_changed");
                }
                exit;
            } else {
                $message = "Error updating password!";
            }
            $update_stmt->close();
        } else {
            $message = "Incorrect old password!";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - AMU SRMS</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <style>
    /* Change Password Page Styles */
    .change-password-container {
        max-width: 500px;
        margin: 2rem auto;
        background: white;
        border-radius: 10px;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        padding: 2rem;
    }

    .change-password-container h2 {
        text-align: center;
        color: #2a2185;
        margin-bottom: 1.5rem;
    }

    .change-password-container form {
        display: flex;
        flex-direction: column;
    }

    .change-password-container label {
        font-weight: bold;
        color: #222;
        margin-bottom: 0.5rem;
    }

    .change-password-container input {
        padding: 0.8rem;
        border: 1px solid #ddd;
        border-radius: 5px;
        margin-bottom: 1.5rem;
        font-size: 1rem;
    }

    .change-password-container input:focus {
        border-color: #2a2185;
        outline: none;
    }

    .change-password-container .btn {
        background: #2a2185;
        color: white;
        padding: 0.8rem;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        transition: background 0.3s;
    }

    .change-password-container .btn:hover {
        background: #1a1666;
    }

    .change-password-container .back-link {
        display: block;
        text-align: center;
        margin-top: 1rem;
        color: #2a2185;
        text-decoration: none;
        font-weight: bold;
    }

    .change-password-container .back-link:hover {
        text-decoration: underline;
    }

    .change-password-container .success {
        color: #28a745;
        text-align: center;
        margin-bottom: 1rem;
    }

    .change-password-container .error {
        color: #dc3545;
        text-align: center;
        margin-bottom: 1rem;
    }
    </style>
</head>
<body>
    <div class="change-password-container">
        <h2>Change Password</h2>
        <?php if ($message): ?>
            <p class="<?=strpos($message, 'success') !== false ? 'success' : 'error'?>"><?=$message?></p>
        <?php endif; ?>
        <form method="POST" action="change_password.php">
            <?= csrf_field() ?>
            <label for="old_password">Old Password:</label>
            <input type="password" id="old_password" name="old_password" required>
            
            <label for="new_password">New Password:</label>
            <input type="password" id="new_password" name="new_password" required>
            
            <label for="confirm_password">Confirm New Password:</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
            
            <button type="submit" name="change_password" class="btn">Change Password</button>
        </form>
        <?php
        // Determine the appropriate dashboard based on the user's role
        $dashboard_url = "login.php"; // Default to login page
        if ($_SESSION['role'] === 'admin') {
            $dashboard_url = "admin_dashboard.php";
        } elseif ($_SESSION['role'] === 'staff') {
            $dashboard_url = "user_dashboard.php";
        } elseif ($_SESSION['role'] === 'technician') {
            $dashboard_url = "technician_dashboard.php";
        } elseif ($_SESSION['role'] === 'superadmin') {
            $dashboard_url = "superadmin_dashboard.php";
        }
        ?>
        <a href="<?= $dashboard_url ?>" class="back-link">Back to Dashboard</a>
    </div>
</body>
</html>
<?php $conn->close(); ?>