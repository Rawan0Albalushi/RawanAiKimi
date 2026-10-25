<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const COOKIE = 'locale';

    public const MINUTES = 60 * 24 * 400;

    /**
     * The interface language lives in a cookie. Chat, polling, and image
     * requests share one session file and can save over each other, which
     * used to drop the selected language and flip the page back to English.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::preferred($request));

        return $next($request);
    }

    public static function preferred(Request $request): string
    {
        foreach ([
            $request->cookie(self::COOKIE),
            $request->hasSession() ? $request->session()->get('locale') : null,
            config('app.locale'),
        ] as $locale) {
            if (is_string($locale) && in_array($locale, ['en', 'ar'], true)) {
                return $locale;
            }
        }

        return 'en';
    }
}
