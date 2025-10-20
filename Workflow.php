<?php

namespace App\WorkflowRodoud;



use function Amp\async;
use function Amp\Future\awaitAll;
/**
 * Workflow executor with advanced features
 *
 * SUPPORTS TWO APPROACHES:
 * 1. Instance-based: addStep($service, 'method', ...) - for Symfony DI
 * 2. String-based: addStep('ServiceClass.method', ...) - for JSON persistence
 */
class Workflow
{
    private array $steps = [];
    private WorkflowContext $context;
    private int $stepCounter = 0;
    private array $executionOrder = [];
    private array $serviceRegistry = []; // Maps service names to instances

    public function __construct()
    {
        $this->context = new WorkflowContext();
    }

    /**
     * Register a service instance for string-based job references
     *
     * @param string $name Service identifier (e.g., 'wordpress', 'processor')
     * @param object $instance The service instance
     */
    public function registerService(string $name, object $instance): self
    {
        $this->serviceRegistry[$name] = $instance;
        return $this;
    }

    /**
     * Register multiple services at once
     */
    public function registerServices(array $services): self
    {
        foreach ($services as $name => $instance) {
            $this->registerService($name, $instance);
        }
        return $this;
    }

    /**
     * Add a step - SUPPORTS MULTIPLE FORMATS
     *
     * Format 1 (Instance): addStep($serviceInstance, 'methodName', [...])
     * Format 2 (String): addStep('serviceName.methodName', [...])
     * Format 3 (Class): addStep('ClassName.methodName', [...])
     *
     * @param string $job Service instance, service name, or class name
     * @param Object $jobProvider Method name
     * @param array $inputs Input parameters (only if second param is method name)
     * @param string $stepId Optional step ID
     * @param array $dependsOn Dependencies
     * @param bool $parallel Can run in parallel
     * @param bool $stopOnError stop all workflow when a step have an error
     */
    public function addStep(
        string $stepId,
        object $jobProvider,
        string $job = "",
        array  $inputs = [],
        array  $dependsOn = [],
        bool   $parallel = false,
        bool   $stopOnError = true,
    ): self
    {


        if (!method_exists($jobProvider, $job)) {
            throw new \InvalidArgumentException("Method '{$job}' does not exist on " . get_class($jobProvider));
        }


        $config = new StepConfig($stepId, $jobProvider, $job);
        $config->inputs = $inputs;
        $config->dependsOn = $dependsOn;
        $config->parallel = $parallel;
        $config->stopOnError = $stopOnError;

        $this->steps[$stepId] = $config;

        return $this;
    }

    /**
     * Configure retry for the last added step
     */
    public function withRetry(int $maxAttempts, float $baseDelay = 1.0, float $multiplier = 2.0): self
    {
        $lastStep = end($this->steps);
        if ($lastStep) {
            $lastStep->retry = new RetryConfig($maxAttempts, $baseDelay, $multiplier);
        }
        return $this;
    }

    /**
     * Configure stopOnError for the last added step
     */
    public function setStopOnError(bool $stopOnError): self
    {
        /**
         * @var StepConfig $lastStep
         */
        $lastStep = end($this->steps);
        if ($lastStep) {
            $lastStep->stopOnError = $stopOnError;
        }
        return $this;
    }

    /**
     * Add decider to last step for conditional routing
     */
    public function withDecider(callable $configCallback): self
    {
        $lastStep = end($this->steps);
        if ($lastStep) {
            $decider = new DeciderConfig();
            $configCallback($decider);
            $lastStep->decider = $decider;
        }
        return $this;
    }

    /**
     * Get the last added step config for advanced configuration
     */
    public function getLastStep(): ?StepConfig
    {
        return end($this->steps) ?: null;
    }

    private function generateStepId(string $jobName): string
    {
        return str_replace('.', '_', $jobName) . '_' . (++$this->stepCounter);
    }

    public function setGlobals(array $globals): self
    {
        foreach ($globals as $key => $value) {
            $this->context->setGlobal($key, $value);
        }
        return $this;
    }

    /**
     * Execute workflow with dependency resolution
     */
    public function execute(): WorkflowContext
    {
        // 1) Build execution order (batches)
        $this->executionOrder = $this->resolveExecutionOrder();

        // 2) For each batch, run parallel steps as async jobs and run non-parallel steps directly
        foreach ($this->executionOrder as $batch) {
            $futures = []; // keyed by stepId => Future

            // First, start ALL parallel jobs in this batch
            foreach ($batch as $stepId) {
                /** @var StepConfig $step */
                $step = $this->steps[$stepId];

                if ($step->parallel) {
                    // Create future for parallel execution
                    $futures[$stepId] = async(function () use ($step) {
                        return $this->executeStepWithRetry($step);
                    });
                }
            }

            // Then execute non-parallel jobs
            foreach ($batch as $stepId) {
                /** @var StepConfig $step */
                $step = $this->steps[$stepId];

                if (!$step->parallel) {
                    $result = $this->executeStepWithRetry($step);
                    $this->context->setResult($stepId, $result);

                    if ($step->stopOnError && !empty($result->errors)) {
                        return $this->context;
                    }
                }
            }

            // 3) Wait for all parallel futures to finish and collect results
            if (!empty($futures)) {
                // awaitAll returns array of arrays: [0 => [results...], 1 => [results...]]
                // Each inner array contains the actual results
                $awaitedResults = awaitAll($futures);

                // Get the step IDs in the same order as $futures
                $stepIds = array_keys($futures);

                // Flatten and map results back to step IDs
                foreach ($awaitedResults as $groupIndex => $group) {
                    // $group should contain one result per future
                    foreach ($group as $stepId => $result) {

                        // result should be a JobResult instance
                        if ($result instanceof JobResult) {
                            $this->context->setResult($stepId, $result);
                        } else {
                            // Defensive: if a task returned unexpected value, wrap it
                            $jr = new JobResult($stepId, (is_object($result) ? get_class($result) : 'parallel'));
                            $jr->finish(is_array($result) ? $result : ['result' => $result]);
                            $this->context->setResult($stepId, $jr);
                            $result = $jr;
                        }

                        if ($this->steps[$stepId]->stopOnError && !empty($result->errors)) {
                            return $this->context;
                        }
                    }
                }
            }
        }

        // 4) Return context with all results
        return $this->context;
    }
    /**
     * Execute a step with retry logic
     */
    private function executeStepWithRetry(StepConfig $step): JobResult
    {
        $attempt = 1;
        $lastResult = null;

        while ($attempt <= $step->retry->maxAttempts) {
            $jobName = is_object($step->jobProvider) ? get_class($step->jobProvider) . '.' . $step->job : $step->jobProvider;

            $result = new JobResult($step->id, $jobName);
            $result->attemptNumber = $attempt;

            // Resolve inputs
            $resolvedInputs = $this->context->resolveInputs($step->inputs);
            $result->input = $resolvedInputs;

            $result->addLog("Attempt {$attempt} of {$step->retry->maxAttempts}");

            try {
                // Execute the job

                // Execute on instance
                $output = $step->jobProvider->{$step->job}($this->context, $result, $resolvedInputs);


                // Ensure output is always an array
                if (!is_array($output)) {
                    $output = ['result' => $output];
                }

                $result->finish($output);

                // Check if job indicated failure through errors array
                if (empty($result->errors)) {
                    // Success! Store and return
                    $result->addLog("Attempt {$attempt} succeeded");
                    $this->context->setResult($step->id, $result);
                    return $result;
                }

                // Job added errors, treat as failure
                $result->addLog("Attempt {$attempt} failed with errors");

            } catch (\Throwable $e) {
                $result->addError("Exception: " . $e->getMessage());
                $result->addLog("Attempt {$attempt} threw exception: " . $e->getMessage());
                $result->finish([]);
            }

            $lastResult = $result;

            // If not the last attempt, wait before retry
            if ($attempt < $step->retry->maxAttempts) {
                $delay = $step->retry->getDelay($attempt);
                $result->addLog("Waiting {$delay}s before retry...");
                usleep((int)($delay * 1000000));
            }

            $attempt++;
        }

        // All retries exhausted
        $lastResult->addLog("All {$step->retry->maxAttempts} attempts exhausted");
        $this->context->setResult($step->id, $lastResult);
        return $lastResult;
    }

    /**
     * Resolve execution order based on dependencies
     * Returns array of batches (steps that can run in parallel)
     */
    private function resolveExecutionOrder(): array
    {
        $graph = [];
        $inDegree = [];

        // Build dependency graph
        foreach ($this->steps as $stepId => $step) {
            $graph[$stepId] = $step->dependsOn;
            $inDegree[$stepId] = count($step->dependsOn);
        }

        $batches = [];
        $executed = [];

        while (count($executed) < count($this->steps)) {
            $batch = [];

            // Find all steps with no pending dependencies
            foreach ($this->steps as $stepId => $step) {
                if (in_array($stepId, $executed)) {
                    continue;
                }

                $canExecute = true;
                foreach ($step->dependsOn as $dep) {
                    if (!in_array($dep, $executed)) {
                        $canExecute = false;
                        break;
                    }
                }

                if ($canExecute) {
                    $batch[] = $stepId;
                }
            }

            if (empty($batch)) {
                throw new \RuntimeException("Circular dependency detected or missing dependency");
            }

            $batches[] = $batch;
            $executed = array_merge($executed, $batch);
        }

        return $batches;
    }

    public function getContext(): WorkflowContext
    {
        return $this->context;
    }

    public function getSummary(): array
    {
        $results = $this->context->getAllResults();
        $summary = [
            'total_steps' => count($results),
            'successful' => 0,
            'failed' => 0,
            'total_duration' => 0,
            'execution_order' => $this->executionOrder,
            'steps' => []
        ];

        foreach ($results as $stepId => $result) {
            if ($result->status === 'success') {
                $summary['successful']++;
            } else {
                $summary['failed']++;
            }

            $summary['total_duration'] += $result->duration;
            $summary['steps'][$stepId] = [
                'job_name' => $result->jobName,
                'status' => $result->status,
                'duration' => round($result->duration, 4),
                'attempts' => $result->attemptNumber,
                'errors' => $result->errors,
                'logs' => $result->logs,
               // 'output_keys' => array_keys($result->output)
                'output' => $result->output
            ];
        }

        return $summary;
    }


    public function reset(): self
    {
        $this->steps = [];
        $this->context = new WorkflowContext();
        $this->stepCounter = 0;
        $this->executionOrder = [];
        return $this;
    }
}