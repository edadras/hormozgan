<?php

namespace App\Console\Commands\Museum;

use App\Models\Museum\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MakeAdminCommand extends Command
{
    protected $signature = 'museum:user {email} {--role=admin} {--name=} {--password=}';

    protected $description = 'Create or update a museum staff user and assign a role';

    public function handle(): int
    {
        $role = Role::where('key', $this->option('role'))->firstOrFail();
        $password = $this->option('password') ?: Str::password(16);
        $user = User::firstOrCreate(['email' => $this->argument('email')], [
            'name' => $this->option('name') ?: $this->argument('email'),
            'password' => Hash::make($password),
        ]);
        $user->museumRoles()->syncWithoutDetaching([$role->id]);
        $this->info("{$user->email} has role {$role->key}");
        if (! $this->option('password') && $user->wasRecentlyCreated) {
            $this->warn('Generated password: '.$password);
        }

        return self::SUCCESS;
    }
}
