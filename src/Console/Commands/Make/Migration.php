<?php

namespace Anon\Core\Console\Commands\Make;

use Anon\Core\Console\Command;

class Migration extends Command
{
    protected string $name = 'make:migration';
    protected string $description = 'Create a new migration file';

    public function execute(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Migration name is required.");
            return 1;
        }

        $name = ucfirst($args[0]);
        $datePrefix = date('Y_m_d_His');
        $fileName = "{$datePrefix}_{$name}.php";
        
        $dir = APP_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

        $template = <<<PHP
<?php

use Anon\Core\Database\Migration\Migration;
use Anon\Core\Facade\DB;

class {$name} extends Migration
{
    public function up(): void
    {
        // \$this->statement("CREATE TABLE example (...)");
        // MongoDB:
        // \$this->schema()->create('example', function (\$collection) {
        //     \$collection->index(['user_id' => 1]);
        // });
    }

    public function down(): void
    {
        // \$this->statement("DROP TABLE example");
        // MongoDB:
        // \$this->schema()->dropIfExists('example');
    }
}
PHP;

        file_put_contents($filePath, $template);
        $this->success("Migration created successfully: {$fileName}");

        return 0;
    }
}
