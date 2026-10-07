<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshesSeededDatabase;

    private function cashier(array $attrs = []): User
    {
        return User::factory()->withRole('Cashier')->create(array_merge(['username' => 'sara', 'password' => 'correct-horse-9'], $attrs));
    }

    public function test_login_page_is_shown_to_guests(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_valid_login_reaches_the_dashboard_and_is_audited(): void
    {
        $user = $this->cashier();

        $this->post('/login', ['username' => 'sara', 'password' => 'correct-horse-9'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->get('/')->assertOk()->assertSee('Welcome, '.$user->name);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'login', 'user_id' => $user->id]);
    }

    public function test_username_is_case_insensitive(): void
    {
        $this->cashier();

        $this->post('/login', ['username' => '  SARA ', 'password' => 'correct-horse-9'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $user = $this->cashier();

        $this->from('/login')->post('/login', ['username' => 'sara', 'password' => 'nope'])
            ->assertRedirect('/login')->assertSessionHasErrors('username');

        $this->assertGuest();
        $row = AuditLog::query()->where('event', 'login_failed')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id, 'A failed login must not be attributed to the account being attacked.');
        $this->assertSame($user->id, $row->auditable_id);
    }

    public function test_unknown_user_gets_the_same_message_as_a_wrong_password(): void
    {
        $this->cashier();

        $message = 'These credentials do not match our records.';

        $this->post('/login', ['username' => 'ghost', 'password' => 'whatever1'])
            ->assertSessionHasErrors(['username' => $message]);

        $this->post('/login', ['username' => 'sara', 'password' => 'wrong-pass1'])
            ->assertSessionHasErrors(['username' => $message]);
    }

    public function test_inactive_user_cannot_log_in_even_with_the_right_password(): void
    {
        $this->cashier(['is_active' => false]);

        $this->post('/login', ['username' => 'sara', 'password' => 'correct-horse-9'])->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'login_refused']);
    }

    public function test_five_failures_lock_the_account_for_a_while_even_for_the_right_password(): void
    {
        $this->cashier();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'sara', 'password' => 'wrong-'.$i])->assertSessionHasErrors('username');
        }

        $response = $this->post('/login', ['username' => 'sara', 'password' => 'correct-horse-9']);
        $response->assertSessionHasErrors('username');
        $this->assertStringContainsString('Too many failed attempts', $response->baseResponse->getSession()->get('errors')->first('username'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'login_locked']);
    }

    public function test_a_user_deactivated_while_logged_in_is_signed_out_on_the_next_request(): void
    {
        $user = $this->cashier();
        $this->actingAs($user)->get('/')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session_and_is_audited(): void
    {
        $user = $this->cashier();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'logout', 'user_id' => $user->id]);
    }

    public function test_passwords_are_stored_hashed(): void
    {
        $user = $this->cashier();
        $stored = DB::table('users')->where('id', $user->id)->value('password');

        $this->assertNotSame('correct-horse-9', $stored);
        $this->assertTrue(Hash::check('correct-horse-9', $stored));
        $this->assertStringStartsWith('$2y$', $stored);
    }

    public function test_session_is_regenerated_on_login(): void
    {
        $this->cashier();
        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['username' => 'sara', 'password' => 'correct-horse-9']);

        $this->assertNotSame($before, session()->getId());
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
