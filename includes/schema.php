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
}
