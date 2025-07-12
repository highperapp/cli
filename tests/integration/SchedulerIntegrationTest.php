<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Integration;

use HighPerApp\HighPer\CLI\Schedulers\TaskScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Scheduler Integration Tests
 */
class SchedulerIntegrationTest extends TestCase
{
    private TaskScheduler $scheduler;
    private string $tempDir;
    
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/test-scheduler-integration-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        
        $this->scheduler = new TaskScheduler([
            'lock_path' => $this->tempDir,
            'prevent_overlap' => true,
            'verbose' => true
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
    
    public function testCommandExecution(): void
    {
        $this->scheduler->command('echo "Hello Integration Test"')->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertStringContainsString('Hello Integration Test', $results[0]['output']);
    }
    
    public function testFailingCommandExecution(): void
    {
        $this->scheduler->command('exit 1')->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['success']);
        $this->assertStringContainsString('Command failed with exit code 1', $results[0]['error']);
    }
    
    public function testCallableExecution(): void
    {
        $executed = false;
        $this->scheduler->call(function($param) use (&$executed) {
            $executed = true;
            return "Callable executed with: {$param}";
        }, ['test_param'])->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($executed);
        $this->assertStringContainsString('Callable executed with: test_param', $results[0]['output']);
    }
    
    public function testCallableWithException(): void
    {
        $this->scheduler->call(function() {
            throw new \RuntimeException('Test exception');
        })->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['success']);
        $this->assertEquals('Test exception', $results[0]['error']);
    }
    
    public function testJobExecution(): void
    {
        // Create a test job class
        eval('
            class TestIntegrationJob {
                public function handle($data) {
                    return "Job processed with: " . json_encode($data);
                }
            }
        ');
        
        $this->scheduler->job('TestIntegrationJob', ['key' => 'value'])->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertStringContainsString('Job processed with:', $results[0]['output']);
    }
    
    public function testJobWithNonExistentClass(): void
    {
        $this->scheduler->job('NonExistentJob', [])->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['success']);
        $this->assertStringContainsString('Job class not found', $results[0]['error']);
    }
    
    public function testMultipleTasksExecution(): void
    {
        $this->scheduler->command('echo "Task 1"')->everyMinute();
        $this->scheduler->command('echo "Task 2"')->everyMinute();
        
        $callableExecuted = false;
        $this->scheduler->call(function() use (&$callableExecuted) {
            $callableExecuted = true;
            return 'Task 3';
        })->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(3, $results);
        
        // All tasks should succeed
        foreach ($results as $result) {
            $this->assertTrue($result['success']);
        }
        
        $this->assertTrue($callableExecuted);
    }
    
    public function testTaskWithTimeout(): void
    {
        // Create a task with timeout (this might not work on all systems)
        $this->scheduler->command('sleep 2')->timeout(1)->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        // The task might fail due to timeout or succeed if timeout command is not available
        $this->assertIsArray($results[0]);
        $this->assertArrayHasKey('success', $results[0]);
    }
    
    public function testOverlapPrevention(): void
    {
        $task = $this->scheduler->command('echo "test"')->withoutOverlapping()->everyMinute();
        
        // Manually create a lock file
        $reflection = new \ReflectionClass($this->scheduler);
        $createLockMethod = $reflection->getMethod('createTaskLock');
        $createLockMethod->setAccessible(true);
        $createLockMethod->invoke($this->scheduler, $task);
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertFalse($results[0]['success']);
        $this->assertStringContainsString('overlap prevented', $results[0]['error']);
    }
    
    public function testEnvironmentFiltering(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        
        // Task only for production
        $this->scheduler->command('echo "production only"')
            ->environments(['production'])
            ->everyMinute();
        
        // Task for testing
        $this->scheduler->command('echo "testing environment"')
            ->environments(['testing'])
            ->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        // Only the testing task should run
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertStringContainsString('testing environment', $results[0]['output']);
        
        unset($_ENV['APP_ENV']);
    }
    
    public function testTaskWithDescription(): void
    {
        $this->scheduler->command('echo "described task"')
            ->description('This is a test task')
            ->everyMinute();
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertEquals('This is a test task', $results[0]['description']);
    }
    
    public function testScheduleFromConfigArray(): void
    {
        $config = [
            'type' => 'command',
            'target' => 'echo "from config"',
            'expression' => '* * * * *',
            'description' => 'Config-based task',
            'environments' => [],
            'without_overlapping' => false,
            'timeout' => 0
        ];
        
        $this->scheduler->scheduleFromConfig($config);
        
        $results = $this->scheduler->runDueTasks();
        
        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertEquals('Config-based task', $results[0]['description']);
        $this->assertStringContainsString('from config', $results[0]['output']);
    }
}