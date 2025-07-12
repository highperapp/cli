<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Performance;

use HighPerApp\HighPer\CLI\Application;
use PHPUnit\Framework\TestCase;

/**
 * Application Performance Tests
 */
class ApplicationPerformanceTest extends TestCase
{
    private Application $app;
    
    protected function setUp(): void
    {
        $this->app = new Application('PerfTestApp', '1.0.0');
    }
    
    public function testApplicationCreationPerformance(): void
    {
        $iterations = 1000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $app = new Application("TestApp{$i}", '1.0.0');
            unset($app);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should create 1000 applications in less than 1 second
        $this->assertLessThan(1.0, $duration, 
            "Creating {$iterations} applications took {$duration}s (expected < 1s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nApplication creation average: {$avgTime}ms per instance\n";
    }
    
    public function testConfigurationSetPerformance(): void
    {
        $iterations = 10000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $this->app->setConfig("key_{$i}", "value_{$i}");
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should set 10000 config values in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Setting {$iterations} config values took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000000;
        echo "\nConfig set average: {$avgTime}μs per operation\n";
    }
    
    public function testConfigurationGetPerformance(): void
    {
        // Pre-populate config
        for ($i = 0; $i < 1000; $i++) {
            $this->app->setConfig("key_{$i}", "value_{$i}");
        }
        
        $iterations = 100000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $key = "key_" . ($i % 1000);
            $value = $this->app->getConfig($key);
            $this->assertNotNull($value);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should retrieve 100000 config values in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Getting {$iterations} config values took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000000;
        echo "\nConfig get average: {$avgTime}μs per operation\n";
    }
    
    public function testWorkerRegistrationPerformance(): void
    {
        $iterations = 1000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $worker = function($data) { return $data; };
            $this->app->registerWorker("worker_{$i}", $worker);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should register 1000 workers in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Registering {$iterations} workers took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nWorker registration average: {$avgTime}ms per worker\n";
    }
    
    public function testWorkerRetrievalPerformance(): void
    {
        // Pre-register workers
        for ($i = 0; $i < 1000; $i++) {
            $worker = function($data) { return $data; };
            $this->app->registerWorker("worker_{$i}", $worker);
        }
        
        $iterations = 10000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $workerName = "worker_" . ($i % 1000);
            $worker = $this->app->getWorker($workerName);
            $this->assertNotNull($worker);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should retrieve 10000 workers in less than 0.05 seconds
        $this->assertLessThan(0.05, $duration,
            "Retrieving {$iterations} workers took {$duration}s (expected < 0.05s)");
        
        $avgTime = ($duration / $iterations) * 1000000;
        echo "\nWorker retrieval average: {$avgTime}μs per lookup\n";
    }
    
    public function testMemoryUsageWithManyWorkers(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Register 1000 workers
        for ($i = 0; $i < 1000; $i++) {
            $worker = function($data) use ($i) { 
                return "Worker {$i} processed: " . json_encode($data); 
            };
            $this->app->registerWorker("worker_{$i}", $worker, [
                'memory_limit' => '128M',
                'timeout' => 3600,
                'max_jobs' => 1000
            ]);
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        // Memory increase should be reasonable (less than 10MB for 1000 workers)
        $this->assertLessThan(10 * 1024 * 1024, $memoryIncrease,
            "Memory increase of {$memoryIncrease} bytes for 1000 workers exceeds 10MB limit");
        
        $avgMemoryPerWorker = $memoryIncrease / 1000;
        echo "\nAverage memory per worker: {$avgMemoryPerWorker} bytes\n";
    }
    
    public function testEnvironmentConfigurationLoadPerformance(): void
    {
        // Set up environment variables
        $envVars = [
            'APP_ENV' => 'performance_test',
            'HIGHPER_PROTOCOLS' => 'http,websocket,grpc',
            'HIGHPER_PORT' => '8080',
            'HIGHPER_MODE' => 'multiplexed',
            'HIGHPER_WORKER_PROCESSES' => '8',
            'HIGHPER_MAX_CONNECTIONS' => '50000',
            'HIGHPER_MEMORY_LIMIT' => '1G',
            'HIGHPER_RUST_FFI' => 'enabled',
            'HIGHPER_CRYPTO_ENGINE' => 'openssl',
            'HIGHPER_JSON_PARSER' => 'native'
        ];
        
        foreach ($envVars as $key => $value) {
            $_ENV[$key] = $value;
        }
        
        $iterations = 100;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $app = new Application("PerfApp{$i}", '1.0.0');
            unset($app);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should create 100 applications with env config in less than 0.5 seconds
        $this->assertLessThan(0.5, $duration,
            "Creating {$iterations} applications with env config took {$duration}s (expected < 0.5s)");
        
        // Clean up environment variables
        foreach ($envVars as $key => $value) {
            unset($_ENV[$key]);
        }
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nApplication creation with env config average: {$avgTime}ms per instance\n";
    }
    
    public function testGetAllWorkersPerformance(): void
    {
        // Register many workers
        for ($i = 0; $i < 1000; $i++) {
            $worker = function($data) { return $data; };
            $this->app->registerWorker("worker_{$i}", $worker);
        }
        
        $iterations = 1000;
        $startTime = microtime(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $workers = $this->app->getWorkers();
            $this->assertCount(1000, $workers);
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        // Should retrieve all workers 1000 times in less than 0.1 seconds
        $this->assertLessThan(0.1, $duration,
            "Getting all workers {$iterations} times took {$duration}s (expected < 0.1s)");
        
        $avgTime = ($duration / $iterations) * 1000;
        echo "\nGet all workers average: {$avgTime}ms per call\n";
    }
}