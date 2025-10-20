<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\Attributes\Job;

#[Job(name: 'get_instructions', description: 'Get AI instructions')]
class GetInstructionsJob extends BaseJob
{
    public function execute(array $inputs = [], array $globals = []): mixed
    {
        $context = $globals['conversationContext'] ?? [];

        // Your logic here
        return [
            'instruction' => 'Process the conversation with AI...',
            'context' => $context
        ];
    }

    public function validateInputs(array $inputs = []): bool
    {
        // Add validation if needed
        return true;
    }
}
