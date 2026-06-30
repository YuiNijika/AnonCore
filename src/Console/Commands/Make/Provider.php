<?php

namespace Anon\Core\Console\Commands\Make;

use Anon\Core\Console\Command;

class Provider extends Command
{
    protected string $name = 'make:provider';
    protected string $description = 'Create a new service provider class';

    public function execute(array $args): int
    {
        $name = trim((string) ($args[0] ?? ''), "\\/ \t\n\r\0\x0B");
        if ($name === '') {
            $this->error('Please provide a provider name.');
            return 1;
        }

        $segments = array_values(array_filter(preg_split('/[\\\\\/]+/', $name) ?: []));
        if ($segments === []) {
            $this->error('Invalid provider name.');
            return 1;
        }

        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                $this->error("Invalid provider segment [{$segment}].");
                return 1;
            }
        }

        $className = array_pop($segments);
        $namespace = 'Anon\\Provider' . ($segments !== [] ? '\\' . implode('\\', $segments) : '');
        $relativePath = ($segments !== [] ? implode('/', $segments) . '/' : '') . $className . '.php';
        $providerDirectory = is_dir(APP_PATH . '/Provider') ? 'Provider' : 'provider';
        $path = APP_PATH . '/' . $providerDirectory . '/' . $relativePath;

        if (file_exists($path)) {
            $this->error('Provider already exists!');
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = <<<EOF
<?php

namespace {$namespace};

use Anon\Core\Foundation\ServiceProvider;

class {$className} extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
EOF;

        file_put_contents($path, $stub);
        $this->success("Service Provider [{$name}] created successfully.");
        return 0;
    }
}
