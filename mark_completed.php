<?php
require_once __DIR__ . '/includes/session.php';
require_once 'includes/access_control.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'technician') {
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
$query = "UPDATE service_requests SET status = 'Awaiting User Confirmation', updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND assigned_to = ? AND status = 'In Progress'";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $request_id, $_SESSION['user_id']);
$stmt->execute();
if ($stmt->affected_rows > 0) {
    log_action($conn, $_SESSION['user_id'], 'UPDATE', 'REQUEST', $request_id, 'Marked request ID ' . $request_id . ' as completed.');
}
$stmt->close();
$conn->close();
header("Location: technician_dashboard.php?section=assigned_requests");
exit;