<?php

namespace App\WorkflowRodoud\Services;

use App\WorkflowRodoud\WorkflowContext;
use Redis;
use DateTime;

/**
 * WorkflowRedisTracker - Optional Redis mirror for real-time debugging
 *
 * This is NOT the source of truth
 * It just mirrors WorkflowContext to Redis for real-time monitoring
 * If Redis fails, workflow continues normally
 */
class WorkflowRedisTracker
{
    private Redis $redis;
    private bool $enabled = true;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Enable/disable tracking (useful for testing or production)
     */
    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get Redis key for workflow
     */
    private function getRedisKey(string $workflowId): string
    {
        return "workflow:realtime:{$workflowId}";
    }

    /**
     * Sync WorkflowContext to Redis
     * This is the ONLY method that writes to Redis
     */
    public function sync(WorkflowContext $context): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $workflowId = $context->getWorkflowId();
            $data = $context->toArray();

            // Write to Redis
            $this->redis->set($this->getRedisKey($workflowId), json_encode($data));

            // Publish to pub/sub for WebSocket broadcasting (optional)
            $this->redis->publish("workflow:updates:{$workflowId}", json_encode($data));

        } catch (\Exception $e) {
            // Redis failed - log but don't break workflow execution
            error_log("WorkflowRedisTracker: Failed to sync to Redis: " . $e->getMessage());
        }
    }

    /**
     * Set expiry on Redis key when workflow completes
     */
    public function setExpiry(string $workflowId, int $seconds = 3600): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->redis->expire($this->getRedisKey($workflowId), $seconds);
        } catch (\Exception $e) {
            error_log("WorkflowRedisTracker: Failed to set expiry: " . $e->getMessage());
        }
    }

    /**
     * Get workflow state from Redis (for API endpoints)
     */
    public static function get(Redis $redis, string $workflowId): ?array
    {
        try {
            $key = "workflow:realtime:{$workflowId}";
            $data = $redis->get($key);
            return $data ? json_decode($data, true) : null;
        } catch (\Exception $e) {
            error_log("WorkflowRedisTracker: Failed to get from Redis: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Clear workflow from Redis
     */
    public function clear(string $workflowId): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->redis->del($this->getRedisKey($workflowId));
        } catch (\Exception $e) {
            error_log("WorkflowRedisTracker: Failed to clear from Redis: " . $e->getMessage());
        }
    }
}



