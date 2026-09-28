ALTER TABLE admin_registration_invites
    ADD COLUMN token_encrypted TEXT NULL,
    ADD COLUMN created_by BIGINT UNSIGNED NULL,
    ADD COLUMN revoked_at DATETIME NULL,
    ADD COLUMN revoked_by BIGINT UNSIGNED NULL,
    ADD COLUMN used_by BIGINT UNSIGNED NULL;
