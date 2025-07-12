<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Schedulers;

use Cron\CronExpression;

/**
 * Scheduled Task
 */
class ScheduledTask
{
    public string $type;
    public $target;
    public array $parameters;
    public string $expression = '* * * * *';
    public string $description = '';
    public array $environments = [];
    public bool $withoutOverlapping = false;
    public int $timeout = 0;
    
    public function __construct(string $type, $target, array $parameters = [])
    {
        $this->type = $type;
        $this->target = $target;
        $this->parameters = $parameters;
    }
    
    /**
     * Set cron expression
     */
    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }
    
    /**
     * Run every minute
     */
    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }
    
    /**
     * Run every five minutes
     */
    public function everyFiveMinutes(): self
    {
        return $this->cron('*/5 * * * *');
    }
    
    /**
     * Run every hour
     */
    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }
    
    /**
     * Run daily at specific time
     */
    public function dailyAt(string $time): self
    {
        [$hour, $minute] = explode(':', $time);
        return $this->cron("{$minute} {$hour} * * *");
    }
    
    /**
     * Run daily
     */
    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }
    
    /**
     * Run weekly
     */
    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }
    
    /**
     * Run monthly
     */
    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }
    
    /**
     * Set task description
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }
    
    /**
     * Set environments where task should run
     */
    public function environments(array $environments): self
    {
        $this->environments = $environments;
        return $this;
    }
    
    /**
     * Prevent overlapping executions
     */
    public function withoutOverlapping(): self
    {
        $this->withoutOverlapping = true;
        return $this;
    }
    
    /**
     * Set task timeout
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }
    
    /**
     * Check if task is due to run
     */
    public function isDue(\DateTime $dateTime): bool
    {
        // Check environment
        if (!empty($this->environments)) {
            $currentEnv = $_ENV['APP_ENV'] ?? 'production';
            if (!in_array($currentEnv, $this->environments)) {
                return false;
            }
        }
        
        // Check cron expression
        if (!class_exists('Cron\CronExpression')) {
            // Fallback: assume task is due (for testing without cron library)
            return true;
        }
        
        $cron = new CronExpression($this->expression);
        return $cron->isDue($dateTime);
    }
    
    /**
     * Get task description
     */
    public function getDescription(): string
    {
        if (!empty($this->description)) {
            return $this->description;
        }
        
        switch ($this->type) {
            case 'command':
                return "Command: {$this->target}";
            case 'job':
                return "Job: {$this->target}";
            case 'callable':
                return "Callable: " . (is_string($this->target) ? $this->target : 'Closure');
            default:
                return "Task: {$this->type}";
        }
    }
}