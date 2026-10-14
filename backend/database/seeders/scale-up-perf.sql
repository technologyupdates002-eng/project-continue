-- KHWWC Performance Load Test - Scale Up Script
-- This script duplicates existing PERF* members to create larger datasets
-- Run from: /home/laban/Documents/kirinyaga App/khwwc
-- Usage: /opt/lampp/bin/mysql -u root khwwc_dev < database/seeders/scale-up-perf.sql

SET @target_count = 5000;
SET @batch_size = 1000;

-- Get current PERF member count
SELECT COUNT(*) INTO @current_count FROM members WHERE member_id LIKE 'PERF%';

SET @needed = @target_count - @current_count;

WHILE @needed > 0 DO
    SET @insert_count = LEAST(@batch_size, @needed);
    
    INSERT INTO auth_users (id, email, phone, encrypted_password, is_banned, raw_user_meta_data, created_at, updated_at)
    SELECT 
        UUID(),
        CONCAT(REPLACE(REPLACE(phone, '+', ''), '2547', '2547'), '_copy_', @current_count + RAND() * 1000, '@perf.local'),
        CONCAT(phone, '_c', FLOOR(RAND() * 10000)),
        encrypted_password,
        is_banned,
        raw_user_meta_data,
        NOW(),
        NOW()
    FROM members
    WHERE member_id LIKE 'PERF%'
    ORDER BY RAND()
    LIMIT @insert_count;
    
    INSERT INTO user_roles (id, user_id, role, is_active)
    SELECT UUID(), id, 'member', 1 FROM auth_users WHERE email LIKE '%_copy_%@perf.local' AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND);
    
    INSERT INTO members (id, user_id, name, phone, member_id, is_active, status, total_contributions, total_penalties, created_at, updated_at)
    SELECT 
        UUID(),
        id,
        CONCAT(name, ' Copy'),
        CONCAT(phone, '_c', FLOOR(RAND() * 10000)),
        CONCAT('PERF', LPAD(@current_count + ROW_NUMBER() OVER(), 6, '0')),
        is_active,
        status,
        0, 0, NOW(), NOW()
    FROM auth_users
    WHERE email LIKE '%_copy_%@perf.local' AND created_at > DATE_SUB(NOW(), INTERVAL 1 SECOND);
    
    SET @current_count = @current_count + @insert_count;
    SET @needed = @target_count - @current_count;
END WHILE;

SELECT CONCAT('Scaled to ', @current_count, ' members') AS result;
