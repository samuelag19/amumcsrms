CREATE TABLE users (
    user_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('staff', 'admin', 'superadmin', 'technician') NOT NULL,
    full_name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(100) DEFAULT NULL,
    department VARCHAR(100) DEFAULT NULL,
    phone_number VARCHAR(15) DEFAULT NULL,
    phone VARCHAR(15) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_locked TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT DEFAULT NULL,
    KEY idx_users_created_by (created_by),
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE login_attempts (
    attempt_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_lookup (username, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE service_categories (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE service_requests (
    request_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('Pending', 'In Progress', 'Completed', 'Rejected', 'Awaiting User Confirmation') NOT NULL DEFAULT 'Pending',
    assigned_to INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    urgency VARCHAR(255) DEFAULT NULL,
    preferred_date DATE DEFAULT NULL,
    preferred_time TIME DEFAULT NULL,
    location VARCHAR(255) DEFAULT NULL,
    contact_info VARCHAR(255) DEFAULT NULL,
    viewed_by_tech TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME DEFAULT NULL,
    KEY idx_service_requests_user (user_id),
    KEY idx_service_requests_assigned_to (assigned_to),
    CONSTRAINT fk_service_requests_user FOREIGN KEY (user_id) REFERENCES users (user_id),
    CONSTRAINT fk_service_requests_assigned_to FOREIGN KEY (assigned_to) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE feedback (
    feedback_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    rating INT DEFAULT NULL,
    comments TEXT DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_feedback_request (request_id),
    CONSTRAINT chk_feedback_rating CHECK (rating IS NULL OR (rating >= 1 AND rating <= 5)),
    CONSTRAINT fk_feedback_request FOREIGN KEY (request_id) REFERENCES service_requests (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE complaint (
    complaint_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    user_id INT NOT NULL,
    description TEXT NOT NULL,
    status ENUM('Pending', 'Resolved') NOT NULL DEFAULT 'Pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_complaint_request (request_id),
    KEY idx_complaint_user (user_id),
    CONSTRAINT fk_complaint_request FOREIGN KEY (request_id) REFERENCES service_requests (request_id) ON DELETE CASCADE,
    CONSTRAINT fk_complaint_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE notifications (
    notification_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_notifications_user (user_id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE password_reset_requests (
    request_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_password_reset_user (user_id),
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE reports (
    report_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    report_type ENUM('Daily', 'Weekly', 'Monthly') NOT NULL,
    report_data TEXT NOT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reports_admin (admin_id),
    CONSTRAINT fk_reports_admin FOREIGN KEY (admin_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE system_config (
    config_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE,
    config_value TEXT DEFAULT NULL,
    description TEXT DEFAULT NULL,
    is_editable TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modified_by INT DEFAULT NULL,
    last_modified TIMESTAMP NULL DEFAULT NULL,
    KEY idx_system_config_modified_by (modified_by),
    CONSTRAINT fk_system_config_modified_by FOREIGN KEY (modified_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE audit_logs (
    log_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    details TEXT DEFAULT NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    KEY idx_audit_logs_user (user_id),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO service_categories (category_name) VALUES
    ('Electrical'),
    ('Plumbing'),
    ('Carpentry'),
    ('Metal Work'),
    ('Masonry'),
    ('Other');
