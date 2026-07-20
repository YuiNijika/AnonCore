<?php

namespace Anon\Core\Session;

use Redis as PhpRedis;
use SessionHandlerInterface;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Env;
use Anon\Core\Support\RedisConnector;

class Redis implements SessionHandlerInterface
{
    /**
     * @var PhpRedis|null
     */
    protected ?PhpRedis $redis = null;

    /**
     * @var string Session 键前缀
     */
    protected string $prefix = '';

    /**
     * @var int Session 过期时间
     */
    protected int $lifetime;

    public function __construct()
    {
        $sessionConfig = Config::get('session', []);
        $this->prefix = is_array($sessionConfig)
            ? (string) ($sessionConfig['prefix'] ?? Env::get('SESSION_PREFIX', 'anon:session:'))
            : (string) Env::get('SESSION_PREFIX', 'anon:session:');
        $this->lifetime = (int) (is_array($sessionConfig)
            ? ($sessionConfig['lifetime'] ?? Env::get('SESSION_LIFETIME', 86400))
            : Env::get('SESSION_LIFETIME', 86400));

        $this->redis = RedisConnector::connect('session.redis', ['session.redis', 'cache.redis', 'redis']);
    }

    protected function getRealKey(string $id): string
    {
        return $this->prefix . $id;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->redis->get($this->getRealKey($id));
        return $data !== false ? $data : '';
    }

    public function write(string $id, string $data): bool
    {
        return $this->redis->setex($this->getRealKey($id), $this->lifetime, $data);
    }

    public function destroy(string $id): bool
    {
        return $this->redis->del($this->getRealKey($id)) > 0;
    }

    public function gc(int $max_lifetime): int|false
    {
        // Redis TTL 自动过期，无需手动 GC
        return 0;
    }
}