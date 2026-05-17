<?php

namespace Anon\Core\Console\Commands\Make;

use Anon\Core\Console\Command;

class Controller extends Command
{
    protected string $name = 'make:controller';
    protected string $description = 'Create a new controller class';

    public function execute(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''), "\\/ \t\n\r\0\x0B");
        if ($name === '') {
            $this->error("Please provide a controller name.");
            return 1;
        }

        $segments = array_values(array_filter(preg_split('/[\\\\\/]+/', $name) ?: []));
        if ($segments === []) {
            $this->error("Invalid controller name.");
            return 1;
        }

        if ($this->usesGroupMode($args)) {
            $segments[] = 'Index';
        }

        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                $this->error("Invalid controller segment [{$segment}].");
                return 1;
            }
        }

        $className = array_pop($segments);
        $namespace = 'Anon\\Controller' . ($segments !== [] ? '\\' . implode('\\', $segments) : '');
        $relativePath = ($segments !== [] ? implode('/', $segments) . '/' : '') . $className . '.php';
        $path = APP_PATH . '/controller/' . $relativePath;

        if (file_exists($path)) {
            $this->error("Controller already exists!");
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = $this->buildStub($namespace, $className, $args);

        file_put_contents($path, $stub);
        $this->info("Controller [{$name}] created successfully.");
        return 0;
    }

    protected function usesGroupMode(array $args): bool
    {
        foreach ($args as $arg) {
            if (in_array($arg, ['--group', '--dir', '--index'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function usesResourceMode(array $args): bool
    {
        foreach ($args as $arg) {
            if (in_array($arg, ['--resource', '--rest', '--crud'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function buildStub(string $namespace, string $className, array $args): string
    {
        if ($this->usesResourceMode($args)) {
            return <<<EOF
<?php

namespace {$namespace};

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;

class {$className}
{
    public function index(Request \$request): Response
    {
        return Response::success([
            ['id' => 1, 'name' => '{$className} A'],
            ['id' => 2, 'name' => '{$className} B'],
        ], 'List Success');
    }

    public function show(Request \$request, string \$id): Response
    {
        return Response::success([
            'id' => \$id,
            'route_id' => \$request->route('id'),
        ], 'Detail Success');
    }

    public function store(Request \$request): Response
    {
        return Response::success([
            'data' => \$request->input(),
        ], 'Create Success');
    }

    public function update(Request \$request, string \$id): Response
    {
        return Response::success([
            'id' => \$id,
            'data' => \$request->input(),
        ], 'Update Success');
    }

    public function delete(Request \$request, string \$id): Response
    {
        return Response::success([
            'id' => \$id,
        ], 'Delete Success');
    }
}
EOF;
        }

        return <<<EOF
<?php

namespace {$namespace};

use Anon\Core\Http\Request;
use Anon\Core\Http\Response;

class {$className}
{
    public function index(Request \$request): Response
    {
        return Response::success(['message' => 'Hello from {$className}']);
    }
}
EOF;
    }
}
