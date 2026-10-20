<?php

/** Keeps the application's CSP intact across the hosting server's header filter. */
final class HttpSecurityPolicy
{
    public static function send(string $policy): void
    {
        if ($policy === '' || preg_match('/[\r\n]/', $policy)) {
            throw new InvalidArgumentException('Invalid content security policy.');
        }

        // Preserve HTTPS upgrading while .htaccess restores this policy after
        // server-level filters. The bridge reads a response header, never input.
        if (!preg_match('/(?:^|;)\s*upgrade-insecure-requests\s*(?:;|$)/', $policy)) {
            $policy .= '; upgrade-insecure-requests';
        }
        header('Content-Security-Policy: ' . $policy);
        header('X-Rental-Content-Security-Policy: ' . $policy);
    }
}
