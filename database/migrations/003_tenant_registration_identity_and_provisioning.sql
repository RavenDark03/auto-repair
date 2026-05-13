-- Owner credentials, tax ID, ID document path, and early tenant shell for post-approval login + payment.
-- Run on production after backup.

ALTER TABLE `tenant_registrations`
  ADD COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `preferred_username`,
  ADD COLUMN `bir_tin` VARCHAR(20) NULL DEFAULT NULL AFTER `password_hash`,
  ADD COLUMN `owner_id_document_path` VARCHAR(500) NULL DEFAULT NULL AFTER `bir_tin`,
  ADD COLUMN `provisioned_tenant_id` INT(11) NULL DEFAULT NULL AFTER `converted_tenant_id`,
  ADD KEY `idx_tr_provisioned_tenant` (`provisioned_tenant_id`);

ALTER TABLE `tenant_registrations`
  ADD CONSTRAINT `fk_tr_provisioned_tenant`
    FOREIGN KEY (`provisioned_tenant_id`) REFERENCES `tenants` (`tenant_id`)
    ON DELETE SET NULL;

ALTER TABLE `tenants`
  MODIFY COLUMN `status` ENUM('pending_payment','active','inactive') NOT NULL DEFAULT 'active';
