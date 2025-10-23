<?php

namespace App\WorkflowRodoud;

use App\WorkflowRodoud\Contracts\JobInterface;
use Symfony\Component\Process\Process;

class JobRunner
{
    private string $phpBinary;
    private string $workerScript;

    public function __construct(string $phpBinary = 'php', ?string $workerScript = null)
    {
        $this->phpBinary = $phpBinary;
        $this->workerScript = $workerScript ?? __DIR__ . '/../../bin/console';
    }

    /**
     * Execute jobs in parallel (async)
     * @param array $jobs Array of [stepId => JobInterface]
     * @param WorkflowContext $context
     * @return array Results keyed by stepId
     */
    public function async(array $jobs, WorkflowContext $context): array
    {

        if (empty($jobs)) {
            return [];
        }

        $processes = [];
        $tempFiles = [];

        // Start all processes
        foreach ($jobs as $stepId => $job) {
            $jobData = $this->prepareJobData($stepId, $job, $context);
            $tempFile = $this->createTempFile($jobData);
            $tempFiles[$stepId] = $tempFile;


            $process = new Process([
                $this->phpBinary,
                $this->workerScript,
                "rodoud:job:execute",
                $tempFile
            ]);

            $process->setTimeout(300); // 5 minutes
            $process->start();

            $processes[$stepId] = $process;
        }

        // Wait for all and collect results
        $results = [];
        foreach ($processes as $stepId => $process) {
            $process->wait();
            //if(!$process->isSuccessful()) dd($process->getOutput());
            $results[$stepId] = $this->processOutput(
                $stepId,
                $process->getOutput(),
                $process->getErrorOutput(),
                $process->isSuccessful()
            );

            //dump($tempFiles);
            // Cleanup
            @unlink($tempFiles[$stepId]);
        }


        return $results;
    }

    /**
     * Execute jobs sequentially (sync)
     * @param array $jobs Array of [stepId => JobInterface]
     * @param WorkflowContext $context
     * @return array Results keyed by stepId
     */
    public function sync(array $jobs, WorkflowContext $context): array
    {
        if (empty($jobs)) {
            return [];
        }

        $results = [];

        foreach ($jobs as $stepId => $job) {
            $jobData = $this->prepareJobData($stepId, $job, $context);
            $tempFile = $this->createTempFile($jobData);

            $process = new Process([
                $this->phpBinary,
                $this->workerScript,
                $tempFile
            ]);

            $process->setTimeout(300);
            $process->start();
            $process->wait();

            $results[$stepId] = $this->processOutput(
                $stepId,
                $process->getOutput(),
                $process->getErrorOutput(),
                $process->isSuccessful()
            );

            // Cleanup
            @unlink($tempFile);
        }

        return $results;
    }

    /**
     * Prepare job data for serialization
     */
    private function prepareJobData(string $stepId, JobInterface $job, WorkflowContext $context): array
    {

        return [
            'stepId' => $stepId,
            'job' => base64_encode(json_encode($job->toArray())),
            'inputs' => $context->resolveInputs($stepId),
            'globals' => serialize($context->toArray()['globals'] ?? []),
            'workflowId' => $context->getWorkflowId(),
        ];
    }

    /**
     * Create temporary file with job data
     */
    private function createTempFile(array $data): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'job_');
        file_put_contents($tempFile, json_encode($data));
        return $tempFile;
    }

    /**
     * Process the output from worker script
     */
    private function processOutput(string $stepId, string $output, string $error, bool $success): array
    {
        if (!$success) {
            return [
                'success' => false,
                'stepId' => $stepId,
                'result' => null,
                'error' => $error ?: 'Process failed',
                'logs' => [],
            ];
        }

        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'stepId' => $stepId,
                'result' => null,
                'error' => 'Invalid JSON output: ' . $output,
                'logs' => [],
            ];
        }

        return $decoded;
    }
}
