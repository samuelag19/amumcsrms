<?php
// Handle form submission if POST request
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_request'])) {
    require_valid_csrf_token();
    $category = $conn->real_escape_string($_POST['category']);
    $description = $conn->real_escape_string($_POST['description']);
    $urgency = $conn->real_escape_string($_POST['urgency']);
    $location = $conn->real_escape_string($_POST['location']);
    
    $stmt = $conn->prepare("INSERT INTO service_requests (user_id, category, description, urgency, location, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("issss", $_SESSION['user_id'], $category, $description, $urgency, $location);
    
    if ($stmt->execute()) {
        $request_id = $stmt->insert_id; // Get the ID of the newly inserted request
        log_action($conn, $_SESSION['user_id'], 'SUBMIT_REQUEST', 'SERVICE_REQUEST', $request_id, 'Submitted a new service request.');
        
        echo '<div class="alert alert-success fade-in">
                <i class="fas fa-check-circle"></i>
                Request submitted successfully!
              </div>';
        // Refresh categories
        $results = [$conn->query("SELECT category_name FROM service_categories WHERE is_active = 1")];
    } else {
        echo '<div class="alert alert-danger fade-in">
                <i class="fas fa-exclamation-circle"></i>
                Error submitting request: ' . $stmt->error . '
              </div>';
    }
    $stmt->close();
}
?>

<form method="POST" action="?section=submit_request" class="fade-in">
    <?= csrf_field() ?>
    <div class="form-group">
        <label for="category"><i class="fas fa-tag"></i> Service Category</label>
        <select id="category" name="category" class="form-control" required>
            <option value="">Select a category</option>
            <?php if ($results[0] && $results[0]->num_rows > 0): ?>
                <?php while ($cat = $results[0]->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($cat['category_name']) ?>">
                        <?= htmlspecialchars($cat['category_name']) ?>
                    </option>
                <?php endwhile; ?>
            <?php endif; ?>
        </select>
    </div>
    
    <div class="form-group">
        <label for="description"><i class="fas fa-align-left"></i> Description</label>
        <textarea id="description" name="description" class="form-control" rows="5" required 
                  placeholder="Please describe your service request in detail..."></textarea>
    </div>
    
    <div class="form-group">
        <label for="urgency"><i class="fas fa-exclamation-triangle"></i> Urgency Level</label>
        <select id="urgency" name="urgency" class="form-control" required>
            <option value="Low">Low (Can wait several days)</option>
            <option value="Medium" selected>Medium (Needs attention within 24-48 hours)</option>
            <option value="High">High (Requires immediate attention)</option>
        </select>
    </div>
    
    <div class="form-group">
        <label for="location"><i class="fas fa-map-marker-alt"></i> Location</label>
        <input type="text" id="location" name="location" class="form-control" required 
               placeholder="Building/Room number where service is needed">
    </div>
    
    <button type="submit" name="submit_request" class="btn">
        <i class="fas fa-paper-plane"></i> Submit Request
    </button>
</form>

<script>
    // Auto-expand textarea as user types
    const textarea = document.getElementById('description');
    if (textarea) {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        // Trigger initial resize
        textarea.dispatchEvent(new Event('input'));
    }
</script>