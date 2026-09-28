ALTER TABLE catering_info
    ADD COLUMN is_user_assign TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;

UPDATE catering_info AS caterer
SET is_user_assign = 1
WHERE EXISTS (
    SELECT 1
    FROM user_info AS account
    WHERE account.catering_id = caterer.id
);
