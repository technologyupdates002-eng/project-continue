-- KHCWW Welfare Management System - MariaDB schema
-- Generated from the live production schema. Import via phpMyAdmin.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Authentication (replaces legacy auth.users)
CREATE TABLE IF NOT EXISTS auth_users (
  id CHAR(36) NOT NULL,
  email VARCHAR(191) NOT NULL,
  phone VARCHAR(32) DEFAULT NULL,
  encrypted_password VARCHAR(255) NOT NULL,
  email_confirmed_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  last_sign_in_at DATETIME(3) DEFAULT NULL,
  raw_user_meta_data LONGTEXT DEFAULT NULL,
  is_banned TINYINT(1) NOT NULL DEFAULT 0,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_users_email (email),
  KEY idx_auth_users_last_sign_in (last_sign_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_refresh_tokens (
  id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  token VARCHAR(128) NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_refresh_token (token),
  KEY idx_refresh_user (user_id),
  CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES auth_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_objects (
  id CHAR(36) NOT NULL,
  bucket VARCHAR(64) NOT NULL,
  object_path VARCHAR(400) NOT NULL,
  mime_type VARCHAR(120) DEFAULT NULL,
  size_bytes BIGINT DEFAULT 0,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  owner_id CHAR(36) DEFAULT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_storage_object (bucket, object_path),
  KEY idx_storage_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `action_items` (
  `id` CHAR(36) NOT NULL,
  `minutes_id` CHAR(36) NOT NULL,
  `description` TEXT NOT NULL,
  `assigned_to` VARCHAR(255) DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `status` VARCHAR(255) DEFAULT 'pending',
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_action_items_minutes_id` (`minutes_id`),
  KEY `idx_action_items_status` (`status`),
  KEY `idx_action_items_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` CHAR(36) NOT NULL,
  `super_admin_id` CHAR(36) NOT NULL,
  `action` VARCHAR(255) NOT NULL,
  `target_user_id` CHAR(36) DEFAULT NULL,
  `target_member_id` CHAR(36) DEFAULT NULL,
  `details` LONGTEXT DEFAULT NULL,
  `ip_address` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_super_admin_id` (`super_admin_id`),
  KEY `idx_audit_logs_target_user_id` (`target_user_id`),
  KEY `idx_audit_logs_target_member_id` (`target_member_id`),
  KEY `idx_audit_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `b2c_transactions` (
  `id` CHAR(36) NOT NULL,
  `withdrawal_id` CHAR(36) NOT NULL,
  `mpesa_transaction_id` VARCHAR(64) NOT NULL,
  `phone_number` TEXT NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `status` TEXT NOT NULL DEFAULT ('initiated'),
  `initiated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `completed_at` DATETIME(3) DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `mpesa_charge` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `working_account_balance` DECIMAL(14,2) DEFAULT NULL,
  `utility_account_balance` DECIMAL(14,2) DEFAULT NULL,
  `recipient_name` TEXT DEFAULT NULL,
  `wallet_type` TEXT NOT NULL DEFAULT ('penalty'),
  `transaction_completed_at` DATETIME(3) DEFAULT NULL,
  `transfer_id` CHAR(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_b2c_transactions_withdrawal_id` (`withdrawal_id`),
  KEY `idx_b2c_transactions_mpesa_transaction_id` (`mpesa_transaction_id`),
  KEY `idx_b2c_transactions_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_transactions` (
  `id` CHAR(36) NOT NULL,
  `bank_name` VARCHAR(50) NOT NULL DEFAULT 'coop',
  `member_id` CHAR(36) DEFAULT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `name` TEXT DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `transaction_date` DATE NOT NULL,
  `month` INT NOT NULL,
  `year` INT NOT NULL,
  `transaction_reference` VARCHAR(120) NOT NULL,
  `mpesa_code` TEXT DEFAULT NULL,
  `raw_details` TEXT DEFAULT NULL,
  `bank_transaction_reference` VARCHAR(120) DEFAULT NULL,
  `transaction_type` VARCHAR(32) DEFAULT NULL,
  `credit_amount` DECIMAL(14,2) DEFAULT NULL,
  `debit_amount` DECIMAL(14,2) DEFAULT NULL,
  `narration` TEXT DEFAULT NULL,
  `account_number` VARCHAR(64) DEFAULT NULL,
  `source` VARCHAR(64) DEFAULT NULL,
  `reconciliation_status` VARCHAR(32) DEFAULT NULL,
  `member_matched_at` DATETIME DEFAULT NULL,
  `transaction_time` TIME DEFAULT NULL,
  `value_date` DATE DEFAULT NULL,
  `bank_balance` DECIMAL(14,2) DEFAULT NULL,
  `match_method` VARCHAR(64) DEFAULT NULL,
  `match_confidence` VARCHAR(32) DEFAULT NULL,
  `matched_by` CHAR(36) DEFAULT NULL,
  `matched_at` TIMESTAMP NULL DEFAULT NULL,
  `contribution_id` CHAR(36) DEFAULT NULL,
  `sync_batch_id` CHAR(36) DEFAULT NULL,
  `raw_response` LONGTEXT DEFAULT NULL,
  `transaction_state` VARCHAR(32) DEFAULT 'completed',
  `category` VARCHAR(64) DEFAULT NULL,
  `updated_at` TIMESTAMP(3) NULL DEFAULT NULL,
  `bank_fingerprint` VARCHAR(128) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_tx_ref` (`transaction_reference`),
  UNIQUE KEY `uq_bank_fingerprint` (`bank_fingerprint`),
  KEY `idx_bank_tx_bank_ref` (`bank_transaction_reference`),
  KEY `idx_bank_transactions_member_id` (`member_id`),
  KEY `idx_bank_transactions_phone` (`phone`),
  KEY `idx_bank_transactions_created_at` (`created_at`),
  KEY `idx_bank_tx_recon_status` (`reconciliation_status`),
  KEY `idx_bank_tx_sync_batch` (`sync_batch_id`),
  KEY `idx_bank_tx_match_method` (`match_method`),
  KEY `idx_bank_tx_category` (`category`),
  KEY `idx_bank_tx_date` (`transaction_date`),
  KEY `idx_bank_tx_state` (`transaction_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_sync_audit` (
  `id` CHAR(36) NOT NULL,
  `account_number` VARCHAR(64) NOT NULL,
  `from_date` DATE NOT NULL,
  `to_date` DATE NOT NULL,
  `total_bank_transactions` INT NOT NULL DEFAULT 0,
  `new_transactions` INT NOT NULL DEFAULT 0,
  `duplicate_skipped` INT NOT NULL DEFAULT 0,
  `matched` INT NOT NULL DEFAULT 0,
  `unmatched` INT NOT NULL DEFAULT 0,
  `bank_balance` DECIMAL(14,2) DEFAULT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'in_progress',
  `error_message` TEXT DEFAULT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bank_sync_account` (`account_number`),
  KEY `idx_bank_sync_date` (`from_date`, `to_date`),
  KEY `idx_bank_sync_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `beneficiaries` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `name` TEXT NOT NULL,
  `relationship` TEXT NOT NULL DEFAULT ('spouse'),
  `phone` TEXT DEFAULT NULL,
  `id_number` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_beneficiaries_member_id` (`member_id`),
  KEY `idx_beneficiaries_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `beneficiary_requests` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `request_type` VARCHAR(255) NOT NULL,
  `status` VARCHAR(255) DEFAULT 'pending',
  `beneficiary_name` VARCHAR(255) DEFAULT NULL,
  `beneficiary_relationship` VARCHAR(255) DEFAULT NULL,
  `beneficiary_phone` VARCHAR(255) DEFAULT NULL,
  `beneficiary_id_number` VARCHAR(255) DEFAULT NULL,
  `beneficiary_id` CHAR(36) DEFAULT NULL,
  `reason` TEXT NOT NULL,
  `admin_notes` TEXT DEFAULT NULL,
  `reviewed_by` CHAR(36) DEFAULT NULL,
  `reviewed_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_beneficiary_reques_member_id` (`member_id`),
  KEY `idx_beneficiary_reques_status` (`status`),
  KEY `idx_beneficiary_reques_beneficiary_id` (`beneficiary_id`),
  KEY `idx_beneficiary_reques_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `book_balance` (
  `id` CHAR(36) NOT NULL,
  `transaction_date` DATE NOT NULL,
  `check_number` VARCHAR(255) NOT NULL,
  `debit` DECIMAL(14,2) NOT NULL,
  `book_balance` DECIMAL(14,2) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `created_by` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_book_balance_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contributions` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `month` INT NOT NULL,
  `year` INT NOT NULL,
  `due_date` DATE NOT NULL,
  `stk_checkout_request_id` VARCHAR(120) DEFAULT NULL,
  `payment_ref` VARCHAR(120) DEFAULT NULL,
  `paid_date` DATE DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `payment_id` CHAR(36) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contrib_member_period` (`member_id`, `month`, `year`),
  KEY `idx_contributions_member_id` (`member_id`),
  KEY `idx_contributions_payment_id` (`payment_id`),
  KEY `idx_contributions_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversation_participants` (
  `id` CHAR(36) NOT NULL,
  `conversation_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `joined_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `last_read_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conv_participant` (`conversation_id`, `user_id`),
  KEY `idx_conversation_parti_conversation_id` (`conversation_id`),
  KEY `idx_conversation_parti_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversations` (
  `id` CHAR(36) NOT NULL,
  `type` TEXT NOT NULL DEFAULT ('private'),
  `name` TEXT DEFAULT NULL,
  `created_by` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_conversations_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_assistant_conversations` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `context_type` VARCHAR(100) NOT NULL,
  `title` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `ai_conversations_user_context_updated` (`user_id`, `context_type`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_assistant_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` CHAR(36) NOT NULL,
  `role` VARCHAR(16) NOT NULL,
  `content` TEXT NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `ai_messages_conversation_order` (`conversation_id`, `id`),
  CONSTRAINT `fk_ai_assistant_messages_conversation`
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_assistant_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dashboard_security` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `pin_hash` TEXT DEFAULT NULL,
  `webauthn_credential_id` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dashsec_user` (`user_id`),
  KEY `idx_dashboard_security_user_id` (`user_id`),
  KEY `idx_dashboard_security_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `documents` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `file_name` TEXT NOT NULL,
  `file_type` TEXT NOT NULL DEFAULT ('other'),
  `file_url` TEXT NOT NULL,
  `uploaded_by` CHAR(36) NOT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_documents_member_id` (`member_id`),
  KEY `idx_documents_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

 CREATE TABLE IF NOT EXISTS `donation_campaigns` (
  `id` CHAR(36) NOT NULL,
  `title` TEXT NOT NULL,
  `description` TEXT DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` CHAR(36) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `goal_type` TEXT NOT NULL DEFAULT ('fixed'),
  `target_total` DECIMAL(14,2) DEFAULT NULL,
  `allow_partial` TINYINT(1) NOT NULL DEFAULT 1,
  `total_collected` DECIMAL(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_donation_campaigns_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `donation_payment_records` (
  `id` CHAR(36) NOT NULL,
  `campaign_id` CHAR(36) DEFAULT NULL,
  `member_id` CHAR(36) DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `mpesa_transaction_id` TEXT DEFAULT NULL,
  `payment_ref` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `verified_at` DATETIME(3) DEFAULT NULL,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_donation_payment_r_campaign_id` (`campaign_id`),
  KEY `idx_donation_payment_r_member_id` (`member_id`),
  KEY `idx_donation_payment_r_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `campaign_contributions` (
  `id` CHAR(36) NOT NULL,
  `campaign_id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `total_contributed` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `target_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `remaining` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` TEXT NOT NULL DEFAULT ('active'),
  `last_payment_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_campaign_member` (`campaign_id`, `member_id`),
  KEY `idx_campaign_contributions_member_id` (`member_id`),
  KEY `idx_campaign_contributions_campaign_id` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `donation_wallet` (
  `id` CHAR(36) NOT NULL,
  `total_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_received` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_withdrawn` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `donation_withdrawal_signatories` (
  `id` CHAR(36) NOT NULL,
  `withdrawal_id` CHAR(36) DEFAULT NULL,
  `signatory_role` TEXT NOT NULL,
  `signatory_user_id` CHAR(36) DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `signature_url` TEXT DEFAULT NULL,
  `approved_at` DATETIME(3) DEFAULT NULL,
  `rejected_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `rejection_reason` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_donation_withdrawa_withdrawal_id` (`withdrawal_id`),
  KEY `idx_donation_withdrawa_signatory_user_id` (`signatory_user_id`),
  KEY `idx_donation_withdrawa_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `donation_withdrawals` (
  `id` CHAR(36) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `reason` TEXT NOT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `requested_by` CHAR(36) DEFAULT NULL,
  `phone_number` TEXT DEFAULT NULL,
  `approval_stage` VARCHAR(40) NOT NULL DEFAULT 'treasurer',
  `submitted_at` DATETIME(3) DEFAULT NULL,
  `completed_at` DATETIME(3) DEFAULT NULL,
  `b2c_reference` VARCHAR(64) DEFAULT NULL,
  `bank_transfer_id` CHAR(36) DEFAULT NULL,
  `mpesa_receipt` VARCHAR(64) DEFAULT NULL,
  `receipt_url` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_donation_withdrawa_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `events` (
  `id` CHAR(36) NOT NULL,
  `title` TEXT NOT NULL,
  `description` TEXT DEFAULT NULL,
  `event_type` TEXT NOT NULL DEFAULT ('funeral'),
  `departed_name` TEXT DEFAULT NULL,
  `relationship` TEXT DEFAULT ('member'),
  `related_member_id` CHAR(36) DEFAULT NULL,
  `contribution_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` TEXT NOT NULL DEFAULT ('active'),
  `created_by` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `scheduled_date` DATETIME(3) DEFAULT NULL,
  `rescheduled_date` DATETIME(3) DEFAULT NULL,
  `reschedule_reason` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_events_related_member_id` (`related_member_id`),
  KEY `idx_events_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `executive_badges` (
  `id` CHAR(36) NOT NULL,
  `role_name` TEXT NOT NULL,
  `badge_url` TEXT DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `created_by` CHAR(36) DEFAULT NULL,
  `updated_by` CHAR(36) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_badge_role` (`role_name`),
  KEY `idx_executive_badges_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` CHAR(36) NOT NULL,
  `expense_type` VARCHAR(255) NOT NULL,
  `category` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `recipient_name` VARCHAR(255) DEFAULT NULL,
  `recipient_member_id` CHAR(36) DEFAULT NULL,
  `payment_method` VARCHAR(255) DEFAULT NULL,
  `reference_number` VARCHAR(255) DEFAULT NULL,
  `status` VARCHAR(255) DEFAULT 'pending',
  `approved_by` CHAR(36) DEFAULT NULL,
  `approved_at` DATETIME(3) DEFAULT NULL,
  `paid_by` CHAR(36) DEFAULT NULL,
  `paid_at` DATETIME(3) DEFAULT NULL,
  `created_by` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `wallet_type` TEXT NOT NULL DEFAULT ('operational'),
  `withdrawal_id` CHAR(36) DEFAULT NULL,
  `withdrawal_table` TEXT DEFAULT NULL,
  `mpesa_charge` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `phone_number` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_recipient_member_id` (`recipient_member_id`),
  KEY `idx_expenses_status` (`status`),
  KEY `idx_expenses_created_at` (`created_at`),
  KEY `idx_expenses_withdrawal_id` (`withdrawal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `financial_reports` (
  `id` CHAR(36) NOT NULL,
  `report_type` VARCHAR(255) NOT NULL,
  `report_period_start` DATE NOT NULL,
  `report_period_end` DATE NOT NULL,
  `total_contributions` DECIMAL(14,2) DEFAULT 0,
  `total_expenses` DECIMAL(14,2) DEFAULT 0,
  `total_payouts` DECIMAL(14,2) DEFAULT 0,
  `net_balance` DECIMAL(14,2) DEFAULT 0,
  `report_data` LONGTEXT DEFAULT NULL,
  `generated_by` CHAR(36) NOT NULL,
  `generated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `meeting_attendance` (
  `id` CHAR(36) NOT NULL,
  `meeting_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `status` TEXT NOT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance` (`meeting_id`, `user_id`),
  KEY `idx_meeting_attendance_meeting_id` (`meeting_id`),
  KEY `idx_meeting_attendance_user_id` (`user_id`),
  KEY `idx_meeting_attendance_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `meeting_minutes` (
  `id` CHAR(36) NOT NULL,
  `created_by` CHAR(36) NOT NULL,
  `meeting_date` DATE NOT NULL,
  `meeting_type` VARCHAR(255) NOT NULL DEFAULT 'general',
  `title` VARCHAR(255) NOT NULL,
  `attendees` LONGTEXT DEFAULT NULL,
  `agenda` TEXT DEFAULT NULL,
  `decisions` TEXT DEFAULT NULL,
  `action_items` TEXT DEFAULT NULL,
  `next_meeting_date` DATE DEFAULT NULL,
  `status` VARCHAR(255) DEFAULT 'draft',
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `discussions` TEXT DEFAULT NULL,
  `absent_with_apology` LONGTEXT DEFAULT NULL,
  `absent_without_apology` LONGTEXT DEFAULT NULL,
  `visible_to_members` LONGTEXT DEFAULT NULL,
  `rejection_notes` TEXT DEFAULT NULL,
  `submitted_by` CHAR(36) DEFAULT NULL,
  `submitted_at` DATETIME(3) DEFAULT NULL,
  `reviewed_by` CHAR(36) DEFAULT NULL,
  `reviewed_at` DATETIME(3) DEFAULT NULL,
  `chairperson_signature_url` TEXT DEFAULT NULL,
  `secretary_signature_url` TEXT DEFAULT NULL,
  `chairperson_name` VARCHAR(255) DEFAULT NULL,
  `secretary_name` VARCHAR(255) DEFAULT NULL,
  `secretary_reviewed_by` CHAR(36) DEFAULT NULL,
  `secretary_reviewed_at` DATETIME(3) DEFAULT NULL,
  `secretary_notes` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_meeting_minutes_status` (`status`),
  KEY `idx_meeting_minutes_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_access_logs` (
  `id` CHAR(36) NOT NULL,
  `super_admin_id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `access_type` VARCHAR(255) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_member_access_logs_super_admin_id` (`super_admin_id`),
  KEY `idx_member_access_logs_member_id` (`member_id`),
  KEY `idx_member_access_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_registrations` (
  `id` CHAR(36) NOT NULL,
  `full_name` TEXT NOT NULL,
  `phone_number` VARCHAR(32) NOT NULL,
  `department` TEXT DEFAULT NULL,
  `working_location` TEXT DEFAULT NULL,
  `date_of_birth` DATE DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('payment_pending'),
  `payment_status` TEXT NOT NULL DEFAULT ('unpaid'),
  `approved_at` DATETIME(3) DEFAULT NULL,
  `approved_by` CHAR(36) DEFAULT NULL,
  `approval_notes` TEXT DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `verified_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_member_registratio_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `members` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) DEFAULT NULL,
  `name` TEXT NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `member_id` VARCHAR(64) DEFAULT NULL,
  `total_contributions` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_penalties` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `profile_picture_url` TEXT DEFAULT NULL,
  `status_message` TEXT DEFAULT ('Hey'),
  `status` TEXT NOT NULL DEFAULT ('active'),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_members_phone` (`phone`),
  KEY `idx_members_user_id` (`user_id`),
  KEY `idx_members_phone` (`phone`),
  KEY `idx_members_member_id` (`member_id`),
  KEY `idx_members_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `memo_recipients` (
  `id` CHAR(36) NOT NULL,
  `memo_id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `delivered_at` DATETIME(3) DEFAULT NULL,
  `seen_at` DATETIME(3) DEFAULT NULL,
  `downloaded_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_memo_recipient` (`memo_id`, `member_id`),
  KEY `idx_memo_recipients_memo_id` (`memo_id`),
  KEY `idx_memo_recipients_member_id` (`member_id`),
  KEY `idx_memo_recipients_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `memo_templates` (
  `id` CHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `category` VARCHAR(255) NOT NULL,
  `template_content` TEXT NOT NULL,
  `variables` LONGTEXT DEFAULT NULL,
  `created_by` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_memo_templates_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `memos` (
  `id` CHAR(36) NOT NULL,
  `reference_number` VARCHAR(255) DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `recipient_type` VARCHAR(255) NOT NULL,
  `status` VARCHAR(255) DEFAULT 'draft',
  `attachments` LONGTEXT DEFAULT NULL,
  `created_by` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `sent_at` DATETIME(3) DEFAULT NULL,
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_memos_status` (`status`),
  KEY `idx_memos_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_reactions` (
  `id` CHAR(36) NOT NULL,
  `message_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `emoji` TEXT NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reaction` (`message_id`, `user_id`, `emoji`),
  KEY `idx_message_reactions_message_id` (`message_id`),
  KEY `idx_message_reactions_user_id` (`user_id`),
  KEY `idx_message_reactions_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) DEFAULT NULL,
  `content` TEXT NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `conversation_id` CHAR(36) DEFAULT NULL,
  `reply_to_id` CHAR(36) DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('sent'),
  `attachment_url` TEXT DEFAULT NULL,
  `attachment_type` TEXT DEFAULT NULL,
  `attachment_name` TEXT DEFAULT NULL,
  `edited_at` DATETIME(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_messages_user_id` (`user_id`),
  KEY `idx_messages_member_id` (`member_id`),
  KEY `idx_messages_created_at` (`created_at`),
  KEY `idx_messages_conversation_id` (`conversation_id`),
  KEY `idx_messages_reply_to_id` (`reply_to_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `news` (
  `id` CHAR(36) NOT NULL,
  `title` TEXT NOT NULL,
  `content` TEXT NOT NULL,
  `author_id` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `scheduled_date` DATETIME(3) DEFAULT NULL,
  `rescheduled_date` DATETIME(3) DEFAULT NULL,
  `reschedule_reason` TEXT DEFAULT NULL,
  `status` TEXT DEFAULT ('active'),
  PRIMARY KEY (`id`),
  KEY `idx_news_author_id` (`author_id`),
  KEY `idx_news_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `news_read` (
  `id` CHAR(36) NOT NULL,
  `news_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `read_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_news_read` (`news_id`, `user_id`),
  KEY `idx_news_read_news_id` (`news_id`),
  KEY `idx_news_read_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `title` TEXT NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `type` TEXT NOT NULL DEFAULT ('info'),
  `target_url` VARCHAR(512) DEFAULT NULL,
  `event_key` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_user_event` (`user_id`, `event_key`),
  KEY `idx_notifications_user_id` (`user_id`),
  KEY `idx_notifications_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `office_bearer_signatures` (
  `id` CHAR(36) NOT NULL,
  `role` VARCHAR(255) NOT NULL,
  `signature_url` TEXT DEFAULT NULL,
  `updated_by` CHAR(36) DEFAULT NULL,
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bearer_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `operational_payment_records` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `source` TEXT NOT NULL DEFAULT ('stk_push'),
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `mpesa_transaction_id` TEXT DEFAULT NULL,
  `payment_ref` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` CHAR(36) DEFAULT NULL,
  `verified_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_operational_paymen_member_id` (`member_id`),
  KEY `idx_operational_paymen_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `operational_wallet` (
  `id` CHAR(36) NOT NULL,
  `total_received` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_withdrawn` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `operational_withdrawal_signatories` (
  `id` CHAR(36) NOT NULL,
  `withdrawal_id` CHAR(36) DEFAULT NULL,
  `signatory_role` TEXT NOT NULL,
  `signatory_user_id` CHAR(36) DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `signature_url` TEXT DEFAULT NULL,
  `approved_at` DATETIME(3) DEFAULT NULL,
  `rejected_at` DATETIME(3) DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_operational_withdr_withdrawal_id` (`withdrawal_id`),
  KEY `idx_operational_withdr_signatory_user_id` (`signatory_user_id`),
  KEY `idx_operational_withdr_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `operational_withdrawals` (
  `id` CHAR(36) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `reason` TEXT NOT NULL,
  `category` TEXT NOT NULL DEFAULT ('payout'),
  `expense_type` TEXT DEFAULT NULL,
  `recipient_name` TEXT DEFAULT NULL,
  `phone_number` TEXT DEFAULT NULL,
  `requested_by` CHAR(36) DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `approval_stage` VARCHAR(40) NOT NULL DEFAULT 'treasurer',
  `submitted_at` DATETIME(3) DEFAULT NULL,
  `completed_at` DATETIME(3) DEFAULT NULL,
  `b2c_reference` VARCHAR(64) DEFAULT NULL,
  `bank_transfer_id` CHAR(36) DEFAULT NULL,
  `mpesa_receipt` VARCHAR(64) DEFAULT NULL,
  `receipt_url` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_operational_withdr_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `organization_settings` (
  `id` CHAR(36) NOT NULL,
  `organization_name` VARCHAR(255) NOT NULL DEFAULT 'KHCWW',
  `organization_address` TEXT DEFAULT NULL,
  `organization_email` VARCHAR(255) DEFAULT NULL,
  `organization_phone` VARCHAR(255) DEFAULT NULL,
  `logo_url` TEXT DEFAULT NULL,
  `signature_url` TEXT DEFAULT NULL,
  `stamp_url` TEXT DEFAULT NULL,
  `letterhead_template` TEXT DEFAULT NULL,
  `payout_rules` LONGTEXT DEFAULT NULL,
  `updated_by` CHAR(36) DEFAULT NULL,
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `reset_token` VARCHAR(255) NOT NULL,
  `reset_by` CHAR(36) DEFAULT NULL,
  `reset_at` DATETIME(3) DEFAULT NULL,
  `new_password_hash` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `expires_at` DATETIME(3) DEFAULT NULL,
  `reason` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_user_id` (`user_id`),
  KEY `idx_password_resets_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `transaction_ref` VARCHAR(120) DEFAULT NULL,
  `source` TEXT NOT NULL DEFAULT ('bank_sms'),
  `matched` TINYINT(1) NOT NULL DEFAULT 0,
  `raw_message` TEXT DEFAULT NULL,
  `received_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_payments_member_id` (`member_id`),
  KEY `idx_payments_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payouts` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `payout_type` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `eligible_amount` DECIMAL(14,2) NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `supporting_documents` LONGTEXT DEFAULT NULL,
  `status` VARCHAR(255) DEFAULT 'pending',
  `approved_by` CHAR(36) DEFAULT NULL,
  `approved_at` DATETIME(3) DEFAULT NULL,
  `paid_by` CHAR(36) DEFAULT NULL,
  `paid_at` DATETIME(3) DEFAULT NULL,
  `payment_reference` VARCHAR(255) DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `created_by` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_payouts_member_id` (`member_id`),
  KEY `idx_payouts_status` (`status`),
  KEY `idx_payouts_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `penalties` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `contribution_id` CHAR(36) DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `reason` TEXT NOT NULL DEFAULT ('Late'),
  `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_penalties_member_id` (`member_id`),
  KEY `idx_penalties_contribution_id` (`contribution_id`),
  KEY `idx_penalties_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `penalty_waivers` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `amount_minor` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(500) NOT NULL,
  `created_by` CHAR(36) NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `penalty_waivers_member_created` (`member_id`, `created_at`),
  KEY `penalty_waivers_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `penalty_payment_records` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `penalty_id` CHAR(36) DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `payment_ref` TEXT DEFAULT NULL,
  `mpesa_transaction_id` TEXT DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `verified_by` CHAR(36) DEFAULT NULL,
  `verified_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_penalty_payment_re_member_id` (`member_id`),
  KEY `idx_penalty_payment_re_penalty_id` (`penalty_id`),
  KEY `idx_penalty_payment_re_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `penalty_payments` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `reference_number` VARCHAR(120) NOT NULL,
  `payment_date` DATE NOT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `verified_by` CHAR(36) DEFAULT NULL,
  `verified_at` DATETIME(3) DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `payment_message` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_penalty_payment_ref` (`reference_number`),
  KEY `idx_penalty_payments_member_id` (`member_id`),
  KEY `idx_penalty_payments_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `penalty_wallet` (
  `id` CHAR(36) NOT NULL,
  `total_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_received` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total_withdrawn` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `last_updated` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_penalty_wallet_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `penalty_withdrawals` (
  `id` CHAR(36) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `reason` TEXT NOT NULL,
  `phone_number` TEXT DEFAULT NULL,
  `requested_by` CHAR(36) NOT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'pending',
  `approval_stage` VARCHAR(40) NOT NULL DEFAULT 'treasurer',
  `submitted_at` DATETIME(3) DEFAULT NULL,
  `completed_at` DATETIME(3) DEFAULT NULL,
  `b2c_reference` VARCHAR(64) DEFAULT NULL,
  `bank_transfer_id` CHAR(36) DEFAULT NULL,
  `mpesa_receipt` VARCHAR(64) DEFAULT NULL,
  `receipt_url` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_penalty_withdrawal_status` (`status`),
  KEY `idx_penalty_withdrawal_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `push_tokens` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `platform` TEXT DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_token` (`token`),
  KEY `idx_push_tokens_user_id` (`user_id`),
  KEY `idx_push_tokens_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `registration_access_links` (
  `id` CHAR(36) NOT NULL,
  `registration_id` CHAR(36) NOT NULL,
  `access_token` VARCHAR(128) NOT NULL,
  `temporary_password` TEXT DEFAULT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `expires_at` DATETIME(3) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_access_token` (`access_token`),
  KEY `idx_registration_acces_registration_id` (`registration_id`),
  KEY `idx_registration_acces_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `registration_config` (
  `id` CHAR(36) NOT NULL,
  `retiring_date` DATE DEFAULT NULL,
  `registration_fee` DECIMAL(14,2) NOT NULL DEFAULT 1000,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `show_on_login` TINYINT(1) NOT NULL DEFAULT 1,
  `auto_approve` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` CHAR(36) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_registration_confi_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `registration_fees` (
  `id` CHAR(36) NOT NULL,
  `registration_id` CHAR(36) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `phone_number` TEXT DEFAULT NULL,
  `mpesa_checkout_request_id` VARCHAR(120) DEFAULT NULL,
  `mpesa_transaction_id` TEXT DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `retry_count` INT NOT NULL DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `last_retry_at` DATETIME(3) DEFAULT NULL,
  `verified_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_registration_fees_registration_id` (`registration_id`),
  KEY `idx_registration_fees_mpesa_checkout_request_id` (`mpesa_checkout_request_id`),
  KEY `idx_registration_fees_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `signatory_signatures` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `signatory_role` TEXT NOT NULL,
  `signature_url` TEXT DEFAULT NULL,
  `full_name` TEXT DEFAULT NULL,
  `updated_by` CHAR(36) DEFAULT NULL,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_signatory_user_role` (`user_id`, `signatory_role`),
  KEY `idx_signatory_signatur_user_id` (`user_id`),
  KEY `idx_signatory_signatur_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sms_logs` (
  `id` CHAR(36) NOT NULL,
  `recipient_phone` VARCHAR(32) NOT NULL,
  `message` TEXT NOT NULL,
  `status` TEXT NOT NULL DEFAULT ('pending'),
  `provider` VARCHAR(32) DEFAULT NULL,
  `provider_ref` TEXT DEFAULT NULL,
  `reference_type` VARCHAR(64) DEFAULT NULL,
  `reference_id` VARCHAR(128) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_sms_logs_created_at` (`created_at`),
  UNIQUE KEY `sms_logs_reference_unique` (`recipient_phone`, `reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_health` (
  `id` CHAR(36) NOT NULL,
  `metric_name` VARCHAR(255) NOT NULL,
  `metric_value` DECIMAL(14,2) DEFAULT NULL,
  `status` VARCHAR(255) DEFAULT NULL,
  `details` LONGTEXT DEFAULT NULL,
  `checked_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_system_health_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` CHAR(36) NOT NULL,
  `log_level` VARCHAR(255) NOT NULL,
  `component` VARCHAR(255) DEFAULT NULL,
  `message` TEXT NOT NULL,
  `error_details` LONGTEXT DEFAULT NULL,
  `user_id` CHAR(36) DEFAULT NULL,
  `request_path` VARCHAR(255) DEFAULT NULL,
  `status_code` INT DEFAULT NULL,
  `created_at` DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),
  `resolved` TINYINT(1) DEFAULT 0,
  `resolved_by` CHAR(36) DEFAULT NULL,
  `resolved_at` DATETIME(3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_system_logs_user_id` (`user_id`),
  KEY `idx_system_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `unmatched_payments` (
  `id` CHAR(36) NOT NULL,
  `payment_id` CHAR(36) DEFAULT NULL,
  `raw_message` TEXT DEFAULT NULL,
  `extracted_phone` TEXT DEFAULT NULL,
  `extracted_amount` DECIMAL(14,2) DEFAULT NULL,
  `resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `resolved_by` CHAR(36) DEFAULT NULL,
  `resolved_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_unmatched_payments_payment_id` (`payment_id`),
  KEY `idx_unmatched_payments_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_presence` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `is_online` TINYINT(1) NOT NULL DEFAULT 0,
  `last_seen` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_presence_user` (`user_id`),
  KEY `idx_user_presence_user_id` (`user_id`),
  KEY `idx_user_presence_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `role` VARCHAR(40) NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_roles` (`user_id`, `role`),
  KEY `idx_user_roles_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id` CHAR(36) NOT NULL,
  `wallet_type` TEXT NOT NULL,
  `direction` TEXT NOT NULL,
  `source` TEXT NOT NULL,
  `reference_id` CHAR(36) DEFAULT NULL,
  `reference_table` TEXT DEFAULT NULL,
  `party_name` TEXT DEFAULT NULL,
  `party_phone` TEXT DEFAULT NULL,
  `gross_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `mpesa_charge` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `system_fee` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `net_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `running_balance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `mpesa_receipt` VARCHAR(64) DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT ('completed'),
  `notes` TEXT DEFAULT NULL,
  `created_by` CHAR(36) DEFAULT NULL,
  `occurred_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_wallet_transaction_reference_id` (`reference_id`),
  KEY `idx_wallet_transaction_occurred_at` (`occurred_at`),
  KEY `idx_wallet_transaction_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `welfare_settings` (
  `id` CHAR(36) NOT NULL,
  `name` TEXT NOT NULL DEFAULT ('Welfare'),
  `monthly_contribution_amount` DECIMAL(14,2) NOT NULL DEFAULT 500,
  `contribution_due_day` INT NOT NULL DEFAULT 5,
  `penalty_amount` DECIMAL(14,2) NOT NULL DEFAULT 100,
  `penalty_grace_days` INT NOT NULL DEFAULT 7,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_welfare_settings_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `withdrawal_receipts` (
  `id` CHAR(36) NOT NULL,
  `withdrawal_id` CHAR(36) NOT NULL,
  `wallet_type` TEXT NOT NULL DEFAULT ('penalty'),
  `receipt_pdf_url` TEXT DEFAULT NULL,
  `generated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `b2c_reference` VARCHAR(64) DEFAULT NULL,
  `bank_transfer_id` CHAR(36) DEFAULT NULL,
  `mpesa_receipt` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `created_by` CHAR(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_withdrawal_receipt_withdrawal_id` (`withdrawal_id`),
  KEY `idx_withdrawal_receipt_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `withdrawal_signatories` (
  `id` CHAR(36) NOT NULL,
  `withdrawal_id` CHAR(36) NOT NULL,
  `signatory_role` TEXT NOT NULL,
  `signatory_user_id` CHAR(36) DEFAULT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'pending',
  `signature_url` TEXT DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `approved_at` DATETIME(3) DEFAULT NULL,
  `rejected_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_withdrawal_signato_withdrawal_id` (`withdrawal_id`),
  KEY `idx_withdrawal_signato_signatory_user_id` (`signatory_user_id`),
  KEY `idx_withdrawal_signato_status` (`status`),
  KEY `idx_withdrawal_signato_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_executive_roles` (
  `id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `badge_id` CHAR(36) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_member_exec_role` (`member_id`, `badge_id`),
  KEY `idx_member_exec_member_id` (`member_id`),
  KEY `idx_member_exec_badge_id` (`badge_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_tokens` (
  `id` CHAR(36) NOT NULL,
  `environment` VARCHAR(64) NOT NULL DEFAULT 'sandbox',
  `token_value` LONGTEXT NOT NULL,
  `refresh_token` LONGTEXT DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_coop_tokens_environment_expires_at` (`environment`, `expires_at`),
  KEY `idx_coop_tokens_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_transfer_requests` (
  `id` CHAR(36) NOT NULL,
  `message_reference` VARCHAR(28) NOT NULL,
  `transfer_type` VARCHAR(64) NOT NULL DEFAULT 'initiated' COMMENT 'STK, B2C_MPESA, PESALINK_ACCOUNT, IFT',
  `status` VARCHAR(64) NOT NULL DEFAULT 'initiated' COMMENT 'initiated, submitted, processing, successful, failed, timeout, reversed, reconciliation_required',
  `destination_account` VARCHAR(64) DEFAULT NULL,
  `destination_bank_code` VARCHAR(32) DEFAULT NULL,
  `destination_name` VARCHAR(255) DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `currency` VARCHAR(3) NOT NULL DEFAULT 'KES',
  `narration_internal` TEXT DEFAULT NULL,
  `narration_bank` VARCHAR(25) DEFAULT NULL,
  `internal_reference` VARCHAR(255) DEFAULT NULL,
  `internal_table` VARCHAR(64) DEFAULT NULL,
  `request_payload` LONGTEXT DEFAULT NULL,
  `error_message` TEXT DEFAULT NULL,
  `retry_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_message_reference` (`message_reference`),
  KEY `idx_internal_ref` (`internal_reference`),
  KEY `idx_status` (`status`),
  KEY `idx_transfer_type` (`transfer_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_transfer_responses` (
  `id` CHAR(36) NOT NULL,
  `transfer_request_id` CHAR(36) NOT NULL,
  `message_reference` VARCHAR(28) NOT NULL,
  `transaction_id` VARCHAR(64) DEFAULT NULL,
  `status` VARCHAR(64) NOT NULL COMMENT 'SUCCESS, FAILED, PENDING',
  `status_description` TEXT DEFAULT NULL,
  `response_payload` LONGTEXT DEFAULT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_id` (`transaction_id`),
  KEY `idx_message_ref` (`message_reference`),
  KEY `idx_status` (`status`),
  KEY `idx_received_at` (`received_at`),
  CONSTRAINT `fk_coop_transfer_responses_transfer_request_id` FOREIGN KEY (`transfer_request_id`) REFERENCES `coop_transfer_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_callbacks` (
  `id` CHAR(36) NOT NULL,
  `callback_type` VARCHAR(64) NOT NULL COMMENT 'TRANSFER, STK, INS, VALIDATION',
  `message_reference` VARCHAR(64) DEFAULT NULL,
  `request_payload` LONGTEXT NOT NULL,
  `response_payload` LONGTEXT DEFAULT NULL,
  `http_status` INT DEFAULT NULL,
  `processing_status` VARCHAR(64) NOT NULL DEFAULT 'pending' COMMENT 'pending, success, error, duplicate',
  `error_message` TEXT DEFAULT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_message_ref` (`message_reference`),
  KEY `idx_callback_type` (`callback_type`),
  KEY `idx_processing_status` (`processing_status`),
  KEY `idx_received_at` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_notifications_ins` (
  `id` CHAR(36) NOT NULL,
  `payment_ref` VARCHAR(64) DEFAULT NULL,
  `account_number` VARCHAR(32) NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `event_type` VARCHAR(32) NOT NULL COMMENT 'DEBIT, CREDIT',
  `transaction_type` CHAR(1) NOT NULL COMMENT 'C=Credit, D=Debit',
  `booked_balance` DECIMAL(14,2) DEFAULT NULL,
  `cleared_balance` DECIMAL(14,2) DEFAULT NULL,
  `narration` VARCHAR(255) DEFAULT NULL,
  `transaction_date` DATE DEFAULT NULL,
  `transaction_time` TIME DEFAULT NULL,
  `transaction_id` VARCHAR(64) NOT NULL,
  `notification_payload` LONGTEXT DEFAULT NULL,
  `processed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transaction_id` (`transaction_id`),
  KEY `idx_account` (`account_number`),
  KEY `idx_payment_ref` (`payment_ref`),
  KEY `idx_transaction_date` (`transaction_date`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_reconciliation_logs` (
  `id` CHAR(36) NOT NULL,
  `reconciliation_type` VARCHAR(64) NOT NULL COMMENT 'DAILY, MONTHLY, MANUAL, ON_DEMAND',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'in_progress' COMMENT 'in_progress, success, failed',
  `total_bank_transactions` INT NOT NULL DEFAULT 0,
  `total_internal_transactions` INT NOT NULL DEFAULT 0,
  `matched_count` INT NOT NULL DEFAULT 0,
  `unmatched_bank_count` INT NOT NULL DEFAULT 0,
  `unmatched_internal_count` INT NOT NULL DEFAULT 0,
  `duplicate_count` INT NOT NULL DEFAULT 0,
  `amount_variance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `report_payload` LONGTEXT DEFAULT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recon_type` (`reconciliation_type`),
  KEY `idx_recon_status` (`status`),
  KEY `idx_recon_date_range` (`start_date`, `end_date`),
  KEY `idx_recon_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coop_account_enquiries` (
  `id` CHAR(36) NOT NULL,
  `message_reference` VARCHAR(64) NOT NULL,
  `account_number` VARCHAR(32) NOT NULL,
  `no_of_transactions` INT DEFAULT NULL,
  `response_payload` LONGTEXT DEFAULT NULL,
  `message_code` VARCHAR(64) DEFAULT NULL,
  `message_description` TEXT DEFAULT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'pending' COMMENT 'pending, completed, error',
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_ref` (`message_reference`),
  KEY `idx_account` (`account_number`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_api_logs` (
  `id` CHAR(36) NOT NULL,
  `provider` VARCHAR(64) NOT NULL DEFAULT 'coop_bank',
  `environment` VARCHAR(64) NOT NULL,
  `operation` VARCHAR(128) NOT NULL,
  `endpoint` VARCHAR(255) NOT NULL,
  `http_method` VARCHAR(10) NOT NULL,
  `message_reference` VARCHAR(64) DEFAULT NULL,
  `correlation_id` VARCHAR(64) DEFAULT NULL,
  `request_payload_redacted` LONGTEXT DEFAULT NULL,
  `response_payload_redacted` LONGTEXT DEFAULT NULL,
  `http_status` INT UNSIGNED DEFAULT NULL,
  `bank_status` VARCHAR(64) DEFAULT NULL,
  `bank_error_code` VARCHAR(64) DEFAULT NULL,
  `bank_error_message` TEXT DEFAULT NULL,
  `transaction_status` VARCHAR(64) DEFAULT NULL,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `error_stage` VARCHAR(64) DEFAULT NULL,
  `exception_class` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_provider` (`provider`),
  KEY `idx_environment` (`environment`),
  KEY `idx_operation` (`operation`),
  KEY `idx_message_ref` (`message_reference`),
  KEY `idx_correlation_id` (`correlation_id`),
  KEY `idx_success` (`success`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_diagnostic_runs` (
  `id` VARCHAR(36) NOT NULL,
  `diagnostic_id` VARCHAR(64) NOT NULL,
  `test_type` VARCHAR(128) NOT NULL,
  `environment` VARCHAR(32) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `error_category` VARCHAR(64) DEFAULT NULL,
  `http_status` INT DEFAULT NULL,
  `bank_response_code` VARCHAR(64) DEFAULT NULL,
  `bank_response_message` VARCHAR(255) DEFAULT NULL,
  `message_reference` VARCHAR(128) DEFAULT NULL,
  `bank_reference` VARCHAR(128) DEFAULT NULL,
  `duration_ms` INT DEFAULT NULL,
  `endpoint` VARCHAR(255) DEFAULT NULL,
  `http_method` VARCHAR(16) DEFAULT NULL,
  `executed_by` VARCHAR(36) DEFAULT NULL,
  `request_summary` JSON DEFAULT NULL,
  `response_summary` JSON DEFAULT NULL,
  `raw_response` JSON DEFAULT NULL,
  `conclusion` TEXT DEFAULT NULL,
  `human_explanation` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_diagnostic_id` (`diagnostic_id`),
  KEY `idx_test_type` (`test_type`),
  KEY `idx_environment` (`environment`),
  KEY `idx_executed_by` (`executed_by`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backups` (
  `id` CHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` VARCHAR(64) NOT NULL,
  `disk` VARCHAR(64) NOT NULL DEFAULT 'backups',
  `path` VARCHAR(1024) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` VARCHAR(64) NOT NULL,
  `checksum` VARCHAR(255) DEFAULT NULL,
  `checksum_algo` VARCHAR(64) NOT NULL DEFAULT 'sha256',
  `verified_at` DATETIME DEFAULT NULL,
  `verification_status` VARCHAR(64) DEFAULT NULL,
  `verification_details` TEXT DEFAULT NULL,
  `failure_reason` TEXT DEFAULT NULL,
  `application_version` VARCHAR(64) DEFAULT NULL,
  `laravel_version` VARCHAR(64) DEFAULT NULL,
  `database_engine` VARCHAR(64) DEFAULT NULL,
  `database_version` VARCHAR(64) DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_by` CHAR(36) DEFAULT NULL,
  `safety_backup_id` CHAR(36) DEFAULT NULL,
  `restored_from_backup_id` CHAR(36) DEFAULT NULL,
  `is_safety` TINYINT(1) NOT NULL DEFAULT 0,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_backups_status_type` (`status`, `type`),
  KEY `idx_backups_created_by` (`created_by`),
  KEY `idx_backups_is_safety` (`is_safety`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_settings` (
  `id` CHAR(36) NOT NULL,
  `settings` JSON DEFAULT NULL,
  `updated_by` CHAR(36) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_import_batches` (
  `id` CHAR(36) NOT NULL,
  `source_name` TEXT DEFAULT NULL,
  `file_name` TEXT DEFAULT NULL,
  `statement_period` TEXT DEFAULT NULL,
  `total_rows` INT DEFAULT 0,
  `success_count` INT DEFAULT 0,
  `skipped_count` INT DEFAULT 0,
  `failed_count` INT DEFAULT 0,
  `status` TEXT NOT NULL DEFAULT 'processing',
  `created_by` CHAR(36) DEFAULT NULL,
  `error_summary` TEXT DEFAULT NULL,
  `summary` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_member_import_batches_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `member_import_rows` (
  `id` CHAR(36) NOT NULL,
  `batch_id` CHAR(36) NOT NULL,
  `row_number` INT DEFAULT NULL,
  `trans_date` DATE DEFAULT NULL,
  `value_date` DATE DEFAULT NULL,
  `transaction_details` TEXT DEFAULT NULL,
  `reference_no` TEXT DEFAULT NULL,
  `credit` DECIMAL(14,2) DEFAULT 0,
  `debit` DECIMAL(14,2) DEFAULT 0,
  `book_balance` DECIMAL(14,2) DEFAULT 0,
  `extracted_name` TEXT DEFAULT NULL,
  `extracted_phone` TEXT DEFAULT NULL,
  `normalized_phone` TEXT DEFAULT NULL,
  `member_name` TEXT DEFAULT NULL,
  `phone` TEXT DEFAULT NULL,
  `member_id_ref` CHAR(36) DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT 'pending',
  `match_status` TEXT NOT NULL DEFAULT 'unmatched',
  `message` TEXT DEFAULT NULL,
  `raw_payload` JSON DEFAULT NULL,
  `contribution_amount` DECIMAL(14,2) DEFAULT 0,
  `contribution_month` INT DEFAULT NULL,
  `contribution_year` INT DEFAULT NULL,
  `contribution_status` TEXT DEFAULT NULL,
  `contribution_paid_date` DATE DEFAULT NULL,
  `default_password` TEXT DEFAULT NULL,
  `auth_user_created` TINYINT(1) DEFAULT 0,
  `member_dashboard_created` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_member_import_rows_batch_id` (`batch_id`),
  KEY `idx_member_import_rows_member_id_ref` (`member_id_ref`),
  KEY `idx_member_import_rows_normalized_phone` (`normalized_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `beneficiary_import_batches` (
  `id` CHAR(36) NOT NULL,
  `source_name` TEXT DEFAULT NULL,
  `file_name` TEXT DEFAULT NULL,
  `total_rows` INT DEFAULT 0,
  `success_count` INT DEFAULT 0,
  `skipped_count` INT DEFAULT 0,
  `failed_count` INT DEFAULT 0,
  `status` TEXT NOT NULL DEFAULT 'processing',
  `created_by` CHAR(36) DEFAULT NULL,
  `error_summary` TEXT DEFAULT NULL,
  `summary` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_beneficiary_import_batches_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `beneficiary_import_rows` (
  `id` CHAR(36) NOT NULL,
  `batch_id` CHAR(36) NOT NULL,
  `row_number` INT DEFAULT NULL,
  `member_id` CHAR(36) DEFAULT NULL,
  `name` TEXT DEFAULT NULL,
  `relationship` TEXT DEFAULT NULL,
  `allocation` DECIMAL(14,2) DEFAULT 0,
  `phone` TEXT DEFAULT NULL,
  `status` TEXT NOT NULL DEFAULT 'pending',
  `error_message` TEXT DEFAULT NULL,
  `raw_row` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_beneficiary_import_rows_batch_id` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Foreign keys
ALTER TABLE `action_items` ADD CONSTRAINT `fk_action_items_minutes_id` FOREIGN KEY (`minutes_id`) REFERENCES `meeting_minutes` (`id`) ON DELETE CASCADE;
ALTER TABLE `bank_transactions` ADD CONSTRAINT `fk_bank_transactions_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;
ALTER TABLE `beneficiaries` ADD CONSTRAINT `fk_beneficiaries_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `beneficiary_requests` ADD CONSTRAINT `fk_beneficiary_requests_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `beneficiary_requests` ADD CONSTRAINT `fk_beneficiary_requests_beneficiary_id` FOREIGN KEY (`beneficiary_id`) REFERENCES `beneficiaries` (`id`) ON DELETE SET NULL;
ALTER TABLE `contributions` ADD CONSTRAINT `fk_contributions_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `contributions` ADD CONSTRAINT `fk_contributions_payment_id` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL;
ALTER TABLE `conversation_participants` ADD CONSTRAINT `fk_conversation_partici_conversation_id` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE;
ALTER TABLE `documents` ADD CONSTRAINT `fk_documents_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `donation_payment_records` ADD CONSTRAINT `fk_donation_payment_rec_campaign_id` FOREIGN KEY (`campaign_id`) REFERENCES `donation_campaigns` (`id`) ON DELETE SET NULL;
ALTER TABLE `donation_payment_records` ADD CONSTRAINT `fk_donation_payment_rec_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;
ALTER TABLE `events` ADD CONSTRAINT `fk_events_related_member_id` FOREIGN KEY (`related_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;
ALTER TABLE `expenses` ADD CONSTRAINT `fk_expenses_recipient_member_id` FOREIGN KEY (`recipient_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;
ALTER TABLE `meeting_attendance` ADD CONSTRAINT `fk_meeting_attendance_meeting_id` FOREIGN KEY (`meeting_id`) REFERENCES `meeting_minutes` (`id`) ON DELETE CASCADE;
ALTER TABLE `member_access_logs` ADD CONSTRAINT `fk_member_access_logs_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `memo_recipients` ADD CONSTRAINT `fk_memo_recipients_memo_id` FOREIGN KEY (`memo_id`) REFERENCES `memos` (`id`) ON DELETE CASCADE;
ALTER TABLE `memo_recipients` ADD CONSTRAINT `fk_memo_recipients_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `message_reactions` ADD CONSTRAINT `fk_message_reactions_message_id` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;
ALTER TABLE `messages` ADD CONSTRAINT `fk_messages_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;
ALTER TABLE `messages` ADD CONSTRAINT `fk_messages_conversation_id` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE SET NULL;
ALTER TABLE `news_read` ADD CONSTRAINT `fk_news_read_news_id` FOREIGN KEY (`news_id`) REFERENCES `news` (`id`) ON DELETE CASCADE;
ALTER TABLE `operational_payment_records` ADD CONSTRAINT `fk_operational_payment__member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;
ALTER TABLE `payments` ADD CONSTRAINT `fk_payments_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;
ALTER TABLE `payouts` ADD CONSTRAINT `fk_payouts_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `penalties` ADD CONSTRAINT `fk_penalties_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `penalties` ADD CONSTRAINT `fk_penalties_contribution_id` FOREIGN KEY (`contribution_id`) REFERENCES `contributions` (`id`) ON DELETE SET NULL;
ALTER TABLE `penalty_payment_records` ADD CONSTRAINT `fk_penalty_payment_reco_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `penalty_payment_records` ADD CONSTRAINT `fk_penalty_payment_reco_penalty_id` FOREIGN KEY (`penalty_id`) REFERENCES `penalties` (`id`) ON DELETE SET NULL;
ALTER TABLE `penalty_payments` ADD CONSTRAINT `fk_penalty_payments_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
ALTER TABLE `registration_access_links` ADD CONSTRAINT `fk_registration_access__registration_id` FOREIGN KEY (`registration_id`) REFERENCES `member_registrations` (`id`) ON DELETE CASCADE;
ALTER TABLE `registration_fees` ADD CONSTRAINT `fk_registration_fees_registration_id` FOREIGN KEY (`registration_id`) REFERENCES `member_registrations` (`id`) ON DELETE CASCADE;
ALTER TABLE `unmatched_payments` ADD CONSTRAINT `fk_unmatched_payments_payment_id` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
