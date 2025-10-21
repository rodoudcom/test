<?php

namespace App\WorkflowRodoud\Services;

 use Redis;

class WorkflowRedisTracker implements \App\WorkflowRodoud\Contracts\TrackerInterface
{
    protected Redis $redis;
    protected string $prefix;

    public function __construct(Redis $redis, string $prefix = 'workflow:updates:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function track(string $workflowId, array $payload): void
    {
        $this->redis->set($this->prefix . $workflowId, json_encode($payload));
        // Publish to pub/sub for WebSocket broadcasting (optional)
        $this->redis->publish($this->prefix . $workflowId, json_encode($payload));

        $this->redis->expire($this->prefix . $workflowId, 100);
    }
}


