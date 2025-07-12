<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI;

use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\Finder;
use ReflectionClass;

/**
 * HighPer CLI Application
 * 
 * Enhanced console application with command auto-discovery and
 * memory-optimized process management for CLI workers.
 */
class Application extends ConsoleApplication
{
    protected array $config = [];
    protected array $workers = [];
    
    public function __construct(string $name = 'HighPer Framework', string $version = '1.0.0')
    {
        parent::__construct($name, $version);
        
        $this->loadConfiguration();
    }
    
    /**
     * Auto-discover commands in directory
     */
    public function discoverCommands(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        
        $finder = new Finder();
        $finder->files()->name('*Command.php')->in($directory);
        
        foreach ($finder as $file) {
            $className = $this->getClassNameFromFile($file->getRealPath());
            
            if ($className && class_exists($className)) {
                $reflection = new ReflectionClass($className);
                
                if ($reflection->isSubclassOf(Command::class) && !$reflection->isAbstract()) {
                    $this->add(new $className());
                }
            }
        }
    }
    
    /**
     * Register worker process
     */
    public function registerWorker(string $name, callable $worker, array $options = []): void
    {
        $this->workers[$name] = [
            'worker' => $worker,
            'options' => array_merge([
                'memory_limit' => '128M',
                'timeout' => 3600,
                'max_jobs' => 1000,
                'sleep_on_empty' => 5
            ], $options)
        ];
    }
    
    /**
     * Get registered worker
     */
    public function getWorker(string $name): ?array
    {
        return $this->workers[$name] ?? null;
    }
    
    /**
     * Get all workers
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }
    
    /**
     * Get configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Set configuration value
     */
    public function setConfig(string $key, $value): void
    {
        $this->config[$key] = $value;
    }
    
    /**
     * Load configuration from environment and files
     */
    protected function loadConfiguration(): void
    {
        // Load from environment variables
        $this->config = [
            'app_env' => $_ENV['APP_ENV'] ?? ($_ENV['PHPUNIT_RUNNING'] === 'true' ? 'testing' : 'production'),
            'protocols' => explode(',', $_ENV['HIGHPER_PROTOCOLS'] ?? 'http'),
            'port' => (int)($_ENV['HIGHPER_PORT'] ?? 8080),
            'mode' => $_ENV['HIGHPER_MODE'] ?? 'multiplexed',
            'worker_processes' => $_ENV['HIGHPER_WORKER_PROCESSES'] ?? 'auto',
            'max_connections' => (int)($_ENV['HIGHPER_MAX_CONNECTIONS'] ?? 10000),
            'memory_limit' => $_ENV['HIGHPER_MEMORY_LIMIT'] ?? '512M',
            'rust_ffi' => $_ENV['HIGHPER_RUST_FFI'] ?? 'auto',
            'crypto_engine' => $_ENV['HIGHPER_CRYPTO_ENGINE'] ?? 'auto',
            'json_parser' => $_ENV['HIGHPER_JSON_PARSER'] ?? 'auto'
        ];
        
        // Load from config file if exists
        $configFile = getcwd() . '/config/app.php';
        if (file_exists($configFile)) {
            $fileConfig = require $configFile;
            $this->config = array_merge($this->config, $fileConfig);
        }
    }
    
    /**
     * Extract class name from file path
     */
    protected function getClassNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        
        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            $namespace = $namespaceMatches[1];
        } else {
            $namespace = '';
        }
        
        // Extract class name
        if (preg_match('/class\s+(\w+)/', $content, $classMatches)) {
            $className = $classMatches[1];
            return $namespace ? $namespace . '\\' . $className : $className;
        }
        
        return null;
    }
}