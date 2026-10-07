<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/access_control.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_valid_csrf_token();
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $technician_id = filter_input(INPUT_POST, 'technician_id', FILTER_VALIDATE_INT);
    if (!$request_id || !$technician_id) {
        http_response_code(400);
        exit('Invalid request or technician.');
    }

    $technician_check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'technician' AND is_active = 1");
    $technician_check->bind_param("i", $technician_id);
    $technician_check->execute();
    $valid_technician = $technician_check->get_result()->num_rows === 1;
    $technician_check->close();
    if (!$valid_technician) {
        http_response_code(400);
        exit('Select an active technician.');
    }

    $query = "UPDATE service_requests SET assigned_to = ?, status = 'In Progress', updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND assigned_to IS NULL AND status = 'Pending'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $technician_id, $request_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $message = "Request assigned successfully!";
        
        // Log the action after assigning a technician
        $details = "Assigned technician with ID: $technician_id to request ID: $request_id";
        log_action($conn, $_SESSION['user_id'], 'ASSIGN_TECHNICIAN', 'REQUEST', $request_id, $details);
        log_action($conn, $_SESSION['user_id'], 'ASSIGN_TASK', 'SERVICE_REQUEST', $request_id, "Assigned request #$request_id to technician #$technician_id.");
    } else {
        $message = "Error assigning request.";
    }

    $stmt->close();
}

// Fetch available technicians
$query_tech = "SELECT user_id, full_name FROM users WHERE role = 'technician'";
$result_tech = $conn->query($query_tech);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Technician</title>
    <!-- Link to external CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="form-container">
        <h2>Assign Technician</h2>
        <?php if (!empty($message)): ?>
            <p class="<?php echo strpos($message, "successfully") !== false ? "success" : "error"; ?>">
                <?php echo $message; ?>
            </p>
        <?php endif; ?>
        <form method="POST" action="assign_technician.php">
            <?= csrf_field() ?>
            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) ($_GET['request_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <label for="technician_id">Select Technician:</label>
            <select id="technician_id" name="technician_id" required>
                <?php while ($row = $result_tech->fetch_assoc()): ?>
                    <option value="<?php echo $row['user_id']; ?>"><?php echo $row['full_name']; ?></option>
                <?php endwhile; ?>
            </select>

            <button type="submit">Assign Technician</button>
        </form>
        <a href="admin_dashboard.php">Back to Dashboard</a>
    </div>
</body>
</html>

<?php
$conn->close();
?>