CREATE TABLE category_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    drive_id INT UNSIGNED NULL,
    rule_type ENUM('extension','folder_path','path_contains','name_contains') NOT NULL,
    pattern VARCHAR(1500) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_category_rules_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_rules_drive FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE,
    KEY idx_category_rules_category_id (category_id),
    KEY idx_category_rules_drive_id (drive_id),
    KEY idx_category_rules_type_enabled (rule_type, is_enabled),
    KEY idx_category_rules_pattern (pattern(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE category_manual_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    file_entry_id BIGINT UNSIGNED NULL,
    drive_id INT UNSIGNED NULL,
    path_prefix VARCHAR(1500) NULL,
    assignment_type ENUM('file','folder') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_category_manual_assignments_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_manual_assignments_file_entry FOREIGN KEY (file_entry_id) REFERENCES file_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_manual_assignments_drive FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE,
    KEY idx_category_manual_assignments_category_id (category_id),
    KEY idx_category_manual_assignments_file_entry_id (file_entry_id),
    KEY idx_category_manual_assignments_drive_path (drive_id, path_prefix(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO category_manual_assignments (category_id, file_entry_id, drive_id, path_prefix, assignment_type, created_at)
SELECT category_id, file_entry_id, drive_id, path_prefix, CASE WHEN assignment_type = 'file' THEN 'file' ELSE 'folder' END, created_at
FROM category_assignments
WHERE assignment_type IN ('file','folder_rule');

INSERT INTO category_rules (category_id, drive_id, rule_type, pattern, is_enabled, notes, created_at)
SELECT category_id, NULL, 'extension', extension, 1, 'Migrated from category_assignments.extension_rule', created_at
FROM category_assignments
WHERE assignment_type = 'extension_rule' AND extension IS NOT NULL AND extension <> '';
