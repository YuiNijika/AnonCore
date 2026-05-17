<?php

namespace Anon\Core\Console\Commands\Db;

use Anon\Core\Console\Command;

class Seed extends Command
{
    protected string $name = 'db:seed';
    protected string $description = 'Seed the database with records';

    public function execute(array $args): int
    {
        $class = $this->getOption($args, 'class', 'DatabaseSeeder');
        $fullClass = "\\App\\Database\\Seeders\\{$class}";

        if (!class_exists($fullClass)) {
            // 尝试去文件里 require
            $filePath = APP_PATH . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders' . DIRECTORY_SEPARATOR . $class . '.php';
            if (file_exists($filePath)) {
                require_once $filePath;
            } else {
                $this->error("Seeder class {$fullClass} not found.");
                return 1;
            }
        }

        if (class_exists($fullClass)) {
            $this->info("Seeding database...");
            $seeder = new $fullClass();
            $seeder->run();
            $this->success("Database seeding completed successfully.");
            return 0;
        }

        $this->error("Seeder class {$fullClass} not found.");
        return 1;
    }
}
