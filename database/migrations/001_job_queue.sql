CREATE TABLE jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_type VARCHAR(50) NOT NULL,
    status ENUM('queued','running','complete','failed','cancelled') NOT NULL DEFAULT 'queued',
    payload_json JSON NOT NULL,
    result_json JSON NULL,
    progress_current BIGINT UNSIGNED NOT NULL DEFAULT 0,
    progress_total BIGINT UNSIGNED NULL,
    progress_message VARCHAR(500) NULL,
    error_message TEXT NULL,
    locked_at DATETIME NULL,
    locked_by VARCHAR(120) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_jobs_status_created_at (status, created_at),
    KEY idx_jobs_job_type (job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
