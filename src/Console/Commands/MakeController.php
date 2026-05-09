<?php

namespace Anon\Core\Console\Commands;

use Anon\Core\Console\Command;

class MakeController extends Command
{
    protected string $name = 'make:controller';
    protected string $description = 'Create a new controller class';

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if (!$name) {
            $this->error("Please provide a controller name.");
            return 1;
        }

        $path = APP_PATH . '/controller/' . $name . '.php';
        if (file_exists($path)) {
            $this->error("Controller already exists!");
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = <<<EOF
<?php

namespace Anon\Controller;

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;

class {$name}
{
    public function index(Request \$request)
    {
        return Response::success(['message' => 'Hello from {$name}']);
    }
}
EOF;

        file_put_contents($path, $stub);
        $this->info("Controller [{$name}] created successfully.");
        return 0;
    }
}
