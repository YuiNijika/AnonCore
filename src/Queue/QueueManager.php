<?php

namespace Anon\Core\Queue;

use Exception;
use Redis as PhpRedis;
use Anon\Core\Facade\Env;

class QueueManager
{
    protected ?PhpRedis $redis = null;
    protected string $defaultQueue = 'default';
    protected string $prefix;

    public function __construct()
    {
        if (extension_loaded('redis')) {
            $host = Env::get('REDIS_HOST', '127.0.0.1');
            $port = Env::get('REDIS_PORT', 6379);
            $password = Env::get('REDIS_PASSWORD', '');
            $database = Env::get('REDIS_DB', 0);
            
            $this->prefix = Env::get('QUEUE_PREFIX', 'anon:queue:');

            $this->redis = new PhpRedis();
            $this->redis->connect($host, $port);

            if ($password !== '') {
                $this->redis->auth($password);
            }

            if ($database !== 0) {
                $this->redis->select($database);
            }
        }
    }

    /**
     * 推送任务到队列
     */
    public function push(Job $job, ?string $queue = null): bool
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queueName = $this->prefix . ($queue ?? $this->defaultQueue);
        
        // 序列化任务对象
        $payload = serialize($job);
        
        return $this->redis->lPush($queueName, $payload) !== false;
    }

    /**
     * 从队列中弹出并执行任务（阻塞模式）
     */
    public function pop(string $queue = null, int $timeout = 3): ?Job
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queueName = $this->prefix . ($queue ?? $this->defaultQueue);
        
        // 阻塞弹出，格式为 [queueName, payload]
        $result = $this->redis->brPop([$queueName], $timeout);
        
        if (empty($result)) {
            return null;
        }

        $payload = $result[1];
        
        /** @var Job $job */
        $job = unserialize($payload);
        return $job;
    }
}
