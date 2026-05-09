<?php

namespace Anon\Core\Console\Commands;

use Anon\Core\Console\Command;

class MakeMiddleware extends Command
{
    protected string $name = 'make:middleware';
    protected string $description = 'Create a new middleware class';

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if (!$name) {
            $this->error("Please provide a middleware name.");
            return 1;
        }

        $path = APP_PATH . '/middleware/' . $name . '.php';
        if (file_exists($path)) {
            $this->error("Middleware already exists!");
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = <<<EOF
<?php

namespace Anon\Middleware;

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;

class {$name}
{
    public function handle(Request \$request, \Closure \$next): Response
    {
        // 预处理
        
        \$response = \$next(\$request);
        
        // 后处理
        
        return \$response;
    }
}
EOF;

        file_put_contents($path, $stub);
        $this->info("Middleware [{$name}] created successfully.");
        return 0;
    }
}
