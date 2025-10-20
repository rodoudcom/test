<?php
namespace App\WorkflowRodoud\Jobs;

use App\WorkflowRodoud\Contracts\JobInterface;
use App\WorkflowRodoud\Attributes\Job;
use ReflectionClass;

abstract class BaseJob implements JobInterface
{
    private array $logs = [];

    public function getName(): string
    {
        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Job::class);

        if (!empty($attributes)) {
            $jobAttribute = $attributes[0]->newInstance();
            return $jobAttribute->name;
        }

        return class_basename($this);
    }

    public function validateInputs(array $inputs = []): bool
    {
        return true;
    }

    public function addLog(string $level, string $message, array $context = []): void
    {
        $this->logs[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => (new DateTime())->format('c')
        ];
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    abstract public function execute(array $inputs = [], array $globals = []): mixed;
}
