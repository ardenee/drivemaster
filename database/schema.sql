CREATE TABLE drives (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    friendly_name VARCHAR(120) NOT NULL,
    serial_number VARCHAR(190) NOT NULL,
    volume_label VARCHAR(190) NULL,
    filesystem VARCHAR(50) NULL,
    mount_path VARCHAR(500) NULL,
    total_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    free_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    used_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NULL,
    last_scanned_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_drives_friendly_name (friendly_name),
    UNIQUE KEY uq_drives_serial_number (serial_number),
    KEY idx_drives_last_seen_at (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scan_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    drive_id INT UNSIGNED NOT NULL,
    root_path VARCHAR(500) NOT NULL,
    status ENUM('running','complete','failed') NOT NULL DEFAULT 'running',
    files_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
    folders_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_seen BIGINT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    CONSTRAINT fk_scan_runs_drive FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE,
    KEY idx_scan_runs_drive_id (drive_id),
    KEY idx_scan_runs_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE file_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    drive_id INT UNSIGNED NOT NULL,
    scan_run_id BIGINT UNSIGNED NOT NULL,
    parent_path VARCHAR(1000) NOT NULL,
    full_path VARCHAR(1500) NOT NULL,
    relative_path VARCHAR(1500) NOT NULL,
    name VARCHAR(500) NOT NULL,
    extension VARCHAR(50) NULL,
    entry_type ENUM('file','folder') NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    modified_at DATETIME NULL,
    created_at_fs DATETIME NULL,
    accessed_at DATETIME NULL,
    is_missing TINYINT(1) NOT NULL DEFAULT 0,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_file_entries_drive FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_entries_scan_run FOREIGN KEY (scan_run_id) REFERENCES scan_runs(id) ON DELETE CASCADE,
    UNIQUE KEY uq_file_entries_drive_relative_path (drive_id, relative_path(700)),
    KEY idx_file_entries_name (name),
    KEY idx_file_entries_extension (extension),
    KEY idx_file_entries_entry_type (entry_type),
    KEY idx_file_entries_size_bytes (size_bytes),
    KEY idx_file_entries_last_seen_at (last_seen_at),
    FULLTEXT KEY ft_file_entries_search (name, relative_path, full_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE category_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    file_entry_id BIGINT UNSIGNED NULL,
    drive_id INT UNSIGNED NULL,
    path_prefix VARCHAR(1500) NULL,
    assignment_type ENUM('file','folder_rule','extension_rule') NOT NULL,
    extension VARCHAR(50) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_category_assignments_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_assignments_file_entry FOREIGN KEY (file_entry_id) REFERENCES file_entries(id) ON DELETE CASCADE,
    CONSTRAINT fk_category_assignments_drive FOREIGN KEY (drive_id) REFERENCES drives(id) ON DELETE CASCADE,
    KEY idx_category_assignments_category_id (category_id),
    KEY idx_category_assignments_extension (extension)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
