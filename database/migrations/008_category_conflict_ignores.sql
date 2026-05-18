CREATE TABLE category_conflict_ignores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_entry_id BIGINT UNSIGNED NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_category_conflict_ignores_file_entry FOREIGN KEY (file_entry_id) REFERENCES file_entries(id) ON DELETE CASCADE,
    UNIQUE KEY uq_category_conflict_ignores_file_entry_id (file_entry_id),
    KEY idx_category_conflict_ignores_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
