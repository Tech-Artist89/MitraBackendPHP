<?php
declare(strict_types=1);

// Error Reporting für Development
if ($_ENV['APP_ENV'] ?? 'development' === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Environment laden
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use MitraSanitaer\Config\Config;
use MitraSanitaer\Controllers\ApiController;
use MitraSanitaer\Middleware\CorsMiddleware;
use MitraSanitaer\Middleware\RateLimitMiddleware;
use MitraSanitaer\Utils\Logger;

// Error Handler
set_error_handler(function ($severity, $message, $file, $line) {
    Logger::error("PHP Error: $message", [
        'file' => $file,
        'line' => $line,
        'severity' => $severity
    ]);
});

set_exception_handler(function ($exception) {
    Logger::error("Uncaught Exception: " . $exception->getMessage(), [
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => Config::get('APP_ENV') === 'production' 
            ? 'Ein interner Serverfehler ist aufgetreten.' 
            : $exception->getMessage(),
        'timestamp' => date('c')
    ]);
});

// CORS Middleware
CorsMiddleware::handle();

// Request Method und Path ermitteln
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// API Prefix entfernen
if (strpos($path, '/api') === 0) {
    $path = substr($path, 4);
}

// Rate Limiting für API Calls
if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    RateLimitMiddleware::handle();
}

// Content-Type für JSON APIs setzen
if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    header('Content-Type: application/json; charset=utf-8');
}

// Router
$controller = new ApiController();

try {
    // Basis Health Check
    if ($path === '/health' && $method === 'GET') {
        $controller->healthCheck();
        exit;
    }
    
    // Kontaktformular
    if ($path === '/contact' && $method === 'POST') {
        $controller->contact();
        exit;
    }
    
    // Badkonfigurator
    if ($path === '/send-bathroom-configuration' && $method === 'POST') {
        $controller->sendBathroomConfiguration();
        exit;
    }
    
    // PDF Test
    if ($path === '/generate-pdf-only' && $method === 'POST') {
        $controller->generatePdfOnly();
        exit;
    }
    
    // Debug PDFs
    if ($path === '/debug-pdfs' && $method === 'GET') {
        $controller->debugPdfs();
        exit;
    }
    
    if ($path === '/debug-pdfs' && $method === 'DELETE') {
        $controller->clearDebugPdfs();
        exit;
    }
    
    // Debug PDF Download
    if (preg_match('/^\/debug\/pdfs\/(.+\.pdf)$/', $path, $matches) && $method === 'GET') {
        $controller->downloadDebugPdf($matches[1]);
        exit;
    }
    
    // 404 für API Routes
    if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Route nicht gefunden',
            'path' => $path,
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    // Frontend Index für alle anderen Routes
    $indexPath = __DIR__ . '/index.html';
    if (file_exists($indexPath)) {
        readfile($indexPath);
    } else {
        // Fallback wenn kein Frontend deployed ist
        echo json_encode([
            'message' => 'Mitra Sanitär Backend API',
            'version' => '1.0.0',
            'endpoints' => [
                'health' => '/api/health',
                'contact' => '/api/contact',
                'bathroom' => '/api/send-bathroom-configuration',
                'pdf-test' => '/api/generate-pdf-only',
                'debug-pdfs' => '/api/debug-pdfs'
            ],
            'documentation' => 'See README.md'
        ]);
    }
    
} catch (Exception $e) {
    Logger::error("Router Exception: " . $e->getMessage(), [
        'path' => $path,
        'method' => $method,
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => Config::get('APP_ENV') === 'production' 
            ? 'Ein interner Serverfehler ist aufgetreten.' 
            : $e->getMessage(),
        'timestamp' => date('c')
    ]);
}