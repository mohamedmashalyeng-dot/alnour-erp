<?php

namespace App\Modules\Core\Livewire;

use App\Modules\Core\Models\TaxRate;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\Settings;
use App\Modules\Core\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class SettingsPage extends Component
{
    public array $form = [];

    public array $rate = ['name' => '', 'name_en' => '', 'percent' => ''];

    public function mount(Settings $settings): void
    {
        $this->authorize('settings.manage');
        $this->form = [
            'company_name' => (string) $settings->get('company_name'),
            'company_tax_number' => (string) $settings->get('company_tax_number'),
            'invoice_footer' => (string) $settings->get('invoice_footer'),
            'tax_enabled' => $settings->bool('tax_enabled'),
            'prices_include_tax' => $settings->bool('prices_include_tax'),
            'costing_method' => (string) $settings->get('costing_method'),
            'walk_in_customer_enabled' => $settings->bool('walk_in_customer_enabled'),
            'max_discount_percent' => (string) $settings->get('max_discount_percent'),
            'cash_shift_required' => $settings->bool('cash_shift_required'),
            'payment_terms_days' => (string) $settings->get('payment_terms_days'),
            'reorder_lead_days' => (string) $settings->get('reorder_lead_days'),
            'reorder_safety_days' => (string) $settings->get('reorder_safety_days'),
            'offline_oversell_policy' => (string) $settings->get('offline_oversell_policy'),
            'offline_max_age_days' => (string) $settings->get('offline_max_age_days'),
            'loyalty_enabled' => $settings->bool('loyalty_enabled'),
            'loyalty_points_per_unit' => (string) $settings->get('loyalty_points_per_unit'),
            'loyalty_point_value' => money_input((int) $settings->get('loyalty_point_value')),
            'loyalty_min_redeem' => (string) $settings->get('loyalty_min_redeem'),
            'loyalty_max_redeem_percent' => (string) $settings->get('loyalty_max_redeem_percent'),
            'loyalty_silver_threshold' => (string) $settings->get('loyalty_silver_threshold'),
            'loyalty_gold_threshold' => (string) $settings->get('loyalty_gold_threshold'),
            'repair_service_product_id' => (string) $settings->get('repair_service_product_id'),
        ];
    }

    public function save(Settings $settings, AuditLogger $audit): void
    {
        $this->authorize('settings.manage');
        $data = $this->validate([
            'form.company_name' => ['required', 'string', 'max:255'],
            'form.company_tax_number' => ['nullable', 'string', 'max:64'],
            'form.invoice_footer' => ['nullable', 'string', 'max:500'],
            'form.tax_enabled' => ['boolean'],
            'form.prices_include_tax' => ['boolean'],
            'form.costing_method' => ['required', Rule::in(['weighted_average', 'last_purchase'])],
            'form.walk_in_customer_enabled' => ['boolean'],
            'form.max_discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'form.cash_shift_required' => ['boolean'],
            'form.payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'form.reorder_lead_days' => ['required', 'integer', 'min:1', 'max:180'],
            'form.reorder_safety_days' => ['required', 'integer', 'min:0', 'max:180'],
            'form.offline_oversell_policy' => ['required', Rule::in(['review', 'reject'])],
            'form.offline_max_age_days' => ['required', 'integer', 'min:1', 'max:30'],
            'form.loyalty_enabled' => ['boolean'],
            'form.loyalty_points_per_unit' => ['required', 'integer', 'min:0', 'max:1000'],
            'form.loyalty_point_value' => ['required', 'string', 'max:20', function ($a, $v, $fail) {
                if (! Money::isValid((string) $v)) {
                    $fail(__('Invalid amount.'));
                }
            }],
            'form.loyalty_min_redeem' => ['required', 'integer', 'min:1', 'max:1000000'],
            'form.loyalty_max_redeem_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'form.loyalty_silver_threshold' => ['required', 'integer', 'min:1'],
            'form.loyalty_gold_threshold' => ['required', 'integer', 'gt:form.loyalty_silver_threshold'],
            'form.repair_service_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('product_type', 'service')->where('track_stock', false)],
        ])['form'];
        $data['max_discount_percent'] = (int) $data['max_discount_percent'];
        $data['loyalty_point_value'] = Money::parse((string) $data['loyalty_point_value']);
        $data['repair_service_product_id'] = $data['repair_service_product_id'] ? (int) $data['repair_service_product_id'] : null;
        foreach (['payment_terms_days', 'reorder_lead_days', 'reorder_safety_days', 'offline_max_age_days', 'loyalty_points_per_unit', 'loyalty_min_redeem',
            'loyalty_max_redeem_percent', 'loyalty_silver_threshold', 'loyalty_gold_threshold'] as $k) {
            $data[$k] = (int) $data[$k];
        }

        $old = $settings->all();
        foreach ($data as $key => $value) {
            $settings->set($key, $value);
        }
        $audit->log('settings.updated', null, array_intersect_key($old, $data), $data);
        $this->dispatch('toast', message: __('Saved successfully.'));
    }

    public function addRate(AuditLogger $audit): void
    {
        $this->authorize('settings.manage');
        $data = $this->validate([
            'rate.name' => ['required', 'string', 'max:255'],
            'rate.name_en' => ['nullable', 'string', 'max:255'],
            'rate.percent' => ['required', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
        ])['rate'];
        [$int, $frac] = array_pad(explode('.', $data['percent']), 2, '0');
        $bp = (int) $int * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');

        $rate = TaxRate::create(['name' => $data['name'], 'name_en' => $data['name_en'] ?: null, 'rate_bp' => $bp, 'is_default' => ! TaxRate::exists(), 'is_active' => true]);
        $audit->created($rate, 'settings.tax_rate');
        $this->rate = ['name' => '', 'name_en' => '', 'percent' => ''];
    }

    public function makeDefault(int $id, AuditLogger $audit): void
    {
        $this->authorize('settings.manage');
        DB::transaction(function () use ($id) {
            TaxRate::query()->update(['is_default' => false]);
            TaxRate::whereKey($id)->update(['is_default' => true, 'is_active' => true]);
        });
        $audit->log('settings.tax_rate_default', TaxRate::find($id));
    }

    public function toggleRate(int $id, AuditLogger $audit): void
    {
        $this->authorize('settings.manage');
        $rate = TaxRate::findOrFail($id);
        $rate->update(['is_active' => ! $rate->is_active]);
        $audit->log('settings.tax_rate_toggled', $rate, [], ['is_active' => $rate->is_active]);
    }

    public function render()
    {
        return view('livewire.core.settings', ['rates' => TaxRate::orderBy('rate_bp')->get()]);
    }
}
