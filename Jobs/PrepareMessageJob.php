<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\Attributes\Job;
use App\WorkflowRodoud\WorkflowContext;

#[Job(name: 'prepare_message', description: 'Prepare message for AI')]
class PrepareMessageJob extends BaseJob
{
    public function execute(array $inputs = []): mixed
    {

        $this->addLog('Preparing message');;

        $instruction = $inputs['instruction'] ?? '';

        // Your logic here
        return [
            'messages' => [
                ['role' => 'system', 'content' => $instruction],
                ['role' => 'user', 'content' => 'Hello AI']
            ]
        ];
    }

    public function validateInputs(array $inputs = []): bool
    {
        return isset($inputs['instruction']);
    }
}
