<?php

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute(['table_name' => $tableName]);

    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function ensurePlatformSchema(PDO $pdo): void
{
    static $hasRun = false;

    if ($hasRun) {
        return;
    }

    $hasRun = true;

    if (tableExists($pdo, 'tenants')) {
        if (!columnExists($pdo, 'tenants', 'access_mode')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN access_mode ENUM('full_access','read_only') NOT NULL DEFAULT 'full_access'
                AFTER status
            ");
        }

        if (!columnExists($pdo, 'tenants', 'read_only_source_plan')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN read_only_source_plan VARCHAR(100) DEFAULT NULL
                AFTER access_mode
            ");
        }

        if (!columnExists($pdo, 'tenants', 'bir_tin')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN bir_tin VARCHAR(20) NULL DEFAULT NULL AFTER business_name
            ");
        }

        if (!columnExists($pdo, 'tenants', 'owner_id_number')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN owner_id_number VARCHAR(64) NULL DEFAULT NULL AFTER bir_tin
            ");
        }

        if (!columnExists($pdo, 'tenants', 'owner_id_document_path')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN owner_id_document_path VARCHAR(500) NULL DEFAULT NULL AFTER owner_id_number
            ");
        }
    }

    if (tableExists($pdo, 'tenant_registrations')) {
        if (!columnExists($pdo, 'tenant_registrations', 'password_hash')) {
            if (columnExists($pdo, 'tenant_registrations', 'preferred_username')) {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL AFTER preferred_username
                ");
            } else {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN password_hash VARCHAR(255) NULL DEFAULT NULL
                ");
            }
        }

        if (!columnExists($pdo, 'tenant_registrations', 'bir_tin')) {
            if (columnExists($pdo, 'tenant_registrations', 'password_hash')) {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN bir_tin VARCHAR(20) NULL DEFAULT NULL AFTER password_hash
                ");
            } elseif (columnExists($pdo, 'tenant_registrations', 'preferred_username')) {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN bir_tin VARCHAR(20) NULL DEFAULT NULL AFTER preferred_username
                ");
            } else {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN bir_tin VARCHAR(20) NULL DEFAULT NULL
                ");
            }
        }

        if (!columnExists($pdo, 'tenant_registrations', 'owner_id_number')) {
            if (columnExists($pdo, 'tenant_registrations', 'bir_tin')) {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN owner_id_number VARCHAR(64) NULL DEFAULT NULL AFTER bir_tin
                ");
            } else {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN owner_id_number VARCHAR(64) NULL DEFAULT NULL
                ");
            }
        }

        if (!columnExists($pdo, 'tenant_registrations', 'owner_id_document_path')) {
            if (columnExists($pdo, 'tenant_registrations', 'owner_id_number')) {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN owner_id_document_path VARCHAR(500) NULL DEFAULT NULL AFTER owner_id_number
                ");
            } elseif (columnExists($pdo, 'tenant_registrations', 'bir_tin')) {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN owner_id_document_path VARCHAR(500) NULL DEFAULT NULL AFTER bir_tin
                ");
            } else {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN owner_id_document_path VARCHAR(500) NULL DEFAULT NULL
                ");
            }
        }

        if (!columnExists($pdo, 'tenant_registrations', 'provisioned_tenant_id')) {
            if (columnExists($pdo, 'tenant_registrations', 'converted_tenant_id')) {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN provisioned_tenant_id INT(11) NULL DEFAULT NULL AFTER converted_tenant_id
                ");
            } else {
                $pdo->exec("
                    ALTER TABLE tenant_registrations
                    ADD COLUMN provisioned_tenant_id INT(11) NULL DEFAULT NULL
                ");
            }
        }
    }

    if (tableExists($pdo, 'billing_requests')) {
        if (!columnExists($pdo, 'billing_requests', 'payment_reference_check_status')) {
            $pdo->exec("
                ALTER TABLE billing_requests
                ADD COLUMN payment_reference_check_status VARCHAR(30) DEFAULT 'unchecked'
                AFTER payment_reference
            ");
        }

        if (!columnExists($pdo, 'billing_requests', 'payment_reference_checked_at')) {
            $pdo->exec("
                ALTER TABLE billing_requests
                ADD COLUMN payment_reference_checked_at DATETIME DEFAULT NULL
                AFTER payment_reference_check_status
            ");
        }

        if (!columnExists($pdo, 'billing_requests', 'payment_reference_check_notes')) {
            $pdo->exec("
                ALTER TABLE billing_requests
                ADD COLUMN payment_reference_check_notes TEXT DEFAULT NULL
                AFTER payment_reference_checked_at
            ");
        }

        if (!columnExists($pdo, 'billing_requests', 'payment_reference_checked_by')) {
            $pdo->exec("
                ALTER TABLE billing_requests
                ADD COLUMN payment_reference_checked_by INT(11) DEFAULT NULL
                AFTER payment_reference_check_notes
            ");
        }
    }

    if (tableExists($pdo, 'subscription_plans')) {
        $stmt = $pdo->prepare("
            SELECT plan_id
            FROM subscription_plans
            WHERE plan_name = 'Read-Only'
            LIMIT 1
        ");
        $stmt->execute();

        if (!$stmt->fetchColumn()) {
            $insert = $pdo->prepare("
                INSERT INTO subscription_plans (
                    plan_name,
                    monthly_price,
                    yearly_price,
                    description,
                    is_active
                ) VALUES (
                    'Read-Only',
                    499.00,
                    4990.00,
                    'Access historical records and reports without editing operational data',
                    1
                )
            ");
            $insert->execute();
        }
    }

    if (tableExists($pdo, 'features')) {
        $fidStmt = $pdo->query("SELECT feature_id FROM features WHERE feature_name = 'subscription_center' LIMIT 1");
        $featureId = $fidStmt ? (int) $fidStmt->fetchColumn() : 0;

        if ($featureId <= 0) {
            $pdo->exec("
                INSERT INTO features (feature_name, description)
                VALUES (
                    'subscription_center',
                    'View plan, term window, and subscription history in tenant admin'
                )
            ");
            $featureId = (int) $pdo->lastInsertId();
        }

        if ($featureId > 0 && tableExists($pdo, 'plan_features') && tableExists($pdo, 'subscription_plans')) {
            $planLink = $pdo->prepare('
                INSERT IGNORE INTO plan_features (plan_id, feature_id, is_included)
                SELECT plan_id, :feature_id, 1 FROM subscription_plans
            ');
            $planLink->execute(['feature_id' => $featureId]);
        }
    }
}
