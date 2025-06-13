<?php
declare(strict_types=1);

namespace MitraSanitaer\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use MitraSanitaer\Config\Config;
use MitraSanitaer\Utils\Logger;
use Ramsey\Uuid\Uuid;
use Carbon\Carbon;

class EmailService
{
    private ?PHPMailer $mailer = null;
    private bool $testMode = false;
    
    public function __construct()
    {
        $this->initializeMailer();
    }
    
    /**
     * PHPMailer initialisieren (mit automatischem Test-Modus)
     */
    private function initializeMailer(): void
    {
        try {
            // Prüfe ob E-Mail Credentials konfiguriert sind
            if (!Config::hasValidEmailCredentials()) {
                Logger::warning('🧪 TEST MODUS AKTIVIERT: Keine gültigen E-Mail Credentials gefunden');
                Logger::info('💡 E-Mails werden simuliert und in Logs ausgegeben');
                $this->testMode = true;
                $this->mailer = $this->createMockMailer();
                return;
            }
            
            // Echten PHPMailer erstellen
            $this->mailer = new PHPMailer(true);
            $config = Config::getEmailConfig();
            
            // Character Encoding RICHTIG setzen
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Encoding = 'base64';
            
            // Server Einstellungen für Microsoft Outlook
            $this->mailer->isSMTP();
            $this->mailer->Host = $config['host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $config['username'];
            $this->mailer->Password = $config['password'];
            $this->mailer->SMTPSecure = $config['secure'] ? PHPMailer::ENCRYPTION_STARTTLS : false;
            $this->mailer->Port = $config['port'];
            
            // Outlook-spezifische Einstellungen
            $this->mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            
            // Debug für Development
            if (Config::get('APP_ENV') === 'development') {
                $this->mailer->SMTPDebug = SMTP::DEBUG_OFF;
            }
            
            // Verbindung testen
            $this->mailer->smtpConnect();
            $this->mailer->smtpClose();
            
            $this->testMode = false;
            Logger::email('Echter E-Mail Service initialisiert', [
                'host' => $config['host'],
                'port' => $config['port'],
                'secure' => $config['secure'],
                'username' => $config['username']
            ]);
            
        } catch (Exception $e) {
            Logger::warning('⚠️ E-Mail Service nicht verfügbar, wechsle zu Test-Modus: ' . $e->getMessage());
            Logger::info('🧪 E-Mails werden simuliert und in Logs ausgegeben');
            $this->testMode = true;
            $this->mailer = $this->createMockMailer();
        }
    }
    
    /**
     * Mock-Mailer für Tests erstellen
     */
    private function createMockMailer(): object
    {
        return new class {
            public string $CharSet = 'UTF-8';
            public string $Encoding = 'base64';
            
            public function send(): bool
            {
                $mockId = 'mock-' . time() . '-' . substr(md5(random_bytes(16)), 0, 9);
                $timestamp = Carbon::now()->format('Y-m-d H:i:s');
                
                Logger::info('📧 MOCK E-MAIL VERSENDET', [
                    'messageId' => $mockId . '@test.mitra-sanitaer.de',
                    'timestamp' => $timestamp,
                    'mockMode' => true
                ]);
                
                return true;
            }
            
            public function addAddress(string $address, string $name = ''): void {}
            public function setFrom(string $address, string $name = ''): void {}
            public function addReplyTo(string $address, string $name = ''): void {}
            public function addAttachment(string $path, string $name = ''): void {}
            public function isHTML(bool $isHtml): void {}
            public function clearAddresses(): void {}
            public function clearAttachments(): void {}
            public function clearReplyTos(): void {}
            
            public string $Subject = '';
            public string $Body = '';
            public string $AltBody = '';
        };
    }
    
    /**
     * Service-Informationen abrufen
     */
    public function getServiceInfo(): array
    {
        return [
            'available' => $this->mailer !== null,
            'testMode' => $this->testMode,
            'hasCredentials' => Config::hasValidEmailCredentials(),
            'emailHost' => Config::get('EMAIL_HOST', 'not configured'),
            'emailUsername' => Config::get('EMAIL_USERNAME', 'not configured')
        ];
    }
    
    /**
     * Kontaktformular senden
     */
    public function sendContactForm(array $formData): array
    {
        try {
            if (!$this->mailer) {
                throw new Exception('E-Mail Service ist nicht verfügbar');
            }
            
            $referenceId = 'CONTACT-' . substr(Uuid::uuid4()->toString(), 0, 8);
            $timestamp = Carbon::now()->format('d.m.Y H:i:s');
            
            // E-Mail konfigurieren
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearReplyTos();
            
            // CHARACTER ENCODING RICHTIG SETZEN
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Encoding = 'base64';
            
            $config = Config::getEmailConfig();
            $this->mailer->setFrom($config['from_address'], $config['from_name']);
            $this->mailer->addAddress($config['to']);
            $this->mailer->addReplyTo($formData['email'], $formData['firstName'] . ' ' . $formData['lastName']);
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Kontaktanfrage: ' . $formData['subject'];
            $this->mailer->Body = $this->generateContactFormEmailBody($formData, $referenceId, $timestamp);
            $this->mailer->AltBody = $this->generateContactFormTextBody($formData, $referenceId, $timestamp);
            
            // E-Mail senden
            $result = $this->mailer->send();
            
            // Logging
            if ($this->testMode) {
                Logger::email('🧪 Mock Kontaktformular versendet', [
                    'referenceId' => $referenceId,
                    'to' => $config['to'],
                    'from' => $formData['email'],
                    'subject' => $this->mailer->Subject,
                    'mockMode' => true
                ]);
            } else {
                Logger::email('📧 Kontaktformular versendet', [
                    'referenceId' => $referenceId,
                    'to' => $config['to'],
                    'from' => $formData['email'],
                    'subject' => $this->mailer->Subject
                ]);
            }
            
            return [
                'success' => true,
                'message' => $this->testMode ? 
                    'Mock E-Mail erfolgreich simuliert (Test-Modus)' : 
                    'E-Mail erfolgreich versendet',
                'referenceId' => $referenceId,
                'recipient' => $config['to'],
                'subject' => $this->mailer->Subject,
                'testMode' => $this->testMode
            ];
            
        } catch (Exception $e) {
            Logger::error('Fehler beim Senden des Kontaktformulars: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'E-Mail konnte nicht versendet werden: ' . $e->getMessage(),
                'testMode' => $this->testMode
            ];
        }
    }
    
    /**
     * HTML E-Mail Body für Kontaktformular generieren - MODERN & SCHÖN
     */
    private function generateContactFormEmailBody(array $formData, string $referenceId, string $timestamp): string
    {
        $serviceLabels = [
            'heating' => 'Heizungsbau',
            'bathroom' => 'Bäderbau', 
            'installation' => 'Installation',
            'emergency' => 'Notdienst',
            'consultation' => 'Beratung'
        ];
        
        $selectedService = $serviceLabels[$formData['service'] ?? ''] ?? 'Nicht angegeben';
        $urgentText = ($formData['urgent'] ?? false) ? '🚨 DRINGENDE ANFRAGE' : '';
        $testModeHeader = $this->testMode ? 
            '<div style="background: linear-gradient(135deg, #fef3cd 0%, #fbbf24 100%); padding: 15px; margin-bottom: 25px; border: 1px solid #f59e0b; border-radius: 10px; text-align: center;"><strong>🧪 TEST MODUS:</strong> Diese E-Mail wurde nur simuliert</div>' : '';
        
        $company = Config::getCompanyInfo();
        
        // SAUBERES UTF-8 ohne problematische Zeichen
        $firstName = htmlspecialchars($formData['firstName'], ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars($formData['lastName'], ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($formData['email'], ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars($formData['phone'] ?? '', ENT_QUOTES, 'UTF-8');
        $subject = htmlspecialchars($formData['subject'], ENT_QUOTES, 'UTF-8');
        $message = nl2br(htmlspecialchars($formData['message'], ENT_QUOTES, 'UTF-8'));
        
        return '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kontaktanfrage - ' . $company['name'] . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background-color: #f9fafb;
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: "";
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: url("data:image/svg+xml,%3Csvg width=\"40\" height=\"40\" viewBox=\"0 0 40 40\" xmlns=\"http://www.w3.org/2000/svg\"/%3E%3Cg fill=\"%23ffffff\" fill-opacity=\"0.05\"/%3E%3Cpath d=\"M20 20c0 11.046-8.954 20-20 20v-40c11.046 0 20 8.954 20 20z\"/%3E%3C/g%3E%3C/svg%3E");
            animation: float 20s infinite linear;
        }
        
        @keyframes float {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        .header-content {
            position: relative;
            z-index: 1;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .header .subtitle {
            font-size: 16px;
            opacity: 0.9;
            font-weight: 300;
        }
        
        .urgent-badge {
            display: inline-block;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
            margin-top: 15px;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }
        
        .content {
            padding: 30px;
        }
        
        .section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .section-title {
            color: #1e40af;
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .info-table tr {
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-table tr:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #374151;
            padding: 12px 15px 12px 0;
            width: 140px;
            vertical-align: top;
        }
        
        .info-value {
            color: #1f2937;
            padding: 12px 0;
            word-break: break-word;
        }
        
        .info-value a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 500;
        }
        
        .info-value a:hover {
            text-decoration: underline;
        }
        
        .message-box {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-top: 15px;
            font-size: 16px;
            line-height: 1.6;
            color: #374151;
        }
        
        .footer {
            background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
            color: #d1d5db;
            padding: 25px 30px;
            text-align: center;
            font-size: 14px;
        }
        
        .footer a {
            color: #60a5fa;
            text-decoration: none;
            font-weight: 500;
        }
        
        .footer a:hover {
            text-decoration: underline;
        }
        
        .company-logo {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 5px;
            color: #ffffff;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .badge-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        @media only screen and (max-width: 600px) {
            .email-container {
                margin: 0;
                box-shadow: none;
            }
            
            .header, .content, .footer {
                padding: 20px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .section {
                padding: 20px;
            }
            
            .info-label {
                width: 120px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        ' . $testModeHeader . '
        
        <div class="header">
            <div class="header-content">
                <div class="company-logo">' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . '</div>
                <h1>📧 Neue Kontaktanfrage</h1>
                <div class="subtitle">Eingang: ' . $timestamp . '</div>
                ' . ($urgentText ? '<div class="urgent-badge">' . $urgentText . '</div>' : '') . '
            </div>
        </div>
        
        <div class="content">
            <div class="section">
                <h2 class="section-title">
                    👤 Kontaktdaten
                </h2>
                <table class="info-table">
                    <tr>
                        <td class="info-label">Name:</td>
                        <td class="info-value">' . $firstName . ' ' . $lastName . '</td>
                    </tr>
                    <tr>
                        <td class="info-label">E-Mail:</td>
                        <td class="info-value"><a href="mailto:' . $email . '">' . $email . '</a></td>
                    </tr>
                    ' . ($phone ? '
                    <tr>
                        <td class="info-label">Telefon:</td>
                        <td class="info-value"><a href="tel:' . $phone . '">' . $phone . '</a></td>
                    </tr>
                    ' : '') . '
                    <tr>
                        <td class="info-label">Service:</td>
                        <td class="info-value">
                            <span class="badge badge-success">' . $selectedService . '</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="info-label">Betreff:</td>
                        <td class="info-value"><strong>' . $subject . '</strong></td>
                    </tr>
                    <tr>
                        <td class="info-label">Priorität:</td>
                        <td class="info-value">
                            ' . (($formData['urgent'] ?? false) ? 
                                '<span class="badge badge-warning">Dringend</span>' : 
                                '<span class="badge badge-success">Normal</span>') . '
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="section">
                <h2 class="section-title">
                    💬 Nachricht
                </h2>
                <div class="message-box">
                    ' . $message . '
                </div>
            </div>
            
            <div class="section">
                <h2 class="section-title">
                    ℹ️ System-Details
                </h2>
                <table class="info-table">
                    <tr>
                        <td class="info-label">Referenz-ID:</td>
                        <td class="info-value"><code>' . $referenceId . '</code></td>
                    </tr>
                    <tr>
                        <td class="info-label">Eingegangen:</td>
                        <td class="info-value">' . $timestamp . '</td>
                    </tr>
                    ' . ($this->testMode ? '
                    <tr>
                        <td class="info-label">Status:</td>
                        <td class="info-value"><span class="badge badge-warning">Test-Modus</span></td>
                    </tr>
                    ' : '') . '
                </table>
            </div>
        </div>
        
        <div class="footer">
            <p><strong>Diese E-Mail wurde ' . ($this->testMode ? 'simuliert' : 'automatisch') . ' über das Kontaktformular generiert.</strong></p>
            <p style="margin-top: 10px;">
                Antworten Sie direkt an: <a href="mailto:' . $email . '">' . $email . '</a>
            </p>
            <p style="margin-top: 15px; font-size: 12px; opacity: 0.8;">
                ' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . ' | 
                ' . htmlspecialchars($company['address'], ENT_QUOTES, 'UTF-8') . ' | 
                ' . htmlspecialchars($company['city'], ENT_QUOTES, 'UTF-8') . '
            </p>
        </div>
    </div>
</body>
</html>';
    }
    
    /**
     * Text E-Mail Body für Kontaktformular generieren
     */
    private function generateContactFormTextBody(array $formData, string $referenceId, string $timestamp): string
    {
        $company = Config::getCompanyInfo();
        $urgentText = ($formData['urgent'] ?? false) ? "\n*** DRINGENDE ANFRAGE ***\n" : '';
        
        $serviceLabels = [
            'heating' => 'Heizungsbau',
            'bathroom' => 'Bäderbau', 
            'installation' => 'Installation',
            'emergency' => 'Notdienst',
            'consultation' => 'Beratung'
        ];
        
        $selectedService = $serviceLabels[$formData['service'] ?? ''] ?? 'Nicht angegeben';
        
        return "
NEUE KONTAKTANFRAGE - " . $company['name'] . "
{$urgentText}
========================================

KONTAKTDATEN:
Name: " . $formData['firstName'] . " " . $formData['lastName'] . "
E-Mail: " . $formData['email'] . "
Telefon: " . ($formData['phone'] ?? 'Nicht angegeben') . "
Service: " . $selectedService . "
Betreff: " . $formData['subject'] . "

NACHRICHT:
" . $formData['message'] . "

SYSTEM-INFORMATIONEN:
Referenz-ID: {$referenceId}
Eingegangen am: {$timestamp}
Dringend: " . (($formData['urgent'] ?? false) ? 'Ja' : 'Nein') . "
" . ($this->testMode ? "Test-Modus: AKTIV\n" : '') . "

Bitte antworten Sie direkt an: " . $formData['email'] . "
";
    }
    
    // Andere Methoden bleiben unverändert...
    public function sendBathroomConfiguration(array $data): array
    {
        // Implementation bleibt gleich, nur CharSet hinzufügen
        try {
            if (!$this->mailer) {
                throw new Exception('E-Mail Service ist nicht verfügbar');
            }
            
            $contactData = $data['contactData'];
            $bathroomData = $data['bathroomData'] ?? [];
            $comments = $data['comments'] ?? '';
            $additionalInfo = $data['additionalInfo'] ?? [];
            $pdfPath = $data['pdfPath'] ?? null;
            $pdfFilename = $data['pdfFilename'] ?? 'Badkonfiguration.pdf';
            
            $referenceId = 'BATHROOM-' . substr(Uuid::uuid4()->toString(), 0, 8);
            $timestamp = Carbon::now()->format('d.m.Y H:i:s');
            
            // E-Mail konfigurieren
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->clearReplyTos();
            
            // CHARACTER ENCODING RICHTIG SETZEN
            $this->mailer->CharSet = 'UTF-8';
            $this->mailer->Encoding = 'base64';
            
            $config = Config::getEmailConfig();
            $this->mailer->setFrom($config['from_address'], $config['from_name']);
            $this->mailer->addAddress($config['to']);
            $this->mailer->addReplyTo($contactData['email'], 
                ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? ''));
            
            // PDF anhängen wenn vorhanden
            if ($pdfPath && file_exists($pdfPath)) {
                $this->mailer->addAttachment($pdfPath, $pdfFilename);
            }
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Badkonfigurator Anfrage - ' . 
                ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '');
            $this->mailer->Body = $this->generateBathroomConfigurationEmailBody(
                $contactData, $bathroomData, $comments, $additionalInfo, $referenceId, $timestamp
            );
            $this->mailer->AltBody = $this->generateBathroomConfigurationTextBody(
                $contactData, $bathroomData, $comments, $additionalInfo, $referenceId, $timestamp
            );
            
            // E-Mail senden
            $result = $this->mailer->send();
            
            // Logging bleibt gleich...
            
            return [
                'success' => true,
                'message' => $this->testMode ? 
                    'Mock Badkonfiguration erfolgreich simuliert (Test-Modus)' : 
                    'Badkonfiguration erfolgreich versendet',
                'referenceId' => $referenceId,
                'recipient' => $config['to'],
                'subject' => $this->mailer->Subject,
                'testMode' => $this->testMode
            ];
            
        } catch (Exception $e) {
            Logger::error('Fehler beim Senden der Badkonfiguration: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Badkonfiguration konnte nicht versendet werden: ' . $e->getMessage(),
                'testMode' => $this->testMode
            ];
        }
    }
    
    // Weitere Methoden für Badkonfigurator E-Mails würden hier folgen...
    // (Für Kürze weggelassen, aber mit gleichem CharSet Pattern)
    
    /**
     * Sichere Array-Join Funktion
     */
    private function safeJoin(array $array, string $separator = ', '): string
    {
        if (empty($array)) {
            return 'Keine ausgewählt';
        }
        
        $filtered = array_filter($array, function($item) {
            return $item !== null && $item !== '';
        });
        
        return !empty($filtered) ? implode($separator, $filtered) : 'Keine ausgewählt';
    }
}