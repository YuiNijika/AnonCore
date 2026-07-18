<?php

namespace Anon\Core\Console\Commands\Route;

use Anon\Core\Console\Command;
use Anon\Core\Routing\RouteItem;
use Anon\Core\Support\OpenApi\Generator;

class RouteList extends Command
{
    protected string $name = 'route:list';
    protected string $description = 'List all registered routes';

    public function execute(array $args): int
    {
        try {
            $app = $this->bootstrapApp();
            $generator = new Generator();
            
            /** @var \Anon\Core\Routing\Router $router */
            $router = $app->make('router');
            
            $routes = $router->getRoutes();

            $payload = [];
            foreach ($routes as $method => $items) {
                foreach ($items as $uri => $routeItem) {
                    if (!$routeItem instanceof RouteItem) {
                        continue;
                    }

                    $payload[] = $this->routePayload((string) $method, (string) $uri, $routeItem, $generator);
                }
            }

            usort($payload, static function (array $left, array $right): int {
                $uriCompare = strcmp((string) ($left['uri'] ?? ''), (string) ($right['uri'] ?? ''));
                if ($uriCompare !== 0) {
                    return $uriCompare;
                }

                return strcmp((string) ($left['method'] ?? ''), (string) ($right['method'] ?? ''));
            });

            if ($this->hasOption($args, 'json')) {
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
                return 0;
            }

            if ($payload === []) {
                $this->info('No routes registered.');
                return 0;
            }

            $rows = array_map(static function (array $route): array {
                return [
                    'Method' => $route['method'],
                    'URI' => $route['uri'],
                    'Name' => $route['name'] !== '' ? $route['name'] : '-',
                    'Action' => $route['action'],
                    'Middleware' => $route['middleware'],
                    'Summary' => $route['summary'] !== '' ? $route['summary'] : '-',
                    'Docs' => $route['docs'],
                ];
            }, $payload);

            $this->info('Registered Routes:');
            echo PHP_EOL;

            $headers = ['Method', 'URI', 'Name', 'Action', 'Middleware', 'Summary', 'Docs'];
            $this->table($headers, $rows);

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to list routes: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function routePayload(string $method, string $uri, RouteItem $routeItem, Generator $generator): array
    {
        $middlewares = implode(', ', $routeItem->middlewares);
        $issueDetails = $generator->routeDocumentationIssueDetails($routeItem);
        $issues = $generator->routeDocumentationIssues($routeItem);

        return [
            'source' => 'route',
            'method' => strtoupper($method),
            'uri' => $uri,
            'name' => $routeItem->name ?? '',
            'action' => $this->actionToString($routeItem->action),
            'middleware' => $middlewares !== '' ? $middlewares : '-',
            'middlewares' => $routeItem->middlewares,
            'summary' => $routeItem->summary ?? '',
            'description' => $routeItem->description ?? '',
            'tags' => $routeItem->tags,
            'security' => $routeItem->security,
            'deprecated' => $routeItem->deprecated,
            'response_headers' => $routeItem->responseHeaders,
            'cors' => $routeItem->cors,
            'status' => $this->statusFromIssues($issues),
            'issues' => $issues,
            'issues_detail' => $issueDetails,
            'issue_count' => count($issues),
            'docs' => $this->docsSummary($issues),
        ];
    }

    /**
     * @param string[] $issues
     */
    protected function docsSummary(array $issues): string
    {
        return $issues === [] ? 'ok' : count($issues) . ' issue(s)';
    }

    /**
     * @param string[] $issues
     */
    protected function statusFromIssues(array $issues): string
    {
        return $issues === [] ? 'ok' : 'warning';
    }

    protected function actionToString(mixed $action): string
    {
        if (is_array($action)) {
            return implode('@', $action);
        }

        if ($action instanceof \Closure) {
            return 'Closure';
        }

        return (string) $action;
    }
}
