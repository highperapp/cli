<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Unit;

use HighPerApp\HighPer\CLI\Schedulers\TaskScheduler;
use HighPerApp\HighPer\CLI\Schedulers\ScheduledTask;
use PHPUnit\Framework\TestCase;

/**
 * TaskScheduler Unit Tests
 */
class TaskSchedulerTest extends TestCase
{
    private TaskScheduler $scheduler;
    private string $tempDir;
    
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test-scheduler-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $this->scheduler = new TaskScheduler([
            'lock_path' => $this->tempDir,
            'prevent_overlap' => true,
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
    
    public function testSchedulerCreation(): void
    {
        $this->assertInstanceOf(TaskScheduler::class, $this->scheduler);
    }
    
    public function testScheduleCommand(): void
    {
        $task = $this->scheduler->command('echo "Hello World"');
        
        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertEquals('command', $task->type);
        $this->assertEquals('echo "Hello World"', $task->target);
    }
    
    public function testScheduleCallable(): void
    {
        $callback = function() { return 'test'; };
        $task = $this->scheduler->call($callback, ['param1', 'param2']);
        
        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertEquals('callable', $task->type);
        $this->assertEquals($callback, $task->target);
        $this->assertEquals(['param1', 'param2'], $task->parameters);
    }
    
    public function testScheduleJob(): void
    {
        $task = $this->scheduler->job('TestJob', ['data' => 'value']);
        
        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertEquals('job', $task->type);
        $this->assertEquals('TestJob', $task->target);
        $this->assertEquals(['data' => 'value'], $task->parameters);
    }
    
    public function testGetTasks(): void
    {
        $this->scheduler->command('echo "test1"');
        $this->scheduler->command('echo "test2"');
        
        $tasks = $this->scheduler->getTasks();
        $this->assertCount(2, $tasks);
    }
    
    public function testScheduleFromConfig(): void
    {
        $config = [
            'type' => 'command',
            'target' => 'echo "configured"',
            'expression' => '0 * * * *',
            'description' => 'Test task',
            'environments' => ['production'],
            'without_overlapping' => true,
            'timeout' => 300
        ];
        
        $task = $this->scheduler->scheduleFromConfig($config);
        
        $this->assertEquals('command', $task->type);
        $this->assertEquals('echo "configured"', $task->target);
        $this->assertEquals('0 * * * *', $task->expression);
        $this->assertEquals('Test task', $task->description);
        $this->assertEquals(['production'], $task->environments);
        $this->assertTrue($task->withoutOverlapping);
        $this->assertEquals(300, $task->timeout);
    }
    
    public function testGetDueTasks(): void
    {
        // Create a task that should always be due (every minute)
        $task = $this->scheduler->command('echo "always due"')->everyMinute();
        
        $dueTasks = $this->scheduler->getDueTasks();
        $this->assertCount(1, $dueTasks);
        $this->assertEquals($task, $dueTasks[0]);
    }
    
    public function testRunDueTasksWithCallable(): void
    {
        $executed = false;
        $this->scheduler->call(function() use (&$executed) {
            $executed = true;
            return 'success';
        })->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($executed);
    }
    
    public function testLockFileCreation(): void
    {
        $task = $this->scheduler->command('sleep 1')->withoutOverlapping();
        
        $reflection = new \ReflectionClass($this->scheduler);
        $getLockFileMethod = $reflection->getMethod('getLockFile');
        $getLockFileMethod->setAccessible(true);
        
        $lockFile = $getLockFileMethod->invoke($this->scheduler, $task);
        $this->assertStringContainsString($this->tempDir, $lockFile);
        $this->assertStringContainsString('.lock', $lockFile);
    }
    
    public function testTaskOverlapPrevention(): void
    {
        // Create a long-running task
        $task = $this->scheduler->command('sleep 2')->withoutOverlapping();
        
        // Manually create a lock file to simulate running task
        $reflection = new \ReflectionClass($this->scheduler);
        $createLockMethod = $reflection->getMethod('createTaskLock');
        $createLockMethod->setAccessible(true);
        $createLockMethod->invoke($this->scheduler, $task);
        
        // Try to run the task - should be prevented
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['success']);
        $this->assertStringContainsString('overlap prevented', $results[0]['error']);
    }
}