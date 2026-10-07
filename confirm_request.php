<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    http_response_code(403);
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

require_valid_csrf_token();
$request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
if (!$request_id) {
    http_response_code(400);
    exit('Invalid request ID.');
}

$conn = db_connect();

$query = "UPDATE service_requests SET status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND user_id = ? AND status = 'Awaiting User Confirmation'";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $request_id, $_SESSION['user_id']);
$stmt->execute();
$updated = $stmt->affected_rows > 0;
$stmt->close();
$conn->close();
if ($updated) {
    header("Location: user_dashboard.php?section=track_requests&message=Request confirmed as completed.");
} else {
    header("Location: user_dashboard.php?section=track_requests&error=The request could not be confirmed.");
}
exit;