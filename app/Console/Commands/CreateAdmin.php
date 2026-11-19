<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Core\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates the first super administrator. Works without SSH too: run via a one-off cron job
 * with --email/--password options, then remove the cron entry.
 */
class CreateAdmin extends Command
{
    protected $signature = 'erp:create-admin {--name=} {--email=} {--password=}';

    protected $description = 'Create a super administrator account';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $data = [
            'name' => $this->option('name') ?: $this->ask('Name'),
            'email' => $this->option('email') ?: $this->ask('Email'),
            'password' => $this->option('password') ?: $this->secret('Password (min 10 chars, upper/lower case and digits)'),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(10)->letters()->mixedCase()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create($data + ['locale' => 'ar', 'is_active' => true]);
        $user->assignRole(Permissions::SUPER_ADMIN_ROLE);
        $this->info("Super administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
