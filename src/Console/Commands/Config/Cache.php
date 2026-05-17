<?php

namespace Anon\Core\Console\Commands\Config;

use Anon\Core\Console\Command;

class Cache extends Command
{
    protected string $name = 'config:cache';
    protected string $description = 'Build and cache the project configuration';

    public function execute(array $args): int
    {
        $this->info('Building configuration cache...');

        try {
            $app = $this->bootstrapApp([
                'ANON_DISABLE_CONFIG_CACHE' => true,
                'ANON_DISABLE_ROUTE_CACHE' => true,
            ]);

            /** @var \Anon\Core\Support\Config $config */
            $config = $app->make('config');

            $this->ensureDirectory($this->cachePath());

            $cacheFile = $this->cachePath() . DIRECTORY_SEPARATOR . 'config.php';
            $content = "<?php\n\nreturn " . var_export($config->all(), true) . ";\n";

            if (file_put_contents($cacheFile, $content) === false) {
                $this->error("Unable to write configuration cache: {$cacheFile}");
                return 1;
            }

            $this->success("Configuration cached successfully: {$cacheFile}");

            return 0;
        } catch (\Throwable $e) {
            $this->error('Configuration cache failed: ' . $e->getMessage());
            return 1;
        }
    }
}
