<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Optional network restriction (Settings → Security). Empty list = everyone on the network.
 * The server PC itself (loopback) is always allowed so an admin can never lock themselves out of the machine.
 */
class IpAllowList
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = self::parse($this->rawList());

        if ($allowed !== [] && ! self::permits($request->ip(), $allowed)) {
            abort(403, 'This computer is not allowed to use the system.');
        }

        return $next($request);
    }

    private function rawList(): string
    {
        try {
            return $this->settings->string('security.ip_allowlist');
        } catch (Throwable) {
            return ''; // settings table not available yet (fresh install)
        }
    }

    /** @return list<string> */
    public static function parse(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: [])));
    }

    /** @param  list<string>  $allowed */
    public static function permits(?string $ip, array $allowed): bool
    {
        if ($ip === null) {
            return false;
        }

        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        return IpUtils::checkIp($ip, $allowed);
    }
}
