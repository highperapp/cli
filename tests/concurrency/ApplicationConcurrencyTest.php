<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Concurrency;

use HighPerApp\HighPer\CLI\Application;
use PHPUnit\Framework\TestCase;

/**
 * Application Concurrency Tests
 */
class ApplicationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for concurrency tests');
        }
    }
    
    public function testConcurrentApplicationInstances(): void
    {
        $instanceCount = 4;
        $workersPerInstance = 10;
        $tempFile = sys_get_temp_dir() . '/app_concurrency_' . uniqid() . '.txt';
        
        file_put_contents($tempFile, '');
        
        $pids = [];
        
        for ($i = 0; $i < $instanceCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork application instance');
            } elseif ($pid === 0) {
                // Child process - create application instance
                $instanceId = $i + 1;
                $app = new Application("ConcurrentApp{$instanceId}", '1.0.0');
                
                // Register workers for this instance
                for ($j = 0; $j < $workersPerInstance; $j++) {
                    $workerId = "instance_{$instanceId}_worker_{$j}";
                    
                    $worker = function($data) use ($tempFile, $instanceId, $j) {
                        $message = "Instance {$instanceId} Worker {$j} executed at " . microtime(true) . "\n";
                        file_put_contents($tempFile, $message, FILE_APPEND | LOCK_EX);
                        return "Worker {$j} result";
                    };
                    
                    $app->registerWorker($workerId, $worker);
                }
                
                // Execute all workers
                $workers = $app->getWorkers();
                foreach ($workers as $workerId => $workerData) {
                    $workerData['worker'](['instance_id' => $instanceId]);
                }
                
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all instances
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Application instance {$pid} failed");
        }
        
        // Verify all workers executed
        $content = file_get_contents($tempFile);
        $lines = array_filter(explode("\n", $content));
        
        $expectedWorkerCount = $instanceCount * $workersPerInstance;
        $this->assertCount($expectedWorkerCount, $lines, 'Not all workers executed');
        
        // Verify workers from all instances executed
        $instanceWorkerCounts = [];
        foreach ($lines as $line) {
            if (preg_match('/Instance (\d+) Worker/', $line, $matches)) {
                $instanceId = (int)$matches[1];
                $instanceWorkerCounts[$instanceId] = ($instanceWorkerCounts[$instanceId] ?? 0) + 1;
            }
        }
        
        $this->assertCount($instanceCount, $instanceWorkerCounts, 'Not all instances executed workers');
        
        foreach ($instanceWorkerCounts as $count) {
            $this->assertEquals($workersPerInstance, $count, 'Instance did not execute expected number of workers');
        }
        
        // Clean up
        unlink($tempFile);
    }
    
    public function testConcurrentConfigurationAccess(): void
    {
        $processCount = 5;
        $configOperationsPerProcess = 50;
        $tempFile = sys_get_temp_dir() . '/config_concurrency_' . uniqid() . '.txt';
        
        file_put_contents($tempFile, '');
        
        $pids = [];
        
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process - concurrent config operations
                $processId = $i + 1;
                $app = new Application("ConfigApp{$processId}", '1.0.0');
                
                for ($j = 0; $j < $configOperationsPerProcess; $j++) {
                    // Set config
                    $key = "process_{$processId}_key_{$j}";
                    $value = "process_{$processId}_value_{$j}";
                    $app->setConfig($key, $value);
                    
                    // Get config
                    $retrieved = $app->getConfig($key);
                    
                    if ($retrieved === $value) {
                        $message = "Process {$processId} operation {$j}: SET/GET success\n";
                    } else {
                        $message = "Process {$processId} operation {$j}: SET/GET failed\n";
                    }
                    
                    file_put_contents($tempFile, $message, FILE_APPEND | LOCK_EX);
                    
                    // Small delay to increase concurrency
                    usleep(rand(100, 1000)); // 0.1-1ms
                }
                
                // Verify all configs are still accessible
                $allCorrect = true;
                for ($j = 0; $j < $configOperationsPerProcess; $j++) {
                    $key = "process_{$processId}_key_{$j}";
                    $expectedValue = "process_{$processId}_value_{$j}";
                    $retrievedValue = $app->getConfig($key);
                    
                    if ($retrievedValue !== $expectedValue) {
                        $allCorrect = false;
                        break;
                    }
                }
                
                $finalMessage = "Process {$processId} final verification: " . ($allCorrect ? "SUCCESS" : "FAILED") . "\n";
                file_put_contents($tempFile, $finalMessage, FILE_APPEND | LOCK_EX);
                
                exit($allCorrect ? 0 : 1);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Process {$pid} failed");
        }
        
        // Verify all operations completed successfully
        $content = file_get_contents($tempFile);
        $lines = explode("\n", $content);
        
        $operationLines = array_filter($lines, fn($line) => strpos($line, 'operation') !== false);
        $verificationLines = array_filter($lines, fn($line) => strpos($line, 'final verification') !== false);
        
        $expectedOperationCount = $processCount * $configOperationsPerProcess;
        $this->assertCount($expectedOperationCount, $operationLines, 'Not all operations completed');
        $this->assertCount($processCount, $verificationLines, 'Not all processes completed verification');
        
        // Verify all operations succeeded
        $failedOperations = array_filter($operationLines, fn($line) => strpos($line, 'failed') !== false);
        $this->assertCount(0, $failedOperations, 'Some config operations failed');
        
        // Verify all final verifications succeeded
        $failedVerifications = array_filter($verificationLines, fn($line) => strpos($line, 'FAILED') !== false);
        $this->assertCount(0, $failedVerifications, 'Some final verifications failed');
        
        // Clean up
        unlink($tempFile);
    }
    
    public function testConcurrentWorkerRegistration(): void
    {
        $processCount = 3;
        $workersPerProcess = 20;
        $tempFile = sys_get_temp_dir() . '/worker_registration_' . uniqid() . '.txt';
        
        file_put_contents($tempFile, '');
        
        $pids = [];
        
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process - register workers concurrently
                $processId = $i + 1;
                $app = new Application("WorkerApp{$processId}", '1.0.0');
                
                // Register workers rapidly
                for ($j = 0; $j < $workersPerProcess; $j++) {
                    $workerId = "process_{$processId}_worker_{$j}";
                    
                    $worker = function($data) use ($processId, $j) {
                        return "Process {$processId} Worker {$j} result: " . json_encode($data);
                    };
                    
                    $options = [
                        'memory_limit' => '64M',
                        'timeout' => 1800,
                        'max_jobs' => 500,
                        'process_id' => $processId,
                        'worker_index' => $j
                    ];
                    
                    $app->registerWorker($workerId, $worker, $options);
                    
                    // Verify immediate retrieval
                    $registeredWorker = $app->getWorker($workerId);
                    if ($registeredWorker !== null) {
                        $message = "Process {$processId} registered worker {$j}: SUCCESS\n";
                    } else {
                        $message = "Process {$processId} registered worker {$j}: FAILED\n";
                    }
                    
                    file_put_contents($tempFile, $message, FILE_APPEND | LOCK_EX);
                }
                
                // Verify all workers are accessible
                $totalWorkers = count($app->getWorkers());
                $finalMessage = "Process {$processId} total workers: {$totalWorkers}\n";
                file_put_contents($tempFile, $finalMessage, FILE_APPEND | LOCK_EX);
                
                exit($totalWorkers === $workersPerProcess ? 0 : 1);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Process {$pid} failed");
        }
        
        // Verify all registrations completed successfully
        $content = file_get_contents($tempFile);
        $lines = explode("\n", $content);
        
        $registrationLines = array_filter($lines, fn($line) => strpos($line, 'registered worker') !== false);
        $totalLines = array_filter($lines, fn($line) => strpos($line, 'total workers:') !== false);
        
        $expectedRegistrationCount = $processCount * $workersPerProcess;
        $this->assertCount($expectedRegistrationCount, $registrationLines, 'Not all worker registrations completed');
        $this->assertCount($processCount, $totalLines, 'Not all processes reported totals');
        
        // Verify all registrations succeeded
        $failedRegistrations = array_filter($registrationLines, fn($line) => strpos($line, 'FAILED') !== false);
        $this->assertCount(0, $failedRegistrations, 'Some worker registrations failed');
        
        // Verify each process has correct number of workers
        foreach ($totalLines as $line) {
            if (preg_match('/total workers: (\d+)/', $line, $matches)) {
                $total = (int)$matches[1];
                $this->assertEquals($workersPerProcess, $total, 'Process did not register expected number of workers');
            }
        }
        
        // Clean up
        unlink($tempFile);
    }
    
    public function testEnvironmentVariableRaceConditions(): void
    {
        $processCount = 4;
        $tempFile = sys_get_temp_dir() . '/env_race_' . uniqid() . '.txt';
        
        file_put_contents($tempFile, '');
        
        // Set up different environment configurations for each process
        $envConfigs = [
            ['HIGHPER_PORT' => '8080', 'HIGHPER_MODE' => 'multiplexed'],
            ['HIGHPER_PORT' => '8081', 'HIGHPER_MODE' => 'dedicated'],
            ['HIGHPER_PORT' => '8082', 'HIGHPER_MODE' => 'hybrid'],
            ['HIGHPER_PORT' => '8083', 'HIGHPER_MODE' => 'multiplexed']
        ];
        
        $pids = [];
        
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process - set environment and create application
                $processId = $i + 1;
                $envConfig = $envConfigs[$i];
                
                // Set environment variables for this process
                foreach ($envConfig as $key => $value) {
                    $_ENV[$key] = $value;
                }
                
                // Create application multiple times to test consistency
                $apps = [];
                for ($j = 0; $j < 10; $j++) {
                    $apps[] = new Application("EnvApp{$processId}_{$j}", '1.0.0');
                }
                
                // Verify all applications have consistent configuration
                $consistent = true;
                $expectedPort = (int)$envConfig['HIGHPER_PORT'];
                $expectedMode = $envConfig['HIGHPER_MODE'];
                
                foreach ($apps as $app) {
                    if ($app->getConfig('port') !== $expectedPort || 
                        $app->getConfig('mode') !== $expectedMode) {
                        $consistent = false;
                        break;
                    }
                }
                
                $message = "Process {$processId} env consistency: " . ($consistent ? "SUCCESS" : "FAILED") . 
                          " (port: {$expectedPort}, mode: {$expectedMode})\n";
                file_put_contents($tempFile, $message, FILE_APPEND | LOCK_EX);
                
                // Test rapid configuration access
                $configTests = 100;
                $configConsistent = true;
                
                for ($k = 0; $k < $configTests; $k++) {
                    $app = $apps[0]; // Use first app
                    
                    if ($app->getConfig('port') !== $expectedPort) {
                        $configConsistent = false;
                        break;
                    }
                }
                
                $configMessage = "Process {$processId} rapid config test: " . 
                               ($configConsistent ? "SUCCESS" : "FAILED") . "\n";
                file_put_contents($tempFile, $configMessage, FILE_APPEND | LOCK_EX);
                
                exit(($consistent && $configConsistent) ? 0 : 1);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Process {$pid} failed");
        }
        
        // Verify all processes completed successfully
        $content = file_get_contents($tempFile);
        $lines = explode("\n", $content);
        
        $consistencyLines = array_filter($lines, fn($line) => strpos($line, 'env consistency:') !== false);
        $configLines = array_filter($lines, fn($line) => strpos($line, 'rapid config test:') !== false);
        
        $this->assertCount($processCount, $consistencyLines, 'Not all processes reported consistency');
        $this->assertCount($processCount, $configLines, 'Not all processes completed config tests');
        
        // Verify all tests passed
        $failedConsistency = array_filter($consistencyLines, fn($line) => strpos($line, 'FAILED') !== false);
        $failedConfig = array_filter($configLines, fn($line) => strpos($line, 'FAILED') !== false);
        
        $this->assertCount(0, $failedConsistency, 'Some environment consistency tests failed');
        $this->assertCount(0, $failedConfig, 'Some rapid config tests failed');
        
        // Clean up
        unlink($tempFile);
    }
    
    public function testApplicationMemoryIsolation(): void
    {
        $processCount = 3;
        $tempFile = sys_get_temp_dir() . '/memory_isolation_' . uniqid() . '.txt';
        
        file_put_contents($tempFile, '');
        
        $pids = [];
        
        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process - test memory isolation
                $processId = $i + 1;
                $initialMemory = memory_get_usage(true);
                
                $app = new Application("MemoryApp{$processId}", '1.0.0');
                
                // Allocate different amounts of memory per process
                $memoryToAllocate = $processId * 1000; // 1KB, 2KB, 3KB
                
                for ($j = 0; $j < $memoryToAllocate; $j++) {
                    $workerId = "memory_worker_{$j}";
                    $worker = function($data) use ($j) {
                        // Create some data to use memory
                        $largeArray = array_fill(0, 100, "Worker {$j} data: " . str_repeat('x', 100));
                        return count($largeArray);
                    };
                    
                    $app->registerWorker($workerId, $worker);
                }
                
                $afterRegistrationMemory = memory_get_usage(true);
                $memoryIncrease = $afterRegistrationMemory - $initialMemory;
                
                // Execute some workers to use more memory
                $workers = $app->getWorkers();
                $executionResults = [];
                
                foreach (array_slice($workers, 0, min(100, count($workers))) as $workerId => $workerData) {
                    $executionResults[] = $workerData['worker'](['process_id' => $processId]);
                }
                
                $finalMemory = memory_get_usage(true);
                $peakMemory = memory_get_peak_usage(true);
                
                $memoryData = [
                    'process_id' => $processId,
                    'initial_memory' => $initialMemory,
                    'after_registration' => $afterRegistrationMemory,
                    'final_memory' => $finalMemory,
                    'peak_memory' => $peakMemory,
                    'memory_increase' => $memoryIncrease,
                    'workers_registered' => count($workers),
                    'workers_executed' => count($executionResults)
                ];
                
                $message = "Process {$processId} memory data: " . json_encode($memoryData) . "\n";
                file_put_contents($tempFile, $message, FILE_APPEND | LOCK_EX);
                
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all processes
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Process {$pid} failed");
        }
        
        // Analyze memory usage patterns
        $content = file_get_contents($tempFile);
        $lines = array_filter(explode("\n", $content));
        
        $this->assertCount($processCount, $lines, 'Not all processes reported memory data');
        
        $memoryData = [];
        foreach ($lines as $line) {
            if (preg_match('/Process (\d+) memory data: (.+)/', $line, $matches)) {
                $processId = (int)$matches[1];
                $data = json_decode($matches[2], true);
                $memoryData[$processId] = $data;
            }
        }
        
        $this->assertCount($processCount, $memoryData, 'Failed to parse all memory data');
        
        // Verify memory usage patterns are reasonable and isolated
        foreach ($memoryData as $processId => $data) {
            $this->assertGreaterThan(0, $data['memory_increase'], 
                "Process {$processId} should have increased memory usage");
            
            $this->assertGreaterThanOrEqual($data['final_memory'], $data['peak_memory'],
                "Process {$processId} peak memory should be >= final memory");
            
            $this->assertEquals($processId * 1000, $data['workers_registered'],
                "Process {$processId} should have registered expected number of workers");
        }
        
        // Verify different processes had different memory patterns
        $memoryIncreases = array_column($memoryData, 'memory_increase');
        $this->assertEquals(count($memoryIncreases), count(array_unique($memoryIncreases)),
            'Processes should have different memory usage patterns');
        
        // Clean up
        unlink($tempFile);
    }
}