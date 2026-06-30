<?php

namespace Anon\Core\Console\Commands\Make;

use Anon\Core\Console\Command;

class Action extends Command
{
    protected string $name = 'make:action';
    protected string $description = 'Create a new server action class';

    public function execute(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''), "\\/ \t\n\r\0\x0B");
        if ($name === '') {
            $this->error('Please provide an action name.');
            return 1;
        }

        $segments = array_values(array_filter(preg_split('/[\\\\\/]+/', $name) ?: []));
        if ($segments === []) {
            $this->error('Invalid action name.');
            return 1;
        }

        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                $this->error("Invalid action segment [{$segment}].");
                return 1;
            }
        }

        $className = array_pop($segments);
        $namespace = 'Anon\\Action' . ($segments !== [] ? '\\' . implode('\\', $segments) : '');
        $relativePath = ($segments !== [] ? implode('/', $segments) . '/' : '') . $className . '.php';
        $actionDirectory = is_dir(APP_PATH . '/Action') ? 'Action' : 'action';
        $path = APP_PATH . '/' . $actionDirectory . '/' . $relativePath;

        if (file_exists($path)) {
            $this->error('Action already exists!');
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $actionName = $this->actionName($segments, $className);
        $stub = <<<EOF
<?php

namespace {$namespace};

use Anon\Core\Action\Action;
use Anon\Core\Http\Request;

class {$className} extends Action
{
    public function code(mixed \$result = null, ?Request \$request = null): string
    {
        return 'ACTION_OK';
    }

    public function message(mixed \$result = null, ?Request \$request = null): string
    {
        return 'Action executed.';
    }

    public function handle(Request \$request): array
    {
        return [
            'action' => '{$actionName}',
            'input' => \$request->input(),
        ];
    }
}
EOF;

        file_put_contents($path, $stub);
        $this->success("Server Action [{$name}] created successfully.");
        return 0;
    }

    /**
     * @param array<int, string> $segments
     */
    protected function actionName(array $segments, string $className): string
    {
        $parts = array_merge($segments, [$className]);
        $parts = array_map(static function (string $part): string {
            $part = preg_replace('/(?<!^)[A-Z]/', '.$0', $part) ?: $part;
            return strtolower(str_replace('_', '.', $part));
        }, $parts);

        return implode('.', $parts);
    }
}
