-- =============================================================
-- TENANT SCHEMA — Single Source of Truth
-- =============================================================
-- Both reset_and_seed_tenants.php and TenantProvisioningService
-- read this file to create tenant databases.
-- If you add/modify a table, do it HERE — nowhere else.
-- =============================================================

-- RBAC
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255),
    `is_system_role` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `permission_key` VARCHAR(100) NOT NULL UNIQUE,
    `description` VARCHAR(255),
    `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL,
    UNIQUE KEY `uq_role_perm` (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- USERS (encrypted, DB-level tenant isolation)
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `encrypted_email` TEXT,
    `email_hash` VARCHAR(64),
    `password_hash` VARCHAR(255) NOT NULL,
    `encrypted_full_name` TEXT,
    `encrypted_phone` TEXT,
    `status` VARCHAR(20) DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME,
    `deleted_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- REFRESH TOKENS (with family for rotation)
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(255) NOT NULL,
    `family` VARCHAR(64),
    `expires_at` DATETIME NOT NULL,
    `revoked` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PATIENTS (encrypted)
CREATE TABLE IF NOT EXISTS `patients` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED,
    `encrypted_name` TEXT,
    `name_hash` VARCHAR(64),
    `encrypted_phone` TEXT,
    `phone_hash` VARCHAR(64),
    `encrypted_email` TEXT,
    `email_hash` VARCHAR(64),
    `encrypted_medical_history` TEXT,
    `encrypted_date_of_birth` TEXT,
    `encrypted_gender` TEXT,
    `encrypted_blood_group` TEXT,
    `encrypted_address` TEXT,
    `encrypted_emergency_contact` TEXT,
    `status` VARCHAR(20) DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME,
    `deleted_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STAFF (encrypted)
CREATE TABLE IF NOT EXISTS `staff` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `tenant_id` INT UNSIGNED,
    `encrypted_department` TEXT,
    `encrypted_specialization` TEXT,
    `encrypted_license_number` TEXT,
    `encrypted_notes` TEXT,
    `hire_date` DATE,
    `status` VARCHAR(20) DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME,
    `deleted_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- APPOINTMENTS
CREATE TABLE IF NOT EXISTS `appointments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED,
    `patient_id` INT UNSIGNED NOT NULL,
    `doctor_id` INT UNSIGNED NOT NULL,
    `appointment_time` DATETIME NOT NULL,
    `encrypted_reason` TEXT,
    `status` VARCHAR(30) DEFAULT 'scheduled',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME,
    `deleted_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- APPOINTMENT NOTES (Communication Module)
CREATE TABLE IF NOT EXISTS `appointment_notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED,
    `appointment_id` INT UNSIGNED NOT NULL,
    `author_id` INT UNSIGNED NOT NULL,
    `message_encrypted` TEXT,
    `note_type` VARCHAR(30) DEFAULT 'note',
    `visible_to_role` VARCHAR(30) DEFAULT 'all',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME,
    `deleted_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOTIFICATIONS
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(50) DEFAULT 'system',
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT,
    `is_read` TINYINT(1) DEFAULT 0,
    `read_at` DATETIME,
    `related_entity_type` VARCHAR(50),
    `related_entity_id` INT UNSIGNED,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PRESCRIPTIONS
CREATE TABLE IF NOT EXISTS `prescriptions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED,
    `appointment_id` INT UNSIGNED,
    `patient_id` INT UNSIGNED NOT NULL,
    `provider_id` INT UNSIGNED NOT NULL,
    `pharmacist_id` INT UNSIGNED,
    `encrypted_medicine_name` TEXT,
    `encrypted_dosage` TEXT,
    `encrypted_notes` TEXT,
    `duration_days` INT DEFAULT 7,
    `status` VARCHAR(20) DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- INVOICES (Billing Module)
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `tenant_id` INT UNSIGNED,
    `invoice_number` VARCHAR(30) UNIQUE,
    `patient_id` INT UNSIGNED NOT NULL,
    `provider_id` INT UNSIGNED,
    `appointment_id` INT UNSIGNED,
    `amount` DECIMAL(10,2) DEFAULT 0,
    `subtotal` DECIMAL(10,2) DEFAULT 0,
    `tax` DECIMAL(10,2) DEFAULT 0,
    `discount` DECIMAL(10,2) DEFAULT 0,
    `total` DECIMAL(10,2) DEFAULT 0,
    `total_amount` DECIMAL(10,2) DEFAULT 0,
    `paid_amount` DECIMAL(10,2) DEFAULT 0,
    `status` VARCHAR(30) DEFAULT 'draft',
    `payment_method` VARCHAR(30),
    `paid_at` DATETIME,
    `due_date` DATE,
    `encrypted_notes` TEXT,
    `created_by` INT UNSIGNED,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME,
    `deleted_at` DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AUDIT LOG
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED,
    `tenant_id` INT UNSIGNED,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(100),
    `entity_id` INT UNSIGNED,
    `old_values` JSON,
    `new_values` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- USER SESSIONS
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `tenant_id` INT UNSIGNED,
    `session_id` VARCHAR(255) NOT NULL UNIQUE,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `is_active` TINYINT(1) DEFAULT 1,
    `last_active` DATETIME,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
