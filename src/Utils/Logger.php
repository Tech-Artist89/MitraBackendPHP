<?php
declare(strict_types=1);

namespace MitraSanitaer\Utils;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use MitraSanitaer\Config\Config;

class Logger
{
    private static ?MonologLogger $instance = null;
    
    /**
     * Logger Singleton initialisieren
     */
    private static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = new MonologLogger('mitra-sanitaer-backend');
            
            // Log Level bestimmen
            $logLevel = match(Config::get('LOG_LEVEL', 'info')) {
                'debug' => MonologLogger::DEBUG,
                'info' => MonologLogger::INFO,
                'warning' => MonologLogger::WARNING,
                'error' => MonologLogger::ERROR,
                default => MonologLogger::INFO
            };
            
            // Custom Formatter
            $formatter = new LineFormatter(
                "[%datetime%] [%level_name%]: %message% %context%\n",
                'Y-m-d H:i:s'
            );
            
            // Console Handler (nur in Development)
            if (Config::get('APP_ENV') !== 'production') {
                $consoleHandler = new StreamHandler('php://stdout', $logLevel);
                $consoleHandler->setFormatter($formatter);
                self::$instance->pushHandler($consoleHandler);
            }
            
            // Verzeichnisse erstellen
            $logsDir = __DIR__ . '/../../storage/logs';
            if (!is_dir($logsDir)) {
                mkdir($logsDir, 0755, true);
            }
            
            // Error Log File
            $errorHandler = new RotatingFileHandler(
                $logsDir . '/error.log',
                5,
                MonologLogger::ERROR
            );
            $errorHandler->setFormatter($formatter);
            self::$instance->pushHandler($errorHandler);
            
            // Combined Log File
            $combinedHandler = new RotatingFileHandler(
                $logsDir . '/combined.log',
                10,
                $logLevel
            );
            $combinedHandler->setFormatter($formatter);
            self::$instance->pushHandler($combinedHandler);
            
            // Production Log File
            if (Config::get('APP_ENV') === 'production') {
                $productionHandler = new RotatingFileHandler(
                    $logsDir . '/production.log',
                    20,
                    MonologLogger::INFO
                );
                $productionHandler->setFormatter($formatter);
                self::$instance->pushHandler($productionHandler);
            }
        }
        
        return self::$instance;
    }
    
    /**
     * Debug Log
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }
    
    /**
     * Info Log
     */
    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }
    
    /**
     * Warning Log
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }
    
    /**
     * Error Log
     */
    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }
    
    /**
     * E-Mail spezifisches Logging
     */
    public static function email(string $action, array $details = []): void
    {
        self::info("📧 Email {$action}", array_merge(['category' => 'email'], $details));
    }
    
    /**
     * PDF spezifisches Logging
     */
    public static function pdf(string $action, array $details = []): void
    {
        self::info("📄 PDF {$action}", array_merge(['category' => 'pdf'], $details));
    }
    
    /**
     * API spezifisches Logging
     */
    public static function api(string $endpoint, string $method, array $details = []): void
    {
        self::info("🔗 API {$method} {$endpoint}", array_merge([
            'category' => 'api',
            'endpoint' => $endpoint,
            'method' => $method
        ], $details));
    }
    
    /**
     * Security spezifisches Logging
     */
    public static function security(string $event, array $details = []): void
    {
        self::warning("🔒 Security Event: {$event}", array_merge([
            'category' => 'security',
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ], $details));
    }
    
    /**
     * Request Logging mit IP und User Agent
     */
    public static function request(string $method, string $path, array $details = []): void
    {
        self::info("📡 {$method} {$path}", array_merge([
            'category' => 'request',
            'method' => $method,
            'path' => $path,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'referer' => $_SERVER['HTTP_REFERER'] ?? null
        ], $details));
    }
    
    /**
     * Log Level zur Laufzeit ändern
     */
    public static function setLevel(string $level): void
    {
        $monologLevel = match($level) {
            'debug' => MonologLogger::DEBUG,
            'info' => MonologLogger::INFO,
            'warning' => MonologLogger::WARNING,
            'error' => MonologLogger::ERROR,
            default => MonologLogger::INFO
        };
        
        foreach (self::getInstance()->getHandlers() as $handler) {
            $handler->setLevel($monologLevel);
        }
    }
    
    /**
     * Log-Statistiken abrufen
     */
    public static function getStats(): array
    {
        $logsDir = __DIR__ . '/../../storage/logs';
        $stats = [
            'directory' => $logsDir,
            'files' => []
        ];
        
        if (is_dir($logsDir)) {
            $files = glob($logsDir . '/*.log');
            foreach ($files as $file) {
                $stats['files'][] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'modified' => filemtime($file),
                    'readable' => is_readable($file)
                ];
            }
        }
        
        return $stats;
    }
}