<?php

namespace Anon\Core\Console\Commands\Route;

use Anon\Core\Console\Command;

class RouteList extends Command
{
    protected string $name = 'route:list';
    protected string $description = 'List all registered routes';

    public function execute(array $args): int
    {
        try {
            $app = $this->bootstrapApp();
            
            /** @var \Anon\Core\Routing\Router $router */
            $router = $app->make('router');
            
            $routes = $router->getRoutes();
            
            if (empty($routes)) {
                $this->info("No routes registered.");
                return 0;
            }

            $rows = [];
            foreach ($routes as $method => $items) {
                foreach ($items as $uri => $routeItem) {
                    $action = $routeItem->action;
                    if (is_array($action)) {
                        $actionStr = implode('@', $action);
                    } elseif ($action instanceof \Closure) {
                        $actionStr = 'Closure';
                    } else {
                        $actionStr = (string) $action;
                    }

                    $middlewares = implode(', ', $routeItem->middlewares);
                    if ($middlewares === '') {
                        $middlewares = '-';
                    }

                    $rows[] = [
                        $method,
                        $uri,
                        $actionStr,
                        $middlewares
                    ];
                }
            }

            if (empty($rows)) {
                $this->info("No routes registered.");
                return 0;
            }

            $this->info("Registered Routes:");
            echo PHP_EOL;
            
            $headers = ['Method', 'URI', 'Action', 'Middleware'];
            $this->table($headers, $rows);

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to list routes: ' . $e->getMessage());
            return 1;
        }
    }
}
