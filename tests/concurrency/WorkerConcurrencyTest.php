<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Concurrency;

use HighPerApp\HighPer\CLI\Workers\QueueWorker;
use PHPUnit\Framework\TestCase;

/**
 * Worker Concurrency Tests
 */
class WorkerConcurrencyTest extends TestCase
{
    private array $baseConfig;
    
    protected function setUp(): void
    {
        if (!extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl extension is required for concurrency tests');
        }
        
        $this->baseConfig = [
            'adapter' => 'memory',
            'queue' => 'concurrency_test',
            'memory_limit' => '64M',
            'timeout' => 30,
            'max_jobs' => 10,
            'sleep_on_empty' => 1,
            'delay_failed_jobs' => 0,
            'max_tries' => 2,
            'force' => false
        ];
    }
    
    public function testMultipleWorkerProcesses(): void
    {
        $workerCount = 3;
        $pids = [];
        $sharedFile = sys_get_temp_dir() . '/worker_test_' . uniqid() . '.txt';
        
        // Create shared file for worker communication
        file_put_contents($sharedFile, '');
        
        for ($i = 0; $i < $workerCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork worker process');
            } elseif ($pid === 0) {
                // Child process - simulate worker
                $config = array_merge($this->baseConfig, ['worker_id' => $i + 1]);
                
                // Simulate some work
                $workerId = $i + 1;
                $message = "Worker {$workerId} executed at " . microtime(true) . "\n";
                file_put_contents($sharedFile, $message, FILE_APPEND | LOCK_EX);
                
                // Simulate processing time
                usleep(100000); // 0.1 seconds
                
                exit(0);
            } else {
                // Parent process
                $pids[] = $pid;
            }
        }
        
        // Wait for all workers to complete
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), "Worker process {$pid} failed");
        }
        
        // Verify all workers executed
        $content = file_get_contents($sharedFile);
        $lines = array_filter(explode("\n", $content));
        
        $this->assertCount($workerCount, $lines, 'Not all workers executed');
        
        // Verify different workers executed
        $workerIds = [];
        foreach ($lines as $line) {
            if (preg_match('/Worker (\d+) executed/', $line, $matches)) {
                $workerIds[] = (int)$matches[1];
            }
        }
        
        $this->assertCount($workerCount, array_unique($workerIds), 'Workers did not execute concurrently');
        
        // Clean up
        unlink($sharedFile);
    }
    
    public function testWorkerSignalHandling(): void
    {
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            $this->fail('Failed to fork worker process');
        } elseif ($pid === 0) {
            // Child process - worker
            $config = array_merge($this->baseConfig, ['timeout' => 60]);
            $worker = new QueueWorker($config);
            
            // Set up signal handler
            pcntl_signal(SIGTERM, [$worker, 'stop']);
            
            // Start worker (it will run indefinitely until signaled)
            $worker->work(function($status) {
                // Status callback - do nothing for test
            });
            
            exit(0);
        } else {
            // Parent process
            // Give worker time to start
            usleep(500000); // 0.5 seconds
            
            // Send termination signal
            posix_kill($pid, SIGTERM);
            
            // Wait for worker to stop
            $status = 0;
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            
            // Give some time for graceful shutdown
            $timeout = 5; // 5 seconds
            $start = time();
            
            while ($result === 0 && (time() - $start) < $timeout) {
                usleep(100000); // 0.1 seconds
                $result = pcntl_waitpid($pid, $status, WNOHANG);
            }
            
            $this->assertGreaterThan(0, $result, 'Worker did not respond to SIGTERM signal within timeout');
            $this->assertEquals(0, pcntl_wexitstatus($status), 'Worker did not exit cleanly');
        }
    }
    
    public function testConcurrentJobProcessing(): void
    {
        eval('
            class ConcurrentTestJob {
                public function handle($data) {
                    $file = $data["shared_file"];
                    $workerId = $data["worker_id"];
                    $jobId = $data["job_id"];
                    
                    // Simulate some processing time
                    usleep(rand(50000, 150000)); // 0.05-0.15 seconds
                    
                    $message = "Worker {$workerId} processed job {$jobId} at " . microtime(true) . "\n";
                    file_put_contents($file, $message, FILE_APPEND | LOCK_EX);
                    
                    return "Job {$jobId} completed";
                }
            }
        ');
        
        $workerCount = 3;
        $jobsPerWorker = 5;
        $sharedFile = sys_get_temp_dir() . '/concurrent_jobs_' . uniqid() . '.txt';
        
        file_put_contents($sharedFile, '');
        
        $pids = [];
        
        for ($i = 0; $i < $workerCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork worker process');
            } elseif ($pid === 0) {
                // Child process - worker
                $workerId = $i + 1;
                $config = array_merge($this->baseConfig, ['worker_id' => $workerId]);
                $worker = new QueueWorker($config);
                
                $reflection = new \ReflectionClass($worker);
                $processMethod = $reflection->getMethod('processJob');
                $processMethod->setAccessible(true);
                
                // Process multiple jobs
                for ($j = 0; $j < $jobsPerWorker; $j++) {
                    $job = [
                        'id' => "job_{$workerId}_{$j}",
                        'class' => 'ConcurrentTestJob',
                        'data' => [
                            'shared_file' => $sharedFile,
                            'worker_id' => $workerId,
                            'job_id' => "job_{$workerId}_{$j}"
                        ],
                        'attempts' => 0
                    ];
                    
                    $processMethod->invoke($worker, $job);
                }
                
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all workers
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status));
        }
        
        // Verify all jobs were processed
        $content = file_get_contents($sharedFile);
        $lines = array_filter(explode("\n", $content));
        
        $expectedJobCount = $workerCount * $jobsPerWorker;
        $this->assertCount($expectedJobCount, $lines, 'Not all jobs were processed');
        
        // Verify jobs from different workers were processed concurrently
        $workerJobCounts = [];
        foreach ($lines as $line) {
            if (preg_match('/Worker (\d+) processed/', $line, $matches)) {
                $workerId = (int)$matches[1];
                $workerJobCounts[$workerId] = ($workerJobCounts[$workerId] ?? 0) + 1;
            }
        }
        
        $this->assertCount($workerCount, $workerJobCounts, 'Not all workers processed jobs');
        
        foreach ($workerJobCounts as $count) {
            $this->assertEquals($jobsPerWorker, $count, 'Worker did not process expected number of jobs');
        }
        
        // Clean up
        unlink($sharedFile);
    }
    
    public function testRaceConditionPrevention(): void
    {
        $sharedCounter = sys_get_temp_dir() . '/race_counter_' . uniqid() . '.txt';
        file_put_contents($sharedCounter, '0');
        
        $workerCount = 5;
        $incrementsPerWorker = 20;
        $pids = [];
        
        for ($i = 0; $i < $workerCount; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork worker process');
            } elseif ($pid === 0) {
                // Child process - increment counter with file locking
                for ($j = 0; $j < $incrementsPerWorker; $j++) {
                    $fp = fopen($sharedCounter, 'r+');
                    if ($fp && flock($fp, LOCK_EX)) {
                        $current = (int)fread($fp, 100);
                        $new = $current + 1;
                        
                        rewind($fp);
                        fwrite($fp, (string)$new);
                        ftruncate($fp, strlen((string)$new));
                        
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        
                        // Small delay to increase chance of race condition
                        usleep(1000); // 1ms
                    } else {
                        exit(1); // Failed to acquire lock
                    }
                }
                
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all workers
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status), 'Worker failed to complete');
        }
        
        // Verify final counter value
        $finalValue = (int)file_get_contents($sharedCounter);
        $expectedValue = $workerCount * $incrementsPerWorker;
        
        $this->assertEquals($expectedValue, $finalValue, 
            'Race condition detected: expected ' . $expectedValue . ' but got ' . $finalValue);
        
        // Clean up
        unlink($sharedCounter);
    }
    
    public function testWorkerMemoryIsolation(): void
    {
        $workerCount = 3;
        $pids = [];
        $resultFiles = [];
        
        for ($i = 0; $i < $workerCount; $i++) {
            $resultFile = sys_get_temp_dir() . "/worker_memory_{$i}_" . uniqid() . '.txt';
            $resultFiles[] = $resultFile;
            
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->fail('Failed to fork worker process');
            } elseif ($pid === 0) {
                // Child process - allocate different amounts of memory
                $workerId = $i + 1;
                $memorySize = $workerId * 1000000; // 1MB, 2MB, 3MB
                
                $largeArray = array_fill(0, $memorySize / 100, str_repeat('x', 100));
                
                $memoryUsage = memory_get_usage(true);
                $peakMemory = memory_get_peak_usage(true);
                
                $result = json_encode([
                    'worker_id' => $workerId,
                    'memory_usage' => $memoryUsage,
                    'peak_memory' => $peakMemory,
                    'array_size' => count($largeArray)
                ]);
                
                file_put_contents($resultFile, $result);
                
                exit(0);
            } else {
                $pids[] = $pid;
            }
        }
        
        // Wait for all workers
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertEquals(0, pcntl_wexitstatus($status));
        }
        
        // Verify memory isolation
        $results = [];
        foreach ($resultFiles as $file) {
            $this->assertFileExists($file);
            $data = json_decode(file_get_contents($file), true);
            $results[] = $data;
            unlink($file);
        }
        
        $this->assertCount($workerCount, $results);
        
        // Verify different memory usage patterns
        $memoryUsages = array_column($results, 'memory_usage');
        $this->assertEquals(count($memoryUsages), count(array_unique($memoryUsages)), 
            'Workers should have different memory usage patterns');
        
        // Verify memory usage increases with worker ID (more allocated memory)
        usort($results, fn($a, $b) => $a['worker_id'] <=> $b['worker_id']);
        
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThan($results[$i-1]['memory_usage'], $results[$i]['memory_usage'],
                "Worker {$results[$i]['worker_id']} should use more memory than worker {$results[$i-1]['worker_id']}");
        }
    }
    
    public function testDeadlockPrevention(): void
    {
        $lockFile1 = sys_get_temp_dir() . '/lock1_' . uniqid() . '.lock';
        $lockFile2 = sys_get_temp_dir() . '/lock2_' . uniqid() . '.lock';
        $resultFile = sys_get_temp_dir() . '/deadlock_result_' . uniqid() . '.txt';
        
        touch($lockFile1);
        touch($lockFile2);
        file_put_contents($resultFile, '');
        
        $pid1 = pcntl_fork();
        
        if ($pid1 === -1) {
            $this->fail('Failed to fork first process');
        } elseif ($pid1 === 0) {
            // Child process 1 - acquire locks in order 1,2
            $fp1 = fopen($lockFile1, 'r');
            if ($fp1 && flock($fp1, LOCK_EX | LOCK_NB)) {
                file_put_contents($resultFile, "Process 1 acquired lock 1\n", FILE_APPEND);
                
                usleep(100000); // 0.1 seconds
                
                $fp2 = fopen($lockFile2, 'r');
                if ($fp2 && flock($fp2, LOCK_EX | LOCK_NB)) {
                    file_put_contents($resultFile, "Process 1 acquired lock 2\n", FILE_APPEND);
                    flock($fp2, LOCK_UN);
                    fclose($fp2);
                } else {
                    file_put_contents($resultFile, "Process 1 failed to acquire lock 2\n", FILE_APPEND);
                }
                
                flock($fp1, LOCK_UN);
                fclose($fp1);
            }
            exit(0);
        }
        
        $pid2 = pcntl_fork();
        
        if ($pid2 === -1) {
            $this->fail('Failed to fork second process');
        } elseif ($pid2 === 0) {
            // Child process 2 - acquire locks in order 1,2 (same order to prevent deadlock)
            usleep(50000); // 0.05 seconds delay
            
            $fp1 = fopen($lockFile1, 'r');
            if ($fp1 && flock($fp1, LOCK_EX | LOCK_NB)) {
                file_put_contents($resultFile, "Process 2 acquired lock 1\n", FILE_APPEND);
                
                $fp2 = fopen($lockFile2, 'r');
                if ($fp2 && flock($fp2, LOCK_EX | LOCK_NB)) {
                    file_put_contents($resultFile, "Process 2 acquired lock 2\n", FILE_APPEND);
                    flock($fp2, LOCK_UN);
                    fclose($fp2);
                } else {
                    file_put_contents($resultFile, "Process 2 failed to acquire lock 2\n", FILE_APPEND);
                }
                
                flock($fp1, LOCK_UN);
                fclose($fp1);
            } else {
                file_put_contents($resultFile, "Process 2 failed to acquire lock 1\n", FILE_APPEND);
            }
            
            exit(0);
        }
        
        // Wait for both processes with timeout
        $timeout = 5; // 5 seconds
        $start = time();
        
        $status1 = $status2 = -1;
        while ((time() - $start) < $timeout && ($status1 === -1 || $status2 === -1)) {
            if ($status1 === -1) {
                $result = pcntl_waitpid($pid1, $status1, WNOHANG);
                if ($result === 0) $status1 = -1; // Still running
            }
            
            if ($status2 === -1) {
                $result = pcntl_waitpid($pid2, $status2, WNOHANG);
                if ($result === 0) $status2 = -1; // Still running
            }
            
            usleep(100000); // 0.1 seconds
        }
        
        // Both processes should complete within timeout (no deadlock)
        $this->assertNotEquals(-1, $status1, 'Process 1 did not complete (possible deadlock)');
        $this->assertNotEquals(-1, $status2, 'Process 2 did not complete (possible deadlock)');
        
        $this->assertEquals(0, pcntl_wexitstatus($status1), 'Process 1 failed');
        $this->assertEquals(0, pcntl_wexitstatus($status2), 'Process 2 failed');
        
        // Verify both processes completed their work
        $result = file_get_contents($resultFile);
        $this->assertStringContainsString('Process 1 acquired lock 1', $result);
        $this->assertStringContainsString('Process 2', $result); // Process 2 should have done something
        
        // Clean up
        unlink($lockFile1);
        unlink($lockFile2);
        unlink($resultFile);
    }
}