<?php

namespace Anon\Core\Console\Commands\Make;

use Anon\Core\Console\Command;

class Middleware extends Command
{
    protected string $name = 'make:middleware';
    protected string $description = 'Create a new middleware class';

    public function execute(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''), "\\/ \t\n\r\0\x0B");
        if ($name === '') {
            $this->error("Please provide a middleware name.");
            return 1;
        }

        $segments = array_values(array_filter(preg_split('/[\\\\\/]+/', $name) ?: []));
        if ($segments === []) {
            $this->error("Invalid middleware name.");
            return 1;
        }

        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                $this->error("Invalid middleware segment [{$segment}].");
                return 1;
            }
        }

        $className = array_pop($segments);
        $namespace = 'Anon\\Middleware' . ($segments !== [] ? '\\' . implode('\\', $segments) : '');
        $relativePath = ($segments !== [] ? implode('/', $segments) . '/' : '') . $className . '.php';
        $middlewareDirectory = is_dir(APP_PATH . '/Middleware') ? 'Middleware' : 'middleware';
        $path = APP_PATH . '/' . $middlewareDirectory . '/' . $relativePath;

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

namespace {$namespace};

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;

class {$className}
{
    public function handle(Request \$request, \Closure \$next): Response
    {
        // 预处�?        
        \$response = \$next(\$request);
        
        // 后处�?        
        return \$response;
    }
}
EOF;

        file_put_contents($path, $stub);
        $this->info("Middleware [{$name}] created successfully.");
        return 0;
    }
}
