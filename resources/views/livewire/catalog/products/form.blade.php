<div>
    <x-page-header :title="$productId ? __('Edit product') : __('New product')">
        <x-slot:actions>
            <a href="{{ $productId ? route('products.show', $productId) : route('products.index') }}" class="btn-secondary">{{ __('Cancel') }}</a>
        </x-slot:actions>
    </x-page-header>

    <form wire:submit="save" class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            <div class="card card-body">
                <h2 class="mb-4 font-semibold">{{ __('Basic information') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="form-label">{{ __('Product name (Arabic)') }} *</label>
                        <input wire:model="form.name" class="form-input">
                        @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="form-label">{{ __('Product name (English)') }}</label>
                        <input wire:model="form.name_en" class="form-input" dir="ltr">
                        @error('form.name_en') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="form-label">{{ __('Product type') }} *</label>
                        <select wire:model.live="form.product_type" class="form-input">
                            @foreach ($types as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                        @error('form.product_type') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="form-label">{{ __('Category') }}</label>
                        <select wire:model="form.category_id" class="form-input">
                            <option value="">—</option>
                            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->displayName() }}</option>@endforeach
                        </select>
                        @error('form.category_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="form-label">{{ __('Brand') }}</label>
                        <select wire:model="form.brand_id" class="form-input">
                            <option value="">—</option>
                            @foreach ($brands as $b)<option value="{{ $b->id }}">{{ $b->displayName() }}</option>@endforeach
                        </select>
                        @error('form.brand_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="form-label">{{ __('Model') }}</label>
                        <input wire:model="form.model" class="form-input" dir="ltr">
                    </div>
                    <div>
                        <label class="form-label">{{ __('Color') }}</label>
                        <input wire:model="form.color" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">{{ __('Size') }}</label>
                        <input wire:model="form.size" class="form-input" dir="ltr" placeholder="52-18-140">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="form-label">{{ __('Description') }}</label>
                        <textarea wire:model="form.description" rows="3" class="form-input"></textarea>
                        @error('form.description') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="card card-body">
                <h2 class="mb-4 font-semibold">{{ __('Eyewear specifications') }}</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label class="form-label">{{ __('Frame type') }}</label>
                        <select wire:model="form.frame_type" class="form-input">
                            <option value="">—</option>
                            @foreach ($frameTypes as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">{{ __('Frame material') }}</label>
                        <select wire:model="form.frame_material" class="form-input">
                            <option value="">—</option>
                            @foreach ($materials as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">{{ __('Lens type') }}</label>
                        <select wire:model="form.lens_type" class="form-input">
                            <option value="">—</option>
                            @foreach ($lensTypes as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">{{ __('Gender') }}</label>
                        <select wire:model="form.gender" class="form-input">
                            <option value="">—</option>
                            @foreach ($genders as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    @foreach ($specKeys as $key)
                        <div wire:key="spec-{{ $key }}">
                            <label class="form-label">{{ __('spec.'.$key) }}</label>
                            <input wire:model="form.specifications.{{ $key }}" class="form-input" dir="ltr" maxlength="64">
                            @error('form.specifications.'.$key) <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card card-body">
                <h2 class="mb-4 font-semibold">{{ __('Images') }}</h2>
                <div class="flex flex-wrap gap-3">
                    @foreach ($images as $img)
                        <div class="relative" wire:key="img-{{ $img->id }}">
                            <img src="{{ $img->url() }}" alt="" class="size-24 rounded-lg border object-cover">
                            @if ($img->is_primary)<span class="badge absolute start-1 top-1 bg-brand-600 text-white">{{ __('Primary') }}</span>@endif
                            <button type="button" wire:click="removeImage({{ $img->id }})" wire:confirm="{{ __('Remove this image?') }}" class="absolute end-1 top-1 rounded-full bg-white p-1 text-rose-600 shadow"><x-icon name="trash" class="size-4" /></button>
                        </div>
                    @endforeach
                    @foreach ($uploads as $i => $file)
                        <div class="relative" wire:key="up-{{ $i }}">
                            @if (method_exists($file, 'isPreviewable') && $file->isPreviewable())
                                <img src="{{ $file->temporaryUrl() }}" alt="" class="size-24 rounded-lg border object-cover opacity-80">
                            @endif
                            <button type="button" wire:click="removeUpload({{ $i }})" class="absolute end-1 top-1 rounded-full bg-white p-1 text-rose-600 shadow"><x-icon name="x" class="size-4" /></button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3">
                    <input type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp" class="text-sm">
                    <div wire:loading wire:target="uploads" class="text-xs text-slate-500">{{ __('Uploading...') }}</div>
                    <p class="mt-1 text-xs text-slate-500">{{ __('JPG, PNG or WEBP, up to 2 MB each.') }}</p>
                    @error('uploads') <p class="form-error">{{ $message }}</p> @enderror
                    @error('uploads.*') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card card-body space-y-4">
                <h2 class="font-semibold">{{ __('Codes') }}</h2>
                <div>
                    <label class="form-label">{{ __('SKU') }}</label>
                    <input wire:model="form.sku" class="form-input font-mono" dir="ltr" placeholder="{{ __('Auto-generated if empty') }}">
                    @error('form.sku') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Barcode') }}</label>
                    <input wire:model="form.barcode" class="form-input font-mono" dir="ltr" placeholder="{{ __('Auto-generated if empty') }}">
                    @error('form.barcode') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('QR code value') }}</label>
                    <input wire:model="form.qr_code" class="form-input font-mono" dir="ltr" placeholder="{{ __('Defaults to SKU') }}">
                    @error('form.qr_code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="card card-body space-y-4">
                <h2 class="font-semibold">{{ __('Pricing') }}</h2>
                <div>
                    <label class="form-label">{{ __('Purchase price') }} *</label>
                    <input wire:model="form.purchase_price" inputmode="decimal" class="form-input" dir="ltr">
                    @error('form.purchase_price') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Selling price') }} *</label>
                    <input wire:model="form.selling_price" inputmode="decimal" class="form-input" dir="ltr">
                    @error('form.selling_price') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label">{{ __('Tax rate') }}</label>
                    <select wire:model="form.tax_rate_id" class="form-input">
                        <option value="">{{ __('Default') }}</option>
                        @foreach ($taxRates as $t)<option value="{{ $t->id }}">{{ $t->name }} ({{ $t->percentLabel() }})</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">{{ __('Default supplier') }}</label>
                    <select wire:model="form.supplier_id" class="form-input">
                        <option value="">—</option>
                        @foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                </div>
            </div>

            <div class="card card-body space-y-3">
                <div><label class="form-label">{{ __('Warranty (months, 0 = none)') }}</label><input type="number" min="0" max="120" wire:model="form.warranty_months" class="form-input max-w-[8rem]">@error('form.warranty_months') <p class="form-error">{{ $message }}</p> @enderror</div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.track_stock" class="rounded border-slate-300 text-brand-600"> {{ __('Track stock for this product') }}</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.is_active" class="rounded border-slate-300 text-brand-600"> {{ __('Active') }}</label>
            </div>

            <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">{{ __('Save product') }}</button>
        </div>
    </form>
</div>
