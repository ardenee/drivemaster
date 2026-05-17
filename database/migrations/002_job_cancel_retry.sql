ALTER TABLE jobs
    ADD COLUMN cancel_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER result_json,
    ADD COLUMN last_heartbeat_at DATETIME NULL AFTER locked_by;

CREATE INDEX idx_jobs_last_heartbeat_at ON jobs (last_heartbeat_at);
