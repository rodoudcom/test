<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\Attributes\Job;

#[Job(name: 'prepare_message', description: 'Prepare message for AI')]
class PrepareMessageJob extends BaseJob
{
    public function execute(array $inputs = [], array $globals = []): mixed
    {
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
