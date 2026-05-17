<?php

namespace Anon\Core\Console\Commands\Queue;

use Anon\Core\Console\Command;
use Anon\Core\Queue\Manager;

class ClearFailed extends Command
{
    protected string $name = 'queue:clear-failed';
    protected string $description = 'Clear all failed jobs from the queue';

    public function execute(array $args): int
    {
        $queueName = (string) $this->getOption($args, 'queue', 'default');

        try {
            $app = $this->bootstrapApp();

            /** @var Manager $queue */
            $queue = $app->make('queue');
            $count = $queue->clearFailed($queueName);

            if ($count === 0) {
                $this->info("No failed jobs to clear on queue [{$queueName}].");
                return 0;
            }

            $this->success("Cleared {$count} failed job(s) from queue [{$queueName}].");

            return 0;
        } catch (\Throwable $e) {
            $this->error('Unable to clear failed jobs: ' . $e->getMessage());
            return 1;
        }
    }
}
