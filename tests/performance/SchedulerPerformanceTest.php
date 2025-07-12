<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Performance;

use HighPerApp\HighPer\CLI\Schedulers\TaskScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Scheduler Performance Tests
 */
class SchedulerPerformanceTest extends TestCase
{
    private TaskScheduler $scheduler;
    private string $tempDir;
    
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/perf-scheduler-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $this->scheduler = new TaskScheduler([
            'lock_path' => $this->tempDir,
            'prevent_overlap' => false,
            'verbose' => false
        ]);
    }
    
    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . '/*');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
    }
    
    public function testTaskSchedulingPerformance(): void
    {
        $iterations = 1000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->scheduler->command("echo 'Task {$i}'")->everyMinute();
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should schedule 1000 tasks in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Scheduling {$iterations} tasks took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nTask scheduling average: {$avgTime}ms per task\n";
        
        // Verify all tasks were scheduled
        $this->assertCount($iterations, $this->scheduler->getTasks());
    }
    
    public function testCallableTaskSchedulingPerformance(): void
    {
        $iterations = 1000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->scheduler->call(function() use ($i) {
                return "Callable {$i}";
            })->everyMinute();
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should schedule 1000 callable tasks in less than 0.2 seconds
        $this->assertLessThan(0.2, $duration,
            "Scheduling {$iterations} callable tasks took {$duration}s (expected < 0.2s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nCallable task scheduling average: {$avgTime}ms per task\n";
    }
    
    public function testJobTaskSchedulingPerformance(): void
    {
        $iterations = 1000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->scheduler->job("TestJob{$i}", ['data' => $i])->everyMinute();
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should schedule 1000 job tasks in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Scheduling {$iterations} job tasks took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nJob task scheduling average: {$avgTime}ms per task\n";
    }
    
    public function testGetDueTasksPerformance(): void
    {
        // Schedule many tasks that are always due
        for ($i = 0; $i < 1000; $i++) {
            $this->scheduler->command("echo 'Task {$i}'")->everyMinute();
        }
        
        $iterations = 100;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $dueTasks = $this->scheduler->getDueTasks();
            $this->assertCount(1000, $dueTasks);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should check due tasks 100 times in less than 0.5 seconds
        $this->assertLessThan(0.5, $duration,
            "Checking due tasks {$iterations} times took {$duration}s (expected < 0.5s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nGet due tasks average: {$avgTime}ms per check\n";
    }
    
    public function testRunDueTasksPerformance(): void
    {
        $taskCount = 100;
        
        // Schedule fast-executing tasks
        for ($i = 0; $i < $taskCount; $i++) {
            $this->scheduler->call(function() use ($i) {
                return "Fast task {$i}";
            })->everyMinute();
        }
        
        $startTime = microtime(true);
        $results = $this->scheduler->runDueTasks();
        $endTime = microtime(true);
        
        $duration = $endTime - $startTime;
        
        // Should run 100 fast tasks in less than 0.5 seconds
        $this->assertLessThan(0.5, $duration,
            "Running {$taskCount} fast tasks took {$duration}s (expected < 0.5s)");
        
        $avgTime = ($duration / $taskCount) * 1000;
        echo "\nTask execution average: {$avgTime}ms per task\n";
        
        $this->assertCount($taskCount, $results);
        
        // All tasks should succeed
        foreach ($results as $result) {
            $this->assertTrue($result['success']);
        }
    }
    
    public function testScheduleFromConfigPerformance(): void
    {
        $iterations = 1000;
        $configs = [];
        
        // Prepare configs
        for ($i = 0; $i < $iterations; $i++) {
            $configs[] = [
                'type' => 'command',
                'target' => "echo 'Config task {$i}'",
                'expression' => '* * * * *',
                'description' => "Config-based task {$i}",
                'environments' => [],
                'without_overlapping' => false,
                'timeout' => 0
            ];
        }
        
        $startTime = microtime(true);
        
        foreach ($configs as $config) {
            $this->scheduler->scheduleFromConfig($config);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should schedule from config 1000 times in less than 0.2 seconds
        $this->assertLessThan(0.2, $duration,
            "Scheduling from config {$iterations} times took {$duration}s (expected < 0.2s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nSchedule from config average: {$avgTime}ms per task\n";
        
        $this->assertCount($iterations, $this->scheduler->getTasks());
    }
    
    public function testTaskExecutionWithTimeout(): void
    {
        $taskCount = 10;
        
        // Schedule tasks with timeout
        for ($i = 0; $i < $taskCount; $i++) {
            $this->scheduler->command("echo 'Timeout test {$i}'")->timeout(30)->everyMinute();
        }
        
        $startTime = microtime(true);
        $results = $this->scheduler->runDueTasks();
        $endTime = microtime(true);
        
        $duration = $endTime - $startTime;
        
        // Should run 10 tasks with timeout in less than 1 second
        $this->assertLessThan(1.0, $duration,
            "Running {$taskCount} tasks with timeout took {$duration}s (expected < 1s)");
        
        $avgTime = ($duration / $taskCount) * 1000;
        echo "\nTask with timeout average: {$avgTime}ms per task\n";
        
        $this->assertCount($taskCount, $results);
    }
    
    public function testMemoryUsageWithManyTasks(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Schedule 1000 tasks
        for ($i = 0; $i < 1000; $i++) {
            $this->scheduler->command("echo 'Memory test {$i}'")->everyMinute();
        }
        
        $afterSchedulingMemory = memory_get_usage(true);
        
        // Run all tasks
        $results = $this->scheduler->runDueTasks();
        
        $finalMemory = memory_get_usage(true);
        
        $schedulingMemoryIncrease = $afterSchedulingMemory - $initialMemory;
        $executionMemoryIncrease = $finalMemory - $afterSchedulingMemory;
        
        // Memory increase for scheduling should be reasonable (less than 5MB)
        $this->assertLessThan(5 * 1024 * 1024, $schedulingMemoryIncrease,
            "Memory increase for scheduling 1000 tasks: {$schedulingMemoryIncrease} bytes exceeds 5MB");
        
        // Execution memory increase should be reasonable (less than 10MB)
        $this->assertLessThan(10 * 1024 * 1024, $executionMemoryIncrease,
            "Memory increase for executing 1000 tasks: {$executionMemoryIncrease} bytes exceeds 10MB");
        
        echo "\nScheduling memory per task: " . ($schedulingMemoryIncrease / 1000) . " bytes\n";
        echo "Execution memory per task: " . ($executionMemoryIncrease / 1000) . " bytes\n";
        
        $this->assertCount(1000, $results);
    }
    
    public function testLockFileOperationsPerformance(): void
    {
        $scheduler = new TaskScheduler([
            'lock_path' => $this->tempDir,
            'prevent_overlap' => true,
            'verbose' => false
        ]);
        
        $taskCount = 100;
        
        // Schedule tasks with overlap prevention
        for ($i = 0; $i < $taskCount; $i++) {
            $scheduler->call(function() use ($i) {
                usleep(1000); // 1ms sleep
                return "Lock test {$i}";
            })->withoutOverlapping()->everyMinute();
        }
        
        $startTime = microtime(true);
        $results = $scheduler->runDueTasks();
        $endTime = microtime(true);
        
        $duration = $endTime - $startTime;
        
        // Should run 100 tasks with locking in less than 2 seconds
        $this->assertLessThan(2.0, $duration,
            "Running {$taskCount} tasks with locking took {$duration}s (expected < 2s)");
        
        $avgTime = ($duration / $taskCount) * 1000;
        echo "\nTask with locking average: {$avgTime}ms per task\n";
        
        $this->assertCount($taskCount, $results);
    }
}