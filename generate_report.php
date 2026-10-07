<?php
require_once __DIR__ . '/includes/session.php';
include 'includes/access_control.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch filtered requests
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'all';
$query = "SELECT * FROM service_requests WHERE 1=1";

if ($report_type === 'pending') {
    $query .= " AND status = 'Pending'";
} elseif ($report_type === 'completed') {
    $query .= " AND status = 'Completed'";
}

$result = $conn->query($query);

// Logging the report generation action
$start_date = ''; // Initialize variables
$end_date = '';
$category = '';
$status = '';

log_action($conn, $_SESSION['user_id'], 'GENERATE_REPORT', 'SERVICE_REQUEST', null, 'Generated a report with filters: start_date=' . $start_date . ', end_date=' . $end_date . ', category=' . $category . ', status=' . $status . '.');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Reports</title>
    <!-- Link to external CSS -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <h2>Generate Reports</h2>
        <form method="GET" action="generate_report.php">
            <label for="report_type">Report Type:</label>
            <select id="report_type" name="report_type">
                <option value="all">All Requests</option>
                <option value="pending">Pending Requests</option>
                <option value="completed">Completed Requests</option>
            </select>
            <button type="submit">Generate Report</button>
        </form>

        <?php if ($result->num_rows > 0): ?>
            <table>
                <tr>
                    <th>Request ID</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Date Submitted</th>
                </tr>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $row['request_id']; ?></td>
                        <td><?php echo $row['category']; ?></td>
                        <td><?php echo $row['status']; ?></td>
                        <td><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        <?php else: ?>
            <p>No data found for the selected report type.</p>
        <?php endif; ?>

        <a href="admin_dashboard.php">Back to Dashboard</a>
    </div>
</body>
</html>

<?php
$conn->close();
?>