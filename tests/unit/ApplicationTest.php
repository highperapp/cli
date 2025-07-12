<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Unit;

use HighPerApp\HighPer\CLI\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/**
 * Application Unit Tests
 */
class ApplicationTest extends TestCase
{
    private Application $app;
    
    protected function setUp(): void
    {
        $this->app = new Application('TestApp', '1.0.0');
    }
    
    public function testApplicationCreation(): void
    {
        $this->assertInstanceOf(Application::class, $this->app);
        $this->assertEquals('TestApp', $this->app->getName());
        $this->assertEquals('1.0.0', $this->app->getVersion());
    }
    
    public function testConfigurationManagement(): void
    {
        $this->app->setConfig('test_key', 'test_value');
        $this->assertEquals('test_value', $this->app->getConfig('test_key'));
        $this->assertEquals('default', $this->app->getConfig('non_existent', 'default'));
    }
    
    public function testWorkerRegistration(): void
    {
        $worker = function() { return 'test'; };
        $options = ['memory_limit' => '256M'];
        
        $this->app->registerWorker('test_worker', $worker, $options);
        
        $registeredWorker = $this->app->getWorker('test_worker');
        $this->assertIsArray($registeredWorker);
        $this->assertEquals($worker, $registeredWorker['worker']);
        $this->assertEquals('256M', $registeredWorker['options']['memory_limit']);
    }
    
    public function testGetNonExistentWorker(): void
    {
        $this->assertNull($this->app->getWorker('non_existent'));
    }
    
    public function testGetAllWorkers(): void
    {
        $worker1 = function() { return 'test1'; };
        $worker2 = function() { return 'test2'; };
        
        $this->app->registerWorker('worker1', $worker1);
        $this->app->registerWorker('worker2', $worker2);
        
        $workers = $this->app->getWorkers();
        $this->assertCount(2, $workers);
        $this->assertArrayHasKey('worker1', $workers);
        $this->assertArrayHasKey('worker2', $workers);
    }
    
    public function testDefaultWorkerOptions(): void
    {
        $worker = function() { return 'test'; };
        $this->app->registerWorker('test_worker', $worker);
        
        $registeredWorker = $this->app->getWorker('test_worker');
        $options = $registeredWorker['options'];
        
        $this->assertEquals('128M', $options['memory_limit']);
        $this->assertEquals(3600, $options['timeout']);
        $this->assertEquals(1000, $options['max_jobs']);
        $this->assertEquals(5, $options['sleep_on_empty']);
    }
    
    public function testDefaultConfiguration(): void
    {
        // Test that default configuration is loaded
        // In test environment, app_env should be 'testing'
        $this->assertEquals('testing', $this->app->getConfig('app_env'));
        $this->assertEquals(8080, $this->app->getConfig('port'));
        $this->assertEquals('multiplexed', $this->app->getConfig('mode'));
    }
    
    public function testEnvironmentConfiguration(): void
    {
        // Set environment variables
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['HIGHPER_PORT'] = '9000';
        $_ENV['HIGHPER_MODE'] = 'dedicated';
        
        $testApp = new Application();
        
        $this->assertEquals('testing', $testApp->getConfig('app_env'));
        $this->assertEquals(9000, $testApp->getConfig('port'));
        $this->assertEquals('dedicated', $testApp->getConfig('mode'));
        
        // Clean up
        unset($_ENV['APP_ENV'], $_ENV['HIGHPER_PORT'], $_ENV['HIGHPER_MODE']);
    }
    
    public function testDiscoverCommandsWithInvalidDirectory(): void
    {
        // Should not throw exception for non-existent directory
        $this->app->discoverCommands('/non/existent/directory');
        $this->assertTrue(true); // If we get here, no exception was thrown
    }
}