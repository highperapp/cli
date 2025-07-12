<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Performance;

use HighPerApp\HighPer\CLI\Workers\QueueWorker;
use PHPUnit\Framework\TestCase;

/**
 * Worker Performance Tests
 */
class WorkerPerformanceTest extends TestCase
{
    private array $baseConfig;
    
    protected function setUp(): void
    {
        $this->baseConfig = [
            'adapter' => 'memory',
            'queue' => 'performance_test',
            'memory_limit' => '128M',
            'timeout' => 60,
            'max_jobs' => 1000,
            'sleep_on_empty' => 1,
            'delay_failed_jobs' => 0,
            'max_tries' => 3,
            'force' => false,
            'worker_id' => 1
        ];
    }
    
    public function testWorkerCreationPerformance(): void
    {
        $iterations = 1000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $config = array_merge($this->baseConfig, ['worker_id' => $i]);
            $worker = new QueueWorker($config);
            unset($worker);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should create 1000 workers in less than 1 second
        $this->assertLessThan(1.0, $duration,
            "Creating {$iterations} workers took {$duration}s (expected < 1s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nWorker creation average: {$avgTime}ms per worker\n";
    }
    
    public function testJobProcessingPerformance(): void
    {
        eval('
            class FastPerformanceJob {
                public function handle($data) {
                    return "Processed: " . $data["id"];
                }
            }
        ');
        
        $jobCount = 100;
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        $processMethod = $reflection->getMethod('processJob');
        $processMethod->setAccessible(true);
        
        $jobs = [];
        for ($i = 0; $i < $jobCount; $i++) {
            $jobs[] = [
                'id' => "job_{$i}",
                'class' => 'FastPerformanceJob',
                'data' => ['id' => $i],
                'attempts' => 0
            ];
        }
        
        $startTime = microtime(true);
        
        foreach ($jobs as $job) {
            $processMethod->invoke($worker, $job);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should process 100 fast jobs in less than 0.5 seconds
        $this->assertLessThan(0.5, $duration,
            "Processing {$jobCount} jobs took {$duration}s (expected < 0.5s)");
        
        $avgTime = ($duration / $jobCount) * 1000;
        echo "\nJob processing average: {$avgTime}ms per job\n";
        
        // Check that all jobs completed successfully
        $completedJobs = array_filter($statusReports, fn($r) => $r['type'] === 'job_completed');
        $this->assertCount($jobCount, $completedJobs);
    }
    
    public function testMemoryLimitCheckPerformance(): void
    {
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('shouldRestartDueToMemory');
        $method->setAccessible(true);
        
        $iterations = 10000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $result = $method->invoke($worker);
            $this->assertIsBool($result);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should check memory limit 10000 times in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Memory limit checks {$iterations} times took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000000;
        echo "\nMemory check average: {$avgTime}μs per check\n";
    }
    
    public function testJobLimitCheckPerformance(): void
    {
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('shouldRestartDueToJobLimit');
        $method->setAccessible(true);
        
        $iterations = 10000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $result = $method->invoke($worker);
            $this->assertIsBool($result);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should check job limit 10000 times in less than 0.05 seconds
        $this->assertLessThan(0.05, $duration,
            "Job limit checks {$iterations} times took {$duration}s (expected < 0.05s)");
        
        $avgTime = ($duration / $iterations) * 1000000;
        echo "\nJob limit check average: {$avgTime}μs per check\n";
    }
    
    public function testTimeoutCheckPerformance(): void
    {
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('shouldRestartDueToTimeout');
        $method->setAccessible(true);
        
        $iterations = 10000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $result = $method->invoke($worker);
            $this->assertIsBool($result);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should check timeout 10000 times in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Timeout checks {$iterations} times took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000000;
        echo "\nTimeout check average: {$avgTime}μs per check\n";
    }
    
    public function testStatusReportingPerformance(): void
    {
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        $reportMethod = $reflection->getMethod('reportStatus');
        $reportMethod->setAccessible(true);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        $iterations = 10000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $reportMethod->invoke($worker, 'test_event', ['iteration' => $i]);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should report status 10000 times in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Status reporting {$iterations} times took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000000;
        echo "\nStatus reporting average: {$avgTime}μs per report\n";
        
        $this->assertCount($iterations, $statusReports);
    }
    
    public function testMemoryLimitParsingPerformance(): void
    {
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('parseMemoryLimit');
        $method->setAccessible(true);
        
        $memoryLimits = ['128M', '256M', '512M', '1G', '2G', '64K', '1024'];
        $iterations = 1000;
        
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($memoryLimits as $limit) {
                $result = $method->invoke($worker, $limit);
                $this->assertIsInt($result);
            }
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $totalParses = $iterations * count($memoryLimits);
        
        // Should parse memory limits many times in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Parsing memory limits {$totalParses} times took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $totalParses) * 1000000;
        echo "\nMemory limit parsing average: {$avgTime}μs per parse\n";
    }
    
    public function testFailedJobHandlingPerformance(): void
    {
        eval('
            class FailingPerformanceJob {
                public function handle($data) {
                    throw new \RuntimeException("Simulated failure for job: " . $data["id"]);
                }
            }
        ');
        
        $jobCount = 50;
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        $processMethod = $reflection->getMethod('processJob');
        $processMethod->setAccessible(true);
        
        $jobs = [];
        for ($i = 0; $i < $jobCount; $i++) {
            $jobs[] = [
                'id' => "failing_job_{$i}",
                'class' => 'FailingPerformanceJob',
                'data' => ['id' => $i],
                'attempts' => 0
            ];
        }
        
        $startTime = microtime(true);
        
        foreach ($jobs as $job) {
            $processMethod->invoke($worker, $job);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should handle 50 failing jobs in less than 0.5 seconds
        $this->assertLessThan(0.5, $duration,
            "Handling {$jobCount} failing jobs took {$duration}s (expected < 0.5s)");
        
        $avgTime = ($duration / $jobCount) * 1000;
        echo "\nFailed job handling average: {$avgTime}ms per job\n";
        
        // Check that all jobs failed
        $failedJobs = array_filter($statusReports, fn($r) => $r['type'] === 'job_failed');
        $this->assertCount($jobCount, $failedJobs);
    }
    
    public function testMemoryUsageUnderLoad(): void
    {
        eval('
            class MemoryIntensiveJob {
                public function handle($data) {
                    $largeArray = array_fill(0, 1000, str_repeat("x", 1000));
                    return "Processed: " . $data["id"];
                }
            }
        ');
        
        $initialMemory = memory_get_usage(true);
        
        $jobCount = 100;
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        $processMethod = $reflection->getMethod('processJob');
        $processMethod->setAccessible(true);
        
        for ($i = 0; $i < $jobCount; $i++) {
            $job = [
                'id' => "memory_job_{$i}",
                'class' => 'MemoryIntensiveJob',
                'data' => ['id' => $i],
                'attempts' => 0
            ];
            
            $processMethod->invoke($worker, $job);
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        // Memory increase should be reasonable despite processing memory-intensive jobs
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease,
            "Memory increase of {$memoryIncrease} bytes for {$jobCount} memory-intensive jobs exceeds 50MB");
        
        $avgMemoryPerJob = $memoryIncrease / $jobCount;
        echo "\nAverage memory per memory-intensive job: {$avgMemoryPerJob} bytes\n";
    }
}