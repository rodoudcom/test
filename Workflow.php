<?php

namespace App\WorkflowRodoud;


/**
 * Refactored Workflow orchestrator
 *
 * Responsibilities:
 * - Orchestrates execution of steps defined in a WorkflowContext
 * - Builds execution groups from ->connect relationships so independent steps run in parallel
 * - Runs groups sequentially (workflow is synchronous by default)
 * - Handles per-step retry (RetryConfig) and stopOnFail behavior
 * - Collects and merges job logs/errors into the WorkflowContext so the tracker can stream updates
 *
 * Requirements:
 * - WorkflowContext implementation (see your project) that exposes methods used below
 * - Jobs that implement JobInterface (and optionally extend BaseJob to use job logs/errors)
 * - amphp/amp installed for simple Promise utilities (used only to run group promises in parallel)
 *
 * Notes:
 * - This class assumes WorkflowContext::markStepStarted/Completed/Failed accept logs and errors
 *   and that Job implementations append logs/errors via BaseJob methods.
 * - Workflow execution is synchronous at workflow level; parallelism only happens inside groups.
 */

use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Contracts\TrackerInterface;
use App\WorkflowRodoud\Contracts\WorkflowSummaryCallbackInterface;
use App\WorkflowRodoud\Enum\WorkflowStatusEnum;
use App\WorkflowRodoud\Exception\WorkflowException;
use function Amp\async;


class Workflow
{
    private WorkflowContext $context;
    private ?WorkflowSummaryCallbackInterface $summaryCallback = null;

    /**
     * Create workflow attached to an existing context or create a new one from name.
     * @param WorkflowContext|string $contextOrName
     */
    public function __construct(WorkflowContext|string $contextOrName)
    {
        if ($contextOrName instanceof WorkflowContext) {
            $this->context = $contextOrName;
        } else {
            $id = bin2hex(random_bytes(8));
            $this->context = new WorkflowContext($id, $contextOrName);
        }
    }

    /*
    * @param string $stepId
    * @param JobInterface $job
    * @param array $inputs  // mapping of inputs like ["instruction" => ["prepareInstruction"=>"instruction"]]
    * @param bool $stopOnFail default true
    * @return $this
    */
    public function addStep(string $stepId, JobInterface $job, array $inputs = [], bool $stopOnFail = true): self
    {
        $this->context->addStep($stepId, $job, $inputs, $stopOnFail);
        return $this;
    }

    /**
     * Connect two steps (from -> to). Delegates to context.
     * @param string $from
     * @param string $to
     * @return $this
     */
    public function connect(string $from, string $to): self
    {
        $this->context->connect($from, $to);
        return $this;
    }


    /**
     * Apply retry to the last added step (convenience).
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


    /** Set tracker on the underlying context */
    public function setTracker(TrackerInterface $tracker): self
    {
        $this->context->setTracker($tracker);
        return $this;
    }

    /** Set optional summary callback (called at end with context->toArray() or getResults()) */
    public function setSummaryCallback(WorkflowSummaryCallbackInterface $cb): self
    {
        $this->summaryCallback = $cb;
        return $this;
    }


    /** Get current workflow context */
    public function getContext(): WorkflowContext
    {
        return $this->context;
    }


    public function setGlobals(array $globales): self
    {
        $this->context->setGlobals($globales);
        return $this;
    }

    /**
     * Execute the workflow synchronously.
     * Steps are grouped based on ->connect relationships. Groups run sequentially.
     * Steps inside a group run in parallel.
     *
     * @return array results keyed by step id
     * @throws \Throwable On a step failure when stopOnFail is true.
     */
    public function execute(): array
    {
        $groups = $this->buildExecutionGroups();
        $allResults = [];

        $this->context->markWorkflowStarted();
        foreach ($groups as $group) {

            // Build async operations for the group
            $futures = [];

            foreach ($group as $stepId) {
                if (!$this->context->is_running) continue; //the workflow already stopped
                $job = $this->context->getStepJob($stepId);

                if (!($job instanceof JobInterface)) {
                    // No-op step: mark as completed with null result
                    $this->context->markStepStarted($stepId);
                    $this->context->markStepCompleted($stepId, null, []);
                    $allResults[$stepId] = null;
                    continue;
                }

                $job->setId($stepId);

                // Each step executed as a Future so we can await all in the group
                $futures[$stepId] = async(function () use ($job) {
                    return $this->runStepWithRetry($job);
                });
            }

            // Wait for group completion (parallel execution)
            $groupResults = [];
            if (!empty($futures)) {
                // Await all futures in parallel
                $groupResults = \Amp\Future\await($futures);
            }

            // Merge results
            foreach ($groupResults as $s => $r) {
                $allResults[$s] = $r;
            }
        }


        $perf = [
            'memory_used' => memory_get_usage(),
            'peak_memory' => memory_get_peak_usage(),
        ];

        $this->context->markWorkflowEnded($perf, WorkflowStatusEnum::SUCCESS);


        // optional summary callback
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
     * Run a step with retry logic. It will update context on start, each retry attempt, completion or failure.
     * Throws if all attempts failed and stopOnFail is true.
     *
     * @param JobInterface $job
     * @return mixed
     * @throws \Throwable
     */
    private function runStepWithRetry(JobInterface $job): mixed
    {
        $this->context->markStepStarted($job->getId());

        $retryConfig = $this->context->getStepRetryConfig($job->getId());
        $attempts = $retryConfig?->maxAttempts ?? 1;
        $baseDelay = $retryConfig?->baseDelay ?? 0.0;
        $mult = $retryConfig?->multiplier ?? 1.0;

        $lastException = null;


        for ($attempt = 1; $attempt <= $attempts; $attempt++) {

            // resolve inputs fresh for each attempt
            $inputs = $this->context->resolveInputs($job->getId());;

            try {
                $result = $job->run($inputs, $this->context);
                $this->context->setLogs($job->getId(), $job->getLogs());

                // if job reports errors via BaseJob, treat as failure
                if ($job instanceof BaseJob && $job->hasErrors()) {
                    $this->context->markStepFailed($job->getId(), $job->getErrors());
                    throw new WorkflowException("");
                }

                // on success, mark completed and push logs
                $perf = [
                    'memory_used' => memory_get_usage(),
                    'peak_memory' => memory_get_peak_usage(),
                ];


                $this->context->markStepCompleted($job->getId(), $result, [], $perf);

                return $result;
            } catch (\Throwable $e) {
                $lastException = $e;



                // merge logs into executed step data and record failure for this attempt
                if (!($e instanceof WorkflowException)){
                    $this->context->markStepFailed($job->getId(), [$e->getMessage()]);
                    $this->context->addLog($job->getId(), '[Error] Attempt ' . $attempt . ' failed: ' . $e->getMessage());

                }


                // if this is last attempt, decide whether to stop or continue
                $isLastAttempt = $attempt >= $attempts;
                $stopOnFail = $this->context->getStepStopOnFail($job->getId());

                if ($isLastAttempt) {
                    if ($stopOnFail) {
                        // bubble exception to stop entire workflow
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
                    // stopOnFail == false => mark completed with null and continue
                    $this->context->markStepFailed($job->getId(), [], $perf);
                    return null;
                }

                // not last attempt: wait according to backoff before retrying
                $delay = $baseDelay * ($mult ** ($attempt - 1));
                if ($delay > 0) {
                    usleep((int)($delay * 1_000_000));
                }

                // continue next attempt
            }
        }

        // should not reach here, but rethrow if it does
        if ($lastException) throw $lastException;
        return null;
    }

    /**
     * Collect job logs if job implements BaseJob or provides getLogs().
     * Always return an array (possibly empty).
     *
     * @param JobInterface $job
     * @return array
     */
    private function collectJobLogs(JobInterface $job): array
    {
        if ($job instanceof BaseJob) {
            return $job->getLogs();
        }

        // attempt generic getter
        if (method_exists($job, 'getLogs')) {
            $logs = $job->getLogs();
            if (is_array($logs)) return $logs;
        }

        return [];
    }

    /**
     * Build execution groups using Kahn's algorithm on the connect graph.
     * Each group is a list of steps that have no pending parents and therefore can run in parallel.
     *
     * @return array<int, string[]>
     */
    private function buildExecutionGroups(): array
    {
        $steps = $this->context->getStepIds();
        $inDegree = array_fill_keys($steps, 0);
        $edges = [];

        // build edges and indegree based on connections (from -> to)
        foreach ($steps as $s) {
            foreach ($this->context->getStepConnections($s) as $to) {
                $edges[$s][] = $to;
                $inDegree[$to] = ($inDegree[$to] ?? 0) + 1;
            }
        }

        // initial queue: nodes with indegree 0
        $queue = [];
        foreach ($inDegree as $node => $deg) {
            if ($deg === 0) $queue[] = $node;
        }

        $groups = [];
        $visited = [];

        while (!empty($queue)) {
            // current group = all nodes in queue
            $group = [];
            foreach ($queue as $node) {
                if (in_array($node, $visited, true)) continue;
                $group[] = $node;
                $visited[] = $node;
            }

            $groups[] = $group;

            // prepare next queue
            $next = [];
            foreach ($group as $node) {
                foreach ($edges[$node] ?? [] as $m) {
                    $inDegree[$m]--;
                    if ($inDegree[$m] === 0) $next[] = $m;
                }
            }

            $queue = $next;
        }

        // if cycle detected (some nodes not visited), append remaining nodes as final group
        if (count($visited) !== count($steps)) {
            foreach ($steps as $s) {
                if (!in_array($s, $visited, true)) $groups[] = [$s];
            }
        }

        return $groups;
    }


    public function getSummary(): mixed
    {
        return ($this->context->toArray());
    }
}

