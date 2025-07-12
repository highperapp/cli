<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Schedulers;

use Cron\CronExpression;

/**
 * Task Scheduler
 * 
 * Comprehensive task scheduling system with cron expressions,
 * overlap prevention, and background task management.
 */
class TaskScheduler
{
    protected array $tasks = [];
    protected array $config;
    protected array $lockFiles = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'prevent_overlap' => true,
            'verbose' => false,
            'lock_path' => sys_get_temp_dir() . '/highper-schedule',
            'timezone' => date_default_timezone_get()
        ], $config);
        
        // Ensure lock directory exists
        if (!is_dir($this->config['lock_path'])) {
            mkdir($this->config['lock_path'], 0755, true);
        }
    }
    
    /**
     * Schedule a command to run
     */
    public function command(string $command, array $arguments = []): ScheduledTask
    {
        $task = new ScheduledTask('command', $command, $arguments);
        $this->tasks[] = $task;
        
        return $task;
    }
    
    /**
     * Schedule a callable to run
     */
    public function call(callable $callback, array $parameters = []): ScheduledTask
    {
        $task = new ScheduledTask('callable', $callback, $parameters);
        $this->tasks[] = $task;
        
        return $task;
    }
    
    /**
     * Schedule a job class to run
     */
    public function job(string $jobClass, array $data = []): ScheduledTask
    {
        $task = new ScheduledTask('job', $jobClass, $data);
        $this->tasks[] = $task;
        
        return $task;
    }
    
    /**
     * Schedule task from configuration array
     */
    public function scheduleFromConfig(array $config): ScheduledTask
    {
        $task = new ScheduledTask(
            $config['type'],
            $config['target'],
            $config['parameters'] ?? []
        );
        
        if (isset($config['expression'])) {
            $task->cron($config['expression']);
        }
        
        if (isset($config['description'])) {
            $task->description($config['description']);
        }
        
        if (isset($config['environments'])) {
            $task->environments($config['environments']);
        }
        
        if (isset($config['without_overlapping']) && $config['without_overlapping']) {
            $task->withoutOverlapping();
        }
        
        if (isset($config['timeout'])) {
            $task->timeout($config['timeout']);
        }
        
        $this->tasks[] = $task;
        
        return $task;
    }
    
    /**
     * Run all due tasks
     */
    public function runDueTasks(): array
    {
        $results = [];
        $now = new \DateTime('now', new \DateTimeZone($this->config['timezone']));
        
        foreach ($this->tasks as $task) {
            if ($task->isDue($now)) {
                $results[] = $this->runTask($task);
            }
        }
        
        return $results;
    }
    
    /**
     * Get all scheduled tasks
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }
    
    /**
     * Get tasks due to run
     */
    public function getDueTasks(\DateTime $dateTime = null): array
    {
        $dateTime = $dateTime ?: new \DateTime('now', new \DateTimeZone($this->config['timezone']));
        
        return array_filter($this->tasks, function ($task) use ($dateTime) {
            return $task->isDue($dateTime);
        });
    }
    
    /**
     * Run individual task
     */
    protected function runTask(ScheduledTask $task): array
    {
        $startTime = microtime(true);
        
        $result = [
            'task' => $task,
            'description' => $task->getDescription(),
            'started_at' => $startTime,
            'success' => false,
            'duration' => 0,
            'output' => '',
            'error' => null
        ];
        
        try {
            // Check for overlap prevention
            if ($task->withoutOverlapping && $this->isTaskRunning($task)) {
                $result['error'] = 'Task is already running (overlap prevented)';
                return $result;
            }
            
            // Create lock file if needed
            if ($task->withoutOverlapping) {
                $this->createTaskLock($task);
            }
            
            // Execute task
            $output = $this->executeTask($task);
            
            $result['success'] = true;
            $result['output'] = $output;
            
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            
        } finally {
            // Remove lock file
            if ($task->withoutOverlapping) {
                $this->removeTaskLock($task);
            }
            
            $result['duration'] = microtime(true) - $startTime;
        }
        
        return $result;
    }
    
    /**
     * Execute task based on type
     */
    protected function executeTask(ScheduledTask $task): string
    {
        switch ($task->type) {
            case 'command':
                return $this->executeCommand($task);
                
            case 'callable':
                return $this->executeCallable($task);
                
            case 'job':
                return $this->executeJob($task);
                
            default:
                throw new \RuntimeException("Unknown task type: {$task->type}");
        }
    }
    
    /**
     * Execute command task
     */
    protected function executeCommand(ScheduledTask $task): string
    {
        $command = $task->target;
        
        if (!empty($task->parameters)) {
            $args = implode(' ', array_map('escapeshellarg', $task->parameters));
            $command .= ' ' . $args;
        }
        
        $output = '';
        $returnCode = 0;
        
        if ($task->timeout > 0) {
            $command = "timeout {$task->timeout} {$command}";
        }
        
        exec($command . ' 2>&1', $outputLines, $returnCode);
        $output = implode("\n", $outputLines);
        
        if ($returnCode !== 0) {
            throw new \RuntimeException("Command failed with exit code {$returnCode}: {$output}");
        }
        
        return $output;
    }
    
    /**
     * Execute callable task
     */
    protected function executeCallable(ScheduledTask $task): string
    {
        $callback = $task->target;
        $parameters = $task->parameters;
        
        if ($task->timeout > 0) {
            // For timeout support, we'd need to implement signal handling
            // For now, execute directly
        }
        
        $result = call_user_func_array($callback, $parameters);
        
        return is_string($result) ? $result : json_encode($result);
    }
    
    /**
     * Execute job task
     */
    protected function executeJob(ScheduledTask $task): string
    {
        $jobClass = $task->target;
        $data = $task->parameters;
        
        if (!class_exists($jobClass)) {
            throw new \RuntimeException("Job class not found: {$jobClass}");
        }
        
        $job = new $jobClass();
        
        if (method_exists($job, 'handle')) {
            $result = $job->handle($data);
        } elseif (is_callable($job)) {
            $result = $job($data);
        } else {
            throw new \RuntimeException("Job must have handle() method or be callable");
        }
        
        return is_string($result) ? $result : json_encode($result);
    }
    
    /**
     * Check if task is already running
     */
    protected function isTaskRunning(ScheduledTask $task): bool
    {
        $lockFile = $this->getLockFile($task);
        
        if (!file_exists($lockFile)) {
            return false;
        }
        
        $pid = (int)file_get_contents($lockFile);
        
        // Check if process is still running
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Fallback for systems without posix
        return file_exists("/proc/{$pid}");
    }
    
    /**
     * Create task lock file
     */
    protected function createTaskLock(ScheduledTask $task): void
    {
        $lockFile = $this->getLockFile($task);
        file_put_contents($lockFile, getmypid());
        $this->lockFiles[] = $lockFile;
    }
    
    /**
     * Remove task lock file
     */
    protected function removeTaskLock(ScheduledTask $task): void
    {
        $lockFile = $this->getLockFile($task);
        
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        $this->lockFiles = array_filter($this->lockFiles, fn($f) => $f !== $lockFile);
    }
    
    /**
     * Get lock file path for task
     */
    protected function getLockFile(ScheduledTask $task): string
    {
        $identifier = md5(serialize($task->target) . serialize($task->parameters));
        return $this->config['lock_path'] . "/task-{$identifier}.lock";
    }
    
    /**
     * Cleanup on shutdown
     */
    public function __destruct()
    {
        foreach ($this->lockFiles as $lockFile) {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }
}

