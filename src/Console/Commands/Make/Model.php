<?php

namespace Anon\Core\Console\Commands\Make;

use Anon\Core\Console\Command;

class Model extends Command
{
    protected string $name = 'make:model';
    protected string $description = 'Create a new model class';

    public function execute(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''), "\\/ \t\n\r\0\x0B");
        if ($name === '') {
            $this->error("Please provide a model name.");
            return 1;
        }

        $segments = array_values(array_filter(preg_split('/[\\\\\/]+/', $name) ?: []));
        if ($segments === []) {
            $this->error("Invalid model name.");
            return 1;
        }

        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                $this->error("Invalid model segment [{$segment}].");
                return 1;
            }
        }

        $className = array_pop($segments);
        $namespace = 'Anon\\Model' . ($segments !== [] ? '\\' . implode('\\', $segments) : '');
        $relativePath = ($segments !== [] ? implode('/', $segments) . '/' : '') . $className . '.php';
        $path = APP_PATH . '/model/' . $relativePath;

        if (file_exists($path)) {
            $this->error("Model already exists!");
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = <<<EOF
<?php

namespace {$namespace};

use Anon\Core\Database\Model;

class {$className} extends Model
{
    protected string \$table = '';
    protected string \$primaryKey = 'id';
}
EOF;

        file_put_contents($path, $stub);
        $this->info("Model [{$name}] created successfully.");
        return 0;
    }
}
