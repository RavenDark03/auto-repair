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

function columnType(PDO $pdo, string $tableName, string $columnName): ?string
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
        LIMIT 1
    ");
    $stmt->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    $type = $stmt->fetchColumn();
    return $type !== false ? (string) $type : null;
}

function indexExists(PDO $pdo, string $tableName, string $indexName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
    ");
    $stmt->execute([
        'table_name' => $tableName,
        'index_name' => $indexName,
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

        if (!columnExists($pdo, 'tenants', 'contact_phone')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN contact_phone VARCHAR(50) NULL DEFAULT NULL AFTER owner_id_document_path
            ");
        }

        if (!columnExists($pdo, 'tenants', 'contact_email')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN contact_email VARCHAR(150) NULL DEFAULT NULL AFTER contact_phone
            ");
        }

        if (!columnExists($pdo, 'tenants', 'address')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN address VARCHAR(255) NULL DEFAULT NULL AFTER contact_email
            ");
        }

        if (!columnExists($pdo, 'tenants', 'operating_hours')) {
            $pdo->exec("
                ALTER TABLE tenants
                ADD COLUMN operating_hours TEXT NULL DEFAULT NULL AFTER address
            ");
        }
    }

    if (tableExists($pdo, 'users')) {
        if (!columnExists($pdo, 'users', 'customer_id')) {
            $pdo->exec("
                ALTER TABLE users
                ADD COLUMN customer_id INT(11) NULL DEFAULT NULL AFTER tenant_id
            ");
        }

        if (!indexExists($pdo, 'users', 'idx_users_tenant_customer')) {
            $pdo->exec("
                ALTER TABLE users
                ADD KEY idx_users_tenant_customer (tenant_id, customer_id)
            ");
        }
    }

    if (tableExists($pdo, 'appointments')) {
        if (!columnExists($pdo, 'appointments', 'mechanic_id')) {
            $pdo->exec("
                ALTER TABLE appointments
                ADD COLUMN mechanic_id INT(11) NULL DEFAULT NULL AFTER vehicle_id
            ");
        }

        if (!columnExists($pdo, 'appointments', 'concern')) {
            $pdo->exec("
                ALTER TABLE appointments
                ADD COLUMN concern TEXT NULL DEFAULT NULL AFTER appointment_date
            ");
        }

        if (!columnExists($pdo, 'appointments', 'cancellation_reason')) {
            $pdo->exec("
                ALTER TABLE appointments
                ADD COLUMN cancellation_reason TEXT NULL DEFAULT NULL AFTER status
            ");
        }

        if (!columnExists($pdo, 'appointments', 'cancelled_at')) {
            $pdo->exec("
                ALTER TABLE appointments
                ADD COLUMN cancelled_at DATETIME NULL DEFAULT NULL AFTER cancellation_reason
            ");
        }

        if (!columnExists($pdo, 'appointments', 'cancelled_by')) {
            $pdo->exec("
                ALTER TABLE appointments
                ADD COLUMN cancelled_by INT(11) NULL DEFAULT NULL AFTER cancelled_at
            ");
        }

        if (!indexExists($pdo, 'appointments', 'idx_appointments_tenant_mechanic')) {
            $pdo->exec("
                ALTER TABLE appointments
                ADD KEY idx_appointments_tenant_mechanic (tenant_id, mechanic_id)
            ");
        }
    }

    if (tableExists($pdo, 'jobs')) {
        $jobStatusType = columnType($pdo, 'jobs', 'status');

        if ($jobStatusType !== null && strpos($jobStatusType, 'pending_inspection') === false) {
            $pdo->exec("
                ALTER TABLE jobs
                MODIFY status ENUM('pending_inspection','in_repair','waiting_for_parts','completed','ongoing') NOT NULL DEFAULT 'pending_inspection'
            ");

            $pdo->exec("
                UPDATE jobs
                SET status = 'in_repair'
                WHERE status = 'ongoing'
            ");
        }

        if (!columnExists($pdo, 'jobs', 'priority')) {
            $pdo->exec("
                ALTER TABLE jobs
                ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER mechanic_id
            ");
        }

        if (!columnExists($pdo, 'jobs', 'description')) {
            $pdo->exec("
                ALTER TABLE jobs
                ADD COLUMN description TEXT NULL DEFAULT NULL AFTER priority
            ");
        }

        if (!columnExists($pdo, 'jobs', 'issue_concern')) {
            $pdo->exec("
                ALTER TABLE jobs
                ADD COLUMN issue_concern TEXT NULL DEFAULT NULL AFTER description
            ");
        }

        if (!columnExists($pdo, 'jobs', 'customer_visible_notes')) {
            $pdo->exec("
                ALTER TABLE jobs
                ADD COLUMN customer_visible_notes TEXT NULL DEFAULT NULL AFTER issue_concern
            ");
        }

        if (!columnExists($pdo, 'jobs', 'ready_for_invoice_at')) {
            $pdo->exec("
                ALTER TABLE jobs
                ADD COLUMN ready_for_invoice_at DATETIME NULL DEFAULT NULL AFTER status
            ");
        }

        if (!columnExists($pdo, 'jobs', 'completed_at')) {
            $pdo->exec("
                ALTER TABLE jobs
                ADD COLUMN completed_at DATETIME NULL DEFAULT NULL AFTER ready_for_invoice_at
            ");
        }

        if (!columnExists($pdo, 'jobs', 'updated_at')) {
            $pdo->exec("
                ALTER TABLE jobs
                ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER completed_at
            ");
        }
    }

    if (tableExists($pdo, 'job_status_logs')) {
        if (!columnExists($pdo, 'job_status_logs', 'user_id')) {
            $pdo->exec("
                ALTER TABLE job_status_logs
                ADD COLUMN user_id INT(11) NULL DEFAULT NULL AFTER job_id
            ");
        }

        if (!columnExists($pdo, 'job_status_logs', 'note')) {
            $pdo->exec("
                ALTER TABLE job_status_logs
                ADD COLUMN note TEXT NULL DEFAULT NULL AFTER status
            ");
        }
    }

    if (!tableExists($pdo, 'job_notes')) {
        $pdo->exec("
            CREATE TABLE job_notes (
                note_id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) NOT NULL,
                job_id INT(11) NOT NULL,
                user_id INT(11) NULL DEFAULT NULL,
                note_type ENUM('admin','mechanic','customer_visible','system') NOT NULL DEFAULT 'mechanic',
                note TEXT NOT NULL,
                is_customer_visible TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (note_id),
                KEY idx_job_notes_tenant_job (tenant_id, job_id),
                KEY idx_job_notes_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    if (tableExists($pdo, 'job_parts_used')) {
        if (!columnExists($pdo, 'job_parts_used', 'created_by')) {
            $pdo->exec("
                ALTER TABLE job_parts_used
                ADD COLUMN created_by INT(11) NULL DEFAULT NULL AFTER quantity_used
            ");
        }

        if (!columnExists($pdo, 'job_parts_used', 'created_at')) {
            $pdo->exec("
                ALTER TABLE job_parts_used
                ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by
            ");
        }
    }

    if (!tableExists($pdo, 'inventory_movements')) {
        $pdo->exec("
            CREATE TABLE inventory_movements (
                movement_id INT(11) NOT NULL AUTO_INCREMENT,
                tenant_id INT(11) NOT NULL,
                inventory_id INT(11) NOT NULL,
                job_id INT(11) NULL DEFAULT NULL,
                user_id INT(11) NULL DEFAULT NULL,
                movement_type ENUM('restock','deduct','adjustment','return') NOT NULL,
                quantity_change INT(11) NOT NULL,
                quantity_before INT(11) NULL DEFAULT NULL,
                quantity_after INT(11) NULL DEFAULT NULL,
                reference_type VARCHAR(50) NULL DEFAULT NULL,
                reference_id INT(11) NULL DEFAULT NULL,
                notes TEXT NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (movement_id),
                KEY idx_inventory_movements_tenant_item (tenant_id, inventory_id),
                KEY idx_inventory_movements_job (tenant_id, job_id),
                KEY idx_inventory_movements_created (tenant_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    if (tableExists($pdo, 'invoices')) {
        if (!columnExists($pdo, 'invoices', 'services_subtotal')) {
            $pdo->exec("
                ALTER TABLE invoices
                ADD COLUMN services_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER invoice_no
            ");
        }

        if (!columnExists($pdo, 'invoices', 'parts_subtotal')) {
            $pdo->exec("
                ALTER TABLE invoices
                ADD COLUMN parts_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER services_subtotal
            ");
        }

        if (!columnExists($pdo, 'invoices', 'labor_fee')) {
            $pdo->exec("
                ALTER TABLE invoices
                ADD COLUMN labor_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER parts_subtotal
            ");
        }

        if (!columnExists($pdo, 'invoices', 'inspection_fee')) {
            $pdo->exec("
                ALTER TABLE invoices
                ADD COLUMN inspection_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER labor_fee
            ");
        }

        if (!columnExists($pdo, 'invoices', 'subtotal')) {
            $pdo->exec("
                ALTER TABLE invoices
                ADD COLUMN subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER inspection_fee
            ");
        }

        if (!columnExists($pdo, 'invoices', 'tax_rate')) {
            $pdo->exec("
                ALTER TABLE invoices
                ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER subtotal
            ");
        }

        if (!columnExists($pdo, 'invoices', 'tax_amount')) {
            $pdo->exec("
                ALTER TABLE invoices
                ADD COLUMN tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_rate
            ");
        }
    }

    if (!tableExists($pdo, 'mobile_api_tokens')) {
        $pdo->exec("
            CREATE TABLE mobile_api_tokens (
                token_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT(11) NOT NULL,
                tenant_id INT(11) NOT NULL,
                access_token_hash CHAR(64) NOT NULL,
                refresh_token_hash CHAR(64) NOT NULL,
                access_expires_at DATETIME NOT NULL,
                refresh_expires_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (token_id),
                UNIQUE KEY uq_access_token_hash (access_token_hash),
                UNIQUE KEY uq_refresh_token_hash (refresh_token_hash),
                KEY idx_user_tenant (user_id, tenant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
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
