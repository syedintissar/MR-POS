<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PermissionCatalogue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class InstallCommand extends Command
{
    protected $signature = 'pos:install
        {--name= : full name of the first administrator}
        {--username= : login name of the first administrator}
        {--password= : password (asked, hidden, when omitted)}
        {--skip-migrate : do not run migrations}';

    protected $description = 'First-time setup: migrate the database, load roles/permissions/document numbers and create the first Admin.';

    public function handle(AuditLogger $audit): int
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot connect to the database: '.$e->getMessage());
            $this->line('Check DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env and that MariaDB is running.');

            return self::FAILURE;
        }

        if (! $this->option('skip-migrate')) {
            $this->line('Running migrations ...');
            Artisan::call('migrate', ['--force' => true]);
            $this->line(trim(Artisan::output()) ?: 'Database is up to date.');
        }

        $this->line('Loading roles, permissions and document numbering ...');
        $this->callSilent('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        if (User::query()->activeAdmins()->exists()) {
            $this->info('An administrator already exists. Nothing else to do.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Create the first administrator account.');

        $name = $this->option('name') ?: text('Full name', required: true);
        $username = $this->option('username') ?: text('Username (used to log in)', required: true);
        $pw = $this->option('password') ?: password('Password (at least 8 characters)', required: true);

        $v = Validator::make(
            ['name' => $name, 'username' => $username, 'password' => $pw],
            ['name' => 'required|string|max:100', 'username' => ['required', 'regex:/^[A-Za-z0-9._-]+$/', 'max:50', 'unique:users,username'], 'password' => 'required|string|min:8|max:100'],
        );
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $name, 'username' => strtolower($username), 'password' => $pw, 'is_active' => true]);
        $user->assignRole(PermissionCatalogue::ADMIN);

        $audit->log('user_created', 'users', "First administrator created: {$username}", $user, new: ['username' => $username, 'role' => PermissionCatalogue::ADMIN], user: $user);

        $this->newLine();
        $this->info("Installation complete. Log in as \"{$username}\".");
        $this->line('Next: open Settings (shop details), then Backups (run "Backup Now" and add a second destination).');

        return self::SUCCESS;
    }
}
