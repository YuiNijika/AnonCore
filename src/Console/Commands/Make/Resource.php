<?php

namespace Anon\Core\Console\Commands\Make;

use Anon\Core\Console\Command;

class Resource extends Command
{
    protected string $name = 'make:resource';
    protected string $description = 'Create a new API resource class';

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if (!$name) {
            $this->error("Please provide a resource name.");
            return 1;
        }

        $path = APP_PATH . '/Http/Resources/' . $name . '.php';
        if (file_exists($path)) {
            $this->error("Resource already exists!");
            return 1;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stub = <<<EOF
<?php

namespace Anon\Http\Resources;

use Anon\Core\Http\Resource\Json;
use Anon\Core\Http\Request;

class {$name} extends Json
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request \$request): array
    {
        return parent::toArray(\$request);
    }
}
EOF;

        file_put_contents($path, $stub);
        $this->success("API Resource [{$name}] created successfully.");
        return 0;
    }
}
