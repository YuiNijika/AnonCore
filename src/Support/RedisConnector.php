<?php

namespace Anon\Core\Support;

use Anon\Core\Exception\Cache as CacheError;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Env;
use Redis as PhpRedis;

/**
 * 统一 Redis 连接创建，避免 Cache / Queue / Session 各写一套 connect 逻辑。
 */
class RedisConnector
{
    /**
     * @param array<string, mixed>|string|null $configPath 配置数组，或 Config 点路径
     * @param list<string> $fallbackPaths 依次尝试的配置路径
     */
    public static function connect(
        array|string|null $config = null,
        array $fallbackPaths = ['cache.redis', 'redis']
    ): PhpRedis {
        if (!extension_loaded('redis')) {
            throw new CacheError("The 'redis' extension is required.");
        }

        $redisConfig = self::resolveConfig($config, $fallbackPaths);

        $host = (string) ($redisConfig['host'] ?? Env::get('REDIS_HOST', '127.0.0.1'));
        $port = (int) ($redisConfig['port'] ?? Env::get('REDIS_PORT', 6379));
        $password = (string) ($redisConfig['password'] ?? Env::get('REDIS_PASSWORD', ''));
        $database = (int) ($redisConfig['database'] ?? Env::get('REDIS_DB', 0));
        $timeout = (float) ($redisConfig['timeout'] ?? Env::get('REDIS_TIMEOUT', 2.0));

        $redis = new PhpRedis();
        $redis->connect($host, $port, $timeout);

        if ($password !== '') {
            $redis->auth($password);
        }

        if ($database !== 0) {
            $redis->select($database);
        }

        return $redis;
    }

    /**
     * @param list<string> $fallbackPaths
     * @return array<string, mixed>
     */
    protected static function resolveConfig(array|string|null $config, array $fallbackPaths): array
    {
        if (is_array($config)) {
            return $config;
        }

        if (is_string($config) && $config !== '') {
            $value = Config::get($config, []);
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        foreach ($fallbackPaths as $path) {
            $value = Config::get($path, []);
            if (is_array($value) && $value !== []) {
                return $value;
            }
        }

        return [];
    }
}