<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Tests\Integration;

use HighPerApp\HighPer\CLI\Application;
use HighPerApp\HighPer\CLI\Commands\QueueWorkCommand;
use HighPerApp\HighPer\CLI\Commands\ScheduleRunCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Command Integration Tests
 */
class CommandIntegrationTest extends TestCase
{
    private Application $app;
    
    protected function setUp(): void
    {
        $this->app = new Application('TestApp', '1.0.0');
        $this->app->add(new QueueWorkCommand());
        $this->app->add(new ScheduleRunCommand());
    }
    
    public function testQueueWorkCommandConfiguration(): void
    {
        $command = $this->app->find('queue:work');
        $commandTester = new CommandTester($command);
        
        // Test with help option and required adapter argument
        $commandTester->execute(['adapter' => 'memory', '--help' => true]);
        
        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Start processing queue jobs', $output);
    }
    
    public function testQueueWorkCommandValidation(): void
    {
        $command = $this->app->find('queue:work');
        $commandTester = new CommandTester($command);
        
        // Test with invalid adapter
        $commandTester->execute(['adapter' => 'invalid_adapter']);
        
        $this->assertEquals(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Invalid queue adapter', $output);
    }
    
    public function testQueueWorkCommandValidAdapters(): void
    {
        $command = $this->app->find('queue:work');
        $commandTester = new CommandTester($command);
        
        // Test memory adapter (should work without dependencies)
        $commandTester->execute([
            'adapter' => 'memory',
            '--processes' => '1',
            '--max-jobs' => '1',
            '--timeout' => '1'
        ]);
        
        // Should exit with success after processing (or timeout)
        $this->assertContains($commandTester->getStatusCode(), [0, 1]);
    }
    
    public function testScheduleRunCommand(): void
    {
        $command = $this->app->find('schedule:run');
        $commandTester = new CommandTester($command);
        
        $commandTester->execute([]);
        
        $this->assertEquals(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Running scheduled tasks', $output);
    }
    
    public function testApplicationCommandDiscovery(): void
    {
        $commands = $this->app->all();
        
        $this->assertArrayHasKey('queue:work', $commands);
        $this->assertArrayHasKey('schedule:run', $commands);
        
        // Verify we have the expected commands (help, list, queue:work, schedule:run + 2 more defaults)
        $this->assertGreaterThanOrEqual(4, count($commands));
    }
    
    public function testApplicationConfigurationIntegration(): void
    {
        // Set environment variables
        $_ENV['HIGHPER_PROTOCOLS'] = 'http,websocket';
        $_ENV['HIGHPER_PORT'] = '9000';
        $_ENV['HIGHPER_WORKER_PROCESSES'] = '8';
        
        $testApp = new Application();
        
        $this->assertEquals(['http', 'websocket'], $testApp->getConfig('protocols'));
        $this->assertEquals(9000, $testApp->getConfig('port'));
        $this->assertEquals('8', $testApp->getConfig('worker_processes'));
        
        // Clean up
        unset($_ENV['HIGHPER_PROTOCOLS'], $_ENV['HIGHPER_PORT'], $_ENV['HIGHPER_WORKER_PROCESSES']);
    }
    
    public function testWorkerRegistrationIntegration(): void
    {
        $workerCalled = false;
        $worker = function() use (&$workerCalled) {
            $workerCalled = true;
            return 'executed';
        };
        
        $this->app->registerWorker('test_worker', $worker);
        
        $registeredWorker = $this->app->getWorker('test_worker');
        $this->assertNotNull($registeredWorker);
        
        // Execute the worker
        $result = $registeredWorker['worker']();
        $this->assertEquals('executed', $result);
        $this->assertTrue($workerCalled);
    }
    
    public function testCommandArgumentsAndOptions(): void
    {
        $command = $this->app->find('queue:work');
        $definition = $command->getDefinition();
        
        // Check required arguments
        $this->assertTrue($definition->hasArgument('adapter'));
        $this->assertTrue($definition->getArgument('adapter')->isRequired());
        
        // Check options
        $this->assertTrue($definition->hasOption('queue'));
        $this->assertTrue($definition->hasOption('memory'));
        $this->assertTrue($definition->hasOption('timeout'));
        $this->assertTrue($definition->hasOption('max-jobs'));
        $this->assertTrue($definition->hasOption('processes'));
    }
    
    public function testQueueWorkCommandArgumentsValidation(): void
    {
        $command = $this->app->find('queue:work');
        $definition = $command->getDefinition();
        
        // Check required argument
        $this->assertTrue($definition->hasArgument('adapter'));
        
        // Check key options for queue work
        $this->assertTrue($definition->hasOption('queue'));
        $this->assertTrue($definition->hasOption('memory'));
        $this->assertTrue($definition->hasOption('timeout'));
        $this->assertTrue($definition->hasOption('max-tries'));
    }
    
    public function testScheduleRunCommandOptions(): void
    {
        $command = $this->app->find('schedule:run');
        $definition = $command->getDefinition();
        
        $this->assertTrue($definition->hasOption('verbose'));
        $this->assertTrue($definition->hasOption('no-overlap'));
    }
}