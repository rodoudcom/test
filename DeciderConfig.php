<?php
namespace App\WorkflowRodoud;


/**
 * Decider configuration for conditional routing
 */
class DeciderConfig
{
    public array $conditions = [];
    public ?string $defaultNext = null;

    /**
     * Add a condition: if outputKey operator value, go to nextStepId
     */
    public function addCondition(string $outputKey, string $operator, mixed $value, string $nextStepId): self
    {
        $this->conditions[] = [
            'key' => $outputKey,
            'operator' => $operator,
            'value' => $value,
            'next' => $nextStepId
        ];
        return $this;
    }

    public function setDefault(string $nextStepId): self
    {
        $this->defaultNext = $nextStepId;
        return $this;
    }

    public function evaluate(array $output): ?string
    {
        foreach ($this->conditions as $condition) {
            $actualValue = $output[$condition['key']] ?? null;

            if ($this->checkCondition($actualValue, $condition['operator'], $condition['value'])) {
                return $condition['next'];
            }
        }

        return $this->defaultNext;
    }

    private function checkCondition(mixed $actual, string $operator, mixed $expected): bool
    {
        return match($operator) {
            '==' => $actual == $expected,
            '===' => $actual === $expected,
            '!=' => $actual != $expected,
            '!==' => $actual !== $expected,
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'in' => is_array($expected) && in_array($actual, $expected),
            'contains' => is_string($actual) && str_contains($actual, $expected),
            default => false
        };
    }
}