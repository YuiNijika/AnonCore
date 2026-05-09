<?php

namespace Anon\Core\Console\Commands;

use Anon\Core\Console\Command;

class MakeModel extends Command
{
    protected string $name = 'make:model';
    protected string $description = 'Create a new model class';

    public function execute(array $args): int
    {
        $name = $args[0] ?? null;
        if (!$name) {
            $this->error("Please provide a model name.");
            return 1;
        }

        $path = APP_PATH . '/model/' . $name . '.php';
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

namespace Anon\Model;

use Anon\Core\Database\Model;

class {$name} extends Model
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
