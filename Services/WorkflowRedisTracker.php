<?php

namespace App\WorkflowRodoud\Services;

use Redis;
use DateTime;

class WorkflowRedisTracker
{
    private Redis $redis;
    private const WORKFLOW_PREFIX = 'workflow:';
    private const TTL = 86400;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function initWorkflow(string $workflowId, array $metadata = []): void
    {
        $key = $this->getWorkflowKey($workflowId);

        $data = [
            'id' => $workflowId,
            'status' => 'initialized',
            'started_at' => (new DateTime())->format('c'),
            'metadata' => $metadata,
            'executed_jobs' => [],
            'current_job' => null,
            'performance' => [
                'start_memory' => memory_get_usage(true),
                'peak_memory' => 0,
                'execution_time' => 0
            ]
        ];

        $this->redis->setex($key, self::TTL, json_encode($data));
        $this->publish($workflowId, 'workflow_initialized', $data);
    }

    public function updateStatus(string $workflowId, string $status): void
    {
        $data = $this->getWorkflowData($workflowId);
        $data['status'] = $status;

        if ($status === 'completed' || $status === 'failed') {
            $data['completed_at'] = (new DateTime())->format('c');

            // Calculate total execution time
            $start = new DateTime($data['started_at']);
            $end = new DateTime($data['completed_at']);
            $data['performance']['execution_time'] = $end->getTimestamp() - $start->getTimestamp();

            // Update peak memory
            $data['performance']['peak_memory'] = memory_get_peak_usage(true);
            $data['performance']['memory_used'] = memory_get_usage(true) - $data['performance']['start_memory'];
        }

        $this->saveWorkflowData($workflowId, $data);
        $this->publish($workflowId, 'status_updated', ['status' => $status]);
    }

    public function startJob(string $workflowId, string $jobName, array $inputs = []): void
    {
        $data = $this->getWorkflowData($workflowId);
        $data['current_job'] = [
            'name' => $jobName,
            'status' => 'running',
            'started_at' => (new DateTime())->format('c'),
            'inputs' => $inputs,
            'performance' => [
                'start_time' => microtime(true),
                'start_memory' => memory_get_usage(true)
            ]
        ];

        $this->saveWorkflowData($workflowId, $data);
        $this->publish($workflowId, 'job_started', [
            'job' => $jobName,
            'inputs' => $inputs
        ]);
    }

    public function completeJob(string $workflowId, string $jobName, mixed $result, array $inputs, array $logs): void
    {
        $data = $this->getWorkflowData($workflowId);
        $currentJob = $data['current_job'];

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $executedJob = [
            'name' => $jobName,
            'status' => 'completed',
            'started_at' => $currentJob['started_at'],
            'completed_at' => (new DateTime())->format('c'),
            'inputs' => $inputs,
            'outputs' => $this->serializeResult($result),
            'logs' => $logs,
            'performance' => [
                'execution_time' => round($endTime - $currentJob['performance']['start_time'], 4),
                'memory_used' => $endMemory - $currentJob['performance']['start_memory'],
                'peak_memory' => memory_get_peak_usage(true)
            ]
        ];

        $data['executed_jobs'][] = $executedJob;
        $data['current_job'] = null;

        $this->saveWorkflowData($workflowId, $data);
        $this->publish($workflowId, 'job_completed', [
            'job' => $jobName,
            'outputs' => $this->serializeResult($result),
            'performance' => $executedJob['performance']
        ]);
    }

    public function failJob(string $workflowId, string $jobName, \Throwable $exception, array $inputs, array $logs): void
    {
        $data = $this->getWorkflowData($workflowId);
        $currentJob = $data['current_job'];

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $failedJob = [
            'name' => $jobName,
            'status' => 'failed',
            'started_at' => $currentJob['started_at'] ?? (new DateTime())->format('c'),
            'failed_at' => (new DateTime())->format('c'),
            'inputs' => $inputs,
            'logs' => $logs,
            'error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ],
            'performance' => [
                'execution_time' => isset($currentJob['performance']['start_time'])
                    ? round($endTime - $currentJob['performance']['start_time'], 4)
                    : 0,
                'memory_used' => isset($currentJob['performance']['start_memory'])
                    ? $endMemory - $currentJob['performance']['start_memory']
                    : 0,
                'peak_memory' => memory_get_peak_usage(true)
            ]
        ];

        $data['executed_jobs'][] = $failedJob;
        $data['current_job'] = null;
        $data['status'] = 'failed';

        $this->saveWorkflowData($workflowId, $data);
        $this->publish($workflowId, 'job_failed', [
            'job' => $jobName,
            'error' => $failedJob['error']
        ]);
    }

    public function skipJob(string $workflowId, string $jobName, array $inputs, string $reason): void
    {
        $data = $this->getWorkflowData($workflowId);

        $skippedJob = [
            'name' => $jobName,
            'status' => 'skipped',
            'skipped_at' => (new DateTime())->format('c'),
            'inputs' => $inputs,
            'outputs' => null,
            'logs' => [],
            'reason' => $reason,
            'performance' => [
                'execution_time' => 0,
                'memory_used' => 0,
                'peak_memory' => 0
            ]
        ];

        $data['executed_jobs'][] = $skippedJob;

        $this->saveWorkflowData($workflowId, $data);
        $this->publish($workflowId, 'job_skipped', [
            'job' => $jobName,
            'reason' => $reason
        ]);
    }

    public function getWorkflowData(string $workflowId): array
    {
        $key = $this->getWorkflowKey($workflowId);
        $data = $this->redis->get($key);

        return $data ? json_decode($data, true) : [];
    }

    private function saveWorkflowData(string $workflowId, array $data): void
    {
        $key = $this->getWorkflowKey($workflowId);
        $this->redis->setex($key, self::TTL, json_encode($data));
    }

    private function publish(string $workflowId, string $eventType, array $data): void
    {
        $this->redis->publish("workflow:$workflowId", json_encode([
            'type' => $eventType,
            'data' => $data,
            'timestamp' => (new DateTime())->format('c')
        ]));
    }

    private function getWorkflowKey(string $workflowId): string
    {
        return self::WORKFLOW_PREFIX . $workflowId;
    }

    private function serializeResult(mixed $result): mixed
    {
        if (is_object($result)) {
            return [
                '_type' => 'object',
                'class' => get_class($result),
                'data' => method_exists($result, 'toArray') ? $result->toArray() : (array) $result
            ];
        }

        return $result;
    }
}



