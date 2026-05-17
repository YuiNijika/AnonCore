<?php

namespace Anon\Core\Console\Commands\Queue;

use Anon\Core\Console\Command;
use Anon\Core\Queue\Job;
use Anon\Core\Queue\Manager;

class Failed extends Command
{
    protected string $name = 'queue:failed';
    protected string $description = 'List failed jobs from the queue';

    public function execute(array $args): int
    {
        $queueName = (string) $this->getOption($args, 'queue', 'default');
        $limit = max(1, (int) $this->getOption($args, 'limit', 20));
        $offset = max(0, (int) $this->getOption($args, 'offset', 0));

        try {
            $app = $this->bootstrapApp();

            /** @var Manager $queue */
            $queue = $app->make('queue');
            $items = $queue->failed($queueName, $limit, $offset);
            $total = $queue->failedCount($queueName);

            if ($items === []) {
                $this->info("No failed jobs found on queue [{$queueName}].");
                return 0;
            }

            $this->info("Failed jobs on queue [{$queueName}] (showing " . count($items) . " of {$total}):");

            foreach ($items as $payload) {
                $jobClass = $this->resolveJobClass($payload);
                $failedAt = isset($payload['failed_at']) ? date('Y-m-d H:i:s', (int) $payload['failed_at']) : '-';
                $attempts = (int) ($payload['attempts'] ?? 0);
                $maxTries = (int) ($payload['max_tries'] ?? 0);
                $message = sprintf(
                    '%s | %s | attempts %d/%d | failed_at %s',
                    (string) ($payload['id'] ?? '-'),
                    $jobClass,
                    $attempts,
                    $maxTries,
                    $failedAt
                );

                $this->info($message);

                if (!empty($payload['last_error'])) {
                    $this->warning('  last_error: ' . (string) $payload['last_error']);
                }
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error('Unable to read failed jobs: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function resolveJobClass(array $payload): string
    {
        $job = @unserialize((string) ($payload['job'] ?? ''));

        if ($job instanceof Job) {
            return get_class($job);
        }

        return 'UnknownJob';
    }
}
