ALTER TABLE drives ADD COLUMN form_factor VARCHAR(10) NOT NULL DEFAULT '3.5' AFTER friendly_name;
