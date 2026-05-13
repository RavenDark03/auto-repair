<?php
declare(strict_types=1);

function api_job_status_mobile(string $dbStatus): string {
    return match ($dbStatus) {
        'pending_inspection' => 'Pending inspection',
        'in_repair', 'ongoing' => 'In repair',
        'waiting_for_parts' => 'Waiting for parts',
        'completed' => 'Completed',
        default => 'Pending inspection',
    };
}

function api_job_status_db(string $mobileStatus): string {
    $status = strtolower(trim($mobileStatus));
    $status = str_replace(['-', ' '], '_', $status);

    return match ($status) {
        'pending_inspection', 'pendinginspection' => 'pending_inspection',
        'in_repair', 'inrepair', 'ongoing' => 'in_repair',
        'waiting_for_parts', 'waitingparts', 'waiting_for_part' => 'waiting_for_parts',
        'completed', 'complete' => 'completed',
        default => '',
    };
}

function api_appt_status_mobile(string $dbStatus): string {
    return match ($dbStatus) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'cancelled' => 'Cancelled',
        default => 'Pending',
    };
}

/** @param array<string, mixed> $row */
function api_job_row_to_payload(array $row): array {
    $year = 0;
    $ym = (string) ($row['year_model'] ?? '');
    if (preg_match('/^(\d{4})/', $ym, $m)) {
        $year = (int) $m[1];
    } elseif (ctype_digit($ym)) {
        $year = (int) $ym;
    }

    $updated = (int) ($row['updated_at_ms'] ?? (time() * 1000));

    return [
        'id' => (string) $row['job_id'],
        'customerName' => (string) ($row['customer_name'] ?? ''),
        'customerContact' => (string) ($row['customer_contact'] ?? ''),
        'customerEmail' => (string) ($row['customer_email'] ?? ''),
        'vehicle' => [
            'id' => (string) $row['vehicle_id'],
            'make' => (string) ($row['make'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'plate' => (string) ($row['plate'] ?? ''),
            'year' => $year,
        ],
        'status' => api_job_status_mobile((string) ($row['status'] ?? 'ongoing')),
        'priority' => (string) ($row['priority'] ?? 'normal'),
        'description' => (string) ($row['description'] ?? ''),
        'issueConcern' => (string) (($row['issue_concern'] ?? '') ?: ($row['appointment_concern'] ?? '')),
        'customerVisibleNotes' => (string) ($row['customer_visible_notes'] ?? ''),
        'mechanicId' => $row['mechanic_id'] !== null ? (string) $row['mechanic_id'] : null,
        'mechanicName' => (string) ($row['mechanic_name'] ?? ''),
        'appointmentId' => (string) $row['appointment_id'],
        'appointmentDate' => (string) ($row['appointment_date'] ?? ''),
        'appointmentStatus' => api_appt_status_mobile((string) ($row['appointment_status'] ?? 'pending')),
        'hasInvoice' => !empty($row['has_invoice']),
        'lineItemsSummary' => (string) ($row['line_items_summary'] ?? ''),
        'updatedAtEpochMs' => $updated,
    ];
}
