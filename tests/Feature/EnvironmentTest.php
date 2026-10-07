<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemCheck;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

/** The server PC must be set up the way the owner decided. These fail loudly if it drifts. */
class EnvironmentTest extends TestCase
{
    use RefreshesSeededDatabase;

    public function test_php_intl_is_actually_loaded_and_formats_rupees(): void
    {
        $this->assertTrue(extension_loaded('intl'), 'Enable extension=intl in php.ini (and restart Apache).');

        $formatted = (new \NumberFormatter('en_PK', \NumberFormatter::CURRENCY))->formatCurrency(1234.5, 'PKR');
        $this->assertStringContainsString('1,234.50', $formatted);
    }

    public function test_all_required_php_extensions_are_loaded(): void
    {
        foreach (SystemCheck::REQUIRED_EXTENSIONS as $ext) {
            $this->assertTrue(extension_loaded($ext), "PHP extension {$ext} is required.");
        }
    }

    public function test_the_database_server_is_mariadb_11_4_or_newer(): void
    {
        $version = (string) DB::selectOne('SELECT VERSION() AS v')->v;

        $this->assertStringContainsString('MariaDB', $version);
        $this->assertGreaterThanOrEqual(11.4, (float) $version, 'The owner decided on MariaDB 11.4 LTS (10.4 is end-of-life).');
    }

    public function test_the_database_uses_utf8mb4_and_innodb(): void
    {
        $db = config('database.connections.'.config('database.default').'.database');

        $this->assertSame('utf8mb4_unicode_ci', DB::selectOne('SELECT DEFAULT_COLLATION_NAME AS c FROM information_schema.schemata WHERE schema_name = ?', [$db])->c);
        $this->assertSame(0, (int) DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ? AND engine <> 'InnoDB' AND table_type = 'BASE TABLE'", [$db])->n, 'Every table must be InnoDB (transactions, foreign keys).');
    }

    public function test_php_and_the_database_agree_about_the_time_and_it_is_pakistan_time(): void
    {
        $this->assertSame('Asia/Karachi', config('app.timezone'));
        $this->assertSame('Asia/Karachi', date_default_timezone_get());

        $dbNow = strtotime((string) DB::selectOne('SELECT NOW() AS n')->n);
        $this->assertEqualsWithDelta(strtotime(now()->toDateTimeString()), $dbNow, 3, 'PHP and MariaDB clocks must agree, or stored times will be off.');
        $this->assertSame('+05:00', DB::selectOne('SELECT @@session.time_zone AS tz')->tz);
    }

    public function test_every_table_is_covered_by_foreign_keys_where_it_references_users(): void
    {
        $db = config('database.connections.'.config('database.default').'.database');
        $fks = DB::select('SELECT table_name AS t, column_name AS c FROM information_schema.key_column_usage WHERE table_schema = ? AND referenced_table_name = "users"', [$db]);
        $have = collect($fks)->map(fn ($r) => "{$r->t}.{$r->c}")->all();

        foreach (['audit_logs.user_id', 'settings.updated_by', 'backups.triggered_by', 'backup_restores.performed_by'] as $expected) {
            $this->assertContains($expected, $have, "{$expected} must be a real foreign key.");
        }
    }

    public function test_no_money_style_column_uses_float_or_double(): void
    {
        $db = config('database.connections.'.config('database.default').'.database');
        $bad = DB::select("SELECT table_name AS t, column_name AS c, data_type AS d FROM information_schema.columns WHERE table_schema = ? AND data_type IN ('float','double')", [$db]);

        $this->assertSame([], $bad, 'Financial amounts must be DECIMAL, never FLOAT/DOUBLE.');
    }

    public function test_the_system_check_passes_on_this_machine(): void
    {
        $checks = app(SystemCheck::class)->run();

        $failures = collect($checks)->where('status', 'fail')->map(fn ($c) => "{$c['name']}: {$c['detail']}")->all();
        $this->assertSame([], $failures);
    }

    public function test_the_doctor_command_reports_success(): void
    {
        $this->artisan('pos:doctor')->expectsOutputToContain('All required checks passed.')->assertExitCode(0);
    }

    public function test_the_dashboard_shows_system_checks_only_to_people_who_manage_settings(): void
    {
        $admin = User::factory()->withRole('Admin')->create();
        $manager = User::factory()->withRole('Manager')->create();

        $this->actingAs($admin)->get('/')->assertOk()->assertSee('System checks')->assertSee('PHP extension: intl');
        $this->actingAs($manager)->get('/')->assertOk()->assertDontSee('System checks');
    }

    public function test_the_app_works_without_any_internet_dependency_in_its_pages(): void
    {
        $admin = User::factory()->withRole('Admin')->create();

        foreach (['/', '/admin/users', '/admin/roles', '/admin/settings', '/admin/backups', '/admin/audit-logs'] as $uri) {
            $html = $this->actingAs($admin)->get($uri)->assertOk()->getContent();
            preg_match_all('/(?:src|href)="(https?:\/\/[^"]+)"/i', $html, $m);
            $external = array_filter($m[1], fn ($u) => ! str_starts_with($u, config('app.url')) && ! str_contains($u, 'localhost') && ! str_contains($u, '127.0.0.1'));
            $this->assertSame([], array_values($external), "{$uri} loads something from the internet.");
        }
    }
}
