UPDATE file_entries
SET parent_path = CASE
    WHEN LOCATE('/', relative_path) = 0 THEN ''
    ELSE SUBSTRING(relative_path, 1, LENGTH(relative_path) - LENGTH(SUBSTRING_INDEX(relative_path, '/', -1)) - 1)
END;

CREATE INDEX idx_file_entries_drive_parent_type_name
ON file_entries (drive_id, parent_path(700), entry_type, name);

CREATE INDEX idx_file_entries_drive_parent_modified
ON file_entries (drive_id, parent_path(700), modified_at);

CREATE INDEX idx_file_entries_drive_parent_size
ON file_entries (drive_id, parent_path(700), size_bytes);
