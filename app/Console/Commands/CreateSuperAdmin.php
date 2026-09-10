<?php
namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperAdmin extends Command
{
    protected $signature = 'superadmin:create {email : Owner email} {--name=System Owner : Display name} {--password= : Password; prompted securely when omitted}';
    protected $description = 'Create or update the owner Super Admin account';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $password = (string) ($this->option('password') ?: $this->secret('Password (min 12 characters)'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->error('Use a valid email and a password of at least 12 characters.');
            return self::FAILURE;
        }
        $admin = Admin::withoutGlobalScopes()->updateOrCreate(['email' => $email], [
            'name' => (string) $this->option('name'),
            'password' => Hash::make($password),
            'super_admin' => true,
            'email_verified_at' => now(),
            'active' => true,
        ]);
        $this->info("Super Admin ready: {$admin->email}");
        return self::SUCCESS;
    }
}
