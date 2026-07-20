<?php

namespace Anon\Core\Console\Commands\Queue;

use Anon\Core\Console\Command;
use Anon\Core\Facade\Hook;
use Anon\Core\Facade\Queue;
use Anon\Core\Queue\Job;
use Anon\Core\Queue\Manager;
use Throwable;

class Work extends Command
{
    protected string $name = 'queue:work';
    protected string $description = 'Start processing jobs on the queue as a daemon';

    public function execute(array $args): int
    {
        $app = $this->bootstrapApp();

        /** @var Manager $queue */
        $queue = $app->make('queue');

        $queueName = $this->getOption($args, 'queue', 'default');
        $backoff = (int) $this->getOption($args, 'backoff', 3);

        $this->info("Queue worker started for queue: [{$queueName}]. Press Ctrl+C to stop.");

        while (true) {
            try {
                $payload = $queue->popPayload($queueName, 3);

                if ($payload === null) {
                    continue;
                }

                // 必须走 Manager 白名单反序列化，禁止裸 unserialize
                $job = $queue->resolveJob($payload);
                $jobClass = $job::class;
                $attempt = ((int) ($payload['attempts'] ?? 0)) + 1;
                $maxTries = (int) ($payload['max_tries'] ?? 1);
                $this->info("Processing: {$jobClass} [attempt {$attempt}/{$maxTries}]");

                $start = microtime(true);

                Hook::trigger('queue_job_process', [
                    'job' => $job,
                    'payload' => $payload,
                    'attempt' => $attempt,
                ]);

                try {
                    $job->handle();
                    $time = round((microtime(true) - $start) * 1000, 2);

                    Hook::trigger('queue_job_success', [
                        'job' => $job,
                        'payload' => $payload,
                        'time' => $time,
                    ]);
                    $this->success("Processed: {$jobClass} ({$time}ms)");
                } catch (Throwable $e) {
                    Hook::trigger('queue_job_failed', [
                        'job' => $job,
                        'payload' => $payload,
                        'exception' => $e,
                    ]);

                    if ($queue->canRetry($payload)) {
                        $queue->release($payload, $backoff, $e);
                        $this->warning("Released: {$jobClass} -> retry after {$backoff}s ({$e->getMessage()})");
                    } else {
                        $queue->fail($payload, $e);
                        $this->error("Failed permanently: {$jobClass} ({$e->getMessage()})");
                    }
                }

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            } catch (Throwable $e) {
                $this->error('Job failed: ' . $e->getMessage());
                // Redis 断开等场景休眠，避免空转打满 CPU
                sleep(3);
            }
        }

        return 0;
    }
}