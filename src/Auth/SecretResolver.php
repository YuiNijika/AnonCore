<?php

namespace Anon\Core\Auth;

use Anon\Core\Exception\Auth as AuthError;
use Anon\Core\Facade\Env;

final class SecretResolver
{
    private const INSECURE_DEFAULTS = [
        'anon_secret_key',
    ];

    public static function resolve(?string $secret = null): string
    {
        $resolved = trim((string) ($secret ?? Env::get('JWT_SECRET', '')));

        if ($resolved === '' || in_array($resolved, self::INSECURE_DEFAULTS, true)) {
            throw new AuthError('JWT_SECRET is missing or insecure. Configure a unique secret in the environment.');
        }

        if (strlen($resolved) < 32) {
            throw new AuthError('JWT_SECRET must contain at least 32 bytes.');
        }

        return $resolved;
    }
}