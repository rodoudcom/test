<?php

namespace App\WorkflowRodoud;


use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Contracts\WorkflowSummaryCallbackInterface;
use App\WorkflowRodoud\Attributes\Job;
use App\WorkflowRodoud\Services\WorkflowRedisTracker;
use ReflectionClass;

/**
 * Workflow - Main workflow execution engine
 *
 * PRIMARY: Works with in-memory WorkflowContext
 * OPTIONAL: Syncs to Redis via WorkflowRedisTracker for real-time debugging
 */
class Workflow
{
    private WorkflowContext $context;
    private ?WorkflowRedisTracker $tracker = null;
    private array $steps = [];
    private array $stepDependencies = [];
    private array $connections = [];
    private ?WorkflowSummaryCallbackInterface $summaryCallback = null;
    private float $startTime;

    public function __construct(string $name)
    {
        $workflowId = $this->generateWorkflowId();
        $this->context = new WorkflowContext($workflowId, $name);
    }

    // ============================================
    // CONFIGURATION
    // ============================================

    /**
     * Set optional Redis tracker for real-time monitoring
     */
    public function setTracker(WorkflowRedisTracker $tracker): self
    {
        $this->tracker = $tracker;
        return $this;
    }

    /**
     * Set global variables accessible to all jobs
     */
    public function setGlobals(array $globals): self
    {
        $this->context->setGlobals($globals);
        $this->syncToRedis();
        return $this;
    }

    /**
     * Set workflow description
     */
    public function setDescription(string $description): self
    {
        $this->context->setDescription($description);
        $this->syncToRedis();
        return $this;
    }

    /**
     * Set callback to persist workflow result
     */
    public function setSummaryCallback(WorkflowSummaryCallbackInterface $callback): self
    {
        $this->summaryCallback = $callback;
        return $this;
    }

    // ============================================
    // WORKFLOW BUILDING
    // ============================================

    /**
     * Add a step/job to workflow
     */
    public function addStep(string $stepId, $job, array $dependencies = []): self
    {
        $this->steps[$stepId] = $job;
        $this->stepDependencies[$stepId] = $dependencies;
        return $this;
    }

    /**
     * Connect two steps (for visualization)
     */
    public function connect(string $fromStep, string $toStep): self
    {
        $this->connections[] = [
            'from' => $fromStep,
            'to' => $toStep
        ];
        return $this;
    }

    // ============================================
    // EXECUTION
    // ============================================

    /**
     * Execute the workflow
     */
    public function execute(): array
    {
        $this->startTime = microtime(true);

        // Prepare context with workflow structure
        $this->prepareContext();

        // Mark workflow as started
        $this->context->markStarted();
        $this->syncToRedis();

        try {
            // Execute steps in dependency order
            $executionOrder = $this->resolveExecutionOrder();
            $results = [];

            foreach ($executionOrder as $stepId) {
                /**
                 * @var JobInterface $job
                 */
                $job = $this->steps[$stepId];

                // Execute step
                $result = $this->executeStep($stepId, $job);
                $results[$stepId] = $result;

                // Store result in context
                $this->context->setResult($stepId, $result);
                $this->syncToRedis();
            }

            // Mark workflow as completed
            $this->completeWorkflow();

            return $results;

        } catch (\Throwable $e) {
            // Mark workflow as failed
            $this->context->markFailed();
            $this->syncToRedis();

            throw $e;
        }
    }

    /**
     * Execute a single step
     */
    private function executeStep(string $stepId, JobInterface $job)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        // Prepare inputs from dependencies
        $inputs = $this->resolveStepInputs($stepId);

        // Add job to executed_jobs as "running"
        $this->context->addExecutedJob([
            'name' => $stepId,
            'status' => 'running',
            'inputs' => $inputs,
            'outputs' => null,
            'logs' => [],
            'started_at' => date('c'),
            'completed_at' => null,
            'performance' => [
                'execution_time' => 0,
                'memory_used' => 0,
                'peak_memory' => memory_get_peak_usage()
            ]
        ]);
        $this->syncToRedis();

        try {
            // Execute the job
            $result = $this->callJob($job, $inputs, $stepId);

            $executionTime = microtime(true) - $startTime;
            $memoryUsed = memory_get_usage() - $startMemory;

            // Update job as "completed"
            $this->context->updateExecutedJob($stepId, [
                'status' => 'completed',
                'outputs' => $result,
                'completed_at' => date('c'),
                'performance' => [
                    'execution_time' => $executionTime,
                    'memory_used' => $memoryUsed,
                    'peak_memory' => memory_get_peak_usage()
                ]
            ]);
            $this->syncToRedis();

            return $result;

        } catch (\Throwable $e) {
            // Update job as "failed"
            $this->context->updateExecutedJob($stepId, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => date('c')
            ]);
            $this->syncToRedis();

            throw $e;
        }
    }

    /**
     * Call the job with inputs
     */
    private function callJob(JobInterface $job, array $inputs, string $stepId = '')
    {
        if (is_callable($job)) {
            return $job($inputs, $this);
        }

        if ($job instanceof JobInterface) {
            return $job->setId($stepId)
                ->setContext($this->context)
                ->execute($inputs);
        }

        throw new \Exception("Job must be callable or have a handle() method");
    }

    /**
     * Resolve step inputs from dependencies
     */
    private function resolveStepInputs(string $stepId): array
    {
        $inputs = [];
        $dependencies = $this->stepDependencies[$stepId] ?? [];

        foreach ($dependencies as $key => $dependency) {
            if (is_array($dependency)) {
                // Format: 'key' => ['stepId' => 'outputKey']
                foreach ($dependency as $sourceStep => $outputKey) {
                    $result = $this->context->getResult($sourceStep);
                    $inputs[$key] = $result[$outputKey] ?? null;
                }
            } else {
                // Simple format: 'stepId'
                $inputs[$key] = $this->context->getResult($dependency);
            }
        }

        return $inputs;
    }

    /**
     * Resolve execution order based on dependencies
     */
    private function resolveExecutionOrder(): array
    {
        // Simple topological sort
        $order = [];
        $visited = [];

        $visit = function ($stepId) use (&$visit, &$order, &$visited) {
            if (isset($visited[$stepId])) {
                return;
            }

            $visited[$stepId] = true;

            // Visit dependencies first
            $deps = $this->stepDependencies[$stepId] ?? [];
            foreach ($deps as $dep) {
                if (is_array($dep)) {
                    foreach ($dep as $sourceStep => $outputKey) {
                        $visit($sourceStep);
                    }
                } else {
                    $visit($dep);
                }
            }

            $order[] = $stepId;
        };

        foreach (array_keys($this->steps) as $stepId) {
            $visit($stepId);
        }

        return $order;
    }

    /**
     * Prepare context with workflow structure
     */
    private function prepareContext(): void
    {
        $steps = [];
        foreach ($this->steps as $stepId => $job) {
            $steps[$stepId] = [
                'id' => $stepId,
                'name' => $stepId,
                'dependencies' => $this->getDependencyStepIds($stepId)
            ];
        }

        $this->context->setSteps($steps);
        $this->context->setConnections($this->connections);
    }

    /**
     * Get dependency step IDs
     */
    private function getDependencyStepIds(string $stepId): array
    {
        $deps = [];
        $dependencies = $this->stepDependencies[$stepId] ?? [];

        foreach ($dependencies as $dependency) {
            if (is_array($dependency)) {
                foreach ($dependency as $sourceStep => $outputKey) {
                    $deps[] = $sourceStep;
                }
            } else {
                $deps[] = $dependency;
            }
        }

        return array_unique($deps);
    }

    /**
     * Complete workflow execution
     */
    private function completeWorkflow(): void
    {
        $executionTime = microtime(true) - $this->startTime;

        $this->context->setExecutionTime($executionTime);
        $this->context->markCompleted();
        $this->syncToRedis();

        // Set Redis expiry
        if ($this->tracker) {
            $this->tracker->setExpiry($this->context->getWorkflowId(), 3600);
        }

        // Call summary callback if set
        if ($this->summaryCallback) {
            $this->summaryCallback->handle($this->getSummary());
        }
    }

    // ============================================
    // LOGGING
    // ============================================

    /**
     * Add log to current executing step
     */
    public function log(string $stepId, string $message): void
    {
        $this->context->addJobLog($stepId, $message);
        $this->syncToRedis();
    }

    // ============================================
    // GETTERS
    // ============================================

    public function getContext(): WorkflowContext
    {
        return $this->context;
    }

    public function getWorkflowId(): string
    {
        return $this->context->getWorkflowId();
    }

    public function getSummary(): array
    {
        return $this->context->toArray();
    }

    // ============================================
    // REDIS SYNC
    // ============================================

    /**
     * Sync current context to Redis (if tracker is set)
     */
    private function syncToRedis(): void
    {
        if ($this->tracker && $this->tracker->isEnabled()) {
            $this->tracker->sync($this->context);
        }
    }

    // ============================================
    // UTILITIES
    // ============================================

    private function generateWorkflowId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
