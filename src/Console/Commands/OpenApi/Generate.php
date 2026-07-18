<?php

namespace Anon\Core\Console\Commands\OpenApi;

use Anon\Core\Action\Definition;
use Anon\Core\Action\Registry;
use Anon\Core\Console\Command;
use Anon\Core\Foundation\App;
use Anon\Core\Routing\RouteItem;
use Anon\Core\Support\OpenApi\Generator;

class Generate extends Command
{
    protected string $name = 'openapi:generate';
    protected string $description = 'Generate OpenAPI document from registered routes';

    public function execute(array $args): int
    {
        try {
            $check = $this->hasOption($args, 'check');
            $checkJson = $this->hasOption($args, 'json');
            $stdout = $this->hasOption($args, 'stdout');
            $pretty = !$this->hasOption($args, 'no-pretty');
            $generator = new Generator();

            if ($check && $stdout) {
                $this->error('The --check and --stdout options cannot be used together.');
                return 1;
            }

            if ($checkJson && !$check) {
                $this->error('The --json option is only available together with --check.');
                return 1;
            }

            $app = $this->bootstrapApp();

            /** @var \Anon\Core\Routing\Router $router */
            $router = $app->make('router');
            $routes = $router->getRoutes();
            $document = $generator->generate($routes);

            $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if ($pretty) {
                $jsonFlags |= JSON_PRETTY_PRINT;
            }

            $json = json_encode($document, $jsonFlags);
            if ($json === false) {
                $this->error('Failed to encode OpenAPI document.');
                return 1;
            }

            if ($stdout) {
                echo $json . PHP_EOL;
                return 0;
            }

            $output = $this->getOption($args, 'output');
            $shouldWriteFile = !$check || is_string($output);

            if ($shouldWriteFile) {
                $output = (string) ($output ?: $this->runtimePath() . DIRECTORY_SEPARATOR . 'openapi.json');
                $directory = dirname($output);
                $this->ensureDirectory($directory);
                file_put_contents($output, $json . PHP_EOL);
                $this->success('OpenAPI document generated: ' . $output);
            }

            if ($check) {
                $routeIssues = $this->inspectRouteEntries($routes, $generator);
                $actionIssues = $this->inspectActionEntries($app, $generator);
                $issues = array_merge(
                    $this->flattenIssueEntries($routeIssues),
                    $this->flattenIssueEntries($actionIssues)
                );

                if ($checkJson) {
                    $payload = [
                        'status' => $issues === [] ? 'ok' : 'warning',
                        'summary' => [
                            'routes_with_issues' => count($routeIssues),
                            'actions_with_issues' => count($actionIssues),
                            'issue_count' => count($issues),
                        ],
                        'issue_count' => count($issues),
                        'issues' => $issues,
                        'routes' => $routeIssues,
                        'actions' => $actionIssues,
                    ];

                    echo json_encode($payload, $jsonFlags) . PHP_EOL;
                    return $issues === [] ? 0 : 1;
                }

                if ($issues === []) {
                    $this->success('OpenAPI check passed.');
                    return 0;
                }

                $this->warning('OpenAPI check found ' . count($issues) . ' issue(s):');
                foreach ($issues as $issue) {
                    $this->warning('- ' . $issue);
                }

                return 1;
            }

            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to generate OpenAPI document: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string, array<string, RouteItem>> $routes
     * @return array<int, array<string, mixed>>
     */
    protected function inspectRouteEntries(array $routes, Generator $generator): array
    {
        $entries = [];

        foreach ($routes as $method => $items) {
            foreach ($items as $uri => $route) {
                if (!$route instanceof RouteItem) {
                    continue;
                }

                $label = strtoupper((string) $method) . ' ' . (string) $uri;
                $issueDetails = $generator->routeDocumentationIssueDetails($route);
                if ($issueDetails === []) {
                    continue;
                }

                $entries[] = [
                    'source' => 'route',
                    'route' => $label,
                    'status' => 'warning',
                    'issues' => $generator->routeDocumentationIssues($route),
                    'issues_detail' => $issueDetails,
                    'issue_count' => count($issueDetails),
                ];
            }
        }

        return $entries;
    }

    /**
     * @param array<string, array<string, RouteItem>> $routes
     * @return array<int, string>
     */
    protected function inspectRoutes(array $routes, Generator $generator): array
    {
        return $this->flattenIssueEntries($this->inspectRouteEntries($routes, $generator), 'route');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function inspectActionEntries(App $app, Generator $generator): array
    {
        $entries = [];

        try {
            $registry = $app->make('action.registry');
        } catch (\Throwable) {
            return $entries;
        }

        if (!$registry instanceof Registry) {
            return $entries;
        }

        foreach ($registry->all() as $action) {
            if (!$action instanceof Definition) {
                continue;
            }

            $issueDetails = $generator->actionDocumentationIssueDetails($action);
            if ($issueDetails === []) {
                continue;
            }

            $entries[] = [
                'source' => 'action',
                'action' => $action->name(),
                'status' => 'warning',
                'issues' => $generator->actionDocumentationIssues($action),
                'issues_detail' => $issueDetails,
                'issue_count' => count($issueDetails),
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, string>
     */
    protected function inspectActions(App $app, Generator $generator): array
    {
        return $this->flattenIssueEntries($this->inspectActionEntries($app, $generator), 'action');
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, string>
     */
    protected function flattenIssueEntries(array $entries, ?string $defaultType = null): array
    {
        $issues = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $issuesList = is_array($entry['issues'] ?? null) ? $entry['issues'] : [];
            $route = isset($entry['route']) ? (string) $entry['route'] : '';
            $action = isset($entry['action']) ? (string) $entry['action'] : '';
            $type = $route !== '' ? 'Route' : ($action !== '' ? 'Action' : ucfirst((string) $defaultType));
            $target = $route !== '' ? $route : $action;

            if ($target === '') {
                continue;
            }

            foreach ($issuesList as $issue) {
                $issues[] = $type . ' [' . $target . '] ' . (string) $issue . '.';
            }
        }

        return $issues;
    }
}
