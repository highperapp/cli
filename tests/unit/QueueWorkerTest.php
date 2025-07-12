<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Unit;

use HighPerApp\HighPer\CLI\Workers\QueueWorker;
use PHPUnit\Framework\TestCase;

/**
 * QueueWorker Unit Tests
 */
class QueueWorkerTest extends TestCase
{
    private array $defaultConfig;
    
    protected function setUp(): void
    {
        $this->defaultConfig = [
            'adapter' => 'memory',
            'queue' => 'test',
            'memory_limit' => '64M',
            'timeout' => 60,
            'max_jobs' => 10,
            'sleep_on_empty' => 1,
            'delay_failed_jobs' => 0,
            'max_tries' => 2,
            'force' => false,
            'worker_id' => 1
        ];
    }
    
    public function testWorkerCreation(): void
    {
        $worker = new QueueWorker($this->defaultConfig);
        $this->assertInstanceOf(QueueWorker::class, $worker);
    }
    
    public function testConfigurationMerging(): void
    {
        $customConfig = [
            'adapter' => 'redis',
            'memory_limit' => '256M'
        ];
        
        $worker = new QueueWorker($customConfig);
        
        // Use reflection to access protected config property
        $reflection = new \ReflectionClass($worker);
        $configProperty = $reflection->getProperty('config');
        $configProperty->setAccessible(true);
        $config = $configProperty->getValue($worker);
        
        $this->assertEquals('redis', $config['adapter']);
        $this->assertEquals('256M', $config['memory_limit']);
        $this->assertEquals('default', $config['queue']); // Default value
    }
    
    public function testMemoryLimitParsing(): void
    {
        $worker = new QueueWorker($this->defaultConfig);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('parseMemoryLimit');
        $method->setAccessible(true);
        
        $this->assertEquals(1024 * 1024 * 1024, $method->invoke($worker, '1G'));
        $this->assertEquals(128 * 1024 * 1024, $method->invoke($worker, '128M'));
        $this->assertEquals(512 * 1024, $method->invoke($worker, '512K'));
        $this->assertEquals(1024, $method->invoke($worker, '1024'));
    }
    
    public function testShouldRestartDueToMemory(): void
    {
        // Use a much higher memory limit for testing to avoid actual memory limit setting
        $config = array_merge($this->defaultConfig, ['memory_limit' => '128M']);
        $worker = new QueueWorker($config);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('shouldRestartDueToMemory');
        $method->setAccessible(true);
        
        // With a reasonable memory limit, this should return false
        $result = $method->invoke($worker);
        $this->assertFalse($result);
    }
    
    public function testShouldRestartDueToJobLimit(): void
    {
        $worker = new QueueWorker($this->defaultConfig);
        
        $reflection = new \ReflectionClass($worker);
        
        // Set jobs processed to max limit
        $jobsProperty = $reflection->getProperty('jobsProcessed');
        $jobsProperty->setAccessible(true);
        $jobsProperty->setValue($worker, 10);
        
        $method = $reflection->getMethod('shouldRestartDueToJobLimit');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($worker));
    }
    
    public function testShouldRestartDueToTimeout(): void
    {
        $config = array_merge($this->defaultConfig, ['timeout' => 1]);
        $worker = new QueueWorker($config);
        
        // Wait a bit to exceed timeout
        sleep(2);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('shouldRestartDueToTimeout');
        $method->setAccessible(true);
        
        $this->assertTrue($method->invoke($worker));
    }
    
    public function testStopWorker(): void
    {
        $worker = new QueueWorker($this->defaultConfig);
        
        $reflection = new \ReflectionClass($worker);
        $shouldStopProperty = $reflection->getProperty('shouldStop');
        $shouldStopProperty->setAccessible(true);
        
        $this->assertFalse($shouldStopProperty->getValue($worker));
        
        $worker->stop();
        
        $this->assertTrue($shouldStopProperty->getValue($worker));
    }
    
    public function testStatusReporting(): void
    {
        $statusReports = [];
        $callback = function($status) use (&$statusReports) {
            $statusReports[] = $status;
        };
        
        $worker = new QueueWorker($this->defaultConfig);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('reportStatus');
        $method->setAccessible(true);
        
        $callbackProperty = $reflection->getProperty('statusCallback');
        $callbackProperty->setAccessible(true);
        $callbackProperty->setValue($worker, $callback);
        
        $method->invoke($worker, 'test_event', ['key' => 'value']);
        
        $this->assertCount(1, $statusReports);
        $this->assertEquals('test_event', $statusReports[0]['type']);
        $this->assertEquals('value', $statusReports[0]['key']);
    }
    
    public function testGetNextJobReturnsNull(): void
    {
        $worker = new QueueWorker($this->defaultConfig);
        
        $reflection = new \ReflectionClass($worker);
        $method = $reflection->getMethod('getNextJob');
        $method->setAccessible(true);
        
        $this->assertNull($method->invoke($worker));
    }
}