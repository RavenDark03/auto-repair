-- Ensures tenant_registrations has identity columns expected by admin/register/superadmin (migration 003 subset).
-- Run after backup. Skip any line that errors with "Duplicate column".

ALTER TABLE `tenant_registrations`
  ADD COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `preferred_username`;

ALTER TABLE `tenant_registrations`
  ADD COLUMN `bir_tin` VARCHAR(20) NULL DEFAULT NULL AFTER `password_hash`;

ALTER TABLE `tenant_registrations`
  ADD COLUMN `owner_id_number` VARCHAR(64) NULL DEFAULT NULL AFTER `bir_tin`;

ALTER TABLE `tenant_registrations`
  ADD COLUMN `owner_id_document_path` VARCHAR(500) NULL DEFAULT NULL AFTER `owner_id_number`;

ALTER TABLE `tenant_registrations`
  ADD COLUMN `provisioned_tenant_id` INT(11) NULL DEFAULT NULL AFTER `converted_tenant_id`;
