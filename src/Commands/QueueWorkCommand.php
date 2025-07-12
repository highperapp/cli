<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use HighPerApp\HighPer\CLI\Workers\QueueWorker;

/**
 * Queue Work Command
 * 
 * Memory-optimized queue worker for background job processing
 * with support for multiple queue adapters and graceful shutdown.
 */
class QueueWorkCommand extends Command
{
    protected static $defaultName = 'queue:work';
    protected static $defaultDescription = 'Start processing jobs from the queue';
    
    protected function configure(): void
    {
        $this
            ->addArgument('adapter', InputArgument::REQUIRED, 'Queue adapter (redis, rabbitmq, sqs, memory)')
            ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name to process', 'default')
            ->addOption('memory', 'm', InputOption::VALUE_OPTIONAL, 'Memory limit for worker', '128M')
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Worker timeout in seconds', '3600')
            ->addOption('max-jobs', 'j', InputOption::VALUE_OPTIONAL, 'Maximum jobs before restart', '1000')
            ->addOption('sleep', 's', InputOption::VALUE_OPTIONAL, 'Sleep time when queue is empty (seconds)', '5')
            ->addOption('processes', 'p', InputOption::VALUE_OPTIONAL, 'Number of worker processes', '1')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Delay failed jobs by seconds', '0')
            ->addOption('max-tries', null, InputOption::VALUE_OPTIONAL, 'Maximum job retry attempts', '3')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force worker to run even in maintenance mode');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $adapter = $input->getArgument('adapter');
        $queue = $input->getOption('queue');
        $processes = (int)$input->getOption('processes');
        
        $io->title("HighPer Queue Worker - {$adapter}:{$queue}");
        
        // Validate adapter
        if (!$this->validateAdapter($adapter, $io)) {
            return Command::FAILURE;
        }
        
        // Build worker configuration
        $config = [
            'adapter' => $adapter,
            'queue' => $queue,
            'memory_limit' => $input->getOption('memory'),
            'timeout' => (int)$input->getOption('timeout'),
            'max_jobs' => (int)$input->getOption('max-jobs'),
            'sleep_on_empty' => (int)$input->getOption('sleep'),
            'delay_failed_jobs' => (int)$input->getOption('delay'),
            'max_tries' => (int)$input->getOption('max-tries'),
            'force' => $input->getOption('force')
        ];
        
        $this->displayWorkerConfig($io, $config);
        
        try {
            if ($processes > 1) {
                return $this->runMultipleWorkers($io, $config, $processes);
            } else {
                return $this->runSingleWorker($io, $config);
            }
        } catch (\Throwable $e) {
            $io->error('Worker failed: ' . $e->getMessage());
            
            if ($io->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }
    
    /**
     * Run single queue worker
     */
    protected function runSingleWorker(SymfonyStyle $io, array $config): int
    {
        $worker = new QueueWorker($config);
        
        // Set up signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$worker, 'stop']);
            pcntl_signal(SIGINT, [$worker, 'stop']);
        }
        
        $io->info('Starting queue worker...');
        
        // Start worker
        $worker->work(function ($status) use ($io) {
            $this->outputWorkerStatus($io, $status);
        });
        
        $io->success('Queue worker stopped gracefully');
        
        return Command::SUCCESS;
    }
    
    /**
     * Run multiple queue workers
     */
    protected function runMultipleWorkers(SymfonyStyle $io, array $config, int $processes): int
    {
        if (!function_exists('pcntl_fork')) {
            $io->error('Multiple workers require pcntl extension');
            return Command::FAILURE;
        }
        
        $io->info("Starting {$processes} queue workers...");
        
        $workers = [];
        
        for ($i = 0; $i < $processes; $i++) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $io->error('Failed to fork worker process');
                return Command::FAILURE;
            } elseif ($pid === 0) {
                // Child process
                $workerConfig = array_merge($config, ['worker_id' => $i + 1]);
                $worker = new QueueWorker($workerConfig);
                
                $worker->work(function ($status) use ($io, $i) {
                    $this->outputWorkerStatus($io, $status, $i + 1);
                });
                
                exit(0);
            } else {
                // Parent process
                $workers[] = $pid;
            }
        }
        
        // Wait for all workers
        foreach ($workers as $pid) {
            pcntl_waitpid($pid, $status);
        }
        
        $io->success('All queue workers stopped');
        
        return Command::SUCCESS;
    }
    
    /**
     * Validate queue adapter
     */
    protected function validateAdapter(string $adapter, SymfonyStyle $io): bool
    {
        $validAdapters = ['redis', 'rabbitmq', 'sqs', 'memory', 'database'];
        
        if (!in_array($adapter, $validAdapters)) {
            $io->error("Invalid queue adapter: {$adapter}");
            $io->writeln('Valid adapters: ' . implode(', ', $validAdapters));
            return false;
        }
        
        // Check adapter dependencies
        switch ($adapter) {
            case 'redis':
                if (!extension_loaded('redis') && !class_exists('Predis\\Client')) {
                    $io->error('Redis adapter requires redis extension or predis/predis package');
                    return false;
                }
                break;
                
            case 'rabbitmq':
                if (!class_exists('PhpAmqpLib\\Connection\\AMQPStreamConnection')) {
                    $io->error('RabbitMQ adapter requires php-amqplib/php-amqplib package');
                    return false;
                }
                break;
        }
        
        return true;
    }
    
    /**
     * Display worker configuration
     */
    protected function displayWorkerConfig(SymfonyStyle $io, array $config): void
    {
        $rows = [
            ['Adapter', $config['adapter']],
            ['Queue', $config['queue']],
            ['Memory Limit', $config['memory_limit']],
            ['Timeout', $config['timeout'] . 's'],
            ['Max Jobs', $config['max_jobs']],
            ['Sleep on Empty', $config['sleep_on_empty'] . 's'],
            ['Max Tries', $config['max_tries']],
            ['Delay Failed Jobs', $config['delay_failed_jobs'] . 's']
        ];
        
        $io->table(['Setting', 'Value'], $rows);
    }
    
    /**
     * Output worker status
     */
    protected function outputWorkerStatus(SymfonyStyle $io, array $status, ?int $workerId = null): void
    {
        $prefix = $workerId ? "[Worker {$workerId}] " : '';
        
        switch ($status['type']) {
            case 'job_started':
                $io->writeln("<info>{$prefix}Processing job: {$status['job_class']}</info>");
                break;
                
            case 'job_completed':
                $duration = round($status['duration'] * 1000, 2);
                $io->writeln("<info>{$prefix}Completed job: {$status['job_class']} ({$duration}ms)</info>");
                break;
                
            case 'job_failed':
                $io->writeln("<error>{$prefix}Failed job: {$status['job_class']} - {$status['error']}</error>");
                break;
                
            case 'queue_empty':
                $io->writeln("<comment>{$prefix}Queue empty, sleeping for {$status['sleep']}s</comment>");
                break;
                
            case 'memory_limit':
                $io->writeln("<warning>{$prefix}Memory limit reached, restarting worker</warning>");
                break;
                
            case 'max_jobs':
                $io->writeln("<info>{$prefix}Max jobs processed, restarting worker</info>");
                break;
                
            case 'shutdown':
                $io->writeln("<info>{$prefix}Graceful shutdown initiated</info>");
                break;
        }
    }
}