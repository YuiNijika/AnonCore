<?php

namespace Anon\Core\Facade;

/**
 * Auth Facade类
 * 
 * @method static string login(array|object $user, int $ttl = 7200, ?string $guard = null, array $claims = [])
 * @method static array|null user(?string $guard = null)
 * @method static bool check(?string $guard = null)
 * @method static bool logout(?string $guard = null)
 * @method static self guard(string $name)
 * @method static string|null token(?string $guard = null)
 * @method static string|null refreshToken(?string $guard = null)
 * @method static array|null payload(?string $guard = null)
 * @method static array|null refreshPayload(?string $guard = null)
 * @method static mixed id(?string $guard = null)
 * @method static array|null currentSession(?string $guard = null)
 * @method static array<int, array<string, mixed>> sessions(?string $guard = null)
 * @method static bool revokeSession(string $sessionId, ?string $guard = null)
 * @method static int revokeOtherSessions(?string $guard = null)
 * @method static string|null refresh(?int $ttl = null, ?string $guard = null)
 * @method static array<string, mixed> issueTokenPair(array|object $user, ?string $guard = null, ?int $ttl = null, ?int $refreshTtl = null, array $claims = [])
 * @method static array<string, mixed>|null refreshTokens(?string $guard = null, ?int $ttl = null, ?int $refreshTtl = null)
 * @method static \Anon\Core\Http\Response setTokenCookie(\Anon\Core\Http\Response $response, string $token, ?string $guard = null)
 * @method static \Anon\Core\Http\Response setRefreshTokenCookie(\Anon\Core\Http\Response $response, string $token, ?string $guard = null)
 * @method static \Anon\Core\Http\Response setTokenPairCookies(\Anon\Core\Http\Response $response, array $tokens, ?string $guard = null)
 * @method static \Anon\Core\Http\Response forgetTokenCookie(\Anon\Core\Http\Response $response, ?string $guard = null)
 * @method static \Anon\Core\Http\Response forgetRefreshTokenCookie(\Anon\Core\Http\Response $response, ?string $guard = null)
 * @method static \Anon\Core\Http\Response forgetTokenPairCookies(\Anon\Core\Http\Response $response, ?string $guard = null)
 * @method static bool hasRole(string|array $roles, ?string $guard = null)
 * @method static bool hasPermission(string|array $permissions, ?string $guard = null)
 * @method static void authorizeRole(string|array $roles, ?string $guard = null)
 * @method static void authorizePermission(string|array $permissions, ?string $guard = null)
 */
class Auth extends Facade
{
    /**
     * 获取组件注册名称
     */
    protected static function getFacadeAccessor(): string
    {
        return 'auth';
    }
}
