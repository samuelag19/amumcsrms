<?php
require_once __DIR__ . '/includes/session.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/includes/access_control.php';
require_once __DIR__ . '/includes/db.php';
$conn = db_connect();

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_feedback'])) {
    require_valid_csrf_token();
    if ($_SESSION['role'] !== 'staff') {
        http_response_code(403);
        exit('Only staff can submit feedback.');
    }
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
    $comments = trim((string) ($_POST['comments'] ?? ''));
    if (!$request_id || !$rating || $rating < 1 || $rating > 5 || $comments === '') {
        http_response_code(400);
        exit('Invalid feedback.');
    }

    $ownership = $conn->prepare("SELECT request_id FROM service_requests WHERE request_id = ? AND user_id = ? AND status = 'Completed'");
    $ownership->bind_param("ii", $request_id, $_SESSION['user_id']);
    $ownership->execute();
    $ownsCompletedRequest = $ownership->get_result()->num_rows === 1;
    $ownership->close();
    if (!$ownsCompletedRequest) {
        http_response_code(403);
        exit('Feedback can only be submitted for your completed requests.');
    }

    $stmt = $conn->prepare("INSERT INTO feedback (request_id, rating, comments, submitted_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iis", $request_id, $rating, $comments);

    if ($stmt->execute()) {
        $message = "Feedback submitted successfully!";
        // Log the action after providing feedback
        $details = "Provided feedback for request ID: $request_id";
        log_action($conn, $_SESSION['user_id'], 'PROVIDE_FEEDBACK', 'FEEDBACK', $request_id, $details);
    } else {
        $message = "Error submitting feedback: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch completed requests for the user
$user_id = $_SESSION['user_id'];
$query = "SELECT request_id, category FROM service_requests WHERE user_id = ? AND status = 'Completed'";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$completed_requests = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provide Feedback</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@300;400;500;700&display=swap">
    <style>
        body {
            font-family: 'Ubuntu', sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
        }

        h2 {
            text-align: center;
            color: #2a2185;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        .form-group select,
        .form-group textarea,
        .form-group input {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
        }

        .form-group textarea {
            resize: none;
            height: 100px;
        }

        .btn {
            background: #2a2185;
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            display: block;
            width: 100%;
            text-align: center;
        }

        .btn:hover {
            background: #1a1666;
        }

        .message {
            text-align: center;
            margin-bottom: 1rem;
            font-weight: bold;
        }

        .message.success {
            color: #28a745;
        }

        .message.error {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Provide Feedback</h2>
        <?php if ($message): ?>
            <p class="message <?= strpos($message, 'success') !== false ? 'success' : 'error' ?>">
                <?= htmlspecialchars($message) ?>
            </p>
        <?php endif; ?>
        <form method="POST" action="provide_feedback.php">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="request_id">Select Completed Request</label>
                <select id="request_id" name="request_id" required>
                    <option value="">-- Select Request --</option>
                    <?php foreach ($completed_requests as $request): ?>
                        <option value="<?= $request['request_id'] ?>">
                            <?= htmlspecialchars($request['category']) ?> (ID: <?= $request['request_id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="rating">Rating (1-5)</label>
                <input type="number" id="rating" name="rating" min="1" max="5" required>
            </div>
            <div class="form-group">
                <label for="comments">Comments</label>
                <textarea id="comments" name="comments" placeholder="Write your feedback here..." required></textarea>
            </div>
            <button type="submit" name="submit_feedback" class="btn">Submit Feedback</button>
        </form>
    </div>
</body>
</html>
<?php $conn->close(); ?>