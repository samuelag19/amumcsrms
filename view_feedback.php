<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$query = "SELECT sr.request_id, sr.category, sr.description, f.rating, f.comments, f.submitted_at, 
                 COALESCE(u.full_name, 'N/A') AS requester_name 
          FROM service_requests sr 
          LEFT JOIN feedback f ON sr.request_id = f.request_id
          LEFT JOIN users u ON sr.user_id = u.user_id
          WHERE f.request_id IS NOT NULL
          ORDER BY f.submitted_at DESC";
$result = $conn->query($query);

// Add error handling for the query execution
if (!$result) {
    die("Error executing query: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Feedback</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <style>
        body {
            font-family: 'Ubuntu', sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 900px;
            margin: 20px auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        h2 {
            text-align: center;
            color: #2a2185;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #2a2185;
            color: white;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        .no-feedback {
            text-align: center;
            color: #999;
            font-size: 1.2rem;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Feedback Overview</h2>
        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Rating</th>
                        <th>Comments</th>
                        <th>Submitted At</th>
                        <th>Requester</th> <!-- New column -->
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $row['request_id']; ?></td>
                            <td><?php echo htmlspecialchars($row['category']); ?></td>
                            <td><?php echo htmlspecialchars(substr($row['description'], 0, 50)) . (strlen($row['description']) > 50 ? '...' : ''); ?></td>
                            <td><?php echo $row['rating']; ?></td>
                            <td><?php echo htmlspecialchars($row['comments']); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($row['submitted_at'])); ?></td>
                            <td><?php echo htmlspecialchars($row['requester_name']); ?></td> <!-- Display requester name -->
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="no-feedback">No feedback available at the moment.</p>
        <?php endif; ?>
        <a href="Admin_dashboard.php" style="display: block; text-align: center; color: #2a2185; text-decoration: none; font-weight: bold;">Back to Dashboard</a>
    </div>
</body>
</html>
<?php $conn->close(); ?>