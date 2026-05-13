/*
  MECHANIX migration 004 — tenant identity on live tenants + registration ID number.

  Run after backup.
  "Duplicate column" = already applied; skip that statement.

  If `tenant_registrations` is missing password_hash / bir_tin / owner_id_document_path /
  provisioned_tenant_id, run migration 003 first (database/migrations/003_...sql).

  owner_id_number is added WITHOUT "AFTER bir_tin" so this runs even when 003 was never applied.
*/

ALTER TABLE `tenant_registrations`
  ADD COLUMN `owner_id_number` VARCHAR(64) NULL DEFAULT NULL;

ALTER TABLE `tenants`
  ADD COLUMN `bir_tin` VARCHAR(20) NULL DEFAULT NULL AFTER `business_name`,
  ADD COLUMN `owner_id_number` VARCHAR(64) NULL DEFAULT NULL AFTER `bir_tin`,
  ADD COLUMN `owner_id_document_path` VARCHAR(500) NULL DEFAULT NULL AFTER `owner_id_number`;
