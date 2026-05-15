/*
  MECHANIX migration 008 — tenant_branding: public slug, logo path, theme colors.

  Run after backup. ensurePlatformSchema in includes/schema.php applies equivalent DDL on boot.
*/

CREATE TABLE IF NOT EXISTS `tenant_branding` (
  `tenant_id` INT NOT NULL,
  `public_slug` VARCHAR(63) NOT NULL,
  `logo_path` VARCHAR(500) NULL DEFAULT NULL,
  `primary_color` VARCHAR(7) NOT NULL DEFAULT '#f5a524',
  `accent_color` VARCHAR(7) NOT NULL DEFAULT '#ffc04a',
  `background_color` VARCHAR(7) NOT NULL DEFAULT '#f8f9fa',
  `text_color` VARCHAR(7) NOT NULL DEFAULT '#1a1a1a',
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`),
  UNIQUE KEY `uq_tenant_branding_public_slug` (`public_slug`),
  CONSTRAINT `fk_tenant_branding_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
