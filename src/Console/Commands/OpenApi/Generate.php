<?php

namespace Anon\Core\Console\Commands\OpenApi;

use Anon\Core\Console\Command;
use Anon\Core\Support\OpenApi\Generator;

class Generate extends Command
{
    protected string $name = 'openapi:generate';
    protected string $description = 'Generate OpenAPI document from registered routes';

    public function execute(array $args): int
    {
        try {
            $app = $this->bootstrapApp();

            /** @var \Anon\Core\Routing\Router $router */
            $router = $app->make('router');
            $document = (new Generator())->generate($router->getRoutes());

            $output = (string) $this->getOption($args, 'output', $this->runtimePath() . DIRECTORY_SEPARATOR . 'openapi.json');
            $directory = dirname($output);
            $this->ensureDirectory($directory);

            $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json === false) {
                $this->error('Failed to encode OpenAPI document.');
                return 1;
            }

            file_put_contents($output, $json . PHP_EOL);
            $this->success('OpenAPI document generated: ' . $output);

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to generate OpenAPI document: ' . $e->getMessage());
            return 1;
        }
    }
}