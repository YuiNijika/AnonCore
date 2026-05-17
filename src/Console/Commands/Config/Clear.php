<?php

namespace Anon\Core\Console\Commands\Config;

use Anon\Core\Console\Command;

class Clear extends Command
{
    protected string $name = 'config:clear';
    protected string $description = 'Remove the cached project configuration';

    public function execute(array $args): int
    {
        $cacheFile = $this->cachePath() . DIRECTORY_SEPARATOR . 'config.php';

        if (!file_exists($cacheFile)) {
            $this->info('Configuration cache is already cleared.');
            return 0;
        }

        if (!unlink($cacheFile)) {
            $this->error("Unable to remove configuration cache: {$cacheFile}");
            return 1;
        }

        $this->success("Configuration cache cleared: {$cacheFile}");

        return 0;
    }
}
