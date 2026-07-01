<?php

namespace Anon\Core\Console\Commands\Make;

use Anon\Core\Console\Command;

class Seeder extends Command
{
    protected string $name = 'make:seeder';
    protected string $description = 'Create a new database seeder class';

    public function execute(array $args): int
    {
        if (empty($args[0])) {
            $this->error("Seeder name is required.");
            return 1;
        }

        $name = ucfirst($args[0]);
        $fileName = "{$name}.php";
        
        $dir = APP_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

        $template = <<<PHP
<?php

namespace Anon\Database\Seeders;

use Anon\Core\Database\Migration\Seeder;
use Anon\Core\Facade\DB;

class {$name} extends Seeder
{
    public function run(): void
    {
        // DB::table('users')->insert([
        //     'name' => 'admin',
        //     'email' => 'admin@example.com'
        // ]);
    }
}
PHP;

        file_put_contents($filePath, $template);
        $this->success("Seeder created successfully: {$fileName}");

        return 0;
    }
}
