@php
    /** Inquiry form. Pass $product (optional) and $defaultType. */
    $site ??= app(\App\Modules\Website\Support\SiteInfo::class);
    $product ??= null;
    $defaultType = old('type', $defaultType ?? ($product ? 'product' : 'exam'));
    $branches = $site->branches();
@endphp
<div id="inquiry" class="scroll-mt-28">
    @if (session('inquiry_sent'))
        <div class="flex items-start gap-3 rounded-2xl bg-emerald-50 p-5 text-emerald-800 ring-1 ring-emerald-200" role="status">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white"><x-icon name="check" class="size-5" /></span>
            <div>
                <div class="font-bold">{{ __('Your request has been received!') }}</div>
                <p class="mt-0.5 text-sm">{{ __('Our team will call you shortly to confirm.') }}</p>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('shop.inquiry') }}" class="space-y-4" data-type-form>
            @csrf
            @if ($product)<input type="hidden" name="product_id" value="{{ $product->id }}">@endif
            {{-- Honeypot: hidden from people, filled by bots. --}}
            <div class="hidden" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            @unless ($product)
                <fieldset>
                    <legend class="form-label">{{ __('How can we help?') }}</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (['exam' => [__('Book an eye exam'), 'eye'], 'contact' => [__('Ask a question'), 'chat']] as $value => [$label, $icon])
                            <label class="choice">
                                <input type="radio" name="type" value="{{ $value }}" class="peer sr-only" @checked($defaultType === $value)>
                                <span class="choice-box"><x-icon :name="$icon" class="size-5" />{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @else
                <input type="hidden" name="type" value="product">
            @endunless

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="form-label" for="inq-name">{{ __('Your name') }}</label>
                    <input id="inq-name" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name" class="form-input">
                    @error('name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="form-label" for="inq-phone">{{ __('Mobile number') }}</label>
                    <input id="inq-phone" name="phone" value="{{ old('phone') }}" required inputmode="tel" autocomplete="tel" dir="ltr" placeholder="01xxxxxxxxx" class="form-input text-start">
                    @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($branches->count() > 1)
                    <div>
                        <label class="form-label" for="inq-branch">{{ __('Nearest branch') }}</label>
                        <select id="inq-branch" name="branch_id" class="form-input">
                            <option value="">{{ __('Any branch') }}</option>
                            @foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->displayName() }}</option>@endforeach
                        </select>
                    </div>
                @elseif ($branches->count() === 1)
                    <input type="hidden" name="branch_id" value="{{ $branches->first()->id }}">
                @endif
                <div @if (! $product) data-show-for="exam" @endif>
                    <label class="form-label" for="inq-date">{{ $product ? __('When would you like to visit?') : __('Preferred date') }}</label>
                    <input id="inq-date" type="date" name="preferred_date" value="{{ old('preferred_date') }}" min="{{ now()->toDateString() }}" class="form-input">
                    @error('preferred_date') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="form-label" for="inq-msg">{{ __('Message') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                <textarea id="inq-msg" name="message" rows="3" maxlength="1000" class="form-input" placeholder="{{ $product ? __('e.g. Is it available in another color?') : __('Tell us what you need…') }}">{{ old('message') }}</textarea>
                @error('message') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            @error('type') <p class="form-error">{{ $message }}</p> @enderror
            <button class="btn-primary w-full rounded-full py-3 text-base">{{ $product ? __('Reserve this product') : __('Send request') }}</button>
            <p class="text-center text-xs text-slate-500">{{ __('We only use your number to contact you about this request.') }}</p>
        </form>
    @endif
</div>
