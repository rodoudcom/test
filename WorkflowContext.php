<?php

namespace App\WorkflowRodoud;

use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Services\WorkflowRedisTracker;

/**
 * Context holds all workflow data and results
 */
class WorkflowContext
{
    private string $workflowId;
    private string $name;
    private string $status = 'pending';
    /**
     * @var array<JobInterface> $steps
     */
    public array $steps = [];
    public array $stepInputs = [];
    public array $connections = [];
    private array $results = [];
    private array $executedJobs = [];
    private array $globals = [];
    public ?string $startedAt = null;
    private ?string $completedAt = null;
    private array $performance = [];
    private ?string $description = null;
    public ?WorkflowRedisTracker $tracker = null;

    public function __construct(string $workflowId, string $name)
    {
        $this->workflowId = $workflowId;
        $this->name = $name;
        $this->performance = [
            'start_memory' => memory_get_usage(),
            'peak_memory' => memory_get_peak_usage(),
            'total_memory_used' => 0,
            'total_execution_time' => 0
        ];
    }

    // ============================================
    // SETTERS - Update context state
    // ============================================

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setSteps(array $steps): self
    {
        $this->steps = $steps;
        return $this;
    }

    public function setConnections(array $connections): self
    {
        $this->connections = $connections;
        return $this;
    }

    public function setGlobals(array $globals): self
    {
        $this->globals = $globals;
        $this->context->syncToRedis();
        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function markStarted(): self
    {
        $this->startedAt = microtime(true);
        $this->status = 'running';
        $this->context->syncToRedis();
        return $this;
    }

    public function markCompleted(): self
    {
        $this->completedAt = microtime(true);
        $this->status = 'completed';
        $this->performance['peak_memory'] = memory_get_peak_usage();
        $this->performance['total_memory_used'] = $this->performance['peak_memory'] - $this->performance['start_memory'];
        $this->context->syncToRedis();
        return $this;
    }

    public function markFailed(): self
    {
        $this->completedAt = microtime(true);
        $this->status = 'failed';
        $this->context->syncToRedis();
        return $this;
    }

    public function setExecutionTime(float $time): self
    {
        $this->performance['total_execution_time'] = $time;
        return $this;
    }

    // ============================================
    // JOB TRACKING
    // ============================================

    public function addExecutedJob(array $jobData): self
    {
        $this->executedJobs[$jobData["name"]] = $jobData;
        $this->context->syncToRedis();
        return $this;
    }

    public function updateExecutedJob(string $jobName, array $updates): self
    {
        $this->executedJobs[$jobName] = array_merge($this->executedJobs[$jobName], $updates);
        $this->context->syncToRedis();
        return $this;
    }

    public function findExecutedJob(string $jobName): ?array
    {
        return $this->executedJobs[$jobName] ?? null;
    }

    public function addJobLog(string $jobName, string $message): self
    {

        $this->executedJobs[$jobName]['logs'][] = '[' . date('c') . '] ' . $message;

        return $this;
    }

    public function addError(string $jobName, string $error): self
    {

        $this->executedJobs[$jobName]['errors'][] = $error;

        return $this;
    }

    // ============================================
    // RESULTS
    // ============================================

    public function setResult(string $stepName, $result): self
    {
        $this->results[$stepName] = $result;
        $this->context->syncToRedis();
        return $this;
    }

    public function getResult(string $stepName)
    {
        return $this->results[$stepName] ?? null;
    }

    public function getAllResults(): array
    {
        return $this->results;
    }

    // ============================================
    // GETTERS
    // ============================================

    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getConnections(): array
    {
        return $this->connections;
    }

    public function getGlobals(): array
    {
        return $this->globals;
    }

    public function getGlobal(string $key, $default = null)
    {
        return $this->globals[$key] ?? $default;
    }

    public function getExecutedJobs(): array
    {
        return $this->executedJobs;
    }

    public function getPerformance(): array
    {
        return $this->performance;
    }

    // ============================================
    // REDIS SYNC
    // ============================================

    /**
     * Sync current context to Redis (if tracker is set)
     */
    public function syncToRedis(): void
    {
        if ($this->tracker && $this->tracker->isEnabled()) {
            $this->tracker->sync($this);
        }
    }



    // ============================================
    // EXPORT - Convert to array for Redis/DB
    // ============================================

    public function toArray(): array
    {
        return [
            'workflow_id' => $this->workflowId,
            'name' => $this->name,
            'status' => $this->status,
            'description' => $this->description,
            'steps' => array_map(fn($step) => $step->toArray(), $this->steps),
            'stepInputs' => $this->stepInputs,
            'connections' => $this->connections,
            'results' => $this->results,
            'executed_jobs' => $this->executedJobs,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'performance' => $this->performance
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}
