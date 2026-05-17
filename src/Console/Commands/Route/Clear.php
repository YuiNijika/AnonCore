<?php

namespace Anon\Core\Console\Commands\Route;

use Anon\Core\Console\Command;

class Clear extends Command
{
    protected string $name = 'route:clear';
    protected string $description = 'Remove the cached application routes';

    public function execute(array $args): int
    {
        $cacheFile = $this->cachePath() . DIRECTORY_SEPARATOR . 'routes.php';

        if (!file_exists($cacheFile)) {
            $this->info('Route cache is already cleared.');
            return 0;
        }

        if (!unlink($cacheFile)) {
            $this->error("Unable to remove route cache: {$cacheFile}");
            return 1;
        }

        $this->success("Route cache cleared: {$cacheFile}");

        return 0;
    }
}
