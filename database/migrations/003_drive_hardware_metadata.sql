ALTER TABLE drives
    ADD COLUMN hardware_serial VARCHAR(190) NULL AFTER serial_number,
    ADD COLUMN filesystem_uuid VARCHAR(190) NULL AFTER hardware_serial,
    ADD COLUMN model VARCHAR(190) NULL AFTER volume_label,
    ADD COLUMN vendor VARCHAR(190) NULL AFTER model,
    ADD COLUMN device_name VARCHAR(80) NULL AFTER mount_path,
    ADD COLUMN identity_source VARCHAR(80) NULL AFTER device_name;

CREATE INDEX idx_drives_hardware_serial ON drives (hardware_serial);
CREATE INDEX idx_drives_filesystem_uuid ON drives (filesystem_uuid);
