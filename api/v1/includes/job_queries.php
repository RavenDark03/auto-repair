<?php
declare(strict_types=1);

function api_job_select_sql(): string {
    return '
        j.job_id,
        j.tenant_id,
        j.appointment_id,
        j.mechanic_id,
        j.status,
        a.status AS appointment_status,
        a.vehicle_id,
        c.name AS customer_name,
        v.make,
        v.model,
        v.plate,
        v.year_model,
        (SELECT COUNT(*) FROM invoices i WHERE i.job_id = j.job_id AND i.tenant_id = j.tenant_id) AS has_invoice,
        COALESCE((
            SELECT GROUP_CONCAT(CONCAT(js.service_name, \' (\', js.quantity, \')\') ORDER BY js.job_service_id SEPARATOR \', \')
            FROM job_services js
            WHERE js.job_id = j.job_id AND js.tenant_id = j.tenant_id
        ), \'\') AS line_items_summary,
        COALESCE((
            SELECT MAX(UNIX_TIMESTAMP(sl.changed_at)) * 1000
            FROM job_status_logs sl
            WHERE sl.job_id = j.job_id AND sl.tenant_id = j.tenant_id
        ), UNIX_TIMESTAMP(NOW()) * 1000) AS updated_at_ms
    ';
}
