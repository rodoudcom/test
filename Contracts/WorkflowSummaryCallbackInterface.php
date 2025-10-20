<?php
namespace App\WorkflowRodoud\Contracts;

interface WorkflowSummaryCallbackInterface
{
    public function handle(array $summary): void;
}
