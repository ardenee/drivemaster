CREATE TABLE category_drive_targets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    drive_id INT UNSIGNED NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_category_drive_targets_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_drive_targets_drive FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE,
    UNIQUE KEY uq_category_drive_targets_category_drive (category_id, drive_id),
    KEY idx_category_drive_targets_drive_id (drive_id),
    KEY idx_category_drive_targets_enabled_priority (is_enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
