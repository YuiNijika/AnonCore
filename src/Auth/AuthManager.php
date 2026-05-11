<?php

namespace Anon\Core\Auth;

use Anon\Core\Foundation\App;
use Anon\Core\Http\Request;

class AuthManager
{
    /**
     * @var Request
     */
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * 为给定的用户数据生成 Token
     */
    public function login(array|object $user, int $ttl = 7200): string
    {
        $payload = [
            'sub' => is_object($user) ? $user->id : $user['id'],
            'iat' => time(),
            'exp' => time() + $ttl
        ];
        
        return JWTUtil::encode($payload);
    }

    /**
     * 解析请求头中的 Bearer Token 并返回 Payload
     */
    public function user(): ?array
    {
        $token = $this->request->bearerToken();
        if (!$token) {
            return null;
        }

        try {
            return JWTUtil::decode($token);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 检查是否已登录
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * 退出登录
     */
    public function logout(): bool
    {
        return true;
    }
}
