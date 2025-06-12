<?php
declare(strict_types=1);

namespace MitraSanitaer\Middleware;

use Respect\Validation\Validator as v;
use Respect\Validation\Exceptions\ValidationException;
use MitraSanitaer\Utils\Logger;

class ValidationMiddleware
{
    /**
     * Kontaktformular validieren
     */
    public static function validateContactForm(array $data): array
    {
        $validator = v::key('firstName', v::stringType()->notEmpty())
                    ->key('lastName', v::stringType()->notEmpty())
                    ->key('email', v::email())
                    ->key('subject', v::stringType()->notEmpty())
                    ->key('message', v::stringType()->notEmpty())
                    ->key('phone', v::optional(v::stringType()))
                    ->key('service', v::optional(v::stringType()))
                    ->key('urgent', v::optional(v::boolType()));
        
        try {
            $validator->assert($data);
            Logger::info('✅ Kontaktformular validiert');
            return ['valid' => true, 'errors' => []];
        } catch (ValidationException $e) {
            $errors = [];
            foreach ($e->getMessages() as $message) {
                $errors[] = $message;
            }
            
            Logger::warning('Kontaktformular Validierungsfehler', [
                'errors' => count($errors),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            return ['valid' => false, 'errors' => $errors];
        }
    }
    
    /**
     * Badkonfigurator validieren (ultra-robust)
     */
    public static function validateBathroomConfiguration(array $data): array
    {
        // Ultra-flexible Validierung - fast alles erlaubt
        $contactValidator = v::key('contactData', 
            v::key('firstName', v::stringType()->notEmpty())
            ->key('lastName', v::stringType()->notEmpty())
            ->key('email', v::email())
            ->key('phone', v::optional(v::stringType()))
            ->key('salutation', v::optional(v::stringType()))
        );
        
        try {
            $contactValidator->assert($data);
            
            // Minimale Validierung für Bathroom Data
            if (isset($data['bathroomData'])) {
                self::validateBathroomData($data['bathroomData']);
            }
            
            Logger::info('✅ Badkonfigurator validiert', [
                'customer' => ($data['contactData']['firstName'] ?? '') . ' ' . ($data['contactData']['lastName'] ?? ''),
                'hasData' => isset($data['bathroomData'])
            ]);
            
            return ['valid' => true, 'errors' => []];
            
        } catch (ValidationException $e) {
            // In ultra-robustem Modus: Nur warnen, nicht blockieren
            $errors = [];
            foreach ($e->getMessages() as $message) {
                $errors[] = $message;
            }
            
            Logger::info('⚠️ Badkonfigurator Validierungswarnungen (werden ignoriert)', [
                'warnings' => count($errors),
                'customer' => ($data['contactData']['firstName'] ?? '') . ' ' . ($data['contactData']['lastName'] ?? 'Unknown')
            ]);
            
            // IMMER als gültig markieren - nur loggen
            return ['valid' => true, 'warnings' => $errors];
        }
    }
    
    /**
     * Bathroom Data validieren (sehr tolerant)
     */
    private static function validateBathroomData(array $data): void
    {
        // Sehr flexible Validierung
        $validator = v::optional(
            v::key('bathroomSize', v::optional(v::numericVal()))
            ->key('equipment', v::optional(v::arrayType()))
            ->key('qualityLevel', v::optional(v::arrayType()))
            ->key('floorTiles', v::optional(v::arrayType()))
            ->key('wallTiles', v::optional(v::arrayType()))
            ->key('heating', v::optional(v::arrayType()))
        );
        
        $validator->assert($data);
    }
    
    /**
     * Equipment Item validieren
     */
    public static function validateEquipmentItem(array $item): bool
    {
        try {
            $validator = v::key('id', v::optional(v::stringType()))
                        ->key('name', v::optional(v::stringType()))
                        ->key('selected', v::optional(v::boolType()));
            
            $validator->assert($item);
            return true;
        } catch (ValidationException $e) {
            return true; // Ultra-tolerant
        }
    }
    
    /**
     * E-Mail Adresse validieren
     */
    public static function isValidEmail(string $email): bool
    {
        try {
            v::email()->assert($email);
            return true;
        } catch (ValidationException $e) {
            return false;
        }
    }
    
    /**
     * Telefonnummer validieren (sehr tolerant)
     */
    public static function isValidPhone(string $phone): bool
    {
        // Sehr tolerante Telefon-Validierung
        $cleaned = preg_replace('/[^0-9+\-\s()]/', '', $phone);
        return strlen($cleaned) >= 5;
    }
    
    /**
     * Input Sanitization
     */
    public static function sanitizeInput(mixed $input): mixed
    {
        if (is_string($input)) {
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        }
        
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }
        
        return $input;
    }
    
    /**
     * Gefährliche Inhalte prüfen
     */
    public static function containsDangerousContent(string $content): bool
    {
        $dangerous = [
            '<script',
            'javascript:',
            'onload=',
            'onclick=',
            'onerror=',
            'eval(',
            'exec(',
            'system(',
            'shell_exec'
        ];
        
        $lowerContent = strtolower($content);
        foreach ($dangerous as $pattern) {
            if (strpos($lowerContent, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * File Upload validieren
     */
    public static function validateFileUpload(array $file): array
    {
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        $errors = [];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload error: ' . $file['error'];
        }
        
        if ($file['size'] > $maxSize) {
            $errors[] = 'File too large. Maximum size: 5MB';
        }
        
        if (!in_array($file['type'], $allowedTypes)) {
            $errors[] = 'Invalid file type. Allowed: PDF, JPEG, PNG, GIF';
        }
        
        return ['valid' => empty($errors), 'errors' => $errors];
    }
    
    /**
     * JSON Input validieren
     */
    public static function validateJsonInput(): array
    {
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            return ['valid' => false, 'error' => 'Empty request body'];
        }
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['valid' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()];
        }
        
        return ['valid' => true, 'data' => $data];
    }
}