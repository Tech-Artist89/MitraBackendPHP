<?php
declare(strict_types=1);

namespace MitraSanitaer\Config;

class Config
{
    private static array $cache = [];
    
    /**
     * Environment Variable mit Default abrufen
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        
        $value = $_ENV[$key] ?? $default;
        
        // Boolean Conversion
        if (is_string($value)) {
            if (strtolower($value) === 'true') {
                $value = true;
            } elseif (strtolower($value) === 'false') {
                $value = false;
            }
        }
        
        self::$cache[$key] = $value;
        return $value;
    }
    
    /**
     * Alle E-Mail Konfigurationen
     */
    public static function getEmailConfig(): array
    {
        return [
            'host' => self::get('EMAIL_HOST', 'smtp-mail.outlook.com'),
            'port' => (int) self::get('EMAIL_PORT', 587),
            'secure' => self::get('EMAIL_SECURE', true),
            'username' => self::get('EMAIL_USERNAME'),
            'password' => self::get('EMAIL_PASSWORD'),
            'from_address' => self::get('EMAIL_FROM_ADDRESS'),
            'from_name' => self::get('EMAIL_FROM_NAME', 'Mitra Sanitär GmbH'),
            'to' => self::get('EMAIL_TO', 'hey@mitra-sanitaer.de')
        ];
    }
    
    /**
     * Alle Firmen-Informationen
     */
    public static function getCompanyInfo(): array
    {
        return [
            'name' => self::get('COMPANY_NAME', 'Mitra Sanitär GmbH'),
            'address' => self::get('COMPANY_ADDRESS', 'Borussiastraße 62a'),
            'city' => self::get('COMPANY_CITY', '12103 Berlin'),
            'phone' => self::get('COMPANY_PHONE', '030 76008921'),
            'email' => self::get('COMPANY_EMAIL', 'hey@mitra-sanitaer.de')
        ];
    }
    
    /**
     * PDF Konfiguration
     */
    public static function getPdfConfig(): array
    {
        return [
            'output_dir' => self::get('PDF_OUTPUT_DIR', 'storage/generated-pdfs'),
            'debug_mode' => self::get('PDF_DEBUG_MODE', true),
            'engine' => self::get('PDF_ENGINE', 'mpdf'),
            'wkhtmltopdf_path' => self::get('WKHTMLTOPDF_PATH')
        ];
    }
    
    /**
     * Rate Limit Konfiguration
     */
    public static function getRateLimitConfig(): array
    {
        return [
            'window_minutes' => (int) self::get('RATE_LIMIT_WINDOW_MINUTES', 15),
            'max_requests' => (int) self::get('RATE_LIMIT_MAX_REQUESTS', 10)
        ];
    }
    
    /**
     * Prüft ob alle erforderlichen Variablen gesetzt sind
     */
    public static function validate(): array
    {
        $required = [
            'EMAIL_USERNAME',
            'EMAIL_PASSWORD',
            'EMAIL_TO'
        ];
        
        $missing = [];
        foreach ($required as $key) {
            if (empty(self::get($key))) {
                $missing[] = $key;
            }
        }
        
        return $missing;
    }
    
    /**
     * Debug-Informationen für Setup
     */
    public static function getDebugInfo(): array
    {
        return [
            'app_env' => self::get('APP_ENV'),
            'app_debug' => self::get('APP_DEBUG'),
            'app_url' => self::get('APP_URL'),
            'frontend_url' => self::get('FRONTEND_URL'),
            'email_host' => self::get('EMAIL_HOST'),
            'email_username' => self::get('EMAIL_USERNAME') ? '***set***' : 'not set',
            'company_name' => self::get('COMPANY_NAME'),
            'pdf_debug_mode' => self::get('PDF_DEBUG_MODE'),
            'rate_limit_enabled' => !empty(self::get('RATE_LIMIT_MAX_REQUESTS'))
        ];
    }
    
    /**
     * Prüft ob E-Mail Credentials gültig sind
     */
    public static function hasValidEmailCredentials(): bool
    {
        $username = self::get('EMAIL_USERNAME');
        $password = self::get('EMAIL_PASSWORD');
        
        if (empty($username) || empty($password)) {
            return false;
        }
        
        // Prüfe auf Dummy-Werte
        $dummyValues = [
            'ihre-email@outlook.com',
            'your-email@outlook.com',
            'test@example.com',
            'ihr-app-passwort',
            'your-app-password',
            'app-passwort',
            'auto-generated'
        ];
        
        if (in_array(strtolower($username), $dummyValues) || 
            in_array(strtolower($password), $dummyValues)) {
            return false;
        }
        
        if (strlen($username) < 5 || strlen($password) < 8) {
            return false;
        }
        
        return true;
    }
}