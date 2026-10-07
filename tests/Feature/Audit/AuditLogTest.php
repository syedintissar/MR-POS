<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshesSeededDatabase;

    private function logger(): AuditLogger
    {
        return app(AuditLogger::class);
    }

    public function test_the_database_refuses_to_update_or_delete_audit_rows(): void
    {
        $row = $this->logger()->log('test_event', 'testing', 'first');

        try {
            DB::table('audit_logs')->where('id', $row->id)->update(['description' => 'tampered']);
            $this->fail('UPDATE on audit_logs should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('audit_logs')->where('id', $row->id)->delete();
            $this->fail('DELETE on audit_logs should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame('first', DB::table('audit_logs')->where('id', $row->id)->value('description'));
    }

    public function test_the_application_model_refuses_updates_and_deletes_too(): void
    {
        $row = $this->logger()->log('test_event', 'testing', 'x');

        $this->expectException(\LogicException::class);
        $row->forceFill(['description' => 'changed'])->save();
    }

    public function test_deleting_through_the_model_is_refused(): void
    {
        $row = $this->logger()->log('test_event', 'testing', 'x');

        $this->expectException(\LogicException::class);
        $row->delete();
    }

    public function test_entries_are_hash_chained_and_the_chain_verifies(): void
    {
        $a = $this->logger()->log('one', 'testing', 'a');
        $b = $this->logger()->log('two', 'testing', 'b', new: ['k' => 'v']);
        $c = $this->logger()->log('three', 'testing', 'c');

        $this->assertSame($a->hash, $b->prev_hash);
        $this->assertSame($b->hash, $c->prev_hash);
        $this->assertSame(64, strlen($c->hash));
        $this->assertNull($this->logger()->verifyChain());
    }

    public function test_a_row_inserted_behind_the_applications_back_breaks_the_chain(): void
    {
        $this->logger()->log('one', 'testing', 'a');

        // Someone with database access appends a forged row with a made-up hash.
        DB::table('audit_logs')->insert([
            'event' => 'forged', 'module' => 'testing', 'description' => 'forged', 'prev_hash' => str_repeat('0', 64),
            'hash' => str_repeat('a', 64), 'created_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $problem = $this->logger()->verifyChain();
        $this->assertNotNull($problem);
        $this->assertStringContainsString('Chain broken', $problem);
    }

    public function test_a_forged_hash_with_the_right_predecessor_is_still_detected(): void
    {
        $a = $this->logger()->log('one', 'testing', 'a');

        DB::table('audit_logs')->insert([
            'event' => 'forged', 'module' => 'testing', 'description' => 'forged', 'prev_hash' => $a->hash,
            'hash' => str_repeat('b', 64), 'created_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        $this->assertStringContainsString('altered', (string) $this->logger()->verifyChain());
    }

    public function test_passwords_and_tokens_are_masked(): void
    {
        $row = $this->logger()->log('x', 'testing', 'masking', new: [
            'username' => 'ali', 'password' => 'hunter2', 'nested' => ['api_token' => 'abc', 'ok' => 1], 'remember_token' => 'zzz',
        ]);

        $json = (string) DB::table('audit_logs')->where('id', $row->id)->value('new_values');
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('abc', $json);
        $this->assertStringNotContainsString('zzz', $json);
        $this->assertStringContainsString('ali', $json);
        $this->assertStringContainsString('[masked]', $json);
    }

    public function test_every_entry_is_also_written_to_the_daily_jsonl_mirror(): void
    {
        $row = $this->logger()->log('mirrored', 'testing', 'copy me');

        $file = config('mrpos.audit_mirror_path').DIRECTORY_SEPARATOR.'audit-'.now()->format('Y-m-d').'.jsonl';
        $this->assertFileExists($file);

        $last = json_decode((string) collect(file($file, FILE_IGNORE_NEW_LINES))->last(), true);
        $this->assertSame($row->hash, $last['hash']);
        $this->assertSame('mirrored', $last['event']);
    }

    public function test_the_acting_user_and_request_details_are_recorded(): void
    {
        $user = User::factory()->withRole('Admin')->create();

        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.168.1.20'])->put('/admin/settings/sales', [
            'sales_max_discount_percent' => '9', 'sales_void_window_hours' => 24, 'sales_store_credit_enabled' => 1, 'sales_round_to' => '0',
        ]);

        $row = AuditLog::query()->where('event', 'settings_changed')->latest('id')->first();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('192.168.1.20', $row->ip_address);
        $this->assertStringContainsString('/admin/settings/sales', (string) $row->url);
    }

    public function test_the_audit_viewer_is_for_admins_only_and_filters(): void
    {
        $this->logger()->log('needle_event', 'testing', 'find the needle');
        $this->logger()->log('other_event', 'testing', 'haystack');

        $this->actingAs(User::factory()->withRole('Manager')->create())->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs(User::factory()->withRole('Cashier')->create())->get('/admin/audit-logs')->assertForbidden();

        $admin = User::factory()->withRole('Admin')->create();
        $this->actingAs($admin)->get('/admin/audit-logs?q=needle')->assertOk()->assertSee('find the needle')->assertDontSee('haystack');
        $this->actingAs($admin)->get('/admin/audit-logs?event=other_event')->assertOk()->assertSee('haystack')->assertDontSee('find the needle');
    }

    public function test_verify_button_reports_success_and_failure(): void
    {
        $admin = User::factory()->withRole('Admin')->create();
        $this->logger()->log('one', 'testing', 'a');

        $this->actingAs($admin)->post('/admin/audit-logs/verify')->assertSessionHas('success');

        DB::table('audit_logs')->insert([
            'event' => 'forged', 'module' => 'testing', 'description' => 'f', 'prev_hash' => str_repeat('0', 64),
            'hash' => str_repeat('c', 64), 'created_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        $this->actingAs($admin)->post('/admin/audit-logs/verify')->assertSessionHas('error');
    }

    public function test_the_verify_command_exits_non_zero_on_tampering(): void
    {
        $this->logger()->log('one', 'testing', 'a');
        $this->artisan('pos:audit:verify')->assertExitCode(0);

        DB::table('audit_logs')->insert([
            'event' => 'forged', 'module' => 'testing', 'description' => 'f', 'prev_hash' => str_repeat('0', 64),
            'hash' => str_repeat('d', 64), 'created_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
        $this->artisan('pos:audit:verify')->assertExitCode(1);
    }
}
