<?php

namespace App\WorkflowRodoud;



use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Contracts\WorkflowSummaryCallbackInterface;
use App\WorkflowRodoud\Attributes\Job;
use App\WorkflowRodoud\Services\WorkflowRedisTracker;
use ReflectionClass;

class Workflow
{
    private string $id;
    private array $steps = [];
    private array $connections = [];
    private array $globals = [];
    private array $results = [];
    private ?WorkflowRedisTracker $tracker = null;
    private ?WorkflowSummaryCallbackInterface $summaryCallback = null;
    private string $name;
    private ?string $description = null;
    private float $startTime;
    private int $startMemory;

    public function __construct(?string $name = null, ?string $description = null)
    {
        $this->id = $this->generateUuid();
        $this->name = $name ?? 'workflow_' . $this->id;
        $this->description = $description;
    }

    public function setTracker(WorkflowRedisTracker $tracker): self
    {
        $this->tracker = $tracker;
        return $this;
    }

    public function setSummaryCallback(WorkflowSummaryCallbackInterface $callback): self
    {
        $this->summaryCallback = $callback;
        return $this;
    }

    public function setGlobals(array $globals): self
    {
        $this->globals = $globals;
        return $this;
    }

    public function addStep(string $stepId, JobInterface $job, array $inputs = []): self
    {
        $jobName = $this->extractJobName($job);

        $this->steps[$stepId] = [
            'id' => $stepId,
            'job' => $job,
            'name' => $jobName,
            'inputs' => $inputs,
            'dependencies' => []
        ];

        return $this;
    }

    public function connect(string $fromNodeId, string $toNodeId): self
    {
        if (!isset($this->steps[$fromNodeId])) {
            throw new \InvalidArgumentException("Step '$fromNodeId' does not exist");
        }

        if (!isset($this->steps[$toNodeId])) {
            throw new \InvalidArgumentException("Step '$toNodeId' does not exist");
        }

        $this->connections[] = [
            'from' => $fromNodeId,
            'to' => $toNodeId
        ];

        $this->steps[$toNodeId]['dependencies'][] = $fromNodeId;

        return $this;
    }

    public function execute(): array
    {
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);

        if ($this->tracker) {
            $this->tracker->initWorkflow($this->id, [
                'name' => $this->name,
                'description' => $this->description,
                'total_steps' => count($this->steps)
            ]);
        }

        try {
            if ($this->tracker) {
                $this->tracker->updateStatus($this->id, 'running');
            }

            $executionOrder = $this->buildExecutionOrder();

            foreach ($executionOrder as $stepId) {
                $this->executeStep($stepId);
            }

            if ($this->tracker) {
                $this->tracker->updateStatus($this->id, 'completed');
            }

            $summary = $this->generateSummary();

            if ($this->summaryCallback) {
                $this->summaryCallback->handle($summary);
            }

            return $this->results;

        } catch (\Throwable $e) {
            if ($this->tracker) {
                $this->tracker->updateStatus($this->id, 'failed');
            }

            throw $e;
        }
    }

    private function executeStep(string $stepId): void
    {
        $step = $this->steps[$stepId];
        $job = $step['job'];
        $jobName = $step['name'];

        $resolvedInputs = $this->resolveInputs($step['inputs']);

        // Validate inputs - skip if validation fails
        if (!$job->validateInputs($resolvedInputs)) {
            if ($this->tracker) {
                $this->tracker->skipJob($this->id, $jobName, $resolvedInputs, 'validation_failed');
            }
            $this->results[$stepId] = ['skipped' => true, 'reason' => 'validation_failed'];
            return;
        }

        if ($this->tracker) {
            $this->tracker->startJob($this->id, $jobName, $resolvedInputs);
        }

        try {
            $result = $job->execute($resolvedInputs, $this->globals);
            $this->results[$stepId] = $result;

            if ($this->tracker) {
                $this->tracker->completeJob($this->id, $jobName, $result, $resolvedInputs, $job->getLogs());
            }

        } catch (\Throwable $e) {
            if ($this->tracker) {
                $this->tracker->failJob($this->id, $jobName, $e, $resolvedInputs, $job->getLogs());
            }

            throw $e;
        }
    }

    private function resolveInputs(array $inputMapping): array
    {
        $resolved = [];

        foreach ($inputMapping as $inputKey => $mapping) {
            if (is_array($mapping) && count($mapping) === 1) {
                $stepId = array_key_first($mapping);
                $resultKey = $mapping[$stepId];

                if (isset($this->results[$stepId])) {
                    $stepResult = $this->results[$stepId];

                    if (is_array($stepResult) && isset($stepResult[$resultKey])) {
                        $resolved[$inputKey] = $stepResult[$resultKey];
                    } else {
                        $resolved[$inputKey] = $stepResult;
                    }
                }
            } else {
                $resolved[$inputKey] = $mapping;
            }
        }

        return $resolved;
    }

    private function buildExecutionOrder(): array
    {
        $order = [];
        $visited = [];
        $temp = [];

        $visit = function($stepId) use (&$visit, &$order, &$visited, &$temp) {
            if (isset($temp[$stepId])) {
                throw new \RuntimeException("Circular dependency detected at step: $stepId");
            }

            if (isset($visited[$stepId])) {
                return;
            }

            $temp[$stepId] = true;

            foreach ($this->steps[$stepId]['dependencies'] as $depId) {
                $visit($depId);
            }

            unset($temp[$stepId]);
            $visited[$stepId] = true;
            $order[] = $stepId;
        };

        foreach (array_keys($this->steps) as $stepId) {
            if (!isset($visited[$stepId])) {
                $visit($stepId);
            }
        }

        return $order;
    }

    private function extractJobName(JobInterface $job): string
    {
        if (method_exists($job, 'getName')) {
            return $job->getName();
        }

        $reflection = new ReflectionClass($job);
        $attributes = $reflection->getAttributes(Job::class);

        if (!empty($attributes)) {
            $jobAttribute = $attributes[0]->newInstance();
            return $jobAttribute->name;
        }

        return basename(str_replace('\\', '/', get_class($job)));
    }

    public function generateSummary(): array
    {
        $trackerData = $this->tracker ? $this->tracker->getWorkflowData($this->id) : [];

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        return [
            'workflow_id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $trackerData['status'] ?? 'completed',
            'started_at' => $trackerData['started_at'] ?? null,
            'completed_at' => $trackerData['completed_at'] ?? null,
            'performance' => [
                'total_execution_time' => round($endTime - $this->startTime, 4),
                'total_memory_used' => $endMemory - $this->startMemory,
                'peak_memory' => memory_get_peak_usage(true),
                'start_memory' => $this->startMemory
            ],
            'steps' => array_map(function($step) {
                return [
                    'id' => $step['id'],
                    'name' => $step['name'],
                    'dependencies' => $step['dependencies']
                ];
            }, $this->steps),
            'connections' => $this->connections,
            'executed_jobs' => $trackerData['executed_jobs'] ?? [],
            'results' => $this->results
        ];
    }

    public function getSummary(): array
    {
        return $this->generateSummary();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public static function fromJson(string $json, array $jobRegistry = []): self
    {
        $config = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }

        $workflow = new self(
            $config['name'] ?? null,
            $config['description'] ?? null
        );

        foreach ($config['nodes'] ?? [] as $node) {
            $jobClass = $node['job_class'] ?? null;

            if (!$jobClass || !isset($jobRegistry[$jobClass])) {
                throw new \InvalidArgumentException("Job class not found in registry: $jobClass");
            }

            $job = $jobRegistry[$jobClass];
            $workflow->addStep($node['id'], $job, $node['inputs'] ?? []);
        }

        foreach ($config['connections'] ?? [] as $connection) {
            $workflow->connect($connection['from'], $connection['to']);
        }

        return $workflow;
    }

    public function toJson(): string
    {
        $config = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'nodes' => array_map(function($step) {
                return [
                    'id' => $step['id'],
                    'job_class' => get_class($step['job']),
                    'name' => $step['name'],
                    'inputs' => $step['inputs']
                ];
            }, array_values($this->steps)),
            'connections' => $this->connections
        ];

        return json_encode($config, JSON_PRETTY_PRINT);
    }

    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
