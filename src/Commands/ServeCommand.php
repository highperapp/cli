<?php

declare(strict_types=1);

namespace HighPerApp\HighPer\CLI\Commands;

use HighPerApp\HighPer\Framework\Core\Deployment\ProductionDeploymentManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use HighPerApp\HighPer\Framework\Core\Application as HighPerApp;

/**
 * Production-Ready Serve Command
 * 
 * Comprehensive CLI command for production deployments:
 * - Single-port multiplexing vs dedicated ports
 * - Multi-process worker management
 * - Load balancing configuration
 * - Protocol-specific optimizations
 * - Environment-specific settings
 */
class ServeCommand extends Command
{
    protected static $defaultName = 'serve';
    protected static $defaultDescription = 'Start the HighPer production server';

    private ProductionDeploymentManager $deploymentManager;

    public function __construct()
    {
        parent::__construct();
        $this->deploymentManager = new ProductionDeploymentManager();
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Start the HighPer production server with advanced deployment options')
            ->addOption('port', 'p', InputOption::VALUE_OPTIONAL, 'Primary server port', 8080)
            ->addOption('workers', 'w', InputOption::VALUE_OPTIONAL, 'Number of worker processes', 4)
            ->addOption('env', 'e', InputOption::VALUE_OPTIONAL, 'Environment mode', 'production')
            
            // Deployment Modes
            ->addOption('mode', 'm', InputOption::VALUE_OPTIONAL, 'Deployment mode (single_port_multiplexing|dedicated_ports|hybrid)', 'single_port_multiplexing')
            ->addOption('protocols', null, InputOption::VALUE_OPTIONAL, 'Enabled protocols (comma-separated)', 'http,websocket')
            
            // Protocol-Specific Ports (Dedicated Mode)
            ->addOption('http-port', null, InputOption::VALUE_OPTIONAL, 'HTTP server port', 8080)
            ->addOption('ws-port', null, InputOption::VALUE_OPTIONAL, 'WebSocket server port', 8081)
            ->addOption('grpc-port', null, InputOption::VALUE_OPTIONAL, 'gRPC server port', 8082)
            ->addOption('tcp-port', null, InputOption::VALUE_OPTIONAL, 'TCP server port', 8083)
            
            // Load Balancing
            ->addOption('load-balancer', 'lb', InputOption::VALUE_OPTIONAL, 'Load balancing strategy', 'round_robin')
            ->addOption('max-connections', null, InputOption::VALUE_OPTIONAL, 'Max connections per worker', 10000)
            
            // Performance Options
            ->addOption('c10m', null, InputOption::VALUE_NONE, 'Enable C10M optimization')
            ->addOption('rust', null, InputOption::VALUE_OPTIONAL, 'Rust FFI acceleration', 'auto')
            ->addOption('memory-limit', null, InputOption::VALUE_OPTIONAL, 'Memory limit per worker', '512M')
            
            // Security Options
            ->addOption('tls', null, InputOption::VALUE_NONE, 'Enable TLS/SSL')
            ->addOption('security-headers', null, InputOption::VALUE_NONE, 'Enable security headers')
            
            // Monitoring Options
            ->addOption('metrics', null, InputOption::VALUE_NONE, 'Enable metrics collection')
            ->addOption('health-check', null, InputOption::VALUE_NONE, 'Enable health check endpoint')
            
            // Development Options
            ->addOption('debug', 'd', InputOption::VALUE_NONE, 'Enable debug mode')
            ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Configuration file path');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('HighPer Framework Server');
        
        // Load environment configuration
        if ($envFile = $input->getOption('env')) {
            if (file_exists($envFile)) {
                $this->loadEnvironmentFile($envFile);
            } else {
                $io->error("Environment file not found: {$envFile}");
                return Command::FAILURE;
            }
        }
        
        // Determine server configuration
        $config = $this->buildServerConfig($input, $io);
        
        if (!$config) {
            return Command::FAILURE;
        }
        
        // Display server configuration
        $this->displayConfiguration($io, $config);
        
        try {
            // Create and start HighPer application
            $app = HighPerApp::create($config);
            
            // Load application routes and services
            $this->loadApplication($app);
            
            $io->success('Server starting...');
            
            // Start the server
            $app->run($config['port'] ?? 8080);
            
        } catch (\Throwable $e) {
            $io->error('Server failed to start: ' . $e->getMessage());
            
            if ($io->isVerbose()) {
                $io->writeln($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Build server configuration from input options and environment
     */
    protected function buildServerConfig(InputInterface $input, SymfonyStyle $io): ?array
    {
        $config = [];
        
        // Determine protocols
        if ($protocols = $input->getOption('protocols')) {
            $config['protocols'] = explode(',', $protocols);
        } else {
            $config['protocols'] = explode(',', $_ENV['HIGHPER_PROTOCOLS'] ?? 'http');
        }
        
        // Validate protocols
        $validProtocols = ['http', 'ws', 'grpc', 'tcp'];
        foreach ($config['protocols'] as $protocol) {
            if (!in_array(trim($protocol), $validProtocols)) {
                $io->error("Invalid protocol: {$protocol}. Valid protocols: " . implode(', ', $validProtocols));
                return null;
            }
        }
        
        // Determine server mode
        if ($input->getOption('dedicated-ports')) {
            $config['mode'] = 'dedicated-ports';
            
            // Configure dedicated ports
            $config['ports'] = [
                'http' => (int)($input->getOption('http') ?? $_ENV['HIGHPER_HTTP_PORT'] ?? 8080),
                'ws' => (int)($input->getOption('ws') ?? $_ENV['HIGHPER_WS_PORT'] ?? 8081),
                'grpc' => (int)($input->getOption('grpc') ?? $_ENV['HIGHPER_GRPC_PORT'] ?? 9090),
                'tcp' => (int)($input->getOption('tcp') ?? $_ENV['HIGHPER_TCP_PORT'] ?? 8082)
            ];
        } else {
            $config['mode'] = 'multiplexed';
            $config['port'] = (int)($input->getOption('port') ?? $_ENV['HIGHPER_PORT'] ?? 8080);
        }
        
        // Worker configuration
        $config['workers'] = $input->getOption('workers') ?? $_ENV['HIGHPER_WORKER_PROCESSES'] ?? 'auto';
        $config['memory_limit'] = $input->getOption('memory') ?? $_ENV['HIGHPER_MEMORY_LIMIT'] ?? '512M';
        
        // Additional configuration from environment
        $config['max_connections'] = (int)($_ENV['HIGHPER_MAX_CONNECTIONS'] ?? 10000);
        $config['rust_ffi'] = $_ENV['HIGHPER_RUST_FFI'] ?? 'auto';
        
        return $config;
    }
    
    /**
     * Display server configuration
     */
    protected function displayConfiguration(SymfonyStyle $io, array $config): void
    {
        $rows = [
            ['Protocols', implode(', ', $config['protocols'])],
            ['Mode', $config['mode']],
            ['Workers', $config['workers']],
            ['Memory Limit', $config['memory_limit']],
            ['Max Connections', number_format($config['max_connections'])],
            ['Rust FFI', $config['rust_ffi']]
        ];
        
        if ($config['mode'] === 'multiplexed') {
            $rows[] = ['Port', $config['port']];
        } else {
            foreach ($config['protocols'] as $protocol) {
                if (isset($config['ports'][$protocol])) {
                    $rows[] = [ucfirst($protocol) . ' Port', $config['ports'][$protocol]];
                }
            }
        }
        
        $io->table(['Setting', 'Value'], $rows);
    }
    
    /**
     * Load application routes and services
     */
    protected function loadApplication(HighPerApp $app): void
    {
        // Load routes
        $routesPath = getcwd() . '/routes';
        if (is_dir($routesPath)) {
            if (file_exists($routesPath . '/web.php')) {
                require $routesPath . '/web.php';
            }
            if (file_exists($routesPath . '/api.php')) {
                require $routesPath . '/api.php';
            }
        }
        
        // Load service providers
        $configPath = getcwd() . '/config/app.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
            if (isset($config['providers'])) {
                foreach ($config['providers'] as $provider) {
                    $app->register(new $provider());
                }
            }
        }
    }
    
    /**
     * Load environment file
     */
    protected function loadEnvironmentFile(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value, '"\'');
            }
        }
    }
}