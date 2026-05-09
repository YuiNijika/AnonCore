<?php

namespace Anon\Core\Facade;

use Anon\Core\Queue\QueueManager;

/**
 * @method static bool push(\Anon\Core\Queue\Job $job, ?string $queue = null)
 * @method static \Anon\Core\Queue\Job|null pop(string $queue = null, int $timeout = 3)
 */
class Queue extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'queue';
    }
}
