<?php

namespace App\WorkflowRodoud\Contracts;

use App\WorkflowRodoud\WorkflowContext;

interface JobInterface
{
    /**
     * Execute the job with given inputs
     *
     * @param array $inputs Input data for the job
     * @return mixed Job execution result
     */
    public function execute(array $inputs = []): mixed;

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

    public function setId(string $id): self;

    public function addLog(string $log): void;

    public function setContext(WorkflowContext $context): self;

}
