#!/usr/bin/env php
<?php
declare(strict_types=1);

// Autoloader prüfen
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    echo "❌ Composer Autoloader nicht gefunden. Bitte führen Sie 'composer install' aus.\n";
    exit(1);
}

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

echo "🚀 Mitra Sanitär Backend PHP Setup\n";
echo "==================================\n\n";

/**
 * Benutzereingabe mit Standardwert
 */
function askQuestion(string $question, string $default = ''): string
{
    $prompt = $question;
    if (!empty($default)) {
        $prompt .= " ({$default})";
    }
    $prompt .= ": ";
    
    echo $prompt;
    $input = trim(fgets(STDIN));
    
    return empty($input) ? $default : $input;
}

/**
 * Ja/Nein Frage
 */
function askYesNo(string $question, bool $default = false): bool
{
    $defaultText = $default ? 'Y/n' : 'y/N';
    $response = askQuestion("{$question} ({$defaultText})", $default ? 'y' : 'n');
    
    return in_array(strtolower($response), ['y', 'yes', 'ja', '1']);
}

/**
 * Verzeichnis erstellen
 */
function createDirectory(string $path): bool
{
    if (!is_dir($path)) {
        if (mkdir($path, 0755, true)) {
            echo "📁 Verzeichnis erstellt: {$path}\n";
            return true;
        } else {
            echo "❌ Fehler beim Erstellen von: {$path}\n";
            return false;
        }
    } else {
        echo "✅ Verzeichnis existiert bereits: {$path}\n";
        return true;
    }
}

/**
 * .env Datei erstellen
 */
function createEnvFile(array $config): bool
{
    $envPath = __DIR__ . '/../.env';
    
    // Prüfe ob .env bereits existiert
    if (file_exists($envPath)) {
        if (!askYesNo('⚠️ .env Datei existiert bereits. Überschreiben?', false)) {
            echo "✅ Setup abgebrochen. Verwende vorhandene .env Datei.\n";
            return true;
        }
    }
    
    $envContent = "# Server Configuration\n";
    $envContent .= "APP_ENV={$config['app_env']}\n";
    $envContent .= "APP_DEBUG={$config['app_debug']}\n";
    $envContent .= "APP_URL={$config['app_url']}\n\n";
    
    $envContent .= "# Email Configuration (Microsoft Outlook)\n";
    $envContent .= "EMAIL_HOST={$config['email_host']}\n";
    $envContent .= "EMAIL_PORT={$config['email_port']}\n";
    $envContent .= "EMAIL_SECURE={$config['email_secure']}\n";
    $envContent .= "EMAIL_USERNAME={$config['email_username']}\n";
    $envContent .= "EMAIL_PASSWORD={$config['email_password']}\n";
    $envContent .= "EMAIL_FROM_ADDRESS={$config['email_from_address']}\n";
    $envContent .= "EMAIL_FROM_NAME=\"{$config['email_from_name']}\"\n";
    $envContent .= "EMAIL_TO={$config['email_to']}\n\n";
    
    $envContent .= "# Company Information\n";
    $envContent .= "COMPANY_NAME=\"{$config['company_name']}\"\n";
    $envContent .= "COMPANY_ADDRESS=\"{$config['company_address']}\"\n";
    $envContent .= "COMPANY_CITY=\"{$config['company_city']}\"\n";
    $envContent .= "COMPANY_PHONE=\"{$config['company_phone']}\"\n";
    $envContent .= "COMPANY_EMAIL=\"{$config['company_email']}\"\n\n";
    
    $envContent .= "# PDF Configuration\n";
    $envContent .= "PDF_OUTPUT_DIR={$config['pdf_output_dir']}\n";
    $envContent .= "PDF_DEBUG_MODE={$config['pdf_debug_mode']}\n";
    $envContent .= "PDF_ENGINE={$config['pdf_engine']}\n\n";
    
    $envContent .= "# Rate Limiting\n";
    $envContent .= "RATE_LIMIT_WINDOW_MINUTES={$config['rate_limit_window_minutes']}\n";
    $envContent .= "RATE_LIMIT_MAX_REQUESTS={$config['rate_limit_max_requests']}\n\n";
    
    $envContent .= "# Frontend URL (for CORS)\n";
    $envContent .= "FRONTEND_URL={$config['frontend_url']}\n\n";
    
    $envContent .= "# Logging\n";
    $envContent .= "LOG_LEVEL={$config['log_level']}\n";
    $envContent .= "LOG_CHANNEL=single\n";
    
    if (file_put_contents($envPath, $envContent)) {
        echo "✅ .env Datei erstellt!\n";
        return true;
    } else {
        echo "❌ Fehler beim Erstellen der .env Datei!\n";
        return false;
    }
}

/**
 * Verzeichnisse erstellen
 */
function createDirectories(): bool
{
    $directories = [
        'storage/logs',
        'storage/generated-pdfs',
        'storage/cache',
        'storage/fonts',
        'public/debug/pdfs'
    ];
    
    $success = true;
    foreach ($directories as $dir) {
        $fullPath = __DIR__ . '/../' . $dir;
        if (!createDirectory($fullPath)) {
            $success = false;
        }
    }
    
    return $success;
}

/**
 * E-Mail Test
 */
function testEmailConnection(array $config): bool
{
    echo "\n📧 Teste E-Mail Verbindung...\n";
    
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['email_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['email_username'];
        $mail->Password = $config['email_password'];
        $mail->SMTPSecure = $config['email_secure'] === 'true' ? 
            PHPMailer::ENCRYPTION_STARTTLS : false;
        $mail->Port = (int) $config['email_port'];
        $mail->SMTPDebug = 0;
        
        if ($mail->smtpConnect()) {
            $mail->smtpClose();
            echo "✅ E-Mail Verbindung erfolgreich!\n";
            return true;
        } else {
            echo "❌ E-Mail Verbindung fehlgeschlagen!\n";
            return false;
        }
        
    } catch (Exception $e) {
        echo "❌ E-Mail Test fehlgeschlagen: " . $e->getMessage() . "\n";
        echo "💡 Tipps:\n";
        echo "   - Prüfe E-Mail Credentials\n";
        echo "   - Für Outlook: App-Passwort verwenden\n";
        echo "   - 2FA muss aktiviert sein\n";
        return false;
    }
}

/**
 * Main Setup Funktion
 */
function runSetup(): int
{
    try {
        echo "\n📧 E-Mail Konfiguration\n";
        echo "Für Microsoft Outlook: App-Passwort verwenden!\n\n";
        
        $config = [];
        
        // E-Mail Konfiguration
        $config['email_host'] = askQuestion('E-Mail Host', 'smtp-mail.outlook.com');
        $config['email_port'] = askQuestion('E-Mail Port', '587');
        $config['email_secure'] = askYesNo('STARTTLS verwenden?', true) ? 'true' : 'false';
        $config['email_username'] = askQuestion('E-Mail Benutzername (Ihre E-Mail Adresse)');
        $config['email_password'] = askQuestion('E-Mail Passwort/App-Passwort');
        $config['email_from_address'] = askQuestion('Absender E-Mail', $config['email_username']);
        $config['email_from_name'] = askQuestion('Absender Name', 'Mitra Sanitär GmbH');
        $config['email_to'] = askQuestion('Ziel E-Mail', 'hey@mitra-sanitaer.de');
        
        echo "\n🏢 Firmeninformationen\n";
        $config['company_name'] = askQuestion('Firmenname', 'Mitra Sanitär GmbH');
        $config['company_address'] = askQuestion('Adresse', 'Borussiastraße 62a');
        $config['company_city'] = askQuestion('Ort', '12103 Berlin');
        $config['company_phone'] = askQuestion('Telefon', '030 76008921');
        $config['company_email'] = askQuestion('Firmen-E-Mail', 'hey@mitra-sanitaer.de');
        
        echo "\n⚙️ Server Konfiguration\n";
        $config['app_env'] = askQuestion('Environment', 'development');
        $config['app_debug'] = $config['app_env'] === 'development' ? 'true' : 'false';
        $config['app_url'] = askQuestion('App URL', 'http://localhost:8000');
        $config['frontend_url'] = askQuestion('Frontend URL', 'http://localhost:4200');
        
        echo "\n📄 PDF Konfiguration\n";
        $config['pdf_output_dir'] = askQuestion('PDF Output Directory', 'storage/generated-pdfs');
        $config['pdf_debug_mode'] = askYesNo('PDF Debug Modus aktivieren?', true) ? 'true' : 'false';
        $config['pdf_engine'] = askQuestion('PDF Engine', 'mpdf');
        
        echo "\n🛡️ Rate Limiting\n";
        $config['rate_limit_window_minutes'] = askQuestion('Rate Limit Fenster (Minuten)', '15');
        $config['rate_limit_max_requests'] = askQuestion('Max Requests pro Fenster', '10');
        
        echo "\n📝 Logging\n";
        $config['log_level'] = askQuestion('Log Level', 'debug');
        
        // .env Datei erstellen
        if (!createEnvFile($config)) {
            return 1;
        }
        
        // Verzeichnisse erstellen
        echo "\n📁 Erstelle Verzeichnisse...\n";
        createDirectories();
        
        // E-Mail Test (optional)
        if (askYesNo("\n🧪 E-Mail Verbindung testen?", true)) {
            testEmailConnection($config);
        }
        
        echo "\n🎉 Setup erfolgreich abgeschlossen!\n";
        echo "\n📋 Nächste Schritte:\n";
        echo "1. Webserver starten: php -S localhost:8000 -t public\n";
        echo "2. Backend testen: curl http://localhost:8000/api/health\n";
        echo "3. E-Mail Test: php scripts/test.php email\n";
        
        return 0;
        
    } catch (Exception $e) {
        echo "\n❌ Fehler beim Setup: " . $e->getMessage() . "\n";
        return 1;
    }
}

// Direkter Aufruf
if (php_sapi_name() === 'cli') {
    exit(runSetup());
} else {
    echo "Dieses Script kann nur über die Kommandozeile ausgeführt werden.\n";
    exit(1);
}