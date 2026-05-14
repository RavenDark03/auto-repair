<?php
declare(strict_types=1);

/**
 * Landing "Why It Sells" modules — consumed by partial + optional app marketing surfaces.
 *
 * @return list<array{kicker:string,title:string,body:string,id?:string}>
 */
function mechanix_landing_modules_definitions(): array
{
    return [
        [
            'id' => 'feat-registration',
            'kicker' => '01',
            'title' => 'Registration to Approval',
            'body' => 'Capture applications with selected plans and optional add-ons, then route them through super admin review before activation.',
        ],
        [
            'id' => 'feat-tiers',
            'kicker' => '02',
            'title' => 'Tier-Based Feature Control',
            'body' => 'Use subscription packaging and feature toggles to give each tenant the right module set without rebuilding the product.',
        ],
        [
            'id' => 'feat-operations',
            'kicker' => '03',
            'title' => 'Operational Workspace',
            'body' => 'Manage customers, vehicles, appointments, jobs, inventory, invoices, and payments inside one tenant-aware dashboard.',
        ],
        [
            'id' => 'feat-billing',
            'kicker' => '04',
            'title' => 'Billing-Ready Direction',
            'body' => 'Prepared to evolve into a live PayMongo workflow once production billing, keys, webhooks, and payment testing are connected.',
        ],
        [
            'id' => 'feat-inventory',
            'kicker' => '05',
            'title' => 'Inventory With AP Direction',
            'body' => 'Track suppliers, purchases, payables, and stock movement while keeping the product modular for smaller shops.',
        ],
        [
            'id' => 'feat-reports',
            'kicker' => '06',
            'title' => 'Reports That Scale',
            'body' => 'Monitor collections, receivables, supplier spending, payables, and low-stock visibility from the same tenant-safe dataset.',
        ],
    ];
}
