<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

final class CreateModerationAdmin extends Command
{
    protected $signature = 'marremove:admin-create {email : Email address to add to the configured admin allowlist} {--name= : Display name for the administrator}';

    protected $description = 'Create or reset an allowlisted Marremove administrator using a hidden password prompt';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Provide a valid email address.');

            return self::FAILURE;
        }

        $adminEmails = config('moderation.admin_emails', []);
        if (! is_array($adminEmails) || ! in_array($email, $adminEmails, true)) {
            $this->error('This email is not in MODERATION_ADMIN_EMAILS. Add it to backend/.env, run config:clear, then retry.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user !== null && ! $this->confirm('This user already exists. Reset its password and administrator profile?', false)) {
            $this->comment('No changes made.');

            return self::SUCCESS;
        }

        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $name = trim((string) ($user?->name ?? $this->ask('Administrator name')));
        }
        if ($name === '') {
            $this->error('An administrator name is required.');

            return self::FAILURE;
        }

        $password = $this->secret('Administrator password (at least 12 characters)');
        if (! is_string($password) || mb_strlen($password, 'UTF-8') < 12) {
            $this->error('The password must contain at least 12 characters. No account changes were made.');

            return self::FAILURE;
        }

        $confirmation = $this->secret('Confirm administrator password');
        if (! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('The passwords did not match. No account changes were made.');

            return self::FAILURE;
        }

        $user ??= new User();
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ])->save();

        $this->info('Administrator account is ready for the configured email. The password was not displayed.');

        return self::SUCCESS;
    }
}
