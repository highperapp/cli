# HighPer CLI

CLI runtime for cron jobs, queue workers, and custom commands for HighPer Framework.

## Features

- 🚀 **CLI Command Interface**: Command-line interface for HighPer Framework operations
- ⏰ **Advanced Task Scheduling**: Cron-like job scheduling with overlap prevention
- 🔄 **Memory-Optimized Queue Workers**: Multi-process queue processing with automatic restarts
- 📊 **Real-time Monitoring**: Comprehensive status reporting and health checks
- 🎯 **Command Auto-Discovery**: Automatic command registration and management
- 🔧 **Environment Configuration**: Flexible configuration management
- 🔒 **Process Control**: Signal handling and graceful shutdowns
- 📈 **Performance Optimization**: C10M support with Rust FFI acceleration

## Installation

```bash
composer require highperapp/cli
```

## Queue Worker Management

```bash
# Start single queue worker
bin/highper queue:work redis --queue=default --memory=128M

# Start multiple workers
bin/highper queue:work redis --processes=4 --max-jobs=1000

# Worker with timeout and retry configuration
bin/highper queue:work redis --timeout=3600 --max-tries=3 --delay=60
```

### Task Scheduling

```bash
# Run scheduled tasks
bin/highper schedule:run

# Run with overlap prevention
bin/highper schedule:run --no-overlap --verbose
```

## Programming Interface

### Application Setup

```php
<?php
use HighPerApp\HighPer\CLI\Application;

$app = new Application('HighPer App', '1.0.0');

// Auto-discover commands
$app->discoverCommands(__DIR__ . '/commands');

// Register workers
$app->registerWorker('email_processor', function($data) {
    // Process email job
    return "Email sent to: {$data['email']}";
}, [
    'memory_limit' => '256M',
    'timeout' => 1800,
    'max_jobs' => 500
]);
```

### Queue Workers

```php
use HighPerApp\HighPer\CLI\Workers\QueueWorker;

$worker = new QueueWorker([
    'adapter' => 'redis',
    'queue' => 'high_priority',
    'memory_limit' => '128M',
    'timeout' => 3600,
    'max_jobs' => 1000,
    'sleep_on_empty' => 5,
    'max_tries' => 3
]);

// Start with status callback
$worker->work(function($status) {
    switch ($status['type']) {
        case 'job_completed':
            echo "Job completed: {$status['job_class']}\n";
            break;
        case 'job_failed':
            echo "Job failed: {$status['error']}\n";
            break;
    }
});
```

### Task Scheduling

```php
use HighPerApp\HighPer\CLI\Schedulers\TaskScheduler;

$scheduler = new TaskScheduler([
    'prevent_overlap' => true,
    'lock_path' => '/tmp/scheduler-locks'
]);

// Schedule command execution
$scheduler->command('backup:database')
          ->daily()
          ->withoutOverlapping()
          ->environments(['production']);

// Schedule callable execution
$scheduler->call(function() {
    // Cleanup temporary files
    return "Cleanup completed";
})->everyFiveMinutes();

// Schedule job class execution
$scheduler->job('App\Jobs\SendNewsletter')
          ->weekly()
          ->timeout(3600)
          ->description('Send weekly newsletter');

// Run due tasks
$results = $scheduler->runDueTasks();
```

### Custom Commands

```php
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BackupCommand extends Command
{
    protected static $defaultName = 'backup:create';
    
    protected function configure(): void
    {
        $this->setDescription('Create application backup');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Creating backup...');
        
        // Backup logic here
        
        $output->writeln('Backup completed successfully!');
        return Command::SUCCESS;
    }
}
```

## Testing

The package includes comprehensive test suites:

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test suites
vendor/bin/phpunit --testsuite=Unit
vendor/bin/phpunit --testsuite=Integration
vendor/bin/phpunit --testsuite=Performance
vendor/bin/phpunit --testsuite=Concurrency

# Generate coverage report
vendor/bin/phpunit --coverage-html=coverage/html
```

## Requirements

- **PHP 8.3+** (Required)
- **PHP 8.4** (Supported)
- Symfony Console ^6.0
- cron/cron ^3.0
- Optional extensions:
  - `ext-pcntl` - Process control for multi-worker support
  - `ext-posix` - POSIX functions for Unix process management
  - `ext-redis` - Redis queue adapter support

## Environment Configuration

```bash
# Application environment
APP_ENV=production

# Queue worker configuration  
QUEUE_CONNECTION=redis
QUEUE_DEFAULT=default
```

## License

MIT