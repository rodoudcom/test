<?php

namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\Attributes\Job;


#[Job(name: 'ask_ai', description: 'Send request to AI')]
class AskAiJob extends BaseJob
{
    public function execute(array $inputs = [], array $globals = []): mixed
    {
        $messages = $inputs['messages'] ?? [];

        // Your AI API call logic here
        $response = $this->callAiApi($messages);

        return [
            'response' => $response,
            'tokens_used' => 150
        ];
    }

    public function validateInputs(array $inputs = []): bool
    {
        return isset($inputs['messages']) && is_array($inputs['messages']);
    }

    private function callAiApi(array $messages): string
    {
        // Simulate AI API call
        return "AI response based on: " . json_encode($messages);
    }
}
