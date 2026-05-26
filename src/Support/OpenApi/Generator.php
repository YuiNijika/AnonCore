<?php

namespace Anon\Core\Support\OpenApi;

use Anon\Core\Action\Definition as ActionDefinition;
use Anon\Core\Action\Registry as ActionRegistry;
use Anon\Core\Facade\Config;
use Anon\Core\Foundation\App;
use Anon\Core\Routing\RouteItem;

class Generator
{
    /**
     * @param array<string, array<string, RouteItem>> $routes
     * @return array<string, mixed>
     */
    public function generate(array $routes): array
    {
        $document = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => App::NAME,
                'version' => App::VERSION,
            ],
            'paths' => [],
        ];

        foreach ($routes as $method => $items) {
            foreach ($items as $route) {
                if (!$route instanceof RouteItem) {
                    continue;
                }

                $path = $this->normalizePath($route->uri);
                $operation = $this->operation($route);

                $document['paths'][$path][strtolower($method)] = array_replace_recursive(
                    $operation,
                    $route->openapi
                );
            }
        }

        $this->appendActions($document);
        ksort($document['paths']);

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    protected function operation(RouteItem $route): array
    {
        $operation = [
            'operationId' => $route->name ?: $this->operationId($route),
            'responses' => [
                '200' => [
                    'description' => 'OK',
                ],
            ],
        ];

        if ($route->summary !== null) {
            $operation['summary'] = $route->summary;
        }

        if ($route->description !== null) {
            $operation['description'] = $route->description;
        }

        if ($route->tags !== []) {
            $operation['tags'] = $route->tags;
        }

        $parameters = $this->parameters($route->uri);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        return $operation;
    }

    protected function normalizePath(string $uri): string
    {
        return preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', '{$1}', $uri) ?: $uri;
    }

    protected function operationId(RouteItem $route): string
    {
        $base = strtolower($route->method) . '_' . trim($route->uri, '/');
        $base = preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', 'by_$1', $base) ?: $base;
        $base = preg_replace('/[^A-Za-z0-9_]+/', '_', $base) ?: $base;
        $base = trim($base, '_');

        return $base !== '' ? $base : strtolower($route->method) . '_root';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function parameters(string $uri): array
    {
        if (!preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $uri, $matches)) {
            return [];
        }

        $parameters = [];
        foreach ($matches[1] as $name) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                ],
            ];
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $document
     */
    protected function appendActions(array &$document): void
    {
        try {
            $registry = App::getInstance()->make('action.registry');
        } catch (\Throwable) {
            return;
        }

        if (!$registry instanceof ActionRegistry) {
            return;
        }

        $basePath = '/' . trim((string) Config::get('actions.path', '/_actions'), '/');
        if ($basePath === '/') {
            $basePath = '/_actions';
        }

        foreach ($registry->all() as $action) {
            if (!$action instanceof ActionDefinition) {
                continue;
            }

            $path = $basePath . '/' . $action->name();
            $operation = $this->actionOperation($action);
            $document['paths'][$path][strtolower($action->method())] = array_replace_recursive(
                $operation,
                $action->openapiSpec()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function actionOperation(ActionDefinition $action): array
    {
        $operation = [
            'operationId' => 'action_' . preg_replace('/[^A-Za-z0-9_]+/', '_', $action->name()),
            'tags' => $action->tagList() !== [] ? $action->tagList() : ['Server Actions'],
            'summary' => $action->summaryText() ?: 'Call server action ' . $action->name(),
            'requestBody' => [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'OK',
                ],
                '401' => [
                    'description' => 'Unauthorized.',
                ],
                '403' => [
                    'description' => 'Forbidden.',
                ],
                '419' => [
                    'description' => 'CSRF token mismatch.',
                ],
                '422' => [
                    'description' => 'Validation failed.',
                ],
                '429' => [
                    'description' => 'Too Many Requests.',
                ],
            ],
        ];

        if ($action->descriptionText() !== null) {
            $operation['description'] = $action->descriptionText();
        }

        return $operation;
    }
}