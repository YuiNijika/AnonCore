<?php

namespace Anon\Core\Console\Commands\Route;

use Anon\Core\Console\Command;

class Cache extends Command
{
    protected string $name = 'route:cache';
    protected string $description = 'Build and cache the application routes';

    public function execute(array $args): int
    {
        $this->info('Building route cache...');

        try {
            $app = $this->bootstrapApp([
                'ANON_DISABLE_CONFIG_CACHE' => true,
                'ANON_DISABLE_ROUTE_CACHE' => true,
            ]);

            /** @var \Anon\Core\Routing\Router $router */
            $router = $app->make('router');

            $payload = $router->exportForCache();

            $this->ensureDirectory($this->cachePath());

            $cacheFile = $this->cachePath() . DIRECTORY_SEPARATOR . 'routes.php';
            $content = "<?php\n\nreturn " . var_export($payload, true) . ";\n";

            if (file_put_contents($cacheFile, $content) === false) {
                $this->error("Unable to write route cache: {$cacheFile}");
                return 1;
            }

            $this->success("Routes cached successfully: {$cacheFile}");

            return 0;
        } catch (\Throwable $e) {
            $this->error('Route cache failed: ' . $e->getMessage());
            return 1;
        }
    }
}
