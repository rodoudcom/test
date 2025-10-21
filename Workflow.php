<?php

namespace App\WorkflowRodoud;


use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Contracts\WorkflowSummaryCallbackInterface;
use App\WorkflowRodoud\Services\WorkflowRedisTracker;

/**
 * Workflow - Main workflow execution engine
 *
 * PRIMARY: Works with in-memory WorkflowContext
 * OPTIONAL: Syncs to Redis via WorkflowRedisTracker for real-time debugging
 */
class Workflow
{
    private WorkflowContext $context;
    private ?WorkflowSummaryCallbackInterface $summaryCallback = null;

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
        $this->context->tracker = $tracker;
        return $this;
    }

    /**
     * Set global variables accessible to all jobs
     */
    public function setGlobals(array $globals): self
    {
        $this->context->setGlobals($globals);

        return $this;
    }

    /**
     * Set workflow description
     */
    public function setDescription(string $description): self
    {
        $this->context->setDescription($description);

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
    public function addStep(string $stepId, JobInterface $job, array $inputs = []): self
    {
        $this->context->steps[$stepId] = $job;
        $this->context->stepInputs[$stepId] = $inputs;
        return $this;
    }

    /**
     * Connect two steps (for visualization) and for stops order
     */
    public function connect(string $fromStep, string $toStep): self
    {
        $this->context->connections[] = [
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


        // Mark workflow as started
        $this->context->markStarted();


        try {
            // Execute steps in dependency order
            //TODO excute in connect order
            $executionOrder = $this->resolveExecutionOrder();
            $results = [];

            foreach ($executionOrder as $stepId) {

                $job = $this->context->steps[$stepId];

                // Execute step
                $result = $this->executeStep($stepId, $job);
                $results[$stepId] = $result;

                // Store result in context
                $this->context->setResult($stepId, $result);
            }

            // Mark workflow as completed
            $this->completeWorkflow();

            return $results;

        } catch (\Throwable $e) {
            // Mark workflow as failed
            $this->context->markFailed();
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
            'error' => [],
            'started_at' => microtime(true),
            'completed_at' => null,
            'performance' => [
                'execution_time' => 0,
                'memory_used' => 0,
                'peak_memory' => memory_get_peak_usage()
            ]
        ]);


        try {
            // Execute the job
            $result = $this->callJob($job, $inputs, $stepId);

            $executionTime = microtime(true) - $startTime;
            $memoryUsed = memory_get_usage() - $startMemory;

            // Update job as "completed"
            $this->context->updateExecutedJob($stepId, [
                'status' => 'completed',
                'outputs' => $result,
                'completed_at' => microtime(true),
                'performance' => [
                    'execution_time' => $executionTime,
                    'memory_used' => $memoryUsed,
                    'peak_memory' => memory_get_peak_usage()
                ]
            ]);


            return $result;

        } catch (\Throwable $e) {
            // Update job as "failed"
            $this->context->updateExecutedJob($stepId, [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => microtime(true)
            ]);


            throw $e;
        }
    }

    /**
     * Call the job with inputs
     */
    private function callJob(JobInterface $job, array $inputs, string $stepId = ''): mixed
    {
        $lastException = null;
        $attempt = 0;
        //  while ($attempt < $job->retryConfig->maxAttempts) {
        $result = $job->setId($stepId)
            ->setContext($this->context)
            ->execute($inputs);
        //}
        return $result;
    }

    /**
     * Resolve step inputs from dependencies
     */
    private function resolveStepInputs(string $stepId): array
    {
        $inputs = [];
        $dependencies = $this->context->stepInputs[$stepId] ?? [];

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
    //TODO order should be depend on connector
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
            $deps = $this->context->stepInputs[$stepId] ?? [];
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

        foreach (array_keys($this->context->steps) as $stepId) {
            $visit($stepId);
        }

        return $order;
    }


    /**
     * Complete workflow execution
     */
    private function completeWorkflow(): void
    {
        $executionTime = microtime(true) - $this->context->startedAt;

        $this->context->setExecutionTime($executionTime);
        $this->context->markCompleted();

        // Set Redis expiry
        if ($this->context->tracker) {
            $this->context->tracker->setExpiry($this->context->getWorkflowId(), 3600);
        }

        // Call summary callback if set
        if ($this->summaryCallback) {
            $this->summaryCallback->handle($this->getSummary());
        }
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


    /**
     * Configure retry for the last added step
     */
    public function withRetry(int $maxAttempts, float $baseDelay = 1.0, float $multiplier = 2.0): self
    {
        /**
         * @var JobInterface $lastStep
         */
        $lastStep = end($this->context->steps);
        if ($lastStep) {
            $lastStep->setRetryConfig(new RetryConfig($maxAttempts, $baseDelay, $multiplier));
        }

        return $this;
    }
}
