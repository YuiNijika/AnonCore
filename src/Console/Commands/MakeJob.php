<?php

namespace Anon\Core\Console\Commands;

use Anon\Core\Console\Command;

class MakeJob extends Command
{
    protected string $name = 'make:job';
    protected string $description = 'Create a new queue job class';

    public function execute(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Job name is required.");
            return 1;
        }

        $name = ucfirst($args[0]);
        $fileName = "{$name}.php";
        
        $dir = APP_PATH . DIRECTORY_SEPARATOR . 'jobs';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

        $template = <<<PHP
<?php

namespace App\Jobs;

use Anon\Core\Queue\Job;
use Anon\Core\Facade\Log;

class {$name} implements Job
{
    protected array \$data;

    public function __construct(array \$data = [])
    {
        \$this->data = \$data;
    }

    public function handle(): void
    {
        // 执行耗时任务逻辑
        Log::info("Job {$name} executed.", \$this->data);
    }
}
PHP;

        file_put_contents($filePath, $template);
        $this->success("Job created successfully: {$fileName}");

        return 0;
    }
}
