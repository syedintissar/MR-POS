<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 60;

    private const IP_MAX_ATTEMPTS = 30;

    private const IP_LOCK_SECONDS = 300;

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $username = Str::lower(trim($data['username']));
        $userKey = 'login:'.$username.'|'.$request->ip();
        $ipKey = 'login-ip:'.$request->ip();

        foreach ([[$userKey, self::MAX_ATTEMPTS], [$ipKey, self::IP_MAX_ATTEMPTS]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);
                $this->audit->log('login_locked', 'auth', "Login blocked (too many attempts) for \"{$username}\"", user: null);

                return back()->withInput($request->only('username'))
                    ->withErrors(['username' => "Too many failed attempts. Please wait {$seconds} seconds and try again."]);
            }
        }

        $user = User::query()
            ->where('username', $username)
            ->orWhere(fn ($q) => $q->whereNotNull('email')->where('email', $username))
            ->first();

        // Always hash-check, so the response time does not reveal whether the username exists.
        $passwordOk = Hash::check($data['password'], $user->password ?? Hash::make(Str::random(16)));

        if (! $user || ! $passwordOk) {
            RateLimiter::hit($userKey, self::LOCK_SECONDS);
            RateLimiter::hit($ipKey, self::IP_LOCK_SECONDS);
            $this->audit->log('login_failed', 'auth', "Failed login for \"{$username}\"", $user);

            return back()->withInput($request->only('username'))->withErrors(['username' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            $this->audit->log('login_refused', 'auth', "Disabled account tried to log in: {$user->username}", $user);

            return back()->withInput($request->only('username'))->withErrors(['username' => 'This account has been disabled. Please contact the administrator.']);
        }

        RateLimiter::clear($userKey);

        Auth::login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $this->audit->log('login', 'auth', "{$user->username} logged in", $user, user: $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            $this->audit->log('logout', 'auth', "{$user->username} logged out", $user, user: $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
