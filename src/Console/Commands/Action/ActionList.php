<?php

namespace Anon\Core\Console\Commands\Action;

use Anon\Core\Action\Definition;
use Anon\Core\Action\Registry;
use Anon\Core\Console\Command;

class ActionList extends Command
{
    protected string $name = 'action:list';
    protected string $description = 'List registered server actions';

    public function execute(array $args): int
    {
        try {
            $app = $this->bootstrapApp();
            $registry = $app->make('action.registry');

            if (!$registry instanceof Registry) {
                $this->error('Action registry is not available.');
                return 1;
            }

            $actions = array_values($registry->all());

            if (in_array('--json', $args, true)) {
                $payload = array_map(static fn (Definition $action): array => $action->toArray(), $actions);
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
                return 0;
            }

            if ($actions === []) {
                $this->info('No server actions registered.');
                return 0;
            }

            $rows = array_map(static function (Definition $action): array {
                return [
                    'Name' => $action->name(),
                    'Method' => $action->method(),
                    'Handler' => $action->handler(),
                    'Middleware' => implode(', ', $action->middlewares()),
                    'Summary' => $action->summaryText() ?? '',
                ];
            }, $actions);

            $this->table(['Name', 'Method', 'Handler', 'Middleware', 'Summary'], $rows);
            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to list server actions: ' . $e->getMessage());
            return 1;
        }
    }
}