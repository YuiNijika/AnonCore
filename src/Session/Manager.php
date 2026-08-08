<?php

namespace Anon\Core\Session;

use Anon\Core\Facade\Config;
use Anon\Core\Facade\Env;

class Manager implements Contract
{
    /**
     * @var bool 是否已经启动 Session
     */
    protected bool $started = false;

    public function __construct()
    {
        // 延迟启动，仅在真正读写 Session 时再 session_start，避免纯 API 请求无谓开销
    }

    /**
     * 启动 Session 并配置驱动
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = session_status() === PHP_SESSION_ACTIVE;
            return;
        }

        $driver = (string) Config::get('session.driver', Env::get('SESSION_DRIVER', 'file'));

        // 强化 Session 安全配置
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_httponly', 1);

        // 注册自定义会话处理器
        $handler = $driver === 'redis' ? new Redis() : new File();
        session_set_save_handler($handler, true);

        // 设置 Cookie 参数
        $lifetime = (int) Config::get('session.lifetime', Env::get('SESSION_LIFETIME', 86400));
        $secureDefault = strtolower((string) Env::get('APP_ENV', 'production')) === 'production';
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => (string) Config::get('session.path', '/'),
            'domain'   => (string) Config::get('session.domain', Env::get('SESSION_DOMAIN', '')),
            'secure'   => (bool) Config::get('session.secure', Env::get('SESSION_SECURE', $secureDefault)),
            'httponly' => (bool) Config::get('session.httponly', Env::get('SESSION_HTTPONLY', true)),
            'samesite' => (string) Config::get('session.samesite', Env::get('SESSION_SAMESITE', 'Lax')),
        ]);

        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->start();
        return isset($_SESSION[$key]);
    }

    public function delete(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $this->start();
        $_SESSION = [];
    }

    public function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // 无活跃会话时无需启动；仅清理可能残留的 cookie
            $this->forgetSessionCookie();
            $this->started = false;
            return;
        }

        $_SESSION = [];

        // 销毁会话数据；session_destroy 后会话不再可写
        session_destroy();

        $this->forgetSessionCookie();
        $this->started = false;
    }

    /**
     * 使会话 Cookie 立即过期，含 SameSite 等完整参数
     */
    protected function forgetSessionCookie(): void
    {
        if (!ini_get('session.use_cookies')) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    public function getId(): string
    {
        $this->start();
        return session_id();
    }

    public function regenerateId(bool $deleteOldSession = true): bool
    {
        $this->start();
        return session_regenerate_id($deleteOldSession);
    }
}
