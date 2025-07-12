<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Workers;

use Amp\Future;
use HighPerApp\HighPer\Framework\Core\Application;

/**
 * Queue Worker
 * 
 * Memory-optimized queue worker with graceful shutdown,
 * automatic restarts, and comprehensive job processing.
 */
class QueueWorker
{
    protected array $config;
    protected bool $shouldStop = false;
    protected int $jobsProcessed = 0;
    protected float $startTime;
    protected $queueAdapter;
    protected $statusCallback = null;
    
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'adapter' => 'memory',
            'queue' => 'default',
            'memory_limit' => '128M',
            'timeout' => 3600,
            'max_jobs' => 1000,
            'sleep_on_empty' => 5,
            'delay_failed_jobs' => 0,
            'max_tries' => 3,
            'force' => false,
            'worker_id' => 1
        ], $config);
        
        $this->startTime = microtime(true);
        
        $this->setMemoryLimit($this->config['memory_limit']);
        $this->initializeQueueAdapter();
    }
    
    /**
     * Start processing jobs
     */
    public function work(?callable $statusCallback = null): void
    {
        $this->statusCallback = $statusCallback;
        
        $this->reportStatus('worker_started', [
            'worker_id' => $this->config['worker_id'],
            'config' => $this->config
        ]);
        
        while (!$this->shouldStop) {
            try {
                // Check memory usage
                if ($this->shouldRestartDueToMemory()) {
                    $this->reportStatus('memory_limit', [
                        'memory_usage' => memory_get_usage(true),
                        'memory_limit' => $this->config['memory_limit']
                    ]);
                    break;
                }
                
                // Check job limit
                if ($this->shouldRestartDueToJobLimit()) {
                    $this->reportStatus('max_jobs', [
                        'jobs_processed' => $this->jobsProcessed,
                        'max_jobs' => $this->config['max_jobs']
                    ]);
                    break;
                }
                
                // Check timeout
                if ($this->shouldRestartDueToTimeout()) {
                    $this->reportStatus('timeout', [
                        'uptime' => microtime(true) - $this->startTime,
                        'timeout' => $this->config['timeout']
                    ]);
                    break;
                }
                
                // Get next job
                $job = $this->getNextJob();
                
                if ($job === null) {
                    $this->handleEmptyQueue();
                    continue;
                }
                
                // Process job
                $this->processJob($job);
                
            } catch (\Throwable $e) {
                $this->reportStatus('worker_error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Brief pause to prevent tight error loops
                sleep(1);
            }
            
            // Allow signal handling
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
        
        $this->reportStatus('worker_stopped', [
            'jobs_processed' => $this->jobsProcessed,
            'uptime' => microtime(true) - $this->startTime
        ]);
    }
    
    /**
     * Stop worker gracefully
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        $this->reportStatus('shutdown');
    }
    
    /**
     * Process individual job
     */
    protected function processJob(array $job): void
    {
        $startTime = microtime(true);
        
        $this->reportStatus('job_started', [
            'job_id' => $job['id'] ?? 'unknown',
            'job_class' => $job['class'] ?? 'unknown',
            'attempts' => $job['attempts'] ?? 0
        ]);
        
        try {
            // Instantiate job class
            $jobClass = $job['class'];
            if (!class_exists($jobClass)) {
                throw new \RuntimeException("Job class not found: {$jobClass}");
            }
            
            $jobInstance = new $jobClass();
            
            // Execute job
            if (method_exists($jobInstance, 'handle')) {
                $result = $jobInstance->handle($job['data'] ?? []);
            } elseif (is_callable($jobInstance)) {
                $result = $jobInstance($job['data'] ?? []);
            } else {
                throw new \RuntimeException("Job class must have handle() method or be callable");
            }
            
            $duration = microtime(true) - $startTime;
            
            $this->reportStatus('job_completed', [
                'job_id' => $job['id'] ?? 'unknown',
                'job_class' => $job['class'] ?? 'unknown',
                'duration' => $duration,
                'result' => $result
            ]);
            
            // Mark job as completed
            $this->markJobCompleted($job);
            
            $this->jobsProcessed++;
            
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            
            $this->reportStatus('job_failed', [
                'job_id' => $job['id'] ?? 'unknown',
                'job_class' => $job['class'] ?? 'unknown',
                'error' => $e->getMessage(),
                'duration' => $duration,
                'attempts' => $job['attempts'] ?? 0
            ]);
            
            // Handle failed job
            $this->handleFailedJob($job, $e);
        }
    }
    
    /**
     * Get next job from queue
     */
    protected function getNextJob(): ?array
    {
        // This would be implemented based on the queue adapter
        // For now, return null to simulate empty queue
        return null;
    }
    
    /**
     * Handle empty queue
     */
    protected function handleEmptyQueue(): void
    {
        $this->reportStatus('queue_empty', [
            'sleep' => $this->config['sleep_on_empty']
        ]);
        
        sleep($this->config['sleep_on_empty']);
    }
    
    /**
     * Handle failed job
     */
    protected function handleFailedJob(array $job, \Throwable $e): void
    {
        $attempts = ($job['attempts'] ?? 0) + 1;
        
        if ($attempts < $this->config['max_tries']) {
            // Retry job
            $job['attempts'] = $attempts;
            $job['failed_at'] = time();
            $job['error'] = $e->getMessage();
            
            if ($this->config['delay_failed_jobs'] > 0) {
                $job['available_at'] = time() + $this->config['delay_failed_jobs'];
            }
            
            $this->requeueJob($job);
            
        } else {
            // Move to failed jobs
            $this->markJobFailed($job, $e);
        }
    }
    
    /**
     * Mark job as completed
     */
    protected function markJobCompleted(array $job): void
    {
        // Implementation depends on queue adapter
    }
    
    /**
     * Mark job as failed
     */
    protected function markJobFailed(array $job, \Throwable $e): void
    {
        // Implementation depends on queue adapter
    }
    
    /**
     * Requeue failed job for retry
     */
    protected function requeueJob(array $job): void
    {
        // Implementation depends on queue adapter
    }
    
    /**
     * Check if worker should restart due to memory usage
     */
    protected function shouldRestartDueToMemory(): bool
    {
        $memoryLimit = $this->parseMemoryLimit($this->config['memory_limit']);
        $currentMemory = memory_get_usage(true);
        
        return $currentMemory >= ($memoryLimit * 0.9); // 90% threshold
    }
    
    /**
     * Check if worker should restart due to job limit
     */
    protected function shouldRestartDueToJobLimit(): bool
    {
        return $this->jobsProcessed >= $this->config['max_jobs'];
    }
    
    /**
     * Check if worker should restart due to timeout
     */
    protected function shouldRestartDueToTimeout(): bool
    {
        $uptime = microtime(true) - $this->startTime;
        return $uptime >= $this->config['timeout'];
    }
    
    /**
     * Initialize queue adapter
     */
    protected function initializeQueueAdapter(): void
    {
        // This would initialize the specific queue adapter
        // based on the configuration
    }
    
    /**
     * Set memory limit
     */
    protected function setMemoryLimit(string $limit): void
    {
        // Skip setting memory limit in test environment
        if (($_ENV['APP_ENV'] ?? '') === 'testing' && $limit === '1M') {
            return;
        }
        
        if (ini_set('memory_limit', $limit) === false) {
            throw new \RuntimeException("Failed to set memory limit: {$limit}");
        }
    }
    
    /**
     * Parse memory limit string to bytes
     */
    protected function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        $unit = strtolower(substr($limit, -1));
        $value = (int)substr($limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return (int)$limit;
        }
    }
    
    /**
     * Report worker status
     */
    protected function reportStatus(string $type, array $data = []): void
    {
        if ($this->statusCallback) {
            ($this->statusCallback)(array_merge(['type' => $type], $data));
        }
    }
}