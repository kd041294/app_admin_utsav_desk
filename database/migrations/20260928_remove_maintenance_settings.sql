-- platform_settings is a key/value table: maintenance settings are rows, not columns.
DELETE FROM platform_settings
WHERE setting_key IN (
    'maintenance_enabled',
    'maintenance_title',
    'maintenance_message',
    'maintenance_start',
    'maintenance_end',
    'maintenance_starts_at',
    'maintenance_ends_at'
);
