<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use HighPerApp\HighPer\CLI\Schedulers\TaskScheduler;

/**
 * Schedule Run Command
 * 
 * Runs scheduled tasks defined in the application's schedule.
 */
class ScheduleRunCommand extends Command
{
    protected static $defaultName = 'schedule:run';
    protected static $defaultDescription = 'Run scheduled tasks';
    
    protected function configure(): void
    {
        $this
            ->addOption('no-overlap', null, InputOption::VALUE_NONE, 'Prevent overlapping scheduled tasks');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $scheduler = new TaskScheduler([
            'prevent_overlap' => $input->getOption('no-overlap'),
            'verbose' => $output->isVerbose()
        ]);
        
        // Load scheduled tasks
        $this->loadScheduledTasks($scheduler);
        
        $io->info('Running scheduled tasks...');
        
        try {
            $results = $scheduler->runDueTasks();
            
            if (empty($results)) {
                $io->writeln('<comment>No scheduled tasks due to run</comment>');
                return Command::SUCCESS;
            }
            
            foreach ($results as $result) {
                $this->displayTaskResult($io, $result);
            }
            
            $successCount = count(array_filter($results, fn($r) => $r['success']));
            $totalCount = count($results);
            
            $io->success("Ran {$successCount}/{$totalCount} scheduled tasks successfully");
            
        } catch (\Throwable $e) {
            $io->error('Scheduler failed: ' . $e->getMessage());
            
            if ($io->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Load scheduled tasks from application
     */
    protected function loadScheduledTasks(TaskScheduler $scheduler): void
    {
        $schedulePath = getcwd() . '/app/Console/Kernel.php';
        
        if (file_exists($schedulePath)) {
            require_once $schedulePath;
            
            if (class_exists('App\\Console\\Kernel')) {
                $kernel = new \App\Console\Kernel();
                if (method_exists($kernel, 'schedule')) {
                    $kernel->schedule($scheduler);
                }
            }
        }
        
        // Alternative: Load from config file
        $configPath = getcwd() . '/config/schedule.php';
        if (file_exists($configPath)) {
            $scheduleConfig = require $configPath;
            
            foreach ($scheduleConfig as $taskConfig) {
                $scheduler->scheduleFromConfig($taskConfig);
            }
        }
    }
    
    /**
     * Display task execution result
     */
    protected function displayTaskResult(SymfonyStyle $io, array $result): void
    {
        $status = $result['success'] ? 'SUCCESS' : 'FAILED';
        $duration = round($result['duration'] * 1000, 2);
        
        $message = sprintf(
            '[%s] %s (%sms)',
            $status,
            $result['description'],
            $duration
        );
        
        if ($result['success']) {
            $io->writeln("<info>{$message}</info>");
        } else {
            $io->writeln("<error>{$message}</error>");
            if (isset($result['error'])) {
                $io->writeln("  Error: {$result['error']}");
            }
        }
        
        if (isset($result['output']) && !empty($result['output'])) {
            $io->writeln("  Output: {$result['output']}");
        }
    }
}