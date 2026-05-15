/*
  MECHANIX migration 007 — tenant branding: public services + mobile store URLs.

  Run after backup. "Duplicate column" / "already exists" = skip that statement.
  `ensurePlatformSchema` in includes/schema.php also applies these changes on boot.
*/

ALTER TABLE `tenants`
  ADD COLUMN `mobile_app_ios_url` VARCHAR(500) NULL DEFAULT NULL AFTER `operating_hours`,
  ADD COLUMN `mobile_app_android_url` VARCHAR(500) NULL DEFAULT NULL AFTER `mobile_app_ios_url`;

CREATE TABLE IF NOT EXISTS `tenant_branding_services` (
  `tenant_branding_service_id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `service_name` VARCHAR(150) NOT NULL,
  `service_description` VARCHAR(500) NULL DEFAULT NULL,
  `sort_order` INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`tenant_branding_service_id`),
  KEY `idx_tenant_branding_services_tenant` (`tenant_id`),
  CONSTRAINT `fk_tenant_branding_services_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
