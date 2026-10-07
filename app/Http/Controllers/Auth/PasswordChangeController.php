<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.change-password', ['forced' => $request->user()->must_change_password]);
    }

    public function update(Request $request, AuditLogger $audit, SettingsService $settings): RedirectResponse
    {
        $user = $request->user();
        $min = max(6, $settings->int('security.password_min_length'));

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min($min)->max(100)],
        ], [
            'password.different' => 'The new password must be different from the current one.',
        ]);

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        // Sign out every other device that was using the old password.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        Auth::login($user);
        $request->session()->regenerate();

        $audit->log('password_changed', 'users', "{$user->username} changed their own password", $user, user: $user);

        return redirect()->route('dashboard')->with('success', 'Your password has been changed.');
    }
}
