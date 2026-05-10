<?php

namespace Anon\Core\Console\Commands;

use Anon\Core\Console\Command;
use Anon\Core\Facade\Queue;
use Throwable;

class QueueWork extends Command
{
    protected string $name = 'queue:work';
    protected string $description = 'Start processing jobs on the queue as a daemon';

    public function execute(array $args): int
    {
        $queueName = $this->getOption($args, 'queue', 'default');
        
        $this->info("Queue worker started for queue: [{$queueName}]. Press Ctrl+C to stop.");

        while (true) {
            try {
                // pop 方法是阻塞的 (brPop)，如果超时没有任务，会返回 null，不用担心 CPU 空转
                $job = Queue::pop($queueName, 3);
                
                if ($job) {
                    $jobClass = get_class($job);
                    $this->info("Processing: {$jobClass}");
                    
                    // 记录开始时间
                    $start = microtime(true);
                    
                    $job->handle();
                    
                    $time = round((microtime(true) - $start) * 1000, 2);
                    $this->success("Processed:  {$jobClass} ({$time}ms)");
                    
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
