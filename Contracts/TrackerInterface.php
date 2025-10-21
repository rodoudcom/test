<?php

namespace App\WorkflowRodoud\Contracts;

interface TrackerInterface
{
    public function track(string $workflowId, array $payload): void;
}
