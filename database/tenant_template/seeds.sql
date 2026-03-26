-- =============================================================
-- TENANT SEED DATA — Roles, Permissions, Role-Permission Map
-- =============================================================
-- Run AFTER schema.sql. Both reset_and_seed_tenants.php and
-- TenantProvisioningService.seedTenantDefaults() read this file.
-- =============================================================

-- ROLES (id order matters — Admin=1, Provider=2, etc.)
INSERT INTO `roles` (`name`, `description`, `is_system_role`, `created_at`) VALUES
('Admin',        'Full system access',    1, NOW()),
('Provider',     'Doctor / Physician',    1, NOW()),
('Nurse',        'Nursing staff access',  1, NOW()),
('Receptionist', 'Front desk staff',      1, NOW()),
('Pharmacist',   'Pharmacy access',       1, NOW()),
('Patient',      'Patient portal access', 1, NOW());

-- PERMISSIONS
INSERT INTO `permissions` (`permission_key`, `description`, `created_at`) VALUES
('patients.view',           'View patient records',       NOW()),
('patients.create',         'Create new patients',        NOW()),
('patients.edit',           'Edit patient records',       NOW()),
('patients.delete',         'Delete patients',            NOW()),
('appointments.view',       'View appointments',          NOW()),
('appointments.create',     'Book appointments',          NOW()),
('appointments.manage',     'Manage appointment status',  NOW()),
('prescriptions.view',      'View prescriptions',         NOW()),
('prescriptions.create',    'Create prescriptions',       NOW()),
('prescriptions.dispense',  'Dispense prescriptions',     NOW()),
('billing.view',            'View billing',               NOW()),
('billing.create',          'Create invoices',            NOW()),
('billing.manage',          'Manage payments',            NOW()),
('staff.view',              'View staff',                 NOW()),
('staff.manage',            'Manage staff',               NOW()),
('reports.view',            'View reports',               NOW()),
('settings.manage',         'System settings',            NOW()),
('audit.view',              'View audit logs',            NOW());

-- ROLE-PERMISSION MAPPING

-- Admin (role_id=1) gets ALL permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, id, NOW() FROM `permissions`;

-- Provider (role_id=2)
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 2, id, NOW() FROM `permissions`
WHERE `permission_key` IN (
    'patients.view','patients.create','patients.edit',
    'appointments.view','appointments.create','appointments.manage',
    'prescriptions.view','prescriptions.create',
    'billing.view','billing.create',
    'reports.view'
);

-- Nurse (role_id=3)
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 3, id, NOW() FROM `permissions`
WHERE `permission_key` IN (
    'patients.view','patients.create','patients.edit',
    'appointments.view','appointments.create','appointments.manage'
);

-- Receptionist (role_id=4)
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 4, id, NOW() FROM `permissions`
WHERE `permission_key` IN (
    'patients.view','patients.create',
    'appointments.view','appointments.create'
);

-- Pharmacist (role_id=5)
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 5, id, NOW() FROM `permissions`
WHERE `permission_key` IN (
    'prescriptions.view','prescriptions.dispense',
    'patients.view'
);

-- Patient (role_id=6)
INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 6, id, NOW() FROM `permissions`
WHERE `permission_key` IN (
    'appointments.view','appointments.create',
    'prescriptions.view',
    'billing.view'
);
