<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Services\AccountResolver;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Treasury\Models\BankAccount;
use Illuminate\Database\Seeder;

/**
 * Default chart of accounts (GCC retail style). Idempotent: existing accounts are never
 * modified, so changes made by the accountant in the UI are preserved on re-runs.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /** [code, name_ar, name_en, type, postable, parent_code] */
    private const ACCOUNTS = [
        ['1000', 'الأصول', 'Assets', 'asset', false, null],
        ['1100', 'الأصول المتداولة', 'Current assets', 'asset', false, '1000'],
        ['1110', 'النقدية بالصناديق', 'Cash on hand', 'asset', false, '1100'],
        ['1120', 'الحسابات البنكية', 'Bank accounts', 'asset', false, '1100'],
        ['1121', 'البنك الرئيسي', 'Main bank account', 'asset', true, '1120'],
        ['1125', 'مدفوعات البطاقات تحت التسوية', 'Card payments clearing', 'asset', true, '1100'],
        ['1130', 'الذمم المدينة (العملاء)', 'Accounts receivable', 'asset', true, '1100'],
        ['1140', 'المخزون', 'Inventory', 'asset', true, '1100'],
        ['1150', 'ضريبة القيمة المضافة - مدخلات', 'VAT input (recoverable)', 'asset', true, '1100'],
        ['1160', 'سلف الموظفين', 'Employee advances', 'asset', true, '1100'],
        ['1170', 'مصروفات مدفوعة مقدمًا', 'Prepaid expenses', 'asset', true, '1100'],
        ['1190', 'حساب جاري الفروع', 'Inter-branch clearing', 'asset', true, '1100'],
        ['1200', 'الأصول غير المتداولة', 'Non-current assets', 'asset', false, '1000'],
        ['1210', 'الأثاث والمعدات', 'Furniture & equipment', 'asset', true, '1200'],
        ['1220', 'مجمع الإهلاك', 'Accumulated depreciation', 'asset', true, '1200'],
        ['2000', 'الالتزامات', 'Liabilities', 'liability', false, null],
        ['2100', 'الالتزامات المتداولة', 'Current liabilities', 'liability', false, '2000'],
        ['2110', 'الذمم الدائنة (الموردون)', 'Accounts payable', 'liability', true, '2100'],
        ['2120', 'أرصدة العملاء الدائنة', 'Customer credit / store credit', 'liability', true, '2100'],
        ['2130', 'مصروفات مستحقة', 'Accrued expenses', 'liability', true, '2100'],
        ['2140', 'ضريبة القيمة المضافة - مخرجات', 'VAT output (payable)', 'liability', true, '2100'],
        ['2150', 'رواتب مستحقة', 'Salaries payable', 'liability', true, '2100'],
        ['2160', 'بضاعة مستلمة لم تُفوتر', 'Goods received not invoiced', 'liability', true, '2100'],
        ['3000', 'حقوق الملكية', 'Equity', 'equity', false, null],
        ['3100', 'رأس المال', "Owner's capital", 'equity', true, '3000'],
        ['3200', 'الأرباح المبقاة', 'Retained earnings', 'equity', true, '3000'],
        ['3400', 'أرصدة افتتاحية', 'Opening balance equity', 'equity', true, '3000'],
        ['4000', 'الإيرادات', 'Revenue', 'revenue', false, null],
        ['4100', 'إيرادات المبيعات', 'Sales revenue', 'revenue', false, '4000'],
        ['4110', 'المبيعات', 'Sales', 'revenue', true, '4100'],
        ['4120', 'مردودات المبيعات', 'Sales returns', 'revenue', true, '4100'],
        ['4200', 'إيرادات أخرى', 'Other income', 'revenue', true, '4000'],
        ['5000', 'تكلفة البضاعة المباعة', 'Cost of goods sold', 'cogs', false, null],
        ['5100', 'تكلفة المبيعات', 'Cost of sales', 'cogs', true, '5000'],
        ['5200', 'عجز وتلف المخزون', 'Inventory shrinkage & write-offs', 'cogs', true, '5000'],
        ['5300', 'زيادة المخزون عند الجرد', 'Inventory count gains', 'cogs', true, '5000'],
        ['6000', 'المصروفات التشغيلية', 'Operating expenses', 'expense', false, null],
        ['6100', 'الإيجار', 'Rent', 'expense', true, '6000'],
        ['6200', 'الرواتب والأجور', 'Salaries & wages', 'expense', true, '6000'],
        ['6210', 'المكافآت', 'Bonuses', 'expense', true, '6000'],
        ['6300', 'الكهرباء', 'Electricity', 'expense', true, '6000'],
        ['6400', 'الإنترنت والاتصالات', 'Internet & telecom', 'expense', true, '6000'],
        ['6500', 'الصيانة', 'Maintenance', 'expense', true, '6000'],
        ['6600', 'النقل والمواصلات', 'Transportation', 'expense', true, '6000'],
        ['6700', 'التسويق', 'Marketing', 'expense', true, '6000'],
        ['6800', 'مصروفات مكتبية', 'Office expenses', 'expense', true, '6000'],
        ['6900', 'مصروفات أخرى', 'Other expenses', 'expense', true, '6000'],
        ['6950', 'رسوم بنكية', 'Bank charges', 'expense', true, '6000'],
        ['6960', 'عجز وزيادة الصندوق', 'Cash over / short', 'expense', true, '6000'],
    ];

    /** [code, name_ar, name_en, account_code] */
    private const EXPENSE_CATEGORIES = [
        ['RENT', 'الإيجار', 'Rent', '6100'],
        ['SALARY', 'الرواتب', 'Salaries', '6200'],
        ['POWER', 'الكهرباء', 'Electricity', '6300'],
        ['NET', 'الإنترنت', 'Internet', '6400'],
        ['MAINT', 'الصيانة', 'Maintenance', '6500'],
        ['TRANS', 'النقل', 'Transportation', '6600'],
        ['MKT', 'التسويق', 'Marketing', '6700'],
        ['OFFICE', 'مصروفات مكتبية', 'Office expenses', '6800'],
        ['OTHER', 'مصروفات أخرى', 'Other expenses', '6900'],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as [$code, $ar, $en, $type, $postable, $parent]) {
            $account = Account::firstOrNew(['code' => $code]);
            if (! $account->exists) {
                $account->fill([
                    'name' => $ar, 'name_en' => $en, 'type' => $type,
                    'normal_balance' => in_array($type, ['asset', 'cogs', 'expense'], true) ? 'debit' : 'credit',
                    'parent_id' => $parent ? Account::where('code', $parent)->value('id') : null,
                    'is_postable' => $postable, 'is_active' => true,
                ]);
                $account->is_system = true;
                $account->save();
            }
        }

        foreach (AccountResolver::KEYS as $key => $code) {
            if (! AccountMapping::where('key', $key)->exists()) {
                AccountMapping::create(['key' => $key, 'account_id' => Account::where('code', $code)->value('id')]);
            }
        }

        foreach (self::EXPENSE_CATEGORIES as [$code, $ar, $en, $account]) {
            ExpenseCategory::firstOrCreate(['code' => $code], ['name' => $ar, 'name_en' => $en, 'account_id' => Account::where('code', $account)->value('id')]);
        }

        if (! BankAccount::exists()) {
            BankAccount::create(['name' => 'البنك الرئيسي', 'account_id' => Account::where('code', '1121')->value('id'), 'is_default' => true]);
        }

        app(AccountResolver::class)->flush();
    }
}
