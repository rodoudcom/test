<?php

namespace App\WorkflowRodoud;

use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Contracts\TrackerInterface;
use App\WorkflowRodoud\Enum\WorkflowStatusEnum;
use App\WorkflowRodoud\Services\WorkflowRedisTracker;

/**
 * Context holds all workflow data and results
 */
class WorkflowContext
{
    private string $workflowId;
    private string $name;
    private array $globals = [];
    private ?TrackerInterface $tracker = null;
    private array $stepDefs = [];
    private array $results = [];
    private array $executed = [];
    private WorkflowStatusEnum $status = WorkflowStatusEnum::RUNNING;
    public bool $is_running = true;
    private array $performance = ["start_time" => 0, "memory_used" => 0, "peak_memory" => 0, "end_time" => 0, "execution_time" => 0];

    public function __construct(string $workflowId, string $name)
    {
        $this->workflowId = $workflowId;
        $this->name = $name;
    }

    public function addStep(string $stepId, JobInterface $job, array $inputs = [], bool $stopOnFail = true): void
    {
        $this->stepDefs[$stepId] = ['job' => $job, 'inputs' => $inputs, 'retry' => null, 'stopOnFail' => $stopOnFail, 'connections' => [], 'logs' => []];
        $this->sync();
    }

    public function connect(string $fromStep, string $toStep): void
    {
        $this->stepDefs[$fromStep]['connections'][] = $toStep;
        if (!isset($this->stepDefs[$toStep])) {
            $this->stepDefs[$toStep] = ['job' => null, 'inputs' => [], 'retry' => null, 'stopOnFail' => true, 'connections' => [], 'logs' => []];
        }
    }

    public function setStepRetry(string $stepId, RetryConfig $retry): void
    {
        $this->stepDefs[$stepId]['retry'] = $retry;

    }

    public function setGlobals(array $globals): void
    {
        $this->globals = $globals;
    }

    public function getGlobal(string $var): mixed
    {
        return $this->globals[$var] ?? null;
    }

    public function setTracker(TrackerInterface $tracker): void
    {
        $this->tracker = $tracker;
    }

    public function markWorkflowStarted(): void
    {
        $this->performance["start_time"] = microtime(true);
        $this->is_running = true;
        $this->status = WorkflowStatusEnum::RUNNING;
        $this->sync();
    }

    public function markWorkflowEnded(array $pref = [], WorkflowStatusEnum $status): void
    {
        if ($this->is_running) {
            $startTime = $this->performance["start_time"];
            $endTime = microtime(true);
            $timing = ["end_time" => $endTime, 'execution_time' => $endTime - $startTime];
            $this->performance = array_merge($this->performance, $pref, $timing);
            $this->status = $status;
            $this->is_running = false;
            $this->sync();
        }
    }


    public function markStepStarted(string $stepId): void
    {
        $this->is_running = true;
        $this->executed[$stepId]['status'] = WorkflowStatusEnum::RUNNING;
        $this->executed[$stepId]['performance']["start_time"] = microtime(true);
        $this->sync();
    }

    public function markStepCompleted(string $stepId, mixed $result, array $logs = [], array $pref = []): void
    {
        $startTime = $this->executed[$stepId]['performance']["start_time"];
        $endTime = microtime(true);
        $timing = ["end_time" => $endTime, 'execution_time' => $endTime - $startTime];

        $this->executed[$stepId]['status'] = WorkflowStatusEnum::SUCCESS;
        $this->executed[$stepId]['performance'] = array_merge($this->executed[$stepId]['performance'] ?? [], $pref, $timing);
        $this->executed[$stepId]['logs'] = array_merge($this->executed[$stepId]['logs'] ?? [], $logs);
        $this->results[$stepId] = $result;
        $this->sync();
    }

    public function markStepFailed(string $stepId, array $error, array $pref = []): void
    {
        $startTime = $this->executed[$stepId]['performance']["start_time"];
        $endTime = microtime(true);
        $timing = ["end_time" => $endTime, 'execution_time' => $endTime - $startTime];

        $this->executed[$stepId]['status'] = WorkflowStatusEnum::FAIL;
        $this->executed[$stepId]['error'] = $error;
        $this->executed[$stepId]['performance'] = array_merge($this->executed[$stepId]['performance'] ?? [], $pref, $timing);
        $this->sync();
    }

    public function getStepJob(string $stepId): ?JobInterface
    {
        return $this->stepDefs[$stepId]['job'] ?? null;
    }

    public function getStepRetryConfig(string $stepId): ?RetryConfig
    {
        return $this->stepDefs[$stepId]['retry'] ?? null;
    }

    public function getStepStopOnFail(string $stepId): bool
    {
        return $this->stepDefs[$stepId]['stopOnFail'] ?? true;
    }

    public function getStepConnections(string $stepId): array
    {
        return $this->stepDefs[$stepId]['connections'] ?? [];
    }

    public function getStatus(): WorkflowStatusEnum
    {
        return $this->status;
    }

    public function resolveInputs(string $stepId): array
    {
        $inputs = $this->stepDefs[$stepId]['inputs'] ?? [];
        $resolved = [];
        foreach ($inputs as $key => $val) {
            if (is_array($val)) {
                foreach ($val as $src => $field) {
                    $resolved[$key] = $this->results[$src][$field] ?? null;
                }
            } else {
                $resolved[$key] = $this->results[$val] ?? null;
            }
        }
        return array_merge($this->globals, $resolved);
    }

    private function sync(): void
    {
        if ($this->tracker) {
            $this->tracker->track($this->workflowId, $this->toArray());
        }
    }

    public function getStepIds(): array
    {
        return array_keys($this->stepDefs);
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getWorkflowId(): string
    {
        return $this->workflowId;
    }


    public function toArray(): array
    {


        return [
            'workflow_id' => $this->workflowId,
            'name' => $this->name,
            'status' => $this->status->value,
            'globals' => $this->globals,
            'performance' => $this->performance,
            'steps' => array_map(function ($stepId) {
                $step = $this->stepDefs[$stepId];
                return [
                    'name' => $stepId,
                    'job' => $step['job']?->getName() ?? null,
                    'description' => $step['job']?->getDescription() ?? null,
                    'inputs' => $step['inputs'] ?? [],
                    'retry' => $step['retry'] ? [
                        'maxAttempts' => $step['retry']->maxAttempts,
                        'baseDelay' => $step['retry']->baseDelay,
                        'multiplier' => $step['retry']->multiplier
                    ] : null,
                    'stopOnFail' => $step['stopOnFail'] ?? true,
                    'connections' => $step['connections'] ?? [],
                ];
            }, array_keys($this->stepDefs)),
            'results' => $this->results,
            'executed_jobs' => $this->executed,
        ];
    }

    public function addLog(string $stepId, string $log)
    {
        $this->executed[$stepId]['logs'][] = $log;
    }

    public function setLogs(string $stepId, array $logs)
    {
        $this->executed[$stepId]['logs'] = $logs;
    }

}
