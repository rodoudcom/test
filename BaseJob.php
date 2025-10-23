<?php

namespace App\WorkflowRodoud;

use App\WorkflowRodoud\Attributes\Job;
use App\WorkflowRodoud\Contracts\JobInterface;
use ReflectionClass;

abstract class BaseJob implements JobInterface
{
    protected array $jobErrors = [];
    protected array $jobLogs = [];

    protected string $id = "";

    public function getName(): string
    {

        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Job::class);

        if (!empty($attributes)) {
            $jobAttribute = $attributes[0]->newInstance();
            return $jobAttribute->name;
        }

        return "";
    }

    public function getDescription(): string
    {

        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Job::class);

        if (!empty($attributes)) {
            $jobAttribute = $attributes[0]->newInstance();
            return $jobAttribute->description;
        }

        return "";
    }

    public function addError(string $error): void
    {
        $this->jobErrors[] = $error;
    }

    public function hasErrors(): bool
    {
        return !empty($this->jobErrors);
    }

    public function getErrors(): array
    {
        return $this->jobErrors;
    }

    public function addLog(string $message): void
    {
        $this->jobLogs[] = $message;
    }

    public function getLogs(): array
    {
        return $this->jobLogs;
    }


    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }
    public function toArray(): array
    {
        return [
            'class' => get_class($this),
            'id' => $this->id,
            'errors' => $this->jobErrors,
            'logs' => $this->jobLogs,
        ];
    }

    public static function fromArray(array $data): JobInterface
    {
        $class = $data['class'];
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Unknown job class: {$class}");
        }

        /** @var self $job */
        $job = new $class();

        $job->id = $data['id'] ?? uniqid();
        $job->jobErrors = $data['errors'] ?? [];
        $job->jobLogs = $data['logs'] ?? [];

        return $job;
    }
}

