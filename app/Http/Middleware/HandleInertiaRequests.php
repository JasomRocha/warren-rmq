<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'locale' => str_replace('_', '-', app()->getLocale()),
            'auth' => [
                'enabled' => (bool) config('warren.auth.enabled'),
                'user' => $request->user()?->only(['name', 'email']),
            ],
        ];
    }
}
