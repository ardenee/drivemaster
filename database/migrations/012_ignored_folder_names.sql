CREATE TABLE ignored_folder_names (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_name VARCHAR(255) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ignored_folder_names_name (folder_name),
    KEY idx_ignored_folder_names_enabled (is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ignored_folder_names (folder_name, is_enabled) VALUES
('@eaDir', 1),
('@tmp', 1),
('$RECYCLE.BIN', 1),
('System Volume Information', 1)
ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled);
