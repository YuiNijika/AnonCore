<?php

namespace Anon\Core\Console\Commands\Queue;

use Anon\Core\Console\Command;
use Anon\Core\Facade\Queue;
use Throwable;

class Work extends Command
{
    protected string $name = 'queue:work';
    protected string $description = 'Start processing jobs on the queue as a daemon';

    public function execute(array $args): int
    {
        $this->bootstrapApp();

        $queueName = $this->getOption($args, 'queue', 'default');
        $backoff = (int) $this->getOption($args, 'backoff', 3);
        
        $this->info("Queue worker started for queue: [{$queueName}]. Press Ctrl+C to stop.");

        while (true) {
            try {
                $payload = Queue::popPayload($queueName, 3);

                if ($payload) {
                    $job = unserialize((string) ($payload['job'] ?? ''));
                    if (!$job instanceof \Anon\Core\Queue\Job) {
                        $this->error('Invalid job payload received.');
                        continue;
                    }

                    $jobClass = get_class($job);
                    $attempt = ((int) ($payload['attempts'] ?? 0)) + 1;
                    $maxTries = (int) ($payload['max_tries'] ?? 1);
                    $this->info("Processing: {$jobClass} [attempt {$attempt}/{$maxTries}]");
                    
                    // 记录开始时�?                    $start = microtime(true);

                    try {
                        $job->handle();
                        $time = round((microtime(true) - $start) * 1000, 2);
                        $this->success("Processed: {$jobClass} ({$time}ms)");
                    } catch (Throwable $e) {
                        if (Queue::canRetry($payload)) {
                            Queue::release($payload, $backoff, $e);
                            $this->warning("Released: {$jobClass} -> retry after {$backoff}s ({$e->getMessage()})");
                        } else {
                            Queue::fail($payload, $e);
                            $this->error("Failed permanently: {$jobClass} ({$e->getMessage()})");
                        }
                    }
                    
                    // 执行完一个任务后，主动进行一次垃圾回收，防止常驻进程内存泄漏
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            } catch (Throwable $e) {
                $this->error("Job failed: " . $e->getMessage());
                // 遇到异常（如 Redis 断开），休眠几秒防止死循环耗尽 CPU
                sleep(3);
            }
        }

        return 0;
    }
}
