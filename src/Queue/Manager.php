<?php

namespace Anon\Core\Queue;

use Exception;
use Throwable;
use Redis as PhpRedis;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Env;
use Anon\Core\Facade\Hook;

class Manager
{
    protected ?PhpRedis $redis = null;
    protected string $defaultQueue = 'default';
    protected string $prefix;
    protected int $defaultMaxTries = 3;

    public function __construct()
    {
        if (extension_loaded('redis')) {
            $redisConfig = Config::get('queue.redis', Config::get('cache.redis', Config::get('redis', [])));
            $queueConfig = Config::get('queue', []);

            $host = is_array($redisConfig) ? ($redisConfig['host'] ?? Env::get('REDIS_HOST', '127.0.0.1')) : Env::get('REDIS_HOST', '127.0.0.1');
            $port = is_array($redisConfig) ? ($redisConfig['port'] ?? Env::get('REDIS_PORT', 6379)) : Env::get('REDIS_PORT', 6379);
            $password = is_array($redisConfig) ? ($redisConfig['password'] ?? Env::get('REDIS_PASSWORD', '')) : Env::get('REDIS_PASSWORD', '');
            $database = is_array($redisConfig) ? ($redisConfig['database'] ?? Env::get('REDIS_DB', 0)) : Env::get('REDIS_DB', 0);

            $this->prefix = (string) Config::get('queue.prefix', Env::get('QUEUE_PREFIX', 'anon:queue:'));
            $this->defaultQueue = (string) Config::get('queue.default', 'default');
            $this->defaultMaxTries = (int) Config::get('queue.max_tries', 3);

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
    public function push(Job $job, ?string $queue = null, int $delay = 0, ?int $maxTries = null): bool
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queue = $queue ?? $this->defaultQueue;
        $payload = $this->createPayload($job, $queue, $maxTries);
        
        Hook::trigger('queue_push', ['job' => $job, 'queue' => $queue, 'delay' => $delay, 'payload' => $payload]);

        if ($delay > 0) {
            return $this->redis->zAdd(
                $this->delayedQueueKey($queue),
                time() + $delay,
                $this->encodePayload($payload)
            ) !== false;
        }

        return $this->redis->lPush($this->queueKey($queue), $this->encodePayload($payload)) !== false;
    }

    /**
     * 从队列中弹出并执行任�?     */
    public function pop(?string $queue = null, int $timeout = 3): ?Job
    {
        $payload = $this->popPayload($queue, $timeout);
        if ($payload === null) {
            return null;
        }

        return $this->decodeJob($payload);
    }

    /**
     * 从队列中弹出原始任务�?     */
    public function popPayload(?string $queue = null, int $timeout = 3): ?array
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queue = $queue ?? $this->defaultQueue;
        $this->migrateDelayedJobs($queue);

        $result = $this->redis->brPop([$this->queueKey($queue)], $timeout);
        if (empty($result)) {
            return null;
        }

        return $this->decodePayload($result[1]);
    }

    /**
     * 将任务重新入�?     */
    public function release(array $payload, int $delay = 0, ?Throwable $exception = null): bool
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $payload['attempts'] = ((int) ($payload['attempts'] ?? 0)) + 1;
        $payload['last_error'] = $exception?->getMessage();
        $payload['released_at'] = time();

        $queue = (string) ($payload['queue'] ?? $this->defaultQueue);
        $encoded = $this->encodePayload($payload);

        if ($delay > 0) {
            return $this->redis->zAdd($this->delayedQueueKey($queue), time() + $delay, $encoded) !== false;
        }

        return $this->redis->lPush($this->queueKey($queue), $encoded) !== false;
    }

    /**
     * 写入失败队列
     */
    public function fail(array $payload, ?Throwable $exception = null): bool
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $payload['attempts'] = ((int) ($payload['attempts'] ?? 0)) + 1;
        $payload['failed_at'] = time();
        $payload['last_error'] = $exception?->getMessage();

        $queue = (string) ($payload['queue'] ?? $this->defaultQueue);
        return $this->redis->lPush($this->failedQueueKey($queue), $this->encodePayload($payload)) !== false;
    }

    /**
     * 判断任务是否还可以重�?     */
    public function canRetry(array $payload): bool
    {
        $attempts = (int) ($payload['attempts'] ?? 0);
        $maxTries = (int) ($payload['max_tries'] ?? $this->defaultMaxTries);

        return ($attempts + 1) < $maxTries;
    }

    /**
     * 读取失败队列中的任务
     *
     * @return array<int, array<string, mixed>>
     */
    public function failed(?string $queue = null, int $limit = 20, int $offset = 0): array
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queue = $queue ?? $this->defaultQueue;
        $end = $limit > 0 ? ($offset + $limit - 1) : -1;
        $items = $this->redis->lRange($this->failedQueueKey($queue), $offset, $end);

        if (!is_array($items) || $items === []) {
            return [];
        }

        $payloads = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }

            $payloads[] = $this->decodePayload($item);
        }

        return $payloads;
    }

    /**
     * 获取失败任务总数
     */
    public function failedCount(?string $queue = null): int
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queue = $queue ?? $this->defaultQueue;
        return (int) $this->redis->lLen($this->failedQueueKey($queue));
    }

    /**
     * 将指定失败任务重新放回队�?     */
    public function retryFailed(string $id, ?string $queue = null, int $delay = 0): bool
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queue = $queue ?? $this->defaultQueue;
        $record = $this->findFailedRecord($queue, $id);
        if ($record === null) {
            return false;
        }

        ['encoded' => $encoded, 'payload' => $payload] = $record;
        $removed = $this->redis->lRem($this->failedQueueKey($queue), $encoded, 1);
        if ($removed === false || $removed < 1) {
            return false;
        }

        return $this->pushRetryPayload($payload, $queue, $delay);
    }

    /**
     * 重试指定队列中的全部失败任务
     */
    public function retryAllFailed(?string $queue = null, int $delay = 0): int
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queue = $queue ?? $this->defaultQueue;
        $items = $this->redis->lRange($this->failedQueueKey($queue), 0, -1);
        if (!is_array($items) || $items === []) {
            return 0;
        }

        $retried = 0;
        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }

            $payload = $this->decodePayload($item);
            $removed = $this->redis->lRem($this->failedQueueKey($queue), $item, 1);
            if ($removed === false || $removed < 1) {
                continue;
            }

            if ($this->pushRetryPayload($payload, $queue, $delay)) {
                $retried++;
            }
        }

        return $retried;
    }

    /**
     * 清空失败队列
     */
    public function clearFailed(?string $queue = null): int
    {
        if (!$this->redis) {
            throw new Exception("Redis extension is required for Queue.");
        }

        $queue = $queue ?? $this->defaultQueue;
        $count = (int) $this->redis->lLen($this->failedQueueKey($queue));
        if ($count === 0) {
            return 0;
        }

        $this->redis->del($this->failedQueueKey($queue));

        return $count;
    }

    protected function queueKey(string $queue): string
    {
        return $this->prefix . $queue;
    }

    protected function delayedQueueKey(string $queue): string
    {
        return $this->queueKey($queue) . ':delayed';
    }

    protected function failedQueueKey(string $queue): string
    {
        return $this->queueKey($queue) . ':failed';
    }

    protected function createPayload(Job $job, string $queue, ?int $maxTries = null): array
    {
        return [
            'id' => bin2hex(random_bytes(16)),
            'queue' => $queue,
            'job' => serialize($job),
            'attempts' => 0,
            'max_tries' => $maxTries ?? $this->defaultMaxTries,
            'pushed_at' => time(),
        ];
    }

    protected function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new Exception('Failed to encode queue payload.');
        }

        return $encoded;
    }

    protected function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new Exception('Invalid queue payload.');
        }

        return $decoded;
    }

    protected function decodeJob(array $payload): Job
    {
        $job = unserialize((string) ($payload['job'] ?? ''));
        if (!$job instanceof Job) {
            throw new Exception('Invalid queue job payload.');
        }

        return $job;
    }

    protected function migrateDelayedJobs(string $queue): void
    {
        if (!$this->redis) {
            return;
        }

        $delayedKey = $this->delayedQueueKey($queue);
        $duePayloads = $this->redis->zRangeByScore($delayedKey, '-inf', (string) time(), ['limit' => [0, 100]]);

        if (!is_array($duePayloads) || $duePayloads === []) {
            return;
        }

        foreach ($duePayloads as $payload) {
            if ($this->redis->zRem($delayedKey, $payload) > 0) {
                $this->redis->lPush($this->queueKey($queue), $payload);
            }
        }
    }

    /**
     * @return array{encoded: string, payload: array<string, mixed>}|null
     */
    protected function findFailedRecord(string $queue, string $id): ?array
    {
        if (!$this->redis) {
            return null;
        }

        $items = $this->redis->lRange($this->failedQueueKey($queue), 0, -1);
        if (!is_array($items) || $items === []) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_string($item)) {
                continue;
            }

            $payload = $this->decodePayload($item);
            if ((string) ($payload['id'] ?? '') === $id) {
                return [
                    'encoded' => $item,
                    'payload' => $payload,
                ];
            }
        }

        return null;
    }

    protected function pushRetryPayload(array $payload, string $queue, int $delay = 0): bool
    {
        if (!$this->redis) {
            return false;
        }

        $payload['queue'] = $queue;
        $payload['attempts'] = 0;
        $payload['retried_at'] = time();
        unset($payload['failed_at']);

        $encoded = $this->encodePayload($payload);

        if ($delay > 0) {
            return $this->redis->zAdd($this->delayedQueueKey($queue), time() + $delay, $encoded) !== false;
        }

        return $this->redis->lPush($this->queueKey($queue), $encoded) !== false;
    }
}
