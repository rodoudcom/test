<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\Attributes\Job;
use App\WorkflowRodoud\WorkflowContext;

#[Job(name: 'get_instructions', description: 'Get AI instructions')]
class GetInstructionsJob extends BaseJob
{
    public function execute(array $inputs = []): mixed
    {

        $this->addLog('>>>>>>>>>Getting instructions');
        $conversationContext = $globals['conversationContext'] ?? [];

        sleep(15);
        $this->addLog('>>>>>>>>>after sleep');
        // Your logic here
        return [
            'instruction' => 'Process the conversation with AI...',
            'context' => $conversationContext
        ];
    }

    public function validateInputs(array $inputs = []): bool
    {
        // Add validation if needed
        return true;
    }
}
