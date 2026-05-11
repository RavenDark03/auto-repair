<?php
declare(strict_types=1);

function api_job_status_mobile(string $dbStatus): string {
    return match ($dbStatus) {
        'ongoing' => 'Ongoing',
        'completed' => 'Completed',
        default => 'Ongoing',
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
        'vehicle' => [
            'id' => (string) $row['vehicle_id'],
            'make' => (string) ($row['make'] ?? ''),
            'model' => (string) ($row['model'] ?? ''),
            'plate' => (string) ($row['plate'] ?? ''),
            'year' => $year,
        ],
        'status' => api_job_status_mobile((string) ($row['status'] ?? 'ongoing')),
        'mechanicId' => $row['mechanic_id'] !== null ? (string) $row['mechanic_id'] : null,
        'appointmentId' => (string) $row['appointment_id'],
        'appointmentStatus' => api_appt_status_mobile((string) ($row['appointment_status'] ?? 'pending')),
        'hasInvoice' => !empty($row['has_invoice']),
        'lineItemsSummary' => (string) ($row['line_items_summary'] ?? ''),
        'updatedAtEpochMs' => $updated,
    ];
}
