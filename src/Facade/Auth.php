<?php

namespace Anon\Core\Facade;

/**
 * Auth Facade类
 * 
 * @method static string login(array|object $user, int $ttl = 7200)
 * @method static array|null user()
 * @method static bool check()
 * @method static bool logout()
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
