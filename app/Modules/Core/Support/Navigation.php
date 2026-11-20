<?php

namespace App\Modules\Core\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The sidebar menu. One place for the layout and for the "hide page" preference to agree on
 * which pages exist. Each item is keyed by its route name.
 *
 * Hiding a page is cosmetic only: permissions are still enforced by the page itself.
 */
class Navigation
{
    /** @return list<array{section: ?string, key: string, items: list<array<string, mixed>>}> */
    public static function definition(): array
    {
        return [
            ['section' => null, 'key' => 'home', 'items' => [
                ['route' => 'dashboard', 'icon' => 'home', 'label' => 'Dashboard', 'can' => 'dashboard.view'],
            ]],
            ['section' => 'Sales', 'key' => 'sales', 'items' => [
                ['route' => 'pos', 'icon' => 'cart', 'label' => 'Point of sale', 'can' => 'sales.create'],
                ['route' => 'pos.offline', 'icon' => 'cart', 'label' => 'Offline POS', 'can' => 'sales.create'],
                ['route' => 'offline.review', 'icon' => 'alert', 'label' => 'Offline sync review', 'can' => 'sync.review'],
                ['route' => 'sales.dashboard', 'icon' => 'chart', 'label' => 'Sales dashboard', 'can' => 'reports.financial'],
                ['route' => 'sales.index', 'icon' => 'receipt', 'label' => 'Sales history', 'can' => 'sales.view', 'active' => 'sales.index|sales.show'],
                ['route' => 'returns.index', 'icon' => 'return', 'label' => 'Returns & exchanges', 'can' => 'returns.create', 'active' => 'returns.*'],
                ['route' => 'customers.index', 'icon' => 'user', 'label' => 'Customers', 'can' => 'customers.view', 'active' => 'customers.*'],
                ['route' => 'payments.index', 'icon' => 'cash', 'label' => 'Payments', 'can' => 'payments.view', 'active' => 'payments.*'],
            ]],
            ['section' => 'Optical services', 'key' => 'optical', 'items' => [
                ['route' => 'optical.prescriptions', 'icon' => 'eyeglasses', 'label' => 'Prescriptions', 'can' => 'prescriptions.view', 'active' => 'optical.prescriptions*'],
                ['route' => 'optical.repairs', 'icon' => 'wrench', 'label' => 'Repairs', 'can' => 'repairs.view'],
                ['route' => 'optical.warranties', 'icon' => 'shield', 'label' => 'Warranties & claims', 'can' => 'warranty.view'],
                ['route' => 'optical.loyalty', 'icon' => 'gift', 'label' => 'Loyalty program', 'can' => 'loyalty.view'],
            ]],
            ['section' => 'Analytics', 'key' => 'analytics', 'items' => [
                ['route' => 'reports.executive', 'icon' => 'chart', 'label' => 'Executive dashboard', 'can' => 'reports.advanced'],
                ['route' => 'reports.center', 'icon' => 'clipboard', 'label' => 'Report center', 'can' => 'reports.advanced'],
                ['route' => 'reports.insights', 'icon' => 'star', 'label' => 'Business insights', 'can' => 'ai.insights'],
            ]],
            ['section' => 'Finance', 'key' => 'finance', 'items' => [
                ['route' => 'accounting.dashboard', 'icon' => 'chart', 'label' => 'Financial dashboard', 'can' => 'accounting.view'],
                ['route' => 'cash.index', 'icon' => 'cash', 'label' => 'Cash management', 'canany' => ['cash.view', 'cash.shift', 'cash.manage']],
                ['route' => 'bank.index', 'icon' => 'building', 'label' => 'Bank accounts', 'canany' => ['bank.manage', 'bank.reconcile', 'accounting.view'], 'active' => 'bank.*'],
                ['route' => 'expenses.index', 'icon' => 'receipt', 'label' => 'Expenses', 'can' => 'expenses.view', 'active' => 'expenses.*'],
                ['route' => 'accounting.journal', 'icon' => 'clipboard', 'label' => 'Journal entries', 'can' => 'accounting.view', 'active' => 'accounting.journal*'],
                ['route' => 'accounting.income-statement', 'icon' => 'chart', 'label' => 'Financial reports', 'can' => 'reports.statements',
                    'active' => 'accounting.income-statement|accounting.balance-sheet|accounting.trial-balance|accounting.cash-flow|accounting.ledger|accounting.vat|accounting.aging|accounting.revenue'],
                ['route' => 'accounting.accounts', 'icon' => 'folder', 'label' => 'Chart of accounts', 'can' => 'accounting.view'],
                ['route' => 'accounting.periods', 'icon' => 'clock', 'label' => 'Accounting periods', 'can' => 'accounting.view'],
                ['route' => 'payroll.index', 'icon' => 'users', 'label' => 'Payroll', 'can' => 'payroll.view', 'active' => 'payroll.*'],
            ]],
            ['section' => 'Human resources', 'key' => 'hr', 'items' => [
                ['route' => 'hr.me', 'icon' => 'clock', 'label' => 'My attendance & leave', 'can' => 'hr.self'],
                ['route' => 'hr.employees', 'icon' => 'users', 'label' => 'Employees', 'can' => 'hr.view', 'active' => 'hr.employees*'],
                ['route' => 'hr.attendance', 'icon' => 'clipboard', 'label' => 'Attendance', 'can' => 'hr.view'],
                ['route' => 'hr.leave', 'icon' => 'calendar', 'label' => 'Leave requests', 'can' => 'hr.view'],
                ['route' => 'hr.performance', 'icon' => 'chart', 'label' => 'Employee performance', 'can' => 'hr.view'],
            ]],
            ['section' => 'Purchasing', 'key' => 'purchasing', 'items' => [
                ['route' => 'purchases.index', 'icon' => 'clipboard', 'label' => 'Purchase orders', 'can' => 'purchases.view', 'active' => 'purchases.*'],
                ['route' => 'suppliers.index', 'icon' => 'truck', 'label' => 'Suppliers', 'can' => 'suppliers.view', 'active' => 'suppliers.*'],
            ]],
            ['section' => 'Inventory', 'key' => 'inventory', 'items' => [
                ['route' => 'products.index', 'icon' => 'cube', 'label' => 'Products', 'can' => 'products.view', 'active' => 'products.*'],
                ['route' => 'inventory.index', 'icon' => 'archive', 'label' => 'Stock levels', 'can' => 'inventory.view'],
                ['route' => 'inventory.operations', 'icon' => 'plus', 'label' => 'Stock operations', 'canany' => ['inventory.opening-stock', 'inventory.receive', 'inventory.adjust']],
                ['route' => 'transfers.index', 'icon' => 'arrows', 'label' => 'Stock transfers', 'can' => 'transfers.view', 'active' => 'transfers.*'],
                ['route' => 'inventory.movements', 'icon' => 'clock', 'label' => 'Stock movements', 'can' => 'inventory.view-movements'],
                ['route' => 'categories.index', 'icon' => 'folder', 'label' => 'Categories', 'can' => 'categories.manage'],
                ['route' => 'brands.index', 'icon' => 'star', 'label' => 'Brands', 'can' => 'brands.manage'],
            ]],
            ['section' => 'Website', 'key' => 'website', 'items' => [
                ['route' => 'website.inquiries', 'icon' => 'chat', 'label' => 'Website requests', 'can' => 'website.inquiries'],
                ['route' => 'website.products', 'icon' => 'cube', 'label' => 'Website products', 'can' => 'website.manage'],
                ['route' => 'website.settings', 'icon' => 'cog', 'label' => 'Website settings', 'can' => 'website.manage'],
                ['route' => 'shop.home', 'icon' => 'eye', 'label' => 'View website', 'can' => 'website.manage', 'external' => true],
            ]],
            ['section' => 'Administration', 'key' => 'admin', 'items' => [
                ['route' => 'branches.index', 'icon' => 'building', 'label' => 'Branches', 'can' => 'branches.view', 'active' => 'branches.*'],
                ['route' => 'users.index', 'icon' => 'users', 'label' => 'Users', 'can' => 'users.view'],
                ['route' => 'imports.index', 'icon' => 'archive', 'label' => 'Data import', 'can' => 'imports.manage', 'active' => 'imports.*'],
                ['route' => 'settings.index', 'icon' => 'cog', 'label' => 'Settings', 'can' => 'settings.manage'],
                ['route' => 'audit.index', 'icon' => 'shield', 'label' => 'Audit log', 'can' => 'audit.view'],
            ]],
        ];
    }

    /** Every page key (route name) that can appear in the menu. @return list<string> */
    public static function keys(): array
    {
        return collect(self::definition())->flatMap(fn ($g) => array_column($g['items'], 'route'))->values()->all();
    }

    /**
     * The menu for one user: only the pages they may open, each flagged hidden/active.
     *
     * @return list<array{section: ?string, key: string, items: list<array<string, mixed>>}>
     */
    public static function for(User $user): array
    {
        $hidden = self::hiddenFor($user);
        $groups = [];

        foreach (self::definition() as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! Route::has($item['route'])) {
                    continue;
                }
                $allowed = isset($item['canany']) ? $user->canany($item['canany']) : $user->can($item['can']);
                if (! $allowed) {
                    continue;
                }
                $item['hidden'] = in_array($item['route'], $hidden, true);
                $item['active'] = request()->routeIs(...explode('|', $item['active'] ?? $item['route']));
                $items[] = $item;
            }
            if ($items !== []) {
                $groups[] = ['section' => $group['section'], 'key' => $group['key'], 'items' => $items];
            }
        }

        return $groups;
    }

    /** @return list<string> */
    public static function hiddenFor(User $user): array
    {
        $hidden = $user->ui_preferences['hidden_pages'] ?? [];

        return array_values(array_intersect(is_array($hidden) ? $hidden : [], self::keys()));
    }

    public static function isHidden(User $user, string $route): bool
    {
        return in_array($route, self::hiddenFor($user), true);
    }
}
