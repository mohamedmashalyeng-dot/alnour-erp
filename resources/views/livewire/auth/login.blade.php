<div class="card">
    <div class="card-body p-6 sm:p-8">
        <div class="mb-6 text-center">
            <img src="{{ asset('images/brand/logo.png') }}" alt="" class="mx-auto mb-4 h-12 w-auto">
            <div class="mx-auto mb-4 h-1 w-12 rounded-full bg-brand-500"></div>
            <h1 class="text-xl font-bold text-slate-900">{{ app(\App\Modules\Core\Services\Settings::class)->get('company_name') }}</h1>
            <p class="text-sm text-slate-500">{{ __('Sign in to your account') }}</p>
        </div>

        <form wire:submit="login" class="space-y-4">
            <div>
                <label for="email" class="form-label">{{ __('Email') }}</label>
                <input wire:model="email" id="email" type="email" autocomplete="username" required autofocus class="form-input" dir="ltr">
                @error('email') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="form-label">{{ __('Password') }}</label>
                <input wire:model="password" id="password" type="password" autocomplete="current-password" required class="form-input" dir="ltr">
                @error('password') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-brand-600">
                {{ __('Remember me') }}
            </label>
            <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">{{ __('Sign in') }}</span>
                <span wire:loading wire:target="login">{{ __('Signing in...') }}</span>
            </button>
        </form>
    </div>
</div>
