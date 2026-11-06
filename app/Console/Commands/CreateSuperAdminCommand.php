<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateSuperAdminCommand extends Command
{
    protected $signature = 'superadmin:create {--email=superadmin@alverstone.com} {--password=SuperAdmin@2026} {--name=Super Administrator}';

    protected $description = 'Create or update the Super Admin user account';

    public function handle(): int
    {
        $email = $this->option('email');
        $password = $this->option('password');
        $name = $this->option('name');

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => '9876543210',
                'password' => Hash::make($password),
                'role' => UserRole::Admin,
                'status' => 'active',
                'must_reset_password' => false,
            ]
        );

        // Also ensure admin@clinic.com has the same password for convenience
        $demoAdmin = User::where('email', 'admin@clinic.com')->first();
        if ($demoAdmin) {
            $demoAdmin->password = Hash::make($password);
            $demoAdmin->status = 'active';
            $demoAdmin->save();
        }

        $this->info("Super Admin account ready:");
        $this->info("Email:    {$user->email}");
        $this->info("Password: {$password}");
        $this->info("Role:     {$user->role->value}");
        $this->info("Status:   {$user->status}");

        return self::SUCCESS;
    }
}
