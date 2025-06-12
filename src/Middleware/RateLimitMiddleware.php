<?php
declare(strict_types=1);

namespace MitraSanitaer\Middleware;

use MitraSanitaer\Config\Config;
use MitraSanitaer\Utils\Logger;

class RateLimitMiddleware
{
    private static string $cacheDir = '';
    
    /**
     * Rate Limiting prüfen
     */
    public static function handle(): void
    {
        if (!self::$cacheDir) {
            self::$cacheDir = __DIR__ . '/../../storage/cache';
            if (!is_dir(self::$cacheDir)) {
                mkdir(self::$cacheDir, 0755, true);
            }
        }
        
        $config = Config::getRateLimitConfig();
        $windowMinutes = $config['window_minutes'];
        $maxRequests = $config['max_requests'];
        
        // Rate Limiting deaktiviert
        if ($maxRequests <= 0) {
            return;
        }
        
        $clientId = self::getClientIdentifier();
        $currentTime = time();
        $windowStart = $currentTime - ($windowMinutes * 60);
        
        // Request History laden
        $history = self::getRequestHistory($clientId);
        
        // Alte Requests entfernen
        $history = array_filter($history, function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });
        
        // Aktuellen Request hinzufügen
        $history[] = $currentTime;
        
        // Rate Limit prüfen
        if (count($history) > $maxRequests) {
            Logger::security('Rate limit exceeded', [
                'client_id' => $clientId,
                'requests' => count($history),
                'max_requests' => $maxRequests,
                'window_minutes' => $windowMinutes
            ]);
            
            self::sendRateLimitResponse($windowMinutes);
            exit;
        }
        
        // History speichern
        self::saveRequestHistory($clientId, $history);
        
        // Rate Limit Headers setzen
        header("X-RateLimit-Limit: {$maxRequests}");
        header("X-RateLimit-Remaining: " . ($maxRequests - count($history)));
        header("X-RateLimit-Reset: " . ($currentTime + ($windowMinutes * 60)));
    }
    
    /**
     * Client Identifier generieren
     */
    private static function getClientIdentifier(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // IP + User-Agent Hash für bessere Identifikation
        return md5($ip . $userAgent);
    }
    
    /**
     * Request History laden
     */
    private static function getRequestHistory(string $clientId): array
    {
        $file = self::$cacheDir . "/rate_limit_{$clientId}.json";
        
        if (!file_exists($file)) {
            return [];
        }
        
        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Request History speichern
     */
    private static function saveRequestHistory(string $clientId, array $history): void
    {
        $file = self::$cacheDir . "/rate_limit_{$clientId}.json";
        file_put_contents($file, json_encode($history));
    }
    
    /**
     * Rate Limit Response senden
     */
    private static function sendRateLimitResponse(int $windowMinutes): void
    {
        http_response_code(429);
        header('Content-Type: application/json');
        header("Retry-After: " . ($windowMinutes * 60));
        
        echo json_encode([
            'success' => false,
            'error' => 'Rate Limit Exceeded',
            'message' => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.',
            'retry_after' => $windowMinutes * 60,
            'timestamp' => date('c')
        ]);
    }
    
    /**
     * Rate Limit Cache aufräumen (alte Dateien löschen)
     */
    public static function cleanup(): int
    {
        if (!is_dir(self::$cacheDir)) {
            return 0;
        }
        
        $files = glob(self::$cacheDir . '/rate_limit_*.json');
        $cleaned = 0;
        $threshold = time() - (24 * 60 * 60); // 24 Stunden alt
        
        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                unlink($file);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Rate Limit Status für Client abrufen
     */
    public static function getStatus(string $clientId = null): array
    {
        $clientId = $clientId ?: self::getClientIdentifier();
        $config = Config::getRateLimitConfig();
        $windowMinutes = $config['window_minutes'];
        $maxRequests = $config['max_requests'];
        
        $currentTime = time();
        $windowStart = $currentTime - ($windowMinutes * 60);
        
        $history = self::getRequestHistory($clientId);
        $history = array_filter($history, function($timestamp) use ($windowStart) {
            return $timestamp > $windowStart;
        });
        
        return [
            'limit' => $maxRequests,
            'remaining' => max(0, $maxRequests - count($history)),
            'reset' => $currentTime + ($windowMinutes * 60),
            'window_minutes' => $windowMinutes
        ];
    }
}