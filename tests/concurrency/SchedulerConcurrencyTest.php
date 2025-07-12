<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Concurrency;

use HighPerApp\HighPer\CLI\Schedulers\TaskScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Scheduler Concurrency Tests
 */
class SchedulerConcurrencyTest extends TestCase
{
    private string $tempDir;
    
    protected function setUp(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for concurrency tests');
        }
        
        $this->tempDir = sys_get_temp_dir() . '/scheduler-concurrency-' . uniqid();
        mkdir($this->tempDir, 0755, true);
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
    
    public function testConcurrentTaskExecution(): void
    {
        $schedulerCount = 3;
        $tasksPerScheduler = 5;
        $sharedFile = $this->tempDir . '/concurrent_tasks.txt';
        
        file_put_contents($sharedFile, '');
        
        $pids = [];
        
        for ($i = 0; $i < $schedulerCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork scheduler process');
            } elseif ($pid === 0) {
                // Child process - run scheduler
                $schedulerId = $i + 1;
                $lockPath = $this->tempDir . "/scheduler_{$schedulerId}";
                mkdir($lockPath, 0755, true);
                
                $scheduler = new TaskScheduler([
                    'lock_path' => $lockPath,
                    'prevent_overlap' => true,
                    'verbose' => false
                ]);
                
                // Schedule multiple tasks
                for ($j = 0; $j < $tasksPerScheduler; $j++) {
                    $scheduler->call(function() use ($sharedFile, $schedulerId, $j) {
                        $message = "Scheduler {$schedulerId} executed task {$j} at " . microtime(true) . "\n";
                        file_put_contents($sharedFile, $message, FILE_APPEND | LOCK_EX);
                        
                        // Simulate some work
                        usleep(rand(10000, 50000)); // 0.01-0.05 seconds
                        
                        return "Task {$j} completed";
                    })->everyMinute();
                }
                
                // Run all due tasks
                $results = $scheduler->runDueTasks();
                
                exit(count($results) === $tasksPerScheduler ? 0 : 1);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all schedulers
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Scheduler process {$pid} failed");
        }
        
        // Verify all tasks executed
        $content = file_get_contents($sharedFile);
        $lines = array_filter(explode("\n", $content));
        
        $expectedTaskCount = $schedulerCount * $tasksPerScheduler;
        $this->assertCount($expectedTaskCount, $lines, 'Not all tasks were executed');
        
        // Verify tasks from different schedulers executed
        $schedulerTaskCounts = [];
        foreach ($lines as $line) {
            if (preg_match('/Scheduler (\d+) executed/', $line, $matches)) {
                $schedulerId = (int)$matches[1];
                $schedulerTaskCounts[$schedulerId] = ($schedulerTaskCounts[$schedulerId] ?? 0) + 1;
            }
        }
        
        $this->assertCount($schedulerCount, $schedulerTaskCounts, 'Not all schedulers executed tasks');
        
        foreach ($schedulerTaskCounts as $count) {
            $this->assertEquals($tasksPerScheduler, $count, 'Scheduler did not execute expected number of tasks');
        }
    }
    
    public function testOverlapPreventionAcrossProcesses(): void
    {
        $sharedLockPath = $this->tempDir . '/shared_locks';
        mkdir($sharedLockPath, 0755, true);
        
        $resultFile = $this->tempDir . '/overlap_test.txt';
        file_put_contents($resultFile, '');
        
        $processCount = 3;
        $pids = [];
        
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process
                $processId = $i + 1;
                
                $scheduler = new TaskScheduler([
                    'lock_path' => $sharedLockPath,
                    'prevent_overlap' => true,
                    'verbose' => false
                ]);
                
                // Schedule the same task (same target and parameters = same lock)
                $scheduler->call(function() use ($resultFile, $processId) {
                    $message = "Process {$processId} started long task at " . microtime(true) . "\n";
                    file_put_contents($resultFile, $message, FILE_APPEND | LOCK_EX);
                    
                    // Simulate long-running task
                    sleep(2);
                    
                    $message = "Process {$processId} finished long task at " . microtime(true) . "\n";
                    file_put_contents($resultFile, $message, FILE_APPEND | LOCK_EX);
                    
                    return "Long task completed by process {$processId}";
                }, ['same', 'parameters'])->withoutOverlapping()->everyMinute();
                
                $results = $scheduler->runDueTasks();
                
                // Check if task ran or was prevented due to overlap
                $taskRan = !empty($results) && $results[0]['success'];
                $overlapPrevented = !empty($results) && !$results[0]['success'] && 
                                   strpos($results[0]['error'] ?? '', 'overlap prevented') !== false;
                
                exit(($taskRan || $overlapPrevented) ? 0 : 1);
            } else {
                $pids[] = $pid;
                
                // Small delay between process starts
                usleep(100000); // 0.1 seconds
            }
        }
        
        // Wait for all processes
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Process {$pid} failed");
        }
        
        // Analyze results
        $content = file_get_contents($resultFile);
        $lines = array_filter(explode("\n", $content));
        
        // Count started and finished tasks
        $startedTasks = array_filter($lines, fn($line) => strpos($line, 'started long task') !== false);
        $finishedTasks = array_filter($lines, fn($line) => strpos($line, 'finished long task') !== false);
        
        // Only one task should have been allowed to run due to overlap prevention
        $this->assertCount(1, $startedTasks, 'More than one long task started (overlap prevention failed)');
        $this->assertCount(1, $finishedTasks, 'Long task did not complete');
        
        // Verify timestamps show no overlap
        if (count($startedTasks) === 1 && count($finishedTasks) === 1) {
            $startLine = array_values($startedTasks)[0];
            $finishLine = array_values($finishedTasks)[0];
            
            preg_match('/at ([\d.]+)/', $startLine, $startMatches);
            preg_match('/at ([\d.]+)/', $finishLine, $finishMatches);
            
            $startTime = (float)$startMatches[1];
            $finishTime = (float)$finishMatches[1];
            
            $this->assertGreaterThan($startTime, $finishTime, 'Task did not complete properly');
            $this->assertGreaterThanOrEqual(2.0, $finishTime - $startTime, 'Task completed too quickly');
        }
    }
    
    public function testConcurrentSchedulerInstances(): void
    {
        $instanceCount = 4;
        $resultFile = $this->tempDir . '/concurrent_instances.txt';
        file_put_contents($resultFile, '');
        
        $pids = [];
        
        for ($i = 0; $i < $instanceCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork scheduler instance');
            } elseif ($pid === 0) {
                // Child process - different scheduler instance
                $instanceId = $i + 1;
                $instanceLockPath = $this->tempDir . "/instance_{$instanceId}";
                mkdir($instanceLockPath, 0755, true);
                
                $scheduler = new TaskScheduler([
                    'lock_path' => $instanceLockPath,
                    'prevent_overlap' => false,
                    'verbose' => false
                ]);
                
                // Each instance schedules unique tasks
                for ($j = 0; $j < 3; $j++) {
                    $scheduler->call(function() use ($resultFile, $instanceId, $j) {
                        $message = "Instance {$instanceId} task {$j} executed at " . microtime(true) . "\n";
                        file_put_contents($resultFile, $message, FILE_APPEND | LOCK_EX);
                        return "Instance {$instanceId} task {$j}";
                    })->everyMinute();
                }
                
                $results = $scheduler->runDueTasks();
                
                exit(count($results) === 3 ? 0 : 1);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all instances
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Scheduler instance {$pid} failed");
        }
        
        // Verify all instances executed their tasks
        $content = file_get_contents($resultFile);
        $lines = array_filter(explode("\n", $content));
        
        $expectedTaskCount = $instanceCount * 3;
        $this->assertCount($expectedTaskCount, $lines, 'Not all tasks from all instances executed');
        
        // Verify each instance executed its tasks
        $instanceTaskCounts = [];
        foreach ($lines as $line) {
            if (preg_match('/Instance (\d+) task/', $line, $matches)) {
                $instanceId = (int)$matches[1];
                $instanceTaskCounts[$instanceId] = ($instanceTaskCounts[$instanceId] ?? 0) + 1;
            }
        }
        
        $this->assertCount($instanceCount, $instanceTaskCounts, 'Not all instances executed tasks');
        
        foreach ($instanceTaskCounts as $count) {
            $this->assertEquals(3, $count, 'Instance did not execute expected number of tasks');
        }
    }
    
    public function testSharedResourceAccess(): void
    {
        $sharedResource = $this->tempDir . '/shared_resource.txt';
        $logFile = $this->tempDir . '/resource_log.txt';
        
        file_put_contents($sharedResource, '0');
        file_put_contents($logFile, '');
        
        $processCount = 3;
        $operationsPerProcess = 10;
        $pids = [];
        
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process
                $processId = $i + 1;
                $lockPath = $this->tempDir . "/process_{$processId}";
                mkdir($lockPath, 0755, true);
                
                $scheduler = new TaskScheduler([
                    'lock_path' => $lockPath,
                    'prevent_overlap' => false,
                    'verbose' => false
                ]);
                
                // Schedule tasks that access shared resource
                for ($j = 0; $j < $operationsPerProcess; $j++) {
                    $scheduler->call(function() use ($sharedResource, $logFile, $processId, $j) {
                        // Use file locking to safely access shared resource
                        $fp = fopen($sharedResource, 'r+');
                        if ($fp && flock($fp, LOCK_EX)) {
                            $current = (int)fread($fp, 100);
                            $new = $current + 1;
                            
                            // Log the operation
                            $message = "Process {$processId} operation {$j}: {$current} -> {$new} at " . microtime(true) . "\n";
                            file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
                            
                            // Small delay to simulate processing
                            usleep(rand(1000, 5000)); // 1-5ms
                            
                            rewind($fp);
                            fwrite($fp, (string)$new);
                            ftruncate($fp, strlen((string)$new));
                            
                            flock($fp, LOCK_UN);
                            fclose($fp);
                            
                            return "Incremented to {$new}";
                        } else {
                            return "Failed to acquire lock";
                        }
                    })->everyMinute();
                }
                
                $results = $scheduler->runDueTasks();
                
                exit(count($results) === $operationsPerProcess ? 0 : 1);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Process {$pid} failed");
        }
        
        // Verify final shared resource value
        $finalValue = (int)file_get_contents($sharedResource);
        $expectedValue = $processCount * $operationsPerProcess;
        
        $this->assertEquals($expectedValue, $finalValue, 
            'Shared resource final value incorrect (race condition detected)');
        
        // Verify all operations were logged
        $logContent = file_get_contents($logFile);
        $logLines = array_filter(explode("\n", $logContent));
        
        $this->assertCount($expectedValue, $logLines, 'Not all operations were logged');
        
        // Verify operations from all processes
        $processOperations = [];
        foreach ($logLines as $line) {
            if (preg_match('/Process (\d+) operation/', $line, $matches)) {
                $processId = (int)$matches[1];
                $processOperations[$processId] = ($processOperations[$processId] ?? 0) + 1;
            }
        }
        
        $this->assertCount($processCount, $processOperations, 'Not all processes performed operations');
        
        foreach ($processOperations as $count) {
            $this->assertEquals($operationsPerProcess, $count, 'Process did not perform expected number of operations');
        }
    }
    
    public function testTaskSchedulingRaceConditions(): void
    {
        $processCount = 4;
        $tasksPerProcess = 25;
        $resultFile = $this->tempDir . '/scheduling_race.txt';
        
        file_put_contents($resultFile, '');
        
        $pids = [];
        
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process - rapid task scheduling
                $processId = $i + 1;
                $lockPath = $this->tempDir . "/rapid_{$processId}";
                mkdir($lockPath, 0755, true);
                
                $scheduler = new TaskScheduler([
                    'lock_path' => $lockPath,
                    'prevent_overlap' => false,
                    'verbose' => false
                ]);
                
                // Rapidly schedule many tasks
                $startTime = microtime(true);
                
                for ($j = 0; $j < $tasksPerProcess; $j++) {
                    $scheduler->call(function() use ($resultFile, $processId, $j) {
                        $message = "P{$processId}T{$j}:" . microtime(true) . "\n";
                        file_put_contents($resultFile, $message, FILE_APPEND | LOCK_EX);
                        return "P{$processId}T{$j}";
                    })->everyMinute();
                }
                
                $schedulingTime = microtime(true) - $startTime;
                
                // Execute all tasks
                $executeStart = microtime(true);
                $results = $scheduler->runDueTasks();
                $executeTime = microtime(true) - $executeStart;
                
                // Log timing info
                $timingMessage = "Process {$processId}: scheduled in {$schedulingTime}s, executed in {$executeTime}s\n";
                file_put_contents($resultFile, $timingMessage, FILE_APPEND | LOCK_EX);
                
                exit(count($results) === $tasksPerProcess ? 0 : 1);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Process {$pid} failed");
        }
        
        // Verify all tasks executed
        $content = file_get_contents($resultFile);
        $lines = explode("\n", $content);
        
        $taskLines = array_filter($lines, fn($line) => preg_match('/^P\d+T\d+:/', $line));
        $timingLines = array_filter($lines, fn($line) => strpos($line, 'scheduled in') !== false);
        
        $expectedTaskCount = $processCount * $tasksPerProcess;
        $this->assertCount($expectedTaskCount, $taskLines, 'Not all tasks executed');
        $this->assertCount($processCount, $timingLines, 'Not all processes reported timing');
        
        // Verify tasks from all processes
        $processTasks = [];
        foreach ($taskLines as $line) {
            if (preg_match('/^P(\d+)T/', $line, $matches)) {
                $processId = (int)$matches[1];
                $processTasks[$processId] = ($processTasks[$processId] ?? 0) + 1;
            }
        }
        
        $this->assertCount($processCount, $processTasks, 'Not all processes executed tasks');
        
        foreach ($processTasks as $count) {
            $this->assertEquals($tasksPerProcess, $count, 'Process did not execute expected number of tasks');
        }
    }
}