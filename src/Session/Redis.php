<?php

namespace Anon\Core\Session;

use Redis as PhpRedis;
use SessionHandlerInterface;
use Anon\Core\Facade\Env;
use Exception;

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
        if (!extension_loaded('redis')) {
            throw new Exception("The 'redis' extension is required to use Redis session.");
        }

        $host = Env::get('REDIS_HOST', '127.0.0.1');
        $port = Env::get('REDIS_PORT', 6379);
        $password = Env::get('REDIS_PASSWORD', '');
        $database = Env::get('REDIS_DB', 0);
        
        $this->prefix = Env::get('SESSION_PREFIX', 'anon:session:');
        $this->lifetime = (int) Env::get('SESSION_LIFETIME', 86400);

        $this->redis = new PhpRedis();
        $this->redis->connect($host, $port);

        if ($password !== '') {
            $this->redis->auth($password);
        }

        if ($database !== 0) {
            $this->redis->select($database);
        }
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
        // Redis 会自动处理过期，不需要手动 GC
        return 0;
    }
}