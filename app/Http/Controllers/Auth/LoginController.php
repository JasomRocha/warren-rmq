<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (! config('warren.auth.enabled') || Auth::check()) {
            return redirect()->route('workspace');
        }

        return Inertia::render('Login', ['noUsers' => ! User::query()->exists()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $email = Str::lower($credentials['email']);
        $max = (int) config('warren.auth.max_attempts');
        // email+IP for a person mistyping; email alone and IP alone so neither changing IPs (behind a proxy that
        // forwards any X-Forwarded-For) nor trying one password on many emails gets around the limit
        $limits = ["login:{$email}|{$request->ip()}" => $max, "login-email:{$email}" => $max * 4, "login-ip:{$request->ip()}" => $max * 4];
        foreach ($limits as $key => $attempts) {
            if (RateLimiter::tooManyAttempts($key, $attempts)) {
                throw ValidationException::withMessages([
                    'email' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            foreach (array_keys($limits) as $key) {
                RateLimiter::hit($key, 60);
            }
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        RateLimiter::clear(array_key_first($limits));
        $request->session()->regenerate();

        return redirect()->intended(route('workspace'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
