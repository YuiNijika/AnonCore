<?php

namespace Anon\Core\Console\Commands\Queue;

use Anon\Core\Console\Command;
use Anon\Core\Queue\Manager;

class Retry extends Command
{
    protected string $name = 'queue:retry';
    protected string $description = 'Retry one or all failed jobs from the queue';

    public function execute(array $args): int
    {
        $queueName = (string) $this->getOption($args, 'queue', 'default');
        $jobId = $this->getOption($args, 'id');
        $delay = max(0, (int) $this->getOption($args, 'delay', 0));
        $retryAll = $this->hasFlag($args, 'all');

        if (!$retryAll && ($jobId === null || $jobId === '')) {
            $this->error('Please provide --id=<job-id> or use --all.');
            return 1;
        }

        try {
            $app = $this->bootstrapApp();

            /** @var Manager $queue */
            $queue = $app->make('queue');

            if ($retryAll) {
                $count = $queue->retryAllFailed($queueName, $delay);
                $this->success("Retried {$count} failed job(s) from queue [{$queueName}].");
                return 0;
            }

            $retried = $queue->retryFailed((string) $jobId, $queueName, $delay);
            if (!$retried) {
                $this->error("Failed job [{$jobId}] was not found on queue [{$queueName}].");
                return 1;
            }

            $this->success("Retried failed job [{$jobId}] from queue [{$queueName}].");

            return 0;
        } catch (\Throwable $e) {
            $this->error('Unable to retry failed jobs: ' . $e->getMessage());
            return 1;
        }
    }

    protected function hasFlag(array $args, string $name): bool
    {
        return in_array('--' . $name, $args, true) || in_array('--' . $name . '=1', $args, true);
    }
}
