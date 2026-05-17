CREATE TABLE scan_entry_errors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    drive_id INT UNSIGNED NOT NULL,
    scan_run_id BIGINT UNSIGNED NOT NULL,
    path VARCHAR(1500) NOT NULL,
    error_stage VARCHAR(80) NOT NULL,
    error_message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_scan_entry_errors_drive FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE,
    CONSTRAINT fk_scan_entry_errors_scan_run FOREIGN KEY (scan_run_id) REFERENCES scan_runs(id) ON DELETE CASCADE,
    KEY idx_scan_entry_errors_drive_id (drive_id),
    KEY idx_scan_entry_errors_scan_run_id (scan_run_id),
    KEY idx_scan_entry_errors_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
