<?php

declare(strict_types=1);

// Set timezone for consistent test results
date_default_timezone_set('UTC');

// Set environment for testing
$_ENV['APP_ENV'] = 'testing';
$_ENV['PHPUNIT_RUNNING'] = 'true';

// Ensure composer autoloader is loaded
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../autoload.php'
];

$autoloaderFound = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    // Manual autoloader for development/testing without composer
    spl_autoload_register(function (string $class): void {
        // Handle HighPerApp\HighPer\CLI namespace
        if (strpos($class, 'HighPerApp\\HighPer\\CLI\\') === 0) {
            $relativePath = str_replace('HighPerApp\\HighPer\\CLI\\', '', $class);
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath);
            
            // Check if it's a test class
            if (strpos($relativePath, 'Tests\\') === 0) {
                $filePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('Tests\\', '', $relativePath) . '.php';
            } else {
                $filePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relativePath . '.php';
            }
            
            if (file_exists($filePath)) {
                require_once $filePath;
            }
        }
    });
}

// Create necessary directories for test artifacts
$testDirectories = [
    __DIR__ . '/results',
    __DIR__ . '/../coverage',
    __DIR__ . '/../coverage/html',
    __DIR__ . '/../coverage/xml'
];

foreach ($testDirectories as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

// Set memory limit for tests
ini_set('memory_limit', '512M');

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Disable output buffering for tests
if (ob_get_level()) {
    ob_end_clean();
}

// Helper function for test isolation
function cleanupTestEnvironment(): void
{
    // Clean up any global state that might affect tests
    $_ENV = array_filter($_ENV, function($key) {
        return !str_starts_with($key, 'TEST_') && 
               !str_starts_with($key, 'HIGHPER_') ||
               in_array($key, ['APP_ENV', 'PHPUNIT_RUNNING']);
    }, ARRAY_FILTER_USE_KEY);
}

// Register cleanup function
register_shutdown_function('cleanupTestEnvironment');

// Test helper functions
if (!function_exists('createTempFile')) {
    function createTempFile(string $prefix = 'test_', string $suffix = '.tmp'): string
    {
        return tempnam(sys_get_temp_dir(), $prefix) . $suffix;
    }
}

if (!function_exists('cleanupTempFile')) {
    function cleanupTempFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}

if (!function_exists('createTempDir')) {
    function createTempDir(string $prefix = 'test_dir_'): string
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        mkdir($tempDir, 0755, true);
        return $tempDir;
    }
}

if (!function_exists('cleanupTempDir')) {
    function cleanupTempDir(string $dirPath): void
    {
        if (is_dir($dirPath)) {
            $files = glob($dirPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                } elseif (is_dir($file)) {
                    cleanupTempDir($file);
                }
            }
            rmdir($dirPath);
        }
    }
}

// Mock classes for testing
if (!class_exists('MockTestJob')) {
    class MockTestJob
    {
        public function handle(array $data = []): string
        {
            return 'Mock job executed with: ' . json_encode($data);
        }
    }
}

if (!class_exists('FailingMockTestJob')) {
    class FailingMockTestJob
    {
        public function handle(array $data = []): never
        {
            throw new RuntimeException('Mock job failure');
        }
    }
}

// Check for required extensions for different test suites
$requiredExtensions = [
    'pcntl' => 'Required for concurrency tests',
    'posix' => 'Required for some concurrency and signal handling tests'
];

$missingExtensions = [];
foreach ($requiredExtensions as $extension => $description) {
    if (!extension_loaded($extension)) {
        $missingExtensions[] = "{$extension}: {$description}";
    }
}

if (!empty($missingExtensions)) {
    echo "Warning: Missing optional extensions for some tests:\n";
    foreach ($missingExtensions as $missing) {
        echo "  - {$missing}\n";
    }
    echo "\n";
}

// Set up test-specific configuration
if (!defined('TESTING_STARTED')) {
    define('TESTING_STARTED', microtime(true));
}

echo "Test bootstrap completed successfully.\n";
echo "Test environment: " . ($_ENV['APP_ENV'] ?? 'unknown') . "\n";
echo "Available test suites: Unit, Integration, Performance, Concurrency\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "\n";