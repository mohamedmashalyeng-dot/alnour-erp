<?php

namespace App\Modules\Core;

/**
 * Single source of truth for permission names and default role assignments.
 * Seeded by Database\Seeders\RolesAndPermissionsSeeder.
 */
final class Permissions
{
    public const SUPER_ADMIN_ROLE = 'super-admin';

    /** @var array<string, string> permission => group */
    public const ALL = [
        'dashboard.view' => 'general',
        // Branches & users
        'branches.view' => 'branches',
        'branches.manage' => 'branches',
        'branches.access-all' => 'branches',
        'users.view' => 'users',
        'users.manage' => 'users',
        'audit.view' => 'users',
        'settings.manage' => 'users',
        // Catalog
        'categories.manage' => 'catalog',
        'brands.manage' => 'catalog',
        'products.view' => 'catalog',
        'products.create' => 'catalog',
        'products.update' => 'catalog',
        'products.toggle' => 'catalog',
        'products.view-cost' => 'catalog',
        'products.print-labels' => 'catalog',
        // Inventory
        'inventory.view' => 'inventory',
        'inventory.opening-stock' => 'inventory',
        'inventory.receive' => 'inventory',
        'inventory.adjust' => 'inventory',
        'inventory.allow-negative' => 'inventory',
        'inventory.view-movements' => 'inventory',
        'transfers.view' => 'inventory',
        'transfers.create' => 'inventory',
        'transfers.approve' => 'inventory',
        'transfers.dispatch' => 'inventory',
        'transfers.receive' => 'inventory',
        'transfers.cancel' => 'inventory',
        // Suppliers & purchasing (Phase 2)
        'suppliers.view' => 'purchasing',
        'suppliers.manage' => 'purchasing',
        'purchases.view' => 'purchasing',
        'purchases.create' => 'purchasing',
        'purchases.receive' => 'purchasing',
        'purchases.cancel' => 'purchasing',
        'supplier-payments.record' => 'purchasing',
        // Customers & sales (Phase 2)
        'customers.view' => 'sales',
        'customers.manage' => 'sales',
        'sales.view' => 'sales',
        'sales.create' => 'sales',
        'sales.cancel' => 'sales',
        'sales.discount' => 'sales',
        'sales.discount-unlimited' => 'sales',
        'returns.create' => 'sales',
        'returns.approve' => 'sales',
        'refunds.process' => 'sales',
        'payments.view' => 'sales',
        'payments.record' => 'sales',
        'reports.financial' => 'sales',
        // Accounting (Phase 3)
        'accounting.view' => 'accounting',
        'accounting.journal.create' => 'accounting',
        'accounting.journal.post' => 'accounting',
        'accounting.accounts.manage' => 'accounting',
        'accounting.close-period' => 'accounting',
        'accounting.reopen-period' => 'accounting',
        'reports.statements' => 'accounting',
        'cash.view' => 'accounting',
        'cash.shift' => 'accounting',
        'cash.manage' => 'accounting',
        'bank.manage' => 'accounting',
        'bank.reconcile' => 'accounting',
        'expenses.view' => 'accounting',
        'expenses.create' => 'accounting',
        'expenses.approve' => 'accounting',
        'payroll.view' => 'accounting',
        'payroll.manage' => 'accounting',
        // Reporting & analytics (Phase 4)
        'reports.advanced' => 'reports',
        'ai.insights' => 'reports',
        // Imports (Phase 5) — administrators only
        'imports.manage' => 'users',
        // Offline POS (Phase 6)
        'sync.review' => 'sales',
        // HR module
        'hr.view' => 'hr',
        'hr.manage' => 'hr',
        'hr.sensitive' => 'hr',
        'hr.self' => 'hr',
        // Eyewear module
        'prescriptions.view' => 'optical',
        'prescriptions.manage' => 'optical',
        'warranty.view' => 'optical',
        'warranty.claims' => 'optical',
        'warranty.decide' => 'optical',
        'repairs.view' => 'optical',
        'repairs.manage' => 'optical',
        'loyalty.view' => 'optical',
        'loyalty.adjust' => 'optical',
    ];

    /** @var array<string, list<string>> role => permissions ('*' = all) */
    public const ROLES = [
        'admin' => ['*'],
        'branch-manager' => [
            'dashboard.view', 'branches.view', 'users.view',
            'products.view', 'products.create', 'products.update', 'products.toggle', 'products.view-cost', 'products.print-labels',
            'categories.manage', 'brands.manage',
            'inventory.view', 'inventory.opening-stock', 'inventory.receive', 'inventory.adjust', 'inventory.view-movements',
            'transfers.view', 'transfers.create', 'transfers.approve', 'transfers.dispatch', 'transfers.receive', 'transfers.cancel',
            'suppliers.view', 'suppliers.manage', 'purchases.view', 'purchases.create', 'purchases.receive', 'purchases.cancel', 'supplier-payments.record',
            'customers.view', 'customers.manage', 'sales.view', 'sales.create', 'sales.cancel', 'sales.discount',
            'returns.create', 'returns.approve', 'refunds.process', 'payments.view', 'payments.record', 'reports.financial',
            'cash.view', 'cash.shift', 'cash.manage', 'expenses.view', 'expenses.create',
            'reports.advanced', 'ai.insights', 'sync.review',
            'hr.view', 'hr.manage', 'hr.self',
            'prescriptions.view', 'warranty.view', 'warranty.claims', 'warranty.decide', 'repairs.view', 'repairs.manage', 'loyalty.view',
        ],
        'inventory-clerk' => [
            'dashboard.view', 'products.view', 'products.print-labels',
            'inventory.view', 'inventory.receive', 'inventory.view-movements',
            'transfers.view', 'transfers.create', 'transfers.dispatch', 'transfers.receive',
            'suppliers.view', 'purchases.view', 'purchases.receive',
            'hr.self',
        ],
        'cashier' => [
            'dashboard.view', 'products.view', 'inventory.view',
            'customers.view', 'customers.manage', 'sales.view', 'sales.create', 'sales.discount',
            'returns.create', 'payments.record', 'cash.shift',
            'hr.self',
            'prescriptions.view', 'warranty.view', 'warranty.claims', 'repairs.view', 'repairs.manage', 'loyalty.view',
        ],
        'optometrist' => [
            'dashboard.view', 'products.view', 'inventory.view', 'customers.view', 'customers.manage',
            'prescriptions.view', 'prescriptions.manage', 'warranty.view', 'repairs.view', 'loyalty.view', 'hr.self',
        ],
        'accountant' => [
            'dashboard.view', 'branches.view', 'branches.access-all', 'products.view', 'products.view-cost', 'inventory.view', 'inventory.view-movements',
            'suppliers.view', 'purchases.view', 'supplier-payments.record',
            'customers.view', 'sales.view', 'payments.view', 'payments.record', 'reports.financial', 'audit.view',
            'accounting.view', 'accounting.journal.create', 'accounting.journal.post', 'accounting.accounts.manage', 'accounting.close-period',
            'reports.statements', 'cash.view', 'cash.manage', 'bank.manage', 'bank.reconcile', 'expenses.view', 'expenses.create', 'expenses.approve',
            'payroll.view', 'payroll.manage', 'reports.advanced', 'ai.insights',
            'hr.view', 'hr.sensitive', 'hr.self',
        ],
    ];

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        $perms = self::ROLES[$role] ?? [];

        return $perms === ['*'] ? array_keys(self::ALL) : $perms;
    }
}
