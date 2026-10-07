<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';

// Check if user is logged in and has the appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'superadmin'])) {
    header("Location: login.php");
    exit;
}

// Determine the redirect URL based on the user's role
$redirect_url = ($_SESSION['role'] === 'superadmin') ? 'superadmin_dashboard.php' : 'admin_dashboard.php';

require_once __DIR__ . '/includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_valid_csrf_token();
$user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
if (!$user_id) {
    http_response_code(400);
    exit('Invalid user ID.');
}

$conn = db_connect();

// Prevent admin or superadmin from deleting their own account
if ($_SESSION['user_id'] == $user_id) {
    header("Location: $redirect_url?section=manage_users&error=" . urlencode("Cannot delete your own account"));
    exit;
}

$target_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND (? = 'superadmin' OR role != 'superadmin')");
$actor_role = $_SESSION['role'];
$target_stmt->bind_param("is", $user_id, $actor_role);
$target_stmt->execute();
$target_exists = $target_stmt->get_result()->num_rows === 1;
$target_stmt->close();
if (!$target_exists) {
    http_response_code(404);
    exit('User not found or not permitted.');
}

// Start a transaction to ensure both deletions succeed or fail together
$conn->begin_transaction();

try {
    // First, delete related service requests
    $query_requests = "DELETE FROM service_requests WHERE user_id = ?";
    $stmt_requests = $conn->prepare($query_requests);
    if ($stmt_requests === false) {
        throw new Exception("Prepare failed for service requests: " . $conn->error);
    }
    $stmt_requests->bind_param("i", $user_id);
    $stmt_requests->execute();
    $stmt_requests->close();

    // Then delete the user
    $query_user = "DELETE FROM users WHERE user_id = ?";
    $stmt_user = $conn->prepare($query_user);
    if ($stmt_user === false) {
        throw new Exception("Prepare failed for user: " . $conn->error);
    }
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $stmt_user->close();

    // Log the action after deleting the user
    $details = "Deleted user with ID: $user_id";
    log_action($conn, $_SESSION['user_id'], 'DELETE_USER', 'USER', $user_id, $details);

    // If both queries succeed, commit the transaction
    $conn->commit();
    header("Location: $redirect_url?section=manage_users&message=" . urlencode("User and related service requests deleted successfully"));

} catch (Exception $e) {
    // If any query fails, roll back the transaction
    $conn->rollback();
    error_log('Failed to delete user ' . $user_id . ': ' . $e->getMessage());
    header("Location: $redirect_url?section=manage_users&error=" . urlencode("The user could not be deleted because linked records still exist."));
}

$conn->close();
exit;
?>