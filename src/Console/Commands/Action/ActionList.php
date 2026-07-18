<?php

namespace Anon\Core\Console\Commands\Action;

use Anon\Core\Action\Definition;
use Anon\Core\Action\Registry;
use Anon\Core\Console\Command;
use Anon\Core\Support\OpenApi\Generator;

class ActionList extends Command
{
    protected string $name = 'action:list';
    protected string $description = 'List registered server actions';

    public function execute(array $args): int
    {
        try {
            $app = $this->bootstrapApp();
            $generator = new Generator();
            $registry = $app->make('action.registry');

            if (!$registry instanceof Registry) {
                $this->error('Action registry is not available.');
                return 1;
            }

            $actions = array_values($registry->all());

            if (in_array('--json', $args, true)) {
                $payload = array_map(fn (Definition $action): array => $this->actionPayload($action, $generator), $actions);
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
                return 0;
            }

            if ($actions === []) {
                $this->info('No server actions registered.');
                return 0;
            }

            $rows = array_map(fn (Definition $action): array => [
                'Name' => $action->name(),
                'Method' => $action->method(),
                'Handler' => $action->handler(),
                'Middleware' => implode(', ', $action->middlewares()),
                'Summary' => $action->summaryText() ?? '',
                'Docs' => $this->docsSummary($generator->actionDocumentationIssues($action)),
            ], $actions);

            $this->table(['Name', 'Method', 'Handler', 'Middleware', 'Summary', 'Docs'], $rows);
            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to list server actions: ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function actionPayload(Definition $action, Generator $generator): array
    {
        $issueDetails = $generator->actionDocumentationIssueDetails($action);
        $issues = $generator->actionDocumentationIssues($action);

        return [
            'source' => 'action',
            'name' => $action->name(),
            'handler' => $action->handler(),
            'method' => $action->method(),
            'middlewares' => $action->middlewares(),
            'summary' => $action->summaryText() ?? '',
            'description' => $action->descriptionText() ?? '',
            'tags' => $action->tagList(),
            'deprecated' => $action->deprecatedState(),
            'status' => $this->statusFromIssues($issues),
            'issues' => $issues,
            'issues_detail' => $issueDetails,
            'issue_count' => count($issues),
            'docs' => $this->docsSummary($issues),
            'meta' => $action->meta(),
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
}
