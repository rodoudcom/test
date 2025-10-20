<?php
namespace App\WorkflowRodoud\Callbacks;

use App\WorkflowRodoud\Contracts\WorkflowSummaryCallbackInterface;

class DatabaseSummaryCallback implements WorkflowSummaryCallbackInterface
{
    private $connection;
    private string $table;

    public function __construct($connection = null, string $table = 'workflow_executions')
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    public function handle(array $summary): void
    {
        // Adapt to your Symfony database connection
        // Example with PDO:
        $sql = "INSERT INTO {$this->table}
                (workflow_id, name, description, status, started_at, completed_at,
                 steps, connections, executed_jobs, results, logs, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute([
            $summary['workflow_id'],
            $summary['name'],
            $summary['description'],
            $summary['status'],
            $summary['started_at'],
            $summary['completed_at'],
            json_encode($summary['steps']),
            json_encode($summary['connections']),
            json_encode($summary['executed_jobs']),
            json_encode($summary['results']),
            json_encode($summary['logs'])
        ]);
    }
}
