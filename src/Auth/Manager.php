<?php

namespace Anon\Core\Auth;

use Anon\Core\Exception\Http;
use Anon\Core\Facade\Cache;
use Anon\Core\Facade\Config;
use Anon\Core\Http\Request;
use Anon\Core\Http\Response;
use Anon\Core\Support\Str;

use Anon\Core\Facade\Env;
use Anon\Core\Facade\Hook;

class Manager
{
    /**
     * @var Request
     */
    protected Request $request;

    /**
     * @var string|null 当前 Guard
     */
    protected ?string $guard = null;

    /**
     * @var array<string, string|null>
     */
    protected array $resolvedTokens = [];

    /**
     * @var array<string, array|null>
     */
    protected array $resolvedPayloads = [];

    /**
     * @var array<string, string|null>
     */
    protected array $resolvedRefreshTokens = [];

    /**
     * @var array<string, array|null>
     */
    protected array $resolvedRefreshPayloads = [];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * 为给定的用户数据生成 Token
     */
    public function login(array|object $user, int $ttl = 7200, ?string $guard = null, array $claims = []): string
    {
        $guard = $this->resolveGuardName($guard);

        return $this->createToken(
            $user,
            $guard,
            $this->resolveTtl($guard, $ttl),
            $claims,
            'access'
        );
    }

    /**
     * 签发 Access / Refresh Token �?     *
     * @return array<string, mixed>
     */
    public function issueTokenPair(
        array|object $user,
        ?string $guard = null,
        ?int $ttl = null,
        ?int $refreshTtl = null,
        array $claims = []
    ): array {
        $guard = $this->resolveGuardName($guard);
        $subject = $this->extractSubject($user, $guard);
        if ($subject === null || $subject === '') {
            throw new \InvalidArgumentException('Unable to resolve auth subject from user payload.');
        }

        $sessionId = (string) ($claims['sid'] ?? Str::uuid());
        $claims['sid'] = $sessionId;
        $accessTtl = $this->resolveTtl($guard, $ttl);
        $accessToken = $this->createToken($user, $guard, $accessTtl, $claims, 'access');

        $tokens = [
            'token' => $accessToken,
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'guard' => $guard,
        ];

        if ($this->refreshTokenEnabled($guard)) {
            $refreshExpiresIn = $this->resolveRefreshTtl($guard, $refreshTtl);
            $tokens['refresh_token'] = $this->createToken($user, $guard, $refreshExpiresIn, $claims, 'refresh');
            $tokens['refresh_expires_in'] = $refreshExpiresIn;
        }

        $this->storeSession(
            $guard,
            (string) $subject,
            $sessionId,
            $tokens,
            $this->extractSessionClaims($claims)
        );

        Hook::trigger('auth_login', [
            'user' => $user,
            'guard' => $guard,
            'subject' => $subject,
            'session_id' => $sessionId,
            'tokens' => $tokens
        ]);

        return $tokens;
    }

    /**
     * 解析当前请求中的 Token 并返�?Payload
     */
    public function user(?string $guard = null): ?array
    {
        $guard = $this->resolveGuardName($guard);
        if (array_key_exists($guard, $this->resolvedPayloads)) {
            return $this->resolvedPayloads[$guard];
        }

        $token = $this->token($guard);
        if (!$token) {
            $this->resolvedPayloads[$guard] = null;
            return null;
        }

        try {
            $payload = JWTUtil::decode($token, $this->resolveSecret($guard));

            if (($payload['guard'] ?? $guard) !== $guard) {
                $this->resolvedPayloads[$guard] = null;
                return null;
            }

            if (($payload['typ'] ?? 'access') !== 'access') {
                $this->resolvedPayloads[$guard] = null;
                return null;
            }

            if (!$this->validateTrackedSession($guard, $payload)) {
                $this->resolvedPayloads[$guard] = null;
                return null;
            }

            $this->resolvedPayloads[$guard] = $payload;
            return $payload;
        } catch (\Exception $e) {
            $this->resolvedPayloads[$guard] = null;
            return null;
        }
    }

    /**
     * 获取当前请求中解析到的原�?Token
     */
    public function token(?string $guard = null): ?string
    {
        $guard = $this->resolveGuardName($guard);
        if (array_key_exists($guard, $this->resolvedTokens)) {
            return $this->resolvedTokens[$guard];
        }

        return $this->resolvedTokens[$guard] = $this->resolveRequestToken($guard);
    }

    /**
     * 获取当前请求中解析到�?Refresh Token
     */
    public function refreshToken(?string $guard = null): ?string
    {
        $guard = $this->resolveGuardName($guard);
        if (array_key_exists($guard, $this->resolvedRefreshTokens)) {
            return $this->resolvedRefreshTokens[$guard];
        }

        return $this->resolvedRefreshTokens[$guard] = $this->resolveRefreshRequestToken($guard);
    }

    /**
     * 获取当前请求中的 Refresh Token 载荷
     */
    public function refreshPayload(?string $guard = null): ?array
    {
        $guard = $this->resolveGuardName($guard);
        if (array_key_exists($guard, $this->resolvedRefreshPayloads)) {
            return $this->resolvedRefreshPayloads[$guard];
        }

        $token = $this->refreshToken($guard);
        if (!$token) {
            $this->resolvedRefreshPayloads[$guard] = null;
            return null;
        }

        try {
            $payload = JWTUtil::decode($token, $this->resolveRefreshSecret($guard));

            if (($payload['guard'] ?? $guard) !== $guard) {
                $this->resolvedRefreshPayloads[$guard] = null;
                return null;
            }

            if (($payload['typ'] ?? null) !== 'refresh') {
                $this->resolvedRefreshPayloads[$guard] = null;
                return null;
            }

            if (!$this->validateTrackedSession($guard, $payload)) {
                $this->resolvedRefreshPayloads[$guard] = null;
                return null;
            }

            $this->resolvedRefreshPayloads[$guard] = $payload;
            return $payload;
        } catch (\Exception $e) {
            $this->resolvedRefreshPayloads[$guard] = null;
            return null;
        }
    }

    /**
     * 检查是否已登录
     */
    public function check(?string $guard = null): bool
    {
        return $this->user($guard) !== null;
    }

    /**
     * 退出登�?     */
    public function logout(?string $guard = null): bool
    {
        $guard = $this->resolveGuardName($guard);
        $loggedOut = false;
        $payload = $this->user($guard);
        if ($payload !== null) {
            $this->blacklistPayload($payload);
            $loggedOut = true;
        }

        $refreshPayload = $this->refreshPayload($guard);
        if ($refreshPayload !== null) {
            $this->blacklistPayload($refreshPayload);
            $loggedOut = true;
        }

        $this->forgetResolvedAuth($guard);
        $this->forgetResolvedRefreshAuth($guard);

        if ($loggedOut) {
            Hook::trigger('auth_logout', [
                'guard' => $guard,
                'payload' => $payload ?? $refreshPayload
            ]);
        }

        return $loggedOut;
    }

    /**
     * 切换 Guard
     */
    public function guard(string $name): self
    {
        $clone = clone $this;
        $clone->guard = $name;
        return $clone;
    }

    /**
     * 获取当前认证载荷
     */
    public function payload(?string $guard = null): ?array
    {
        return $this->user($guard);
    }

    /**
     * 获取当前用户 ID
     */
    public function id(?string $guard = null): mixed
    {
        return $this->user($guard)['sub'] ?? null;
    }

    /**
     * 获取当前会话
     */
    public function currentSession(?string $guard = null): ?array
    {
        $guard = $this->resolveGuardName($guard);
        $payload = $this->user($guard) ?? $this->refreshPayload($guard);
        if ($payload === null) {
            return null;
        }

        $subject = (string) ($payload['sub'] ?? '');
        $sessionId = (string) ($payload['sid'] ?? '');
        if ($subject === '' || $sessionId === '') {
            return null;
        }

        $session = $this->getSession($guard, $subject, $sessionId);
        if ($session === null) {
            return null;
        }

        return array_merge($session, ['current' => true]);
    }

    /**
     * 获取当前用户全部活跃会话
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessions(?string $guard = null): array
    {
        $guard = $this->resolveGuardName($guard);
        $payload = $this->user($guard) ?? $this->refreshPayload($guard);
        if ($payload === null) {
            return [];
        }

        $subject = (string) ($payload['sub'] ?? '');
        if ($subject === '') {
            return [];
        }

        $currentSessionId = (string) ($payload['sid'] ?? '');
        $result = [];

        foreach ($this->getSessionIndex($guard, $subject) as $sessionId) {
            $session = $this->getSession($guard, $subject, $sessionId);
            if ($session === null) {
                continue;
            }

            $session['current'] = $currentSessionId !== '' && $sessionId === $currentSessionId;
            $result[] = $session;
        }

        usort(
            $result,
            static fn (array $left, array $right): int => ((int) ($right['last_seen_at'] ?? 0)) <=> ((int) ($left['last_seen_at'] ?? 0))
        );

        return $result;
    }

    /**
     * 吊销指定会话
     */
    public function revokeSession(string $sessionId, ?string $guard = null): bool
    {
        $guard = $this->resolveGuardName($guard);
        $payload = $this->user($guard) ?? $this->refreshPayload($guard);
        if ($payload === null) {
            return false;
        }

        $subject = (string) ($payload['sub'] ?? '');
        if ($subject === '' || trim($sessionId) === '') {
            return false;
        }

        $revoked = $this->removeSession($guard, $subject, trim($sessionId));
        if ($revoked && (($payload['sid'] ?? null) === trim($sessionId))) {
            $this->forgetResolvedAuth($guard);
            $this->forgetResolvedRefreshAuth($guard);
        }

        return $revoked;
    }

    /**
     * 吊销除当前会话外的其他会�?     */
    public function revokeOtherSessions(?string $guard = null): int
    {
        $guard = $this->resolveGuardName($guard);
        $payload = $this->user($guard) ?? $this->refreshPayload($guard);
        if ($payload === null) {
            return 0;
        }

        $subject = (string) ($payload['sub'] ?? '');
        $currentSessionId = (string) ($payload['sid'] ?? '');
        if ($subject === '') {
            return 0;
        }

        $count = 0;
        foreach ($this->getSessionIndex($guard, $subject) as $sessionId) {
            if ($sessionId === '' || $sessionId === $currentSessionId) {
                continue;
            }

            if ($this->removeSession($guard, $subject, $sessionId)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 刷新当前 token
     */
    public function refresh(?int $ttl = null, ?string $guard = null): ?string
    {
        $guard = $this->resolveGuardName($guard);

        if ($this->refreshTokenEnabled($guard) && $this->refreshToken($guard) !== null) {
            $tokens = $this->refreshTokens($guard, $ttl);

            return $tokens['access_token'] ?? null;
        }

        $payload = $this->user($guard);
        if ($payload === null) {
            return null;
        }

        $this->blacklistPayload($payload);
        $this->forgetResolvedAuth($guard);
        unset($payload['iat'], $payload['exp'], $payload['jti']);

        return $this->login(
            ['id' => $payload['sub'] ?? null],
            $ttl ?? $this->resolveTtl($guard),
            $guard,
            $payload
        );
    }

    /**
     * 基于 Refresh Token 刷新 Token �?     *
     * @return array<string, mixed>|null
     */
    public function refreshTokens(?string $guard = null, ?int $ttl = null, ?int $refreshTtl = null): ?array
    {
        $guard = $this->resolveGuardName($guard);
        if (!$this->refreshTokenEnabled($guard)) {
            $token = $this->refresh($ttl, $guard);

            if ($token === null) {
                return null;
            }

            return [
                'token' => $token,
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => $this->resolveTtl($guard, $ttl),
                'guard' => $guard,
            ];
        }

        $payload = $this->refreshPayload($guard);
        if ($payload === null) {
            return null;
        }

        $accessPayload = $this->user($guard);
        if ($accessPayload !== null) {
            $this->blacklistPayload($accessPayload);
        }

        $this->blacklistPayload($payload);
        $this->forgetResolvedAuth($guard);
        $this->forgetResolvedRefreshAuth($guard);
        unset($payload['iat'], $payload['exp'], $payload['jti'], $payload['typ']);

        return $this->issueTokenPair(
            ['id' => $payload['sub'] ?? null],
            $guard,
            $ttl,
            $refreshTtl,
            $payload
        );
    }

    /**
     * �?Token 写入响应 Cookie
     */
    public function setTokenCookie(Response $response, string $token, ?string $guard = null): Response
    {
        $guard = $this->resolveGuardName($guard);

        if (!(bool) $this->resolveGuardConfig($guard, 'cookie_enabled', false)) {
            return $response;
        }

        try {
            $payload = JWTUtil::decode($token, $this->resolveSecret($guard));
            $expires = isset($payload['exp']) ? (int) $payload['exp'] : (time() + $this->resolveTtl($guard));
        } catch (\Throwable $e) {
            $expires = time() + $this->resolveTtl($guard);
        }

        return $response->setCookie(
            $this->resolveCookieName($guard),
            $token,
            $this->resolveCookieOptions($guard, $expires)
        );
    }

    /**
     * �?Refresh Token 写入响应 Cookie
     */
    public function setRefreshTokenCookie(Response $response, string $token, ?string $guard = null): Response
    {
        $guard = $this->resolveGuardName($guard);

        if (!(bool) $this->resolveGuardConfig($guard, 'refresh_cookie_enabled', $this->refreshTokenEnabled($guard))) {
            return $response;
        }

        try {
            $payload = JWTUtil::decode($token, $this->resolveRefreshSecret($guard));
            $expires = isset($payload['exp']) ? (int) $payload['exp'] : (time() + $this->resolveRefreshTtl($guard));
        } catch (\Throwable $e) {
            $expires = time() + $this->resolveRefreshTtl($guard);
        }

        return $response->setCookie(
            $this->resolveRefreshCookieName($guard),
            $token,
            $this->resolveRefreshCookieOptions($guard, $expires)
        );
    }

    /**
     * 从响应中清理 Token Cookie
     */
    public function forgetTokenCookie(Response $response, ?string $guard = null): Response
    {
        $guard = $this->resolveGuardName($guard);

        return $response->deleteCookie(
            $this->resolveCookieName($guard),
            $this->resolveCookieOptions($guard, time() - 3600)
        );
    }

    /**
     * 从响应中清理 Refresh Token Cookie
     */
    public function forgetRefreshTokenCookie(Response $response, ?string $guard = null): Response
    {
        $guard = $this->resolveGuardName($guard);

        return $response->deleteCookie(
            $this->resolveRefreshCookieName($guard),
            $this->resolveRefreshCookieOptions($guard, time() - 3600)
        );
    }

    /**
     * �?Token 对写入响�?Cookie
     *
     * @param array<string, mixed> $tokens
     */
    public function setTokenPairCookies(Response $response, array $tokens, ?string $guard = null): Response
    {
        $guard = $this->resolveGuardName($guard);

        if (isset($tokens['access_token']) && is_string($tokens['access_token'])) {
            $response = $this->setTokenCookie($response, $tokens['access_token'], $guard);
        }

        if (isset($tokens['refresh_token']) && is_string($tokens['refresh_token'])) {
            $response = $this->setRefreshTokenCookie($response, $tokens['refresh_token'], $guard);
        }

        return $response;
    }

    /**
     * 清理 Token �?Cookie
     */
    public function forgetTokenPairCookies(Response $response, ?string $guard = null): Response
    {
        $guard = $this->resolveGuardName($guard);
        $response = $this->forgetTokenCookie($response, $guard);

        return $this->forgetRefreshTokenCookie($response, $guard);
    }

    /**
     * 检查用户是否具备指定角�?     */
    public function hasRole(string|array $roles, ?string $guard = null): bool
    {
        $payload = $this->user($guard);
        if ($payload === null) {
            return false;
        }

        $roles = (array) $roles;
        $userRoles = $this->normalizeClaimList($payload['roles'] ?? ($payload['role'] ?? []));

        return count(array_intersect($roles, $userRoles)) > 0;
    }

    /**
     * 检查用户是否具备指定权�?     */
    public function hasPermission(string|array $permissions, ?string $guard = null): bool
    {
        $payload = $this->user($guard);
        if ($payload === null) {
            return false;
        }

        $permissions = (array) $permissions;
        $userPermissions = $this->normalizeClaimList($payload['permissions'] ?? []);

        return count(array_intersect($permissions, $userPermissions)) > 0;
    }

    /**
     * 角色鉴权
     */
    public function authorizeRole(string|array $roles, ?string $guard = null): void
    {
        if (!$this->check($guard)) {
            throw new Http(401, 'Unauthorized');
        }

        if (!$this->hasRole($roles, $guard)) {
            throw new Http(403, 'Forbidden');
        }
    }

    /**
     * 权限鉴权
     */
    public function authorizePermission(string|array $permissions, ?string $guard = null): void
    {
        if (!$this->check($guard)) {
            throw new Http(401, 'Unauthorized');
        }

        if (!$this->hasPermission($permissions, $guard)) {
            throw new Http(403, 'Forbidden');
        }
    }

    protected function resolveGuardName(?string $guard = null): string
    {
        return $guard
            ?? $this->guard
            ?? (string) Config::get('auth.default_guard', 'api');
    }

    protected function resolveSecret(string $guard): string
    {
        return (string) $this->resolveGuardConfig($guard, 'secret', Env::get('JWT_SECRET', 'anon_secret_key'));
    }

    protected function resolveRefreshSecret(string $guard): string
    {
        return (string) $this->resolveGuardConfig($guard, 'refresh_secret', $this->resolveSecret($guard));
    }

    protected function resolveTtl(string $guard, ?int $ttl = null): int
    {
        if ($ttl !== null && $ttl !== 7200) {
            return $ttl;
        }

        return (int) $this->resolveGuardConfig($guard, 'ttl', $ttl ?? 7200);
    }

    protected function resolveRefreshTtl(string $guard, ?int $ttl = null): int
    {
        if ($ttl !== null && $ttl !== 604800) {
            return $ttl;
        }

        return (int) $this->resolveGuardConfig($guard, 'refresh_ttl', $ttl ?? 604800);
    }

    protected function resolveSubjectKey(string $guard): string
    {
        return (string) $this->resolveGuardConfig($guard, 'subject_key', 'id');
    }

    protected function extractUserClaims(array|object $user): array
    {
        $data = is_object($user) ? get_object_vars($user) : $user;
        $claims = [];

        if (isset($data['role'])) {
            $claims['role'] = $data['role'];
        }

        if (isset($data['roles'])) {
            $claims['roles'] = $this->normalizeClaimList($data['roles']);
        }

        if (isset($data['permissions'])) {
            $claims['permissions'] = $this->normalizeClaimList($data['permissions']);
        }

        return $claims;
    }

    protected function normalizeClaimList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn ($item) => $item !== ''));
    }

    protected function blacklistPayload(array $payload): void
    {
        $jti = $payload['jti'] ?? null;
        if (!$jti) {
            return;
        }

        $ttl = max(1, ((int) ($payload['exp'] ?? time())) - time());
        Cache::set($this->resolveBlacklistKey((string) $jti), 1, $ttl);
    }

    protected function resolveGuardConfig(string $guard, string $key, mixed $default = null): mixed
    {
        return Config::get(
            "auth.guards.{$guard}.{$key}",
            Config::get("auth.{$key}", $default)
        );
    }

    protected function extractSubject(array|object $user, string $guard): mixed
    {
        $data = is_object($user) ? get_object_vars($user) : $user;
        $subjectKey = $this->resolveSubjectKey($guard);

        return $data[$subjectKey] ?? $data['id'] ?? null;
    }

    protected function createToken(
        array|object $user,
        string $guard,
        int $ttl,
        array $claims = [],
        string $type = 'access'
    ): string {
        $subject = $this->extractSubject($user, $guard);
        if ($subject === null || $subject === '') {
            throw new \InvalidArgumentException('Unable to resolve auth subject from user payload.');
        }

        $payload = [
            'sub' => $subject,
            'iat' => time(),
            'exp' => time() + $ttl,
            'guard' => $guard,
            'jti' => $claims['jti'] ?? Str::uuid(),
            'typ' => $type,
        ];

        $claims = array_merge($this->extractUserClaims($user), $claims);
        unset($claims['sub'], $claims['iat'], $claims['exp'], $claims['guard'], $claims['jti'], $claims['typ']);

        return JWTUtil::encode(
            array_merge($payload, $claims),
            $type === 'refresh' ? $this->resolveRefreshSecret($guard) : $this->resolveSecret($guard)
        );
    }

    protected function resolveRequestToken(string $guard): ?string
    {
        foreach ($this->resolveTokenSources($guard) as $source) {
            $token = match ($source) {
                'header', 'bearer' => $this->resolveHeaderToken($guard),
                'cookie' => $this->resolveCookieToken($guard),
                'query' => $this->resolveQueryToken($guard),
                default => null,
            };

            if (is_string($token) && trim($token) !== '') {
                return trim($token);
            }
        }

        return null;
    }

    protected function resolveRefreshRequestToken(string $guard): ?string
    {
        foreach ($this->resolveRefreshTokenSources($guard) as $source) {
            $token = match ($source) {
                'header', 'bearer' => $this->resolveRefreshHeaderToken($guard),
                'cookie' => $this->resolveRefreshCookieToken($guard),
                'query' => $this->resolveRefreshQueryToken($guard),
                default => null,
            };

            if (is_string($token) && trim($token) !== '') {
                return trim($token);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveTokenSources(string $guard): array
    {
        $rawSources = $this->resolveGuardConfig($guard, 'token_sources', ['header']);
        if (is_string($rawSources)) {
            $sources = array_values(array_filter(array_map('trim', explode(',', $rawSources))));
        } else {
            $sources = $this->normalizeClaimList($rawSources);
        }

        return $sources === [] ? ['header'] : array_map('strtolower', $sources);
    }

    /**
     * @return array<int, string>
     */
    protected function resolveRefreshTokenSources(string $guard): array
    {
        $rawSources = $this->resolveGuardConfig(
            $guard,
            'refresh_token_sources',
            ['cookie']
        );

        if (is_string($rawSources)) {
            $sources = array_values(array_filter(array_map('trim', explode(',', $rawSources))));
        } else {
            $sources = $this->normalizeClaimList($rawSources);
        }

        return $sources === [] ? ['cookie'] : array_map('strtolower', $sources);
    }

    protected function resolveHeaderToken(string $guard): ?string
    {
        $headerName = (string) $this->resolveGuardConfig($guard, 'header_name', 'Authorization');
        $prefix = trim((string) $this->resolveGuardConfig($guard, 'header_prefix', 'Bearer'));
        $header = (string) $this->request->header($headerName, '');

        return $this->parseHeaderToken($header, $prefix);
    }

    protected function resolveRefreshHeaderToken(string $guard): ?string
    {
        $headerName = (string) $this->resolveGuardConfig($guard, 'refresh_header_name', 'X-Refresh-Token');
        $prefix = trim((string) $this->resolveGuardConfig($guard, 'refresh_header_prefix', 'Bearer'));
        $header = (string) $this->request->header($headerName, '');

        return $this->parseHeaderToken($header, $prefix);
    }

    protected function resolveCookieToken(string $guard): ?string
    {
        $token = $this->request->cookie($this->resolveCookieName($guard));

        return is_scalar($token) && $token !== '' ? (string) $token : null;
    }

    protected function resolveRefreshCookieToken(string $guard): ?string
    {
        $token = $this->request->cookie($this->resolveRefreshCookieName($guard));

        return is_scalar($token) && $token !== '' ? (string) $token : null;
    }

    protected function resolveQueryToken(string $guard): ?string
    {
        $queryKey = (string) $this->resolveGuardConfig($guard, 'query_key', 'access_token');
        $token = $this->request->get[$queryKey] ?? null;

        return is_scalar($token) && $token !== '' ? (string) $token : null;
    }

    protected function resolveRefreshQueryToken(string $guard): ?string
    {
        $queryKey = (string) $this->resolveGuardConfig($guard, 'refresh_query_key', 'refresh_token');
        $token = $this->request->get[$queryKey] ?? null;

        return is_scalar($token) && $token !== '' ? (string) $token : null;
    }

    protected function resolveBlacklistKey(string $jti): string
    {
        return (string) Config::get('auth.blacklist_prefix', 'auth:blacklist:') . $jti;
    }

    protected function resolveCookieName(string $guard): string
    {
        return (string) $this->resolveGuardConfig($guard, 'cookie_name', 'access_token');
    }

    protected function resolveRefreshCookieName(string $guard): string
    {
        return (string) $this->resolveGuardConfig($guard, 'refresh_cookie_name', 'refresh_token');
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveCookieOptions(string $guard, int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => (string) $this->resolveGuardConfig($guard, 'cookie_path', '/'),
            'domain' => (string) $this->resolveGuardConfig($guard, 'cookie_domain', ''),
            'secure' => (bool) $this->resolveGuardConfig($guard, 'cookie_secure', false),
            'httponly' => (bool) $this->resolveGuardConfig($guard, 'cookie_httponly', true),
            'samesite' => (string) $this->resolveGuardConfig($guard, 'cookie_samesite', 'Lax'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveRefreshCookieOptions(string $guard, int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => (string) $this->resolveGuardConfig(
                $guard,
                'refresh_cookie_path',
                $this->resolveGuardConfig($guard, 'cookie_path', '/')
            ),
            'domain' => (string) $this->resolveGuardConfig(
                $guard,
                'refresh_cookie_domain',
                $this->resolveGuardConfig($guard, 'cookie_domain', '')
            ),
            'secure' => (bool) $this->resolveGuardConfig(
                $guard,
                'refresh_cookie_secure',
                $this->resolveGuardConfig($guard, 'cookie_secure', false)
            ),
            'httponly' => (bool) $this->resolveGuardConfig(
                $guard,
                'refresh_cookie_httponly',
                $this->resolveGuardConfig($guard, 'cookie_httponly', true)
            ),
            'samesite' => (string) $this->resolveGuardConfig(
                $guard,
                'refresh_cookie_samesite',
                $this->resolveGuardConfig($guard, 'cookie_samesite', 'Lax')
            ),
        ];
    }

    protected function refreshTokenEnabled(string $guard): bool
    {
        return (bool) $this->resolveGuardConfig($guard, 'refresh_enabled', false);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function validateTrackedSession(string $guard, array $payload): bool
    {
        if (!(bool) $this->resolveGuardConfig($guard, 'session_track_enabled', true)) {
            return true;
        }

        $subject = (string) ($payload['sub'] ?? '');
        $sessionId = (string) ($payload['sid'] ?? '');
        if ($subject === '' || $sessionId === '') {
            return true;
        }

        if (!$this->hasSession($guard, $subject, $sessionId)) {
            return false;
        }

        $this->touchSession(
            $guard,
            $subject,
            $sessionId,
            [
                'last_seen_at' => time(),
                'last_ip' => $this->request->ip(),
                'user_agent' => (string) $this->request->header('User-Agent', ''),
            ]
        );

        return true;
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    protected function extractSessionClaims(array $claims): array
    {
        $sessionClaims = [];

        foreach (['role', 'roles', 'permissions'] as $key) {
            if (array_key_exists($key, $claims)) {
                $sessionClaims[$key] = $claims[$key];
            }
        }

        return $sessionClaims;
    }

    /**
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $claims
     */
    protected function storeSession(string $guard, string $subject, string $sessionId, array $tokens, array $claims = []): void
    {
        if (!(bool) $this->resolveGuardConfig($guard, 'session_track_enabled', true)) {
            return;
        }

        $now = time();
        $accessExpiresAt = $now + (int) ($tokens['expires_in'] ?? 0);
        $refreshExpiresAt = isset($tokens['refresh_expires_in'])
            ? $now + (int) $tokens['refresh_expires_in']
            : $accessExpiresAt;
        $ttl = max(1, $refreshExpiresAt - $now);
        $existing = $this->getSession($guard, $subject, $sessionId);

        $session = array_merge($existing ?? [], [
            'session_id' => $sessionId,
            'guard' => $guard,
            'subject' => $subject,
            'created_at' => (int) ($existing['created_at'] ?? $now),
            'last_seen_at' => $now,
            'last_ip' => $this->request->ip(),
            'user_agent' => (string) $this->request->header('User-Agent', ''),
            'access_expires_at' => $accessExpiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
            'token_type' => (string) ($tokens['token_type'] ?? 'Bearer'),
        ], $claims);

        Cache::set($this->resolveSessionKey($guard, $subject, $sessionId), $session, $ttl);
        $index = $this->getSessionIndex($guard, $subject);
        if (!in_array($sessionId, $index, true)) {
            $index[] = $sessionId;
        }

        Cache::set($this->resolveSessionIndexKey($guard, $subject), array_values($index), $ttl);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    protected function touchSession(string $guard, string $subject, string $sessionId, array $attributes = []): void
    {
        $session = $this->getSession($guard, $subject, $sessionId);
        if ($session === null) {
            return;
        }

        $expiresAt = max(
            (int) ($session['refresh_expires_at'] ?? 0),
            (int) ($session['access_expires_at'] ?? 0),
            time() + 1
        );
        $ttl = max(1, $expiresAt - time());

        Cache::set(
            $this->resolveSessionKey($guard, $subject, $sessionId),
            array_merge($session, $attributes),
            $ttl
        );
    }

    protected function hasSession(string $guard, string $subject, string $sessionId): bool
    {
        return Cache::has($this->resolveSessionKey($guard, $subject, $sessionId));
    }

    protected function getSession(string $guard, string $subject, string $sessionId): ?array
    {
        $session = Cache::get($this->resolveSessionKey($guard, $subject, $sessionId));

        return is_array($session) ? $session : null;
    }

    /**
     * @return array<int, string>
     */
    protected function getSessionIndex(string $guard, string $subject): array
    {
        $index = Cache::get($this->resolveSessionIndexKey($guard, $subject), []);
        if (!is_array($index)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $index), static fn (string $item): bool => $item !== ''));
    }

    protected function removeSession(string $guard, string $subject, string $sessionId): bool
    {
        $deleted = Cache::delete($this->resolveSessionKey($guard, $subject, $sessionId));
        $index = array_values(array_filter(
            $this->getSessionIndex($guard, $subject),
            static fn (string $item): bool => $item !== $sessionId
        ));

        if ($index === []) {
            Cache::delete($this->resolveSessionIndexKey($guard, $subject));
        } else {
            $ttl = $this->resolveSessionIndexTtl($guard, $subject, $index);
            Cache::set($this->resolveSessionIndexKey($guard, $subject), $index, $ttl);
        }

        return $deleted;
    }

    protected function resolveSessionIndexTtl(string $guard, string $subject, array $index): int
    {
        $expiresAt = time() + 60;

        foreach ($index as $sessionId) {
            $session = $this->getSession($guard, $subject, (string) $sessionId);
            if ($session === null) {
                continue;
            }

            $expiresAt = max(
                $expiresAt,
                (int) ($session['refresh_expires_at'] ?? 0),
                (int) ($session['access_expires_at'] ?? 0)
            );
        }

        return max(1, $expiresAt - time());
    }

    protected function resolveSessionKey(string $guard, string $subject, string $sessionId): string
    {
        return (string) $this->resolveGuardConfig($guard, 'session_prefix', 'auth:session:')
            . $guard . ':' . $subject . ':' . $sessionId;
    }

    protected function resolveSessionIndexKey(string $guard, string $subject): string
    {
        return (string) $this->resolveGuardConfig($guard, 'session_index_prefix', 'auth:session:index:')
            . $guard . ':' . $subject;
    }

    protected function parseHeaderToken(string $header, string $prefix): ?string
    {
        if ($header === '') {
            return null;
        }

        if ($prefix === '') {
            return $header;
        }

        $expectedPrefix = $prefix . ' ';
        if (stripos($header, $expectedPrefix) === 0) {
            return substr($header, strlen($expectedPrefix));
        }

        return null;
    }

    protected function forgetResolvedAuth(string $guard): void
    {
        unset($this->resolvedTokens[$guard], $this->resolvedPayloads[$guard]);
    }

    protected function forgetResolvedRefreshAuth(string $guard): void
    {
        unset($this->resolvedRefreshTokens[$guard], $this->resolvedRefreshPayloads[$guard]);
    }
}
