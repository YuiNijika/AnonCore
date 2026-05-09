<?php

namespace Anon\Core\Session;

use Anon\Core\Facade\Env;

class Manager implements Contract
{
    /**
     * @var bool 是否已经启动 Session
     */
    protected bool $started = false;

    public function __construct()
    {
        $this->start();
    }

    /**
     * 启动 Session 并配置驱动
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $driver = Env::get('SESSION_DRIVER', 'file');

        // 注册自定义会话处理器
        $handler = $driver === 'redis' ? new Redis() : new File();
        session_set_save_handler($handler, true);

        // 设置 Cookie 参数
        $lifetime = Env::get('SESSION_LIFETIME', 86400);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => Env::get('SESSION_DOMAIN', ''),
            'secure'   => Env::get('SESSION_SECURE', false),
            'httponly' => Env::get('SESSION_HTTPONLY', true), // HttpOnly 默认应为 true 防止 XSS
            'samesite' => Env::get('SESSION_SAMESITE', 'Lax'), // 必须是 Lax, Strict, 或 None
        ]);

        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $_SESSION = [];
    }

    public function destroy(): void
    {
        $this->clear();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            // 移除客户端 Cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
        }
        $this->started = false;
    }

    public function getId(): string
    {
        return session_id();
    }

    public function regenerateId(bool $deleteOldSession = true): bool
    {
        return session_regenerate_id($deleteOldSession);
    }
}