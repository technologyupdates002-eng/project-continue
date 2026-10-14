-- Fast Performance Data Seeder for MySQL/MariaDB
-- Creates synthetic PERF* members and related data
-- Usage: /opt/lampp/bin/mysql -u root khwwc_dev < database/seeders/fast-perf-seed.sql

SET @target_count = 10000;
SET @now = NOW();

-- Clean up existing PERF data
DELETE FROM contributions WHERE member_id IN (SELECT id FROM members WHERE member_id LIKE 'PERF%');
DELETE FROM notifications WHERE message = 'Synthetic performance test data.';
DELETE FROM events WHERE title LIKE 'Perf Event %';
DELETE FROM news WHERE title LIKE 'Perf News %';
DELETE FROM user_roles WHERE user_id IN (SELECT user_id FROM members WHERE member_id LIKE 'PERF%');
DELETE FROM auth_users WHERE email LIKE '%@perf.local';
DELETE FROM members WHERE member_id LIKE 'PERF%';

-- Create members in batches using a numbers table approach
SET @batch = 1000;
SET @created = 0;

WHILE @created < @target_count DO
    SET @limit = LEAST(@batch, @target_count - @created);
    
    INSERT INTO auth_users (id, email, phone, encrypted_password, is_banned, raw_user_meta_data, created_at, updated_at)
    SELECT 
        UUID(),
        CONCAT('perf', @created + ROW_NUMBER() OVER(), '@perf.local'),
        CONCAT('+2547', LPAD(FLOOR(RAND() * 10000000), 7, '0')),
        '$2y$10$abcdefghijklmnopqrstuvwxyz1234567890ABCDEFG',
        0,
        JSON_OBJECT('name', CONCAT('Perf User ', @created + ROW_NUMBER() OVER())),
        @now,
        @now
    FROM (
        SELECT 1 FROM information_schema.columns 
        WHERE table_schema = DATABASE() AND table_name = 'members'
        LIMIT @limit
    ) AS t;
    
    SET @created = @created + @limit;
END WHILE;

SELECT CONCAT('Created ', @created, ' auth users') AS status;
