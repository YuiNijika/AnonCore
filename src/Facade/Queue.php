<?php

namespace Anon\Core\Facade;

/**
 * @method static bool push(\Anon\Core\Queue\Job $job, ?string $queue = null, int $delay = 0, ?int $maxTries = null)
 * @method static \Anon\Core\Queue\Job|null pop(?string $queue = null, int $timeout = 3)
 * @method static array|null popPayload(?string $queue = null, int $timeout = 3)
 * @method static \Anon\Core\Queue\Job resolveJob(array $payload)
 * @method static bool release(array $payload, int $delay = 0, ?\Throwable $exception = null)
 * @method static bool fail(array $payload, ?\Throwable $exception = null)
 * @method static bool canRetry(array $payload)
 * @method static array failed(?string $queue = null, int $limit = 20, int $offset = 0)
 * @method static int failedCount(?string $queue = null)
 * @method static bool retryFailed(string $id, ?string $queue = null, int $delay = 0)
 * @method static int retryAllFailed(?string $queue = null, int $delay = 0)
 * @method static int clearFailed(?string $queue = null)
 *
 * @method static never allowUnsafeJobUnserialize() 已废弃：调用抛出 Deprecated，不再允许全量反序列化
 */
class Queue extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'queue';
    }
}