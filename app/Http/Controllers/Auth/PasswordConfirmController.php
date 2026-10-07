<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/** "Please type your password again" before a sensitive action (valid for 10 minutes: auth.password_timeout). */
class PasswordConfirmController extends Controller
{
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $key = 'confirm:'.$request->user()->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['password' => 'Too many attempts. Please wait a minute.']);
        }

        if (! Hash::check($request->string('password')->toString(), $request->user()->password)) {
            RateLimiter::hit($key, 60);
            $audit->log('password_confirm_failed', 'auth', 'Wrong password on a confirmation screen');

            return back()->withErrors(['password' => 'That password is not correct.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard'));
    }
}
