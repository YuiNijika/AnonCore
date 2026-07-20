<?php

namespace Anon\Core\Queue;

use Throwable;
use Redis as PhpRedis;
use Anon\Core\Exception\Deprecated;
use Anon\Core\Exception\Queue as QueueError;
use Anon\Core\Facade\Config;
use Anon\Core\Facade\Env;
use Anon\Core\Facade\Hook;
use Anon\Core\Support\RedisConnector;

class Manager
{
    protected ?PhpRedis $redis = null;
    protected string $defaultQueue = 'default';
    protected string $prefix = 'anon:queue:';
    protected int $defaultMaxTries = 3;
    protected bool $redisResolved = false;

    public function __construct()
    {
        $this->prefix = (string) Config::get('queue.prefix', Env::get('QUEUE_PREFIX', 'anon:queue:'));
        $this->defaultQueue = (string) Config::get('queue.default', 'default');
        $this->defaultMaxTries = (int) Config::get('queue.max_tries', 3);
    }

    /**
     * 延迟连接 Redis：仅在首次 push/pop 等操作时建立，避免未使用队列时拖垮启动。
     */
    protected function connection(): PhpRedis
    {
        if ($this->redis instanceof PhpRedis) {
            return $this->redis;
        }

        if ($this->redisResolved) {
            throw new QueueError('Queue Redis connection was already resolved but is unavailable.');
        }

        $this->redisResolved = true;
        $this->redis = RedisConnector::connect('queue.redis', ['queue.redis', 'cache.redis', 'redis']);

        return $this->redis;
    }

    /**
     * 推送任务到队列
     */
    public function push(Job $job, ?string $queue = null, int $delay = 0, ?int $maxTries = null): bool
    {
        $redis = $this->connection();

        $queue = $queue ?? $this->defaultQueue;
        $payload = $this->createPayload($job, $queue, $maxTries);

        Hook::trigger('queue_push', ['job' => $job, 'queue' => $queue, 'delay' => $delay, 'payload' => $payload]);

        if ($delay > 0) {
            return $redis->zAdd(
                $this->delayedQueueKey($queue),
                time() + $delay,
                $this->encodePayload($payload)
            ) !== false;
        }

        return $redis->lPush($this->queueKey($queue), $this->encodePayload($payload)) !== false;
    }

    /**
     * 从队列中弹出并执行任务（阻塞模式）
     *
     * @param string|null $queue 队列名称
     * @param int $timeout 阻塞超时时间(秒)
     * @return Job|null 返回任务实例，超时返回 null
     */
    public function pop(?string $queue = null, int $timeout = 3): ?Job
    {
        $payload = $this->popPayload($queue, $timeout);
        if ($payload === null) {
            return null;
        }

        return $this->decodeJob($payload);
    }

    /**
     * 从队列中弹出原始任务载荷
     *
     * @param string|null $queue 队列名称
     * @param int $timeout 阻塞超时时间(秒)
     * @return array<string, mixed>|null
     */
    public function popPayload(?string $queue = null, int $timeout = 3): ?array
    {
        $this->connection();

        $queue = $queue ?? $this->defaultQueue;
        $this->migrateDelayedJobs($queue);

        $result = $this->redis->brPop([$this->queueKey($queue)], $timeout);
        if (empty($result)) {
            return null;
        }

        return $this->decodePayload($result[1]);
    }

    /**
     * 将任务重新入队
     */
    public function release(array $payload, int $delay = 0, ?Throwable $exception = null): bool
    {
        $this->connection();

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
        $this->connection();

        $payload['attempts'] = ((int) ($payload['attempts'] ?? 0)) + 1;
        $payload['failed_at'] = time();
        $payload['last_error'] = $exception?->getMessage();

        $queue = (string) ($payload['queue'] ?? $this->defaultQueue);
        return $this->redis->lPush($this->failedQueueKey($queue), $this->encodePayload($payload)) !== false;
    }

    /**
     * 判断任务是否还可以重试
     */
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
        $this->connection();

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
        $this->connection();

        $queue = $queue ?? $this->defaultQueue;
        return (int) $this->redis->lLen($this->failedQueueKey($queue));
    }

    /**
     * 将指定失败任务重新放回队列
     */
    public function retryFailed(string $id, ?string $queue = null, int $delay = 0): bool
    {
        $this->connection();

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
        $redis = $this->connection();

        $queue = $queue ?? $this->defaultQueue;
        $failedKey = $this->failedQueueKey($queue);

        // 原子 RPOP，避免 lRange + lRem 竞态导致同一任务被多个进程重复重试
        $retried = 0;
        while (true) {
            $item = $redis->rPop($failedKey);
            if ($item === false || !is_string($item)) {
                break;
            }

            $payload = $this->decodePayload($item);
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
        $this->connection();

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
            throw new QueueError('Failed to encode queue payload.');
        }

        return $encoded;
    }

    protected function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new QueueError('Invalid queue payload.');
        }

        return $decoded;
    }

    protected function decodeJob(array $payload): Job
    {
        $raw = (string) ($payload['job'] ?? '');
        if ($raw === '') {
            throw new QueueError('Invalid queue job payload.');
        }

        // 仅允许白名单类，防止对象注入 RCE
        $allowed = $this->allowedJobClasses();
        $job = @unserialize($raw, ['allowed_classes' => $allowed]);

        if (!$job instanceof Job) {
            throw new QueueError('Invalid queue job payload.');
        }

        return $job;
    }

    /**
     * 从载荷安全还原 Job（白名单反序列化）。
     *
     * @param array<string, mixed> $payload
     */
    public function resolveJob(array $payload): Job
    {
        return $this->decodeJob($payload);
    }

    /**
     * @deprecated 无限制反序列化已移除；请配置 queue.allowed_job_classes 为 class-string 列表。
     * @throws \Anon\Core\Exception\Deprecated
     */
    public function allowUnsafeJobUnserialize(): never
    {
        throw Deprecated::method(
            __METHOD__,
            'queue.allowed_job_classes as an explicit class-string[] allowlist'
        );
    }

    /**
     * @return list<class-string>
     */
    protected function allowedJobClasses(): array
    {
        $configured = Config::get('queue.allowed_job_classes', null);

        // 不再兼容 true / '*' 全量反序列化
        if ($configured === true || $configured === '*') {
            throw Deprecated::config(
                'queue.allowed_job_classes=true|\'*\'',
                'Configure an explicit list of Job class names instead.'
            );
        }

        $classes = [Job::class];

        if (is_array($configured)) {
            foreach ($configured as $class) {
                if (is_string($class) && $class !== '') {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * 将到期延迟任务迁移到就绪队列。
     * 每批 100 条，循环直到本轮没有到期项，避免单次 pop 只迁 100 导致积压。
     */
    protected function migrateDelayedJobs(string $queue): void
    {
        if (!$this->redis) {
            return;
        }

        $delayedKey = $this->delayedQueueKey($queue);
        $readyKey = $this->queueKey($queue);
        $now = (string) time();
        $batchSize = 100;
        // 硬上限防止异常情况下死循环
        $maxBatches = 50;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $duePayloads = $this->redis->zRangeByScore(
                $delayedKey,
                '-inf',
                $now,
                ['limit' => [0, $batchSize]]
            );

            if (!is_array($duePayloads) || $duePayloads === []) {
                return;
            }

            foreach ($duePayloads as $payload) {
                if ($this->redis->zRem($delayedKey, $payload) > 0) {
                    $this->redis->lPush($readyKey, $payload);
                }
            }

            if (count($duePayloads) < $batchSize) {
                return;
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