<?php

namespace App\WorkflowRodoud\Contracts;

use App\WorkflowRodoud\RetryConfig;
use App\WorkflowRodoud\WorkflowContext;

interface JobInterface
{
    public function run(array $inputs, WorkflowContext $context): mixed;
    public function getId(): string;
    public function setId(string $id): self;

    public function toArray();
}
