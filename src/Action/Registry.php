<?php

namespace Anon\Core\Action;

use Anon\Core\Exception\Http;

class Registry
{
    /**
     * @var array<string, Definition>
     */
    protected array $definitions = [];

    public function register(string $name, string $handler, array $options = []): Definition
    {
        $name = $this->normalizeName($name);
        $method = strtoupper((string) ($options['method'] ?? 'POST'));

        if ($method !== 'POST') {
            throw new \InvalidArgumentException('Server actions only support POST in this version.');
        }

        if (!class_exists($handler)) {
            throw new \InvalidArgumentException("Action handler does not exist: {$handler}");
        }

        $definition = new Definition($name, $handler, $method);

        if (isset($options['middleware'])) {
            $definition->middleware($options['middleware']);
        }

        if (isset($options['summary']) && is_string($options['summary'])) {
            $definition->summary($options['summary']);
        }

        if (isset($options['description']) && is_string($options['description'])) {
            $definition->description($options['description']);
        }

        if (isset($options['tags'])) {
            $definition->tags($options['tags']);
        }

        if (isset($options['openapi']) && is_array($options['openapi'])) {
            $definition->openapi($options['openapi']);
        }

        return $this->definitions[$name] = $definition;
    }

    public function post(string $name, string $handler, array $options = []): Definition
    {
        $options['method'] = 'POST';

        return $this->register($name, $handler, $options);
    }

    public function get(string $name): ?Definition
    {
        try {
            $name = $this->normalizeName($name);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->definitions[$name] ?? null;
    }

    public function require(string $name): Definition
    {
        $definition = $this->get($name);
        if (!$definition instanceof Definition) {
            throw new Http(404, "Action not found: {$name}", [], null, 'NOT_FOUND');
        }

        return $definition;
    }

    /**
     * @return array<string, Definition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    public function has(string $name): bool
    {
        return $this->get($name) instanceof Definition;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportForCache(): array
    {
        return array_values(array_map(
            static fn (Definition $definition) => $definition->toArray(),
            $this->definitions
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function loadCached(array $items): void
    {
        $this->definitions = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $definition = Definition::fromArray($item);
            $this->definitions[$definition->name()] = $definition;
        }
    }

    protected function normalizeName(string $name): string
    {
        $name = trim($name);

        if (!preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $name)) {
            throw new \InvalidArgumentException('Invalid action name. Use names like users.create or posts.publish.');
        }

        return $name;
    }
}