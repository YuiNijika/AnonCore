<?php

namespace Anon\Core\Cache;

use Redis as PhpRedis;
use Exception;
use Anon\Core\Facade\Env;

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
        if (!extension_loaded('redis')) {
            throw new Exception("The 'redis' extension is required to use Redis cache.");
        }

        $host = Env::get('REDIS_HOST', '127.0.0.1');
        $port = Env::get('REDIS_PORT', 6379);
        $password = Env::get('REDIS_PASSWORD', '');
        $database = Env::get('REDIS_DB', 0);
        $this->prefix = Env::get('REDIS_PREFIX', 'anon:cache:');

        $this->redis = new PhpRedis();
        $this->redis->connect($host, $port);

        if ($password !== '') {
            $this->redis->auth($password);
        }

        if ($database !== 0) {
            $this->redis->select($database);
        }
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
        $value = $this->redis->get($this->getRealKey($key));
        
        if ($value === false) {
            return $default;
        }

        // 尝试反序列化
        $unserialized = @unserialize($value);
        return $unserialized !== false ? $unserialized : $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $realKey = $this->getRealKey($key);
        // 对数组或对象进行序列化
        $valueToStore = (is_array($value) || is_object($value)) ? serialize($value) : $value;

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
        // 危险操作：清空当前数据库的所有数据
        // 在实际业务中可能需要使用 scan 命令只清除指定 prefix 的键
        return $this->redis->flushDB();
    }
}
