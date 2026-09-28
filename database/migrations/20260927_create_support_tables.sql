CREATE TABLE IF NOT EXISTS support_ticket (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_code VARCHAR(30) NOT NULL,
    caterer_id BIGINT UNSIGNED NULL,
    subject VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    requester_name VARCHAR(150) NOT NULL,
    requester_email VARCHAR(150) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
    priority VARCHAR(20) NOT NULL DEFAULT 'MEDIUM',
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    assigned_to VARCHAR(150) NOT NULL DEFAULT 'Unassigned',
    created_by_admin_id BIGINT UNSIGNED NULL,
    created_by_admin_name VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_support_ticket_code (ticket_code),
    KEY idx_support_ticket_caterer (caterer_id),
    KEY idx_support_ticket_status (status),
    KEY idx_support_ticket_priority (priority),
    KEY idx_support_ticket_updated_at (updated_at),
    CONSTRAINT fk_support_ticket_caterer
        FOREIGN KEY (caterer_id) REFERENCES catering_info(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS support_ticket_message (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ticket_id BIGINT UNSIGNED NOT NULL,
    sender_type VARCHAR(20) NOT NULL DEFAULT 'ADMIN',
    sender_name VARCHAR(150) NOT NULL,
    sender_email VARCHAR(150) NULL,
    message TEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_support_ticket_message_ticket (ticket_id),
    KEY idx_support_ticket_message_created_at (created_at),
    CONSTRAINT fk_support_ticket_message_ticket
        FOREIGN KEY (ticket_id) REFERENCES support_ticket(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_support_ticket_status_priority
    ON support_ticket (status, priority);
