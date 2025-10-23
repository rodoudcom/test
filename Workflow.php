<?php

namespace App\WorkflowRodoud;

use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Contracts\TrackerInterface;
use App\WorkflowRodoud\Contracts\WorkflowSummaryCallbackInterface;
use App\WorkflowRodoud\Enum\WorkflowStatusEnum;
use App\WorkflowRodoud\Exception\WorkflowException;
use App\WorkflowRodoud\Exception\TimeoutException;
use function Amp\async;
use function Amp\ParallelFunctions\parallelMap;

class Workflow
{
    private WorkflowContext $context;
    private ?WorkflowSummaryCallbackInterface $summaryCallback = null;

    // Store dynamic routing logic
    private array $routingCallbacks = [];
    private JobRunner $jobRunner;

    public function __construct(WorkflowContext|string $contextOrName, ?JobRunner $jobRunner = null)
    {
        if ($contextOrName instanceof WorkflowContext) {
            $this->context = $contextOrName;
        } else {
            $id = bin2hex(random_bytes(8));
            $this->context = new WorkflowContext($id, $contextOrName);
        }
        $this->jobRunner = $jobRunner ?? new JobRunner();
    }

    public function addStep(string $stepId, JobInterface $job, array $inputs = [], bool $stopOnFail = true): self
    {
        $this->context->addStep($stepId, $job, $inputs, $stopOnFail);
        return $this;
    }

    public function connect(string $from, string $to): self
    {
        $this->context->connect($from, $to);
        return $this;
    }

    /**
     * Apply retry to the last added step
     */
    public function withRetry(int $maxAttempts, float $baseDelay = 0.0, float $multiplier = 1.0): self
    {
        $ids = $this->context->getStepIds();
        if (!empty($ids)) {
            $last = array_pop($ids);
            $this->context->setStepRetry($last, new RetryConfig($maxAttempts, $baseDelay, $multiplier));
        }
        return $this;
    }

    /**
     * Apply timeout to the last added step
     */
    public function withTimeout(float $seconds): self
    {
        $ids = $this->context->getStepIds();
        if (!empty($ids)) {
            $last = array_pop($ids);
            $this->context->setStepTimeout($last, $seconds);
        }
        return $this;
    }

    /**
     * Add conditional routing for the last added step
     * The callback receives ($result, $context) and returns:
     * - string: single next step
     * - array: multiple steps to run in parallel
     * - null: no next steps (terminal)
     *
     * Example:
     * ->withRouting(function($result, $context) {
     *     if ($result['need_rag']) {
     *         return ['openSearch', 'vectorSearch']; // Both run parallel
     *     }
     *     return 'End'; // Skip to End
     * })
     */
    public function withRouting(callable $callback): self
    {
        $ids = $this->context->getStepIds();
        if (!empty($ids)) {
            $last = array_pop($ids);
            $this->routingCallbacks[$last] = $callback;
        }
        return $this;
    }

    /**
     * Conditional routing builder pattern
     * Returns a RouteBuilder for fluent when/else conditions
     *
     * Example:
     * ->route()
     *   ->when(fn($r) => $r['score'] > 0.8, 'highQuality')
     *   ->when(fn($r) => $r['need_rag'], ['openSearch', 'vectorSearch'])
     *   ->else('defaultPath')
     */
    public function route(): RouteBuilder
    {
        $ids = $this->context->getStepIds();
        $stepId = !empty($ids) ? array_pop($ids) : null;
        return new RouteBuilder($this, $stepId);
    }

    /**
     * Internal method to set routing callback (used by RouteBuilder)
     */
    public function setRouting(string $stepId, callable $callback): void
    {
        $this->routingCallbacks[$stepId] = $callback;
    }

    public function setTracker(TrackerInterface $tracker): self
    {
        $this->context->setTracker($tracker);
        return $this;
    }

    public function setSummaryCallback(WorkflowSummaryCallbackInterface $cb): self
    {
        $this->summaryCallback = $cb;
        return $this;
    }

    public function getContext(): WorkflowContext
    {
        return $this->context;
    }

    public function setGlobals(array $globals): self
    {
        $this->context->setGlobals($globals);
        return $this;
    }

    /**
     * Execute the workflow with dynamic routing support
     */
    public function execute(): array
    {
        $this->context->markWorkflowStarted();

        // Build initial execution groups
        $groups = $this->buildExecutionGroups();

        $allResults = [];
        $processedSteps = [];
        // Prepare jobs for this group


        foreach ($groups as $group) {

            if (!$this->context->is_running) break;

            $groupJobs = [];

            //TODO A RIVISER
            foreach ($group as $stepId) {

                if (in_array($stepId, $processedSteps)) continue;


                $job = $this->context->getStepJob($stepId);
                if (!($job instanceof JobInterface)) {
                    $this->context->markStepStarted($stepId);
                    $this->context->markStepCompleted($stepId, null, []);
                    $allResults[$stepId] = null;
                    $processedSteps[] = $stepId;
                    continue;
                }

                $job->setId($stepId);

                $groupJobs[$stepId] = $job;
            }


            // Execute group (parallel or sequential based on group size)
            if (!empty($groupJobs)) {
                $shouldRunParallel = count($groupJobs) > 1;
                $groupResults = $shouldRunParallel
                    ? $this->executeGroupParallel($groupJobs)
                    : $this->executeGroupSequential($groupJobs);

                $allResults = array_merge($allResults, $groupResults);
            }


//
//            if (!empty($futures)) {
//
//                $groupResults = \Amp\Future\await($futures);
//                foreach ($groupResults as $s => $r) {
//                    $allResults[$s] = $r;
//                    $processedSteps[] = $s;
//
//                    // Check for dynamic routing
//                    if (isset($this->routingCallbacks[$s])) {
//                        $dynamicNext = $this->evaluateRouting($s, $r);
//                        if ($dynamicNext !== null) {
//                            // Add dynamic steps to execution queue
//                            $newGroups = $this->buildDynamicGroups($dynamicNext, $processedSteps);
//                            $groups = array_merge($groups, $newGroups);
//                        }
//                    }
//
//
//                }
//
//            }

        }

        $perf = [
            'memory_used' => memory_get_usage(),
            'peak_memory' => memory_get_peak_usage(),
        ];

        $this->context->markWorkflowEnded($perf, WorkflowStatusEnum::SUCCESS);


        if ($this->summaryCallback instanceof WorkflowSummaryCallbackInterface) {
            try {
                $this->summaryCallback->handle($this->getSummary());
            } catch (\Throwable $e) {
                // ignore summary callback errors
            }
        }

        return $allResults;
    }


    /**
     * Execute jobs in parallel using JobRunner
     */
    private function executeGroupParallel(array $jobs): array
    {
        // Mark all steps as started
        foreach (array_keys($jobs) as $stepId) {
            $this->context->markStepStarted($stepId);
        }

        // Execute in parallel
        $processResults = $this->jobRunner->async($jobs, $this->context);


        // Process results
        $results = [];
        foreach ($processResults as $stepId => $processResult) {
            $results[$stepId] = $this->handleJobResult($stepId, $processResult);
        }

        return $results;
    }

    /**
     * Execute jobs sequentially
     */
    private function executeGroupSequential(array $jobs): array
    {
         $results = [];

        foreach ($jobs as $stepId => $job) {
            if (!$this->context->is_running) break;

            $results[$stepId] = $this->runStepWithRetry($job);
        }

        return $results;
    }


    /**
     * Handle job result from worker process
     */
    private function handleJobResult(string $stepId, array $processResult): mixed
    {
        if (!$processResult['success']) {
            $errors = [$processResult['error']];

            $stopOnFail = $this->context->getStepStopOnFail($stepId);
            $perf = [
                'memory_used' => $processResult['memory_used'] ?? memory_get_usage(),
                'peak_memory' => $processResult['peak_memory'] ?? memory_get_peak_usage(),
            ];

            $this->context->markStepFailed($stepId, $errors, $perf);

            if ($stopOnFail) {
                $this->context->markWorkflowEnded($perf, WorkflowStatusEnum::FAIL);
            }

            return null;
        }

        // Success
        $result = $processResult['result'];
        $logs = $processResult['logs'] ?? [];
        $perf = [
            'memory_used' => $processResult['memory_used'] ?? memory_get_usage(),
            'peak_memory' => $processResult['peak_memory'] ?? memory_get_peak_usage(),
        ];

        $this->context->markStepCompleted($stepId, $result, $logs, $perf);

        return $result;
    }


    /**
     * Evaluate routing callback and return next steps
     */
    private function evaluateRouting(string $stepId, mixed $result): string|array|null
    {
        if (!isset($this->routingCallbacks[$stepId])) {
            return null;
        }

        $callback = $this->routingCallbacks[$stepId];
        $next = $callback($result, $this->context);

        // Clear original connections if routing returns something
        if ($next !== null) {
            $this->context->clearStepConnections($stepId);

            // Add new connections based on routing result
            if (is_string($next)) {
                $this->context->connect($stepId, $next);
            } elseif (is_array($next)) {
                foreach ($next as $nextStep) {
                    $this->context->connect($stepId, $nextStep);
                }
            }
        }

        return $next;
    }

    /**
     * Build execution groups for dynamically added steps
     */
    private function buildDynamicGroups(string|array $steps, array $alreadyProcessed): array
    {
        $stepsToProcess = is_array($steps) ? $steps : [$steps];
        $stepsToProcess = array_diff($stepsToProcess, $alreadyProcessed);

        if (empty($stepsToProcess)) {
            return [];
        }

        // Return as single group if multiple (they can run parallel)
        return [$stepsToProcess];
    }

    /**
     * Run a step with retry and timeout logic
     */
    private function runStepWithRetry(JobInterface $job): mixed
    {

        $this->context->markStepStarted($job->getId());

        $retryConfig = $this->context->getStepRetryConfig($job->getId());
        $timeout = $this->context->getStepTimeout($job->getId());
        $attempts = $retryConfig?->maxAttempts ?? 1;
        $baseDelay = $retryConfig?->baseDelay ?? 0.0;
        $mult = $retryConfig?->multiplier ?? 1.0;

        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $inputs = $this->context->resolveInputs($job->getId());

            try {
                // Run with timeout if specified
                if ($timeout !== null) {
                    $result = $this->runWithTimeout($job, $inputs, $timeout);
                } else {
                    $result = $job->run($inputs, $this->context);
                }

                $this->context->setLogs($job->getId(), $job->getLogs());

                if ($job instanceof BaseJob && $job->hasErrors()) {
                    $this->context->markStepFailed($job->getId(), $job->getErrors());
                    throw new WorkflowException("Job reported errors");
                }

                $perf = [
                    'memory_used' => memory_get_usage(),
                    'peak_memory' => memory_get_peak_usage(),
                ];

                $this->context->markStepCompleted($job->getId(), $result, [], $perf);
                return $result;

            } catch (\Throwable $e) {
                $lastException = $e;

                if (!($e instanceof WorkflowException)) {
                    $this->context->markStepFailed($job->getId(), [$e->getMessage()]);
                    $this->context->addLog($job->getId(), '[Error] Attempt ' . $attempt . ' failed: ' . $e->getMessage());
                }

                $isLastAttempt = $attempt >= $attempts;
                $stopOnFail = $this->context->getStepStopOnFail($job->getId());

                if ($isLastAttempt) {
                    if ($stopOnFail) {
                        $perf = [
                            'memory_used' => memory_get_usage(),
                            'peak_memory' => memory_get_peak_usage(),
                        ];
                        $this->context->markWorkflowEnded($perf, WorkflowStatusEnum::FAIL);
                        return null;
                    }

                    $perf = [
                        'memory_used' => memory_get_usage(),
                        'peak_memory' => memory_get_peak_usage(),
                    ];
                    $this->context->markStepFailed($job->getId(), [], $perf);
                    return null;
                }

                $delay = $baseDelay * ($mult ** ($attempt - 1));
                if ($delay > 0) {
                    usleep((int)($delay * 1_000_000));
                }
            }
        }

        if ($lastException) throw $lastException;
        return null;
    }

    /**
     * Run job with timeout using amphp
     */
    private function runWithTimeout(JobInterface $job, array $inputs, float $timeout): mixed
    {
        $future = async(fn() => $job->run($inputs, $this->context));

        try {
            return $future->await(\Amp\Future\timeout($timeout));
        } catch (\Amp\TimeoutCancellation $e) {
            throw new TimeoutException("Job '{$job->getId()}' exceeded timeout of {$timeout}s");
        }
    }

    /**
     * Build execution groups using Kahn's algorithm
     */
    private function buildExecutionGroups(): array
    {
        $steps = $this->context->getStepIds();
        $inDegree = array_fill_keys($steps, 0);
        $edges = [];


        foreach ($steps as $s) {
            foreach ($this->context->getStepConnections($s) as $to) {
                $edges[$s][] = $to;
                $inDegree[$to] = ($inDegree[$to] ?? 0) + 1;
            }
        }


        $queue = [];
        foreach ($inDegree as $node => $deg) {
            if ($deg === 0) $queue[] = $node;
        }

        $groups = [];
        $visited = [];

        while (!empty($queue)) {
            $group = [];
            foreach ($queue as $node) {
                if (in_array($node, $visited, true)) continue;
                $group[] = $node;
                $visited[] = $node;
            }

            $groups[] = $group;

            $next = [];
            foreach ($group as $node) {
                foreach ($edges[$node] ?? [] as $m) {
                    $inDegree[$m]--;
                    if ($inDegree[$m] === 0) $next[] = $m;
                }
            }

            $queue = $next;
        }


        if (count($visited) !== count($steps)) {
            foreach ($steps as $s) {
                if (!in_array($s, $visited, true)) $groups[] = [$s];
            }
        }

        return $groups;
    }

    public function getSummary(): mixed
    {
        return $this->context->toArray();
    }
}


/**
 * Fluent builder for conditional routing
 */
class RouteBuilder
{
    private Workflow $workflow;
    private ?string $stepId;
    private array $conditions = [];
    private string|array|null $elseRoute = null;

    public function __construct(Workflow $workflow, ?string $stepId)
    {
        $this->workflow = $workflow;
        $this->stepId = $stepId;
    }

    /**
     * Add a conditional route
     * @param callable $condition Function($result, $context): bool
     * @param string|array $route Next step(s) if condition is true
     */
    public function when(callable $condition, string|array $route): self
    {
        $this->conditions[] = ['condition' => $condition, 'route' => $route];
        return $this;
    }

    /**
     * Set fallback route if no conditions match
     */
    public function else(string|array $route): Workflow
    {
        $this->elseRoute = $route;
        return $this->build();
    }

    /**
     * Build and return to workflow (auto-called by else())
     */
    private function build(): Workflow
    {
        if ($this->stepId === null) {
            return $this->workflow;
        }

        $conditions = $this->conditions;
        $elseRoute = $this->elseRoute;

        $callback = function ($result, $context) use ($conditions, $elseRoute) {
            foreach ($conditions as $cond) {
                if ($cond['condition']($result, $context)) {
                    return $cond['route'];
                }
            }
            return $elseRoute;
        };

        $this->workflow->setRouting($this->stepId, $callback);
        return $this->workflow;
    }
}
