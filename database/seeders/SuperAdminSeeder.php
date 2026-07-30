<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * SaaS platform superadmin — NOT a location owner (no users_locations rows).
 *
 * Fill SUPERADMIN_* in .env, then:
 *   php artisan db:seed --class=SuperAdminSeeder
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('engage.superadmin.email')));
        $password = (string) config('engage.superadmin.password');
        $name = trim((string) config('engage.superadmin.name')) ?: 'Super Admin';

        if ($email === '' || $password === '') {
            $this->command?->error('SUPERADMIN_EMAIL and SUPERADMIN_PASSWORD are required in .env');

            return;
        }

        if (strlen($password) < 8) {
            $this->command?->error('SUPERADMIN_PASSWORD must be at least 8 characters.');

            return;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user) {
            if ($user->hasRole(User::ROLE_SUPERADMIN)) {
                $user->name = $name;
                $user->status = User::STATUS_ACTIVE;
                $user->password = $password;
                $user->save();
                // Ensure no location links for SaaS superadmin.
                $user->locationLinks()->delete();
                $this->command?->info("Updated existing superadmin [{$user->id}] {$user->email}");

                return;
            }

            $this->command?->error("Email {$email} already belongs to a non-superadmin user — refusing to overwrite.");

            return;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'roles' => [User::ROLE_SUPERADMIN],
            'status' => User::STATUS_ACTIVE,
            'created_by' => null,
        ]);

        $this->command?->info("Created SaaS superadmin [{$user->id}] {$user->email}");
    }
}
