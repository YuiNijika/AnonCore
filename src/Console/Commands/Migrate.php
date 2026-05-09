<?php

namespace Anon\Core\Console\Commands;

use Anon\Core\Console\Command;
use Anon\Core\Database\Migration\Migrator;

class Migrate extends Command
{
    protected string $name = 'migrate';
    protected string $description = 'Run the database migrations';

    public function execute(array $args): int
    {
        $migrator = new Migrator();
        
        $this->info("Running migrations...");
        
        try {
            $executed = $migrator->run();
            if (empty($executed)) {
                $this->info("Nothing to migrate.");
            } else {
                foreach ($executed as $migration) {
                    $this->success("Migrated: {$migration}");
                }
            }
        } catch (\Exception $e) {
            $this->error("Migration failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
