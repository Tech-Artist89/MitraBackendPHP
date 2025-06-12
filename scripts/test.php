#!/usr/bin/env php
<?php
declare(strict_types=1);

// Autoloader prüfen
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "❌ Composer Autoloader nicht gefunden. Bitte führen Sie 'composer install' aus.\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

// Environment laden falls vorhanden
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

use MitraSanitaer\Config\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Test-URL bestimmen
$baseUrl = Config::get('APP_URL', 'http://localhost:8000');

// Test Data
$testContactForm = [
    'firstName' => 'Test',
    'lastName' => 'User',
    'email' => 'test@beispiel.de',
    'phone' => '030 123456789',
    'subject' => 'Test Anfrage',
    'message' => 'Das ist eine Test-Nachricht für das Kontaktformular.',
    'service' => 'bathroom',
    'urgent' => false
];

/**
 * HTTP Request senden
 */
function sendRequest(string $url, string $method = 'GET', array $data = null): array
{
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    if ($method === 'POST' && $data !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        return [
            'success' => false,
            'error' => $error,
            'httpCode' => 0
        ];
    }
    
    $decodedResponse = json_decode($response, true);
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data' => $decodedResponse,
        'raw' => $response
    ];
}

/**
 * Health Check Test
 */
function testHealthCheck(string $baseUrl): bool
{
    echo "🏥 Health Check Test...\n";
    
    $result = sendRequest($baseUrl . '/api/health');
    
    if ($result['success']) {
        echo "✅ Health Check erfolgreich\n";
        echo "   Status: " . ($result['data']['status'] ?? 'unknown') . "\n";
        return true;
    } else {
        echo "❌ Health Check fehlgeschlagen\n";
        echo "   HTTP Code: " . $result['httpCode'] . "\n";
        echo "   Error: " . ($result['error'] ?? 'Unknown error') . "\n";
        return false;
    }
}

/**
 * E-Mail Service Test
 */
function testEmailService(): bool
{
    echo "\n📨 E-Mail Service Test...\n";
    
    try {
        $config = Config::getEmailConfig();
        
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = $config['secure'] ? PHPMailer::ENCRYPTION_STARTTLS : false;
        $mail->Port = $config['port'];
        $mail->SMTPDebug = 0;
        
        if ($mail->smtpConnect()) {
            $mail->smtpClose();
            echo "✅ E-Mail Service Verbindung erfolgreich\n";
            echo "   Host: " . $config['host'] . "\n";
            echo "   Username: " . $config['username'] . "\n";
            return true;
        } else {
            echo "❌ E-Mail Service Verbindung fehlgeschlagen\n";
            return false;
        }
        
    } catch (Exception $e) {
        echo "❌ E-Mail Service Verbindung fehlgeschlagen\n";
        echo "   Error: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Usage Information anzeigen
 */
function showUsage(): void
{
    echo "📋 Test Script Usage:\n";
    echo "  php scripts/test.php          # Alle Tests\n";
    echo "  php scripts/test.php health   # Health Check\n";
    echo "  php scripts/test.php email    # E-Mail Service\n";
}

/**
 * Main Test Runner
 */
function runTests(array $args): int
{
    global $baseUrl, $testContactForm;
    
    $testType = $args[1] ?? 'all';
    
    echo "🧪 Mitra Sanitär Backend PHP Tests\n";
    echo "==================================\n";
    echo "Base URL: {$baseUrl}\n\n";
    
    $results = [];
    
    if ($testType === 'all') {
        echo "🚀 Führe alle Tests aus...\n\n";
        
        $results[] = testHealthCheck($baseUrl);
        $results[] = testEmailService();
        
    } else {
        switch (strtolower($testType)) {
            case 'health':
                $results[] = testHealthCheck($baseUrl);
                break;
                
            case 'email':
                $results[] = testEmailService();
                break;
                
            default:
                echo "❌ Unbekannter Test: {$testType}\n";
                showUsage();
                return 1;
        }
    }
    
    // Ergebnisse auswerten
    $passed = count(array_filter($results));
    $total = count($results);
    
    echo "\n📊 Test Ergebnisse: {$passed}/{$total} erfolgreich\n";
    
    if ($passed === $total) {
        echo "🎉 Alle Tests bestanden!\n";
        return 0;
    } else {
        echo "⚠️ Einige Tests fehlgeschlagen. Prüfe die Konfiguration.\n";
        return 1;
    }
}

// Direkter Aufruf über CLI
if (php_sapi_name() === 'cli') {
    exit(runTests($argv));
} else {
    echo "Dieses Script kann nur über die Kommandozeile ausgeführt werden.\n";
    exit(1);
}