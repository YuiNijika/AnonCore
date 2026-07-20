<?php

namespace Anon\Core\Cache;

use Redis as PhpRedis;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Env;
use Anon\Core\Support\RedisConnector;

class Redis implements Contract
{
    /**
     * @var PhpRedis|null
     */
    protected ?PhpRedis $redis = null;

    /**
     * @var string 缓存键前缀
     */
    protected string $prefix = '';

    public function __construct()
    {
        $cacheConfig = Config::get('cache', []);
        $this->prefix = is_array($cacheConfig)
            ? (string) ($cacheConfig['prefix'] ?? Env::get('CACHE_PREFIX', Env::get('REDIS_PREFIX', 'anon:cache:')))
            : (string) Env::get('CACHE_PREFIX', Env::get('REDIS_PREFIX', 'anon:cache:'));

        $this->redis = RedisConnector::connect('cache.redis', ['cache.redis', 'redis']);
    }

    /**
     * 获取带前缀的真实键名
     */
    protected function getRealKey(string $key): string
    {
        return $this->prefix . $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $realKey = $this->getRealKey($key);

        // exists 先判断，避免把存储的 false / 空串当成 miss
        if ($this->redis->exists($realKey) === 0) {
            return $default;
        }

        $value = $this->redis->get($realKey);
        if ($value === false && !$this->redis->exists($realKey)) {
            return $default;
        }

        return $this->decodeStoredValue($value);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $realKey = $this->getRealKey($key);

        // 统一序列化，避免数字/字符串类型丢失；整数计数器通过 increment 路径维护
        $valueToStore = serialize($value);

        if ($ttl !== null && $ttl > 0) {
            return $this->redis->setex($realKey, $ttl, $valueToStore);
        }

        return $this->redis->set($realKey, $valueToStore);
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($this->getRealKey($key)) > 0;
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($this->getRealKey($key)) > 0;
    }

    public function clear(): bool
    {
        $iterator = null;
        $success = true;

        do {
            $keys = $this->redis->scan($iterator, $this->prefix . '*', 100);
            if ($keys === false) {
                continue;
            }

            foreach ($keys as $key) {
                $success = $this->redis->del($key) !== false && $success;
            }
        } while ($iterator !== 0);

        return $success;
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        $realKey = $this->getRealKey($key);

        // 若键是序列化整数，先解码再以原生数字重写，保证 INCR 可用
        if ($this->redis->exists($realKey) > 0) {
            $raw = $this->redis->get($realKey);
            $decoded = $this->decodeStoredValue($raw);
            if (is_int($decoded) || (is_string($decoded) && ctype_digit($decoded))) {
                $this->redis->set($realKey, (string) (int) $decoded);
            } elseif (is_float($decoded) || (is_numeric($decoded) && !is_string($decoded))) {
                $this->redis->set($realKey, (string) (int) $decoded);
            }
        }

        return $this->redis->incrBy($realKey, $value);
    }

    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $realKey = $this->getRealKey($key);

        $this->redis->multi();
        $this->redis->get($realKey);
        $this->redis->del($realKey);
        $results = $this->redis->exec();

        $value = $results[0] ?? false;

        if ($value === false && ($results[1] ?? 0) === 0) {
            return $default;
        }

        if ($value === false) {
            return $default;
        }

        return $this->decodeStoredValue($value);
    }

    /**
     * 为键设置过期时间（秒）。用于限流等原子计数场景。
     */
    public function expire(string $key, int $ttl): bool
    {
        if ($ttl <= 0) {
            return true;
        }

        return $this->redis->expire($this->getRealKey($key), $ttl);
    }

    protected function decodeStoredValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // 兼容旧版：裸数字字符串（increment 路径）
        if (is_numeric($value) && !str_starts_with($value, 's:') && !str_starts_with($value, 'i:') && !str_starts_with($value, 'd:') && !str_starts_with($value, 'b:') && !str_starts_with($value, 'a:') && !str_starts_with($value, 'N;') && !str_starts_with($value, 'O:')) {
            if (!str_contains($value, ';') && !str_contains($value, ':')) {
                return str_contains($value, '.') ? (float) $value : (int) $value;
            }
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);
        if ($unserialized !== false || $value === 'b:0;') {
            return $unserialized;
        }

        return $value;
    }
}