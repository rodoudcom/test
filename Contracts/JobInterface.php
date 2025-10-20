<?php

namespace App\WorkflowRodoud\Contracts;

interface JobInterface
{
    /**
     * Execute the job with given inputs
     *
     * @param array $inputs Input data for the job
     * @param array $globals Global context available to all jobs
     * @return mixed Job execution result
     */
    public function execute(array $inputs = [], array $globals = []): mixed;

    /**
     * Get the job name (can be overridden or use attribute)
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Validate inputs before execution
     *
     * @param array $inputs
     * @return bool
     */
    public function validateInputs(array $inputs = []): bool;

    public function getLogs(): array;
    public function addLog(string $level, string $message, array $context = []): void;
}
