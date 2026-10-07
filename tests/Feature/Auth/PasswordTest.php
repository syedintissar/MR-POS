<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesSeededDatabase;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshesSeededDatabase;

    public function test_a_user_flagged_to_change_password_cannot_use_the_system_until_they_do(): void
    {
        $user = User::factory()->withRole('Cashier')->mustChangePassword()->create(['password' => 'temp-pass-123']);

        $this->actingAs($user)->get('/')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get('/admin/users')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('password.change'))->assertOk()->assertSee('must choose a new password');
    }

    public function test_changing_the_password_clears_the_flag_and_is_audited(): void
    {
        $user = User::factory()->withRole('Cashier')->mustChangePassword()->create(['password' => 'temp-pass-123']);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'temp-pass-123',
            'password' => 'brand-new-pass-77',
            'password_confirmation' => 'brand-new-pass-77',
        ])->assertRedirect('/');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(password_verify('brand-new-pass-77', $user->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'password_changed', 'user_id' => $user->id]);
        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $user = User::factory()->withRole('Cashier')->create(['password' => 'temp-pass-123']);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'wrong', 'password' => 'brand-new-pass-77', 'password_confirmation' => 'brand-new-pass-77',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_the_new_password_must_be_long_enough_confirmed_and_different(): void
    {
        $user = User::factory()->withRole('Cashier')->create(['password' => 'temp-pass-123']);
        $put = fn (array $d) => $this->actingAs($user)->put(route('password.update'), $d + ['current_password' => 'temp-pass-123']);

        $put(['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $put(['password' => 'long-enough-pass', 'password_confirmation' => 'different-pass'])->assertSessionHasErrors('password');
        $put(['password' => 'temp-pass-123', 'password_confirmation' => 'temp-pass-123'])->assertSessionHasErrors('password');
    }

    public function test_changing_the_password_signs_out_other_devices(): void
    {
        $user = User::factory()->withRole('Cashier')->create(['password' => 'temp-pass-123']);
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'temp-pass-123', 'password' => 'brand-new-pass-77', 'password_confirmation' => 'brand-new-pass-77',
        ]);

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
    }

    public function test_sensitive_actions_ask_for_the_password_again(): void
    {
        $admin = User::factory()->withRole('Admin')->create(['password' => 'admin-pass-123']);
        $target = User::factory()->withRole('Cashier')->create();

        // Not confirmed yet → sent to the confirmation screen, nothing changes.
        $this->actingAs($admin)->post(route('users.reset-password', $target), ['password' => 'reset-pass-999'])
            ->assertRedirect(route('password.confirm'));
        $this->assertFalse(password_verify('reset-pass-999', $target->fresh()->password));

        // Wrong password on the confirmation screen.
        $this->actingAs($admin)->post(route('password.confirm'), ['password' => 'nope'])->assertSessionHasErrors('password');

        // Correct password → the action is allowed (for the next 10 minutes).
        $this->actingAs($admin)->post(route('password.confirm'), ['password' => 'admin-pass-123'])->assertRedirect();
        $this->post(route('users.reset-password', $target), ['password' => 'reset-pass-999'])->assertRedirect();
        $this->assertTrue(password_verify('reset-pass-999', $target->fresh()->password));
    }

    public function test_the_confirmation_expires_after_ten_minutes(): void
    {
        $admin = User::factory()->withRole('Admin')->create(['password' => 'admin-pass-123']);
        $target = User::factory()->withRole('Cashier')->create();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time() - 601])
            ->post(route('users.reset-password', $target), ['password' => 'reset-pass-999'])
            ->assertRedirect(route('password.confirm'));
    }
}
