<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Integration;

use HighPerApp\HighPer\CLI\Workers\QueueWorker;
use PHPUnit\Framework\TestCase;

/**
 * Worker Integration Tests
 */
class WorkerIntegrationTest extends TestCase
{
    private array $baseConfig;
    
    protected function setUp(): void
    {
        $this->baseConfig = [
            'adapter' => 'memory',
            'queue' => 'test',
            'memory_limit' => '32M',
            'timeout' => 5,
            'max_jobs' => 2,
            'sleep_on_empty' => 1,
            'delay_failed_jobs' => 0,
            'max_tries' => 2,
            'force' => false,
            'worker_id' => 1
        ];
    }
    
    public function testWorkerStatusReporting(): void
    {
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->baseConfig);
        
        // Use reflection to test status reporting
        $reflection = new \ReflectionClass($worker);
        $reportMethod = $reflection->getMethod('reportStatus');
        $reportMethod->setAccessible(true);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        // Report various statuses
        $reportMethod->invoke($worker, 'worker_started', ['worker_id' => 1]);
        $reportMethod->invoke($worker, 'job_started', ['job_id' => '123']);
        $reportMethod->invoke($worker, 'job_completed', ['job_id' => '123', 'duration' => 0.5]);
        
        $this->assertCount(3, $statusReports);
        $this->assertEquals('worker_started', $statusReports[0]['type']);
        $this->assertEquals('job_started', $statusReports[1]['type']);
        $this->assertEquals('job_completed', $statusReports[2]['type']);
    }
    
    public function testWorkerStopSignal(): void
    {
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        $shouldStopProperty = $reflection->getProperty('shouldStop');
        $shouldStopProperty->setAccessible(true);
        
        $this->assertFalse($shouldStopProperty->getValue($worker));
        
        $worker->stop();
        
        $this->assertTrue($shouldStopProperty->getValue($worker));
    }
    
    public function testWorkerMemoryLimitCheck(): void
    {
        $config = array_merge($this->baseConfig, ['memory_limit' => '64M']);
        $worker = new QueueWorker($config);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('shouldRestartDueToMemory');
        $method->setAccessible(true);
        
        // With 64MB limit, should not trigger restart condition
        $result = $method->invoke($worker);
        $this->assertFalse($result);
        
        // Test with higher limit
        $config2 = array_merge($this->baseConfig, ['memory_limit' => '1G']);
        $worker2 = new QueueWorker($config2);
        
        $reflection2 = new \ReflectionClass($worker2);
        $method2 = $reflection2->getMethod('shouldRestartDueToMemory');
        $method2->setAccessible(true);
        
        $this->assertFalse($method2->invoke($worker2));
    }
    
    public function testWorkerJobLimitCheck(): void
    {
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        
        // Simulate processing max jobs
        $jobsProperty = $reflection->getProperty('jobsProcessed');
        $jobsProperty->setAccessible(true);
        $jobsProperty->setValue($worker, 2); // matches max_jobs in config
        
        $method = $reflection->getMethod('shouldRestartDueToJobLimit');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($worker));
    }
    
    public function testWorkerTimeoutCheck(): void
    {
        $config = array_merge($this->baseConfig, ['timeout' => 1]);
        $worker = new QueueWorker($config);
        
        // Wait to exceed timeout
        sleep(2);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('shouldRestartDueToTimeout');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($worker));
    }
    
    public function testWorkerEmptyQueueHandling(): void
    {
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        $method = $reflection->getMethod('handleEmptyQueue');
        $method->setAccessible(true);
        
        $startTime = microtime(true);
        $method->invoke($worker);
        $endTime = microtime(true);
        
        // Should have slept for approximately 1 second (sleep_on_empty)
        $duration = $endTime - $startTime;
        $this->assertGreaterThanOrEqual(0.9, $duration);
        $this->assertLessThanOrEqual(1.5, $duration);
        
        // Should have reported queue empty status
        $this->assertCount(1, $statusReports);
        $this->assertEquals('queue_empty', $statusReports[0]['type']);
        $this->assertEquals(1, $statusReports[0]['sleep']);
    }
    
    public function testWorkerJobProcessingFailure(): void
    {
        eval('
            class FailingTestJob {
                public function handle($data) {
                    throw new \RuntimeException("Test job failure");
                }
            }
        ');
        
        $job = [
            'id' => 'test_job_123',
            'class' => 'FailingTestJob',
            'data' => ['test' => 'data'],
            'attempts' => 0
        ];
        
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        $method = $reflection->getMethod('processJob');
        $method->setAccessible(true);
        
        $method->invoke($worker, $job);
        
        // Should have job_started and job_failed status reports
        $this->assertGreaterThanOrEqual(2, count($statusReports));
        
        $jobStarted = array_filter($statusReports, fn($r) => $r['type'] === 'job_started');
        $jobFailed = array_filter($statusReports, fn($r) => $r['type'] === 'job_failed');
        
        $this->assertCount(1, $jobStarted);
        $this->assertCount(1, $jobFailed);
        
        $failedReport = array_values($jobFailed)[0];
        $this->assertEquals('test_job_123', $failedReport['job_id']);
        $this->assertEquals('FailingTestJob', $failedReport['job_class']);
        $this->assertEquals('Test job failure', $failedReport['error']);
    }
    
    public function testWorkerJobProcessingSuccess(): void
    {
        eval('
            class SuccessTestJob {
                public function handle($data) {
                    return "Success with: " . json_encode($data);
                }
            }
        ');
        
        $job = [
            'id' => 'test_job_456',
            'class' => 'SuccessTestJob',
            'data' => ['success' => true],
            'attempts' => 0
        ];
        
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        $jobsProperty = $reflection->getProperty('jobsProcessed');
        $jobsProperty->setAccessible(true);
        $initialJobsProcessed = $jobsProperty->getValue($worker);
        
        $method = $reflection->getMethod('processJob');
        $method->setAccessible(true);
        
        $method->invoke($worker, $job);
        
        // Jobs processed counter should increment
        $this->assertEquals($initialJobsProcessed + 1, $jobsProperty->getValue($worker));
        
        // Should have job_started and job_completed status reports
        $jobStarted = array_filter($statusReports, fn($r) => $r['type'] === 'job_started');
        $jobCompleted = array_filter($statusReports, fn($r) => $r['type'] === 'job_completed');
        
        $this->assertCount(1, $jobStarted);
        $this->assertCount(1, $jobCompleted);
        
        $completedReport = array_values($jobCompleted)[0];
        $this->assertEquals('test_job_456', $completedReport['job_id']);
        $this->assertEquals('SuccessTestJob', $completedReport['job_class']);
        $this->assertArrayHasKey('duration', $completedReport);
        $this->assertArrayHasKey('result', $completedReport);
    }
    
    public function testWorkerJobWithNonExistentClass(): void
    {
        $job = [
            'id' => 'test_job_789',
            'class' => 'NonExistentJobClass',
            'data' => [],
            'attempts' => 0
        ];
        
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->baseConfig);
        
        $reflection = new \ReflectionClass($worker);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        $method = $reflection->getMethod('processJob');
        $method->setAccessible(true);
        
        $method->invoke($worker, $job);
        
        $jobFailed = array_filter($statusReports, fn($r) => $r['type'] === 'job_failed');
        $this->assertCount(1, $jobFailed);
        
        $failedReport = array_values($jobFailed)[0];
        $this->assertStringContainsString('Job class not found', $failedReport['error']);
    }
}