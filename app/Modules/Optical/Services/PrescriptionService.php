<?php

namespace App\Modules\Optical\Services;

use App\Modules\Branches\Services\BranchAccess;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Models\Attachment;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DocumentNumberGenerator;
use App\Modules\Customers\Models\Customer;
use App\Modules\Optical\Models\Prescription;
use App\Modules\Optical\Support\Diopter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Prescriptions are medical records: restricted to prescriptions.view / prescriptions.manage, never edited
 * (a correction is a new prescription; the old one is voided with a reason), attachments are private.
 */
class PrescriptionService
{
    /** Clinical ranges (hundredths of a dioptre / tenths of a mm). */
    public const RANGES = [
        'sph' => [-3000, 3000], 'cyl' => [-1000, 1000], 'add' => [50, 400],
        'pd' => [400, 800], 'pd_mono' => [200, 400], 'bc' => [700, 1000], 'dia' => [1200, 1600],
    ];

    public function __construct(private BranchAccess $branches, private DocumentNumberGenerator $numbers, private AuditLogger $audit) {}

    /**
     * $input holds the values as typed ("-2.25", "63.5"); they are parsed to integers here.
     */
    public function create(Customer $customer, array $input, ?UploadedFile $file = null): Prescription
    {
        Gate::authorize('prescriptions.manage');
        $branch = $this->branches->authorizeOperational((int) ($input['branch_id'] ?? 0));
        if ($customer->is_walk_in || ! $customer->is_active) {
            throw ValidationException::withMessages(['customer_id' => __('Prescriptions need a registered, active customer.')]);
        }
        $base = Validator::make($input, [
            'type' => ['required', Rule::in(['spectacles', 'contact_lens'])],
            'examined_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expires_on' => ['required', 'date_format:Y-m-d', 'after:examined_on'],
            'examiner_name' => ['nullable', 'string', 'max:255'],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $values = $this->parseValues($input, $base['type']);
        if ($file) {
            Validator::make(['file' => $file], ['file' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']])->validate();
        }

        return DB::transaction(function () use ($customer, $branch, $base, $values, $file) {
            $rx = Prescription::create($base + $values + [
                'number' => $this->numbers->next('prescription', $branch->id, $branch->code),
                'customer_id' => $customer->id, 'branch_id' => $branch->id, 'status' => 'active', 'created_by' => auth()->id(),
            ]);
            if ($file) {
                $this->storeFile($rx, $file);
            }
            $this->audit->log('prescription.created', $rx, [], ['number' => $rx->number, 'customer_id' => $customer->id], $branch->id);

            return $rx;
        });
    }

    public function void(Prescription $rx, string $reason): Prescription
    {
        Gate::authorize('prescriptions.manage');
        $this->branches->authorize($rx->branch_id);
        if (trim($reason) === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['void_reason' => __('A reason is required (max 255 characters).')]);
        }
        if ($rx->status !== 'active') {
            throw ValidationException::withMessages(['void_reason' => __('This prescription is already void.')]);
        }
        $rx->forceFill(['status' => 'void', 'void_reason' => $reason, 'voided_by' => auth()->id()])->save();
        $this->audit->log('prescription.voided', $rx, [], ['reason' => $reason], $rx->branch_id);

        return $rx;
    }

    public function attach(Prescription $rx, UploadedFile $file): Attachment
    {
        Gate::authorize('prescriptions.manage');
        $this->branches->authorize($rx->branch_id);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']])->validate();

        return $this->storeFile($rx, $file);
    }

    private function storeFile(Prescription $rx, UploadedFile $file): Attachment
    {
        $ext = match ($file->getMimeType()) {
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };
        $path = $file->storeAs('attachments/prescriptions/'.$rx->id, Str::random(40).'.'.$ext, 'local');
        $attachment = $rx->attachments()->create([
            'disk' => 'local', 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => auth()->id(),
        ]);
        $this->audit->log('prescription.attachment_added', $rx, [], ['attachment' => $attachment->id], $rx->branch_id);

        return $attachment;
    }

    /** Parse and validate the optical values; returns integer columns. */
    public function parseValues(array $in, string $type): array
    {
        $errors = [];
        $out = [];
        foreach (['od', 'os'] as $eye) {
            foreach (['sph', 'cyl', 'add'] as $f) {
                $key = "{$eye}_{$f}";
                $v = Diopter::parse($in[$key] ?? null);
                if ($v === false) {
                    $errors[$key] = __('Invalid value.');

                    continue;
                }
                if ($v !== null) {
                    [$min, $max] = self::RANGES[$f];
                    if ($v < $min || $v > $max) {
                        $errors[$key] = __('Out of range (:min to :max).', ['min' => Diopter::format($min), 'max' => Diopter::format($max)]);
                    } elseif (! Diopter::isQuarterStep($v)) {
                        $errors[$key] = __('Use steps of 0.25.');
                    }
                }
                $out[$key] = $v;
            }
            $axisRaw = trim((string) ($in["{$eye}_axis"] ?? ''));
            $axis = $axisRaw === '' ? null : (ctype_digit($axisRaw) ? (int) $axisRaw : false);
            if ($axis === false || ($axis !== null && ($axis < 1 || $axis > 180))) {
                $errors["{$eye}_axis"] = __('Axis must be a whole number from 1 to 180.');
                $axis = null;
            }
            $cyl = $out["{$eye}_cyl"] ?? null;
            if ($cyl && $axis === null && ! isset($errors["{$eye}_axis"])) {
                $errors["{$eye}_axis"] = __('Axis is required when cylinder is set.');
            }
            if (! $cyl && $axis !== null) {
                $errors["{$eye}_axis"] = __('Axis needs a cylinder value.');
            }
            $out["{$eye}_axis"] = $axis;
            if (($out["{$eye}_sph"] ?? null) === null && ! isset($errors["{$eye}_sph"])) {
                $errors["{$eye}_sph"] = __('Sphere is required (use 0.00 for plano).');
            }
        }

        foreach (['pd' => 'pd', 'pd_right' => 'pd_mono', 'pd_left' => 'pd_mono'] as $key => $range) {
            $v = Diopter::parse($in[$key] ?? null, 1);
            if ($v === false || ($v !== null && ($v < self::RANGES[$range][0] || $v > self::RANGES[$range][1]))) {
                $errors[$key] = __('Out of range (:min to :max).', ['min' => Diopter::formatTenths(self::RANGES[$range][0]), 'max' => Diopter::formatTenths(self::RANGES[$range][1])]);
                $v = null;
            }
            $out[$key] = $v;
        }
        if (($out['pd_right'] === null) !== ($out['pd_left'] === null) && ! isset($errors['pd_right'], $errors['pd_left'])) {
            $errors['pd_left'] = __('Enter both monocular PD values or neither.');
        }
        if ($type === 'spectacles' && $out['pd'] === null && $out['pd_right'] === null && ! isset($errors['pd'])) {
            $errors['pd'] = __('PD is required for spectacles.');
        }

        $out['cl_base_curve'] = $out['cl_diameter'] = null;
        if ($type === 'contact_lens') {
            foreach (['cl_base_curve' => 'bc', 'cl_diameter' => 'dia'] as $key => $range) {
                $v = Diopter::parse($in[$key] ?? null);
                if ($v === null || $v === false || $v < self::RANGES[$range][0] || $v > self::RANGES[$range][1]) {
                    $errors[$key] = __('Out of range (:min to :max).', ['min' => Diopter::format(self::RANGES[$range][0], false), 'max' => Diopter::format(self::RANGES[$range][1], false)]);
                }
                $out[$key] = $v ?: null;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Can this lens product be made for this prescription? Uses the lens specification ranges
     * (sph_min/sph_max/cyl_min/cyl_max/add_max, in dioptres as entered on the product).
     *
     * @return list<string> problems (empty = compatible)
     */
    public function lensProblems(Prescription $rx, Product $lens): array
    {
        if ($lens->product_type !== ProductType::OphthalmicLens) {
            return [];
        }
        $spec = $lens->specifications ?? [];
        $limit = function (string $key) use ($spec): ?int {
            $v = Diopter::parse(isset($spec[$key]) ? (string) $spec[$key] : null);

            return is_int($v) ? $v : null;
        };
        $problems = [];
        foreach (['od' => __('Right eye (OD)'), 'os' => __('Left eye (OS)')] as $eye => $label) {
            $sph = $rx->{$eye.'_sph'};
            $cyl = $rx->{$eye.'_cyl'};
            $add = $rx->{$eye.'_add'};
            if ($sph !== null && (($min = $limit('sph_min')) !== null && $sph < $min || ($max = $limit('sph_max')) !== null && $sph > $max)) {
                $problems[] = __(':eye: sphere :v is outside the lens range.', ['eye' => $label, 'v' => Diopter::format($sph)]);
            }
            if ($cyl !== null && (($min = $limit('cyl_min')) !== null && $cyl < $min || ($max = $limit('cyl_max')) !== null && $cyl > $max)) {
                $problems[] = __(':eye: cylinder :v is outside the lens range.', ['eye' => $label, 'v' => Diopter::format($cyl)]);
            }
            if ($add !== null && ($max = $limit('add_max')) !== null && $add > $max) {
                $problems[] = __(':eye: addition :v is above the lens maximum.', ['eye' => $label, 'v' => Diopter::format($add)]);
            }
        }

        return $problems;
    }
}
