<?php
declare(strict_types=1);

namespace MitraSanitaer\Middleware;

use MitraSanitaer\Config\Config;

class CorsMiddleware
{
    /**
     * CORS Headers setzen
     */
    public static function handle(): void
    {
        // Erlaubte Origins
        $allowedOrigins = [
            Config::get('FRONTEND_URL', 'http://localhost:4200'),
            'http://localhost:4200',
            'http://127.0.0.1:4200',
            'http://localhost:8000',
            'http://127.0.0.1:8000'
        ];
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Origin prüfen und setzen
        if (in_array($origin, $allowedOrigins) || Config::get('APP_ENV') === 'development') {
            header("Access-Control-Allow-Origin: {$origin}");
        }
        
        // CORS Headers setzen
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Max-Age: 86400'); // 24 Stunden
        
        // Preflight OPTIONS Request behandeln
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
    
    /**
     * Origin validieren
     */
    public static function isValidOrigin(string $origin): bool
    {
        $allowedOrigins = [
            Config::get('FRONTEND_URL', 'http://localhost:4200'),
            'http://localhost:4200',
            'http://127.0.0.1:4200'
        ];
        
        return in_array($origin, $allowedOrigins) || 
               Config::get('APP_ENV') === 'development';
    }
}