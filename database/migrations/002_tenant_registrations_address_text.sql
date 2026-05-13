-- Widen registration address for structured lines + PSGC labels (run once on existing DBs).
ALTER TABLE `tenant_registrations`
    MODIFY COLUMN `address` TEXT NULL DEFAULT NULL;
