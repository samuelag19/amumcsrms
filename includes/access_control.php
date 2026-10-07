<?php
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/session.php';

function redirect_to_login($message = null) {
    if ($message !== null) {
        $_SESSION['login_error'] = $message;
    }

    header("Location: login.php");
    exit;
}

function require_login($allowed_roles = null) {
    if (!isset($_SESSION['user_id'])) {
        redirect_to_login("Please log in to continue.");
    }

    require_once __DIR__ . '/db.php';
    $conn = db_connect();

    $stmt = $conn->prepare("SELECT role, is_active FROM users WHERE user_id = ?");
    if (!$stmt) {
        $conn->close();
        redirect_to_login("Unable to validate your session.");
    }

    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        session_unset();
        session_destroy();
        redirect_to_login("Your session has expired. Please log in again.");
    }

    $user = $result->fetch_assoc();
    $stmt->close();
    $conn->close();

    if ((int) $user['is_active'] !== 1) {
        session_unset();
        session_destroy();
        redirect_to_login("Your account has been deactivated. Please contact the administrator.");
    }

    $_SESSION['role'] = $user['role'];

    if ($allowed_roles !== null && !in_array($_SESSION['role'], $allowed_roles, true)) {
        header("Location: login.php");
        exit;
    }
}

if (basename($_SERVER['SCRIPT_NAME']) !== 'login.php') {
    require_login();
}

function log_action($conn, $user_id, $action, $entity_type, $entity_id, $details) {
    if (!$conn instanceof mysqli) {
        return;
    }

    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, timestamp) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        return;
    }

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt->bind_param("ississ", $user_id, $action, $entity_type, $entity_id, $details, $ip_address);
    $stmt->execute();
    $stmt->close();
}
?>