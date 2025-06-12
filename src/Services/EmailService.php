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
                $this->mailer->SMTPDebug = SMTP::DEBUG_OFF; // Kein Debug Output im Log
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
     * Badkonfigurator E-Mail mit PDF senden
     */
    public function sendBathroomConfiguration(array $data): array
    {
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
            
            // Logging
            if ($this->testMode) {
                Logger::email('🧪 Mock Badkonfigurator E-Mail versendet', [
                    'referenceId' => $referenceId,
                    'to' => $config['to'],
                    'from' => $contactData['email'],
                    'subject' => $this->mailer->Subject,
                    'pdfAttached' => $pdfPath !== null,
                    'mockMode' => true,
                    'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '')
                ]);
            } else {
                Logger::email('📧 Badkonfigurator E-Mail versendet', [
                    'referenceId' => $referenceId,
                    'to' => $config['to'],
                    'from' => $contactData['email'],
                    'subject' => $this->mailer->Subject,
                    'pdfAttached' => $pdfPath !== null,
                    'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '')
                ]);
            }
            
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
    
    /**
     * HTML E-Mail Body für Kontaktformular generieren
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
        $urgentText = ($formData['urgent'] ?? false) ? '🔴 DRINGENDE ANFRAGE' : '';
        $testModeHeader = $this->testMode ? 
            '<div style="background: #fef3cd; padding: 10px; margin-bottom: 20px; border: 1px solid #f59e0b; border-radius: 5px;"><strong>🧪 TEST MODUS:</strong> Diese E-Mail wurde nur simuliert</div>' : '';
        
        $company = Config::getCompanyInfo();
        
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .header { background-color: #1e3a8a; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; }
        .section { margin-bottom: 20px; padding: 15px; border-left: 4px solid #1e3a8a; background-color: #f8fafc; }
        .urgent { background-color: #fee2e2; border-left-color: #dc2626; }
        .footer { background-color: #f1f5f9; padding: 15px; text-align: center; font-size: 12px; color: #64748b; }
        .info-grid { display: grid; grid-template-columns: 150px 1fr; gap: 10px; }
        .info-label { font-weight: bold; }
    </style>
</head>
<body>
    {$testModeHeader}
    
    <div class=\"header\">
        <h1>📧 Neue Kontaktanfrage</h1>
        <p>{$company['name']}</p>
        " . ($urgentText ? "<p style=\"font-size: 18px; font-weight: bold;\">{$urgentText}</p>" : '') . "
    </div>
    
    <div class=\"content\">
        <div class=\"section " . (($formData['urgent'] ?? false) ? 'urgent' : '') . "\">
            <h3>📋 Kontaktdaten</h3>
            <div class=\"info-grid\">
                <span class=\"info-label\">Name:</span>
                <span>{$formData['firstName']} {$formData['lastName']}</span>
                
                <span class=\"info-label\">E-Mail:</span>
                <span><a href=\"mailto:{$formData['email']}\">{$formData['email']}</a></span>
                
                " . (isset($formData['phone']) ? "
                <span class=\"info-label\">Telefon:</span>
                <span><a href=\"tel:{$formData['phone']}\">{$formData['phone']}</a></span>
                " : '') . "
                
                <span class=\"info-label\">Service:</span>
                <span>{$selectedService}</span>
                
                <span class=\"info-label\">Betreff:</span>
                <span>{$formData['subject']}</span>
            </div>
        </div>
        
        <div class=\"section\">
            <h3>💬 Nachricht</h3>
            <p style=\"white-space: pre-line;\">{$formData['message']}</p>
        </div>
        
        <div class=\"section\">
            <h3>ℹ️ System-Informationen</h3>
            <div class=\"info-grid\">
                <span class=\"info-label\">Referenz-ID:</span>
                <span>{$referenceId}</span>
                
                <span class=\"info-label\">Eingegangen am:</span>
                <span>{$timestamp}</span>
                
                <span class=\"info-label\">Dringend:</span>
                <span>" . (($formData['urgent'] ?? false) ? 'Ja - Antwort binnen 2 Stunden gewünscht' : 'Nein') . "</span>
                
                " . ($this->testMode ? "
                <span class=\"info-label\">Test-Modus:</span>
                <span style=\"color: #f59e0b; font-weight: bold;\">AKTIV - Keine echte E-Mail</span>
                " : '') . "
            </div>
        </div>
    </div>
    
    <div class=\"footer\">
        <p>Diese E-Mail wurde " . ($this->testMode ? 'simuliert' : 'automatisch') . " über das Kontaktformular der {$company['name']} Website generiert.</p>
        <p>Bitte antworten Sie direkt an: <a href=\"mailto:{$formData['email']}\">{$formData['email']}</a></p>
    </div>
</body>
</html>";
    }
    
    /**
     * Text E-Mail Body für Kontaktformular generieren
     */
    private function generateContactFormTextBody(array $formData, string $referenceId, string $timestamp): string
    {
        $company = Config::getCompanyInfo();
        $urgentText = ($formData['urgent'] ?? false) ? "\n*** DRINGENDE ANFRAGE ***\n" : '';
        
        return "
NEUE KONTAKTANFRAGE - {$company['name']}
{$urgentText}
========================================

KONTAKTDATEN:
Name: {$formData['firstName']} {$formData['lastName']}
E-Mail: {$formData['email']}
Telefon: " . ($formData['phone'] ?? 'Nicht angegeben') . "
Betreff: {$formData['subject']}

NACHRICHT:
{$formData['message']}

SYSTEM-INFORMATIONEN:
Referenz-ID: {$referenceId}
Eingegangen am: {$timestamp}
Dringend: " . (($formData['urgent'] ?? false) ? 'Ja' : 'Nein') . "
" . ($this->testMode ? "Test-Modus: AKTIV\n" : '') . "

Bitte antworten Sie direkt an: {$formData['email']}
";
    }
    
    /**
     * HTML E-Mail Body für Badkonfigurator generieren
     */
    private function generateBathroomConfigurationEmailBody(
        array $contactData, 
        array $bathroomData, 
        string $comments, 
        array $additionalInfo, 
        string $referenceId, 
        string $timestamp
    ): string {
        // Sichere Ausgewählte Ausstattung formatieren
        $selectedEquipment = [];
        if (isset($bathroomData['equipment']) && is_array($bathroomData['equipment'])) {
            foreach ($bathroomData['equipment'] as $item) {
                if (isset($item['selected']) && $item['selected']) {
                    $selectedOption = null;
                    if (isset($item['popupDetails']['options']) && is_array($item['popupDetails']['options'])) {
                        foreach ($item['popupDetails']['options'] as $opt) {
                            if (isset($opt['selected']) && $opt['selected']) {
                                $selectedOption = $opt;
                                break;
                            }
                        }
                    }
                    
                    $selectedEquipment[] = $selectedOption ? 
                        ($item['name'] ?? '') . ': ' . ($selectedOption['name'] ?? '') : 
                        ($item['name'] ?? '');
                }
            }
        }
        
        // Zusätzliche Informationen formatieren
        $additionalInfoList = [];
        if (is_array($additionalInfo)) {
            $labels = [
                'projektablauf' => 'Projektablauf',
                'garantie' => 'Garantie & Gewährleistung',
                'referenzen' => 'Referenzen',
                'foerderung' => 'Förderungsmöglichkeiten'
            ];
            
            foreach ($additionalInfo as $key => $value) {
                if ($value) {
                    $additionalInfoList[] = $labels[$key] ?? $key;
                }
            }
        }
        
        $testModeHeader = $this->testMode ? 
            '<div style="background: #fef3cd; padding: 10px; margin-bottom: 20px; border: 1px solid #f59e0b; border-radius: 5px;"><strong>🧪 TEST MODUS:</strong> Diese E-Mail wurde nur simuliert</div>' : '';
        
        $company = Config::getCompanyInfo();
        
        return "
<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .header { background-color: #1e3a8a; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; }
        .section { margin-bottom: 20px; padding: 15px; border-left: 4px solid #1e3a8a; background-color: #f8fafc; }
        .footer { background-color: #f1f5f9; padding: 15px; text-align: center; font-size: 12px; color: #64748b; }
        .info-grid { display: grid; grid-template-columns: 150px 1fr; gap: 10px; }
        .info-label { font-weight: bold; }
        .equipment-list { margin: 10px 0; }
        .equipment-item { padding: 5px 0; border-bottom: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    {$testModeHeader}
    
    <div class=\"header\">
        <h1>🛁 Neue Badkonfigurator Anfrage</h1>
        <p>{$company['name']}</p>
        " . ($this->testMode ? '<p style="font-size: 14px; opacity: 0.9;">🧪 Test-Modus aktiv</p>' : '') . "
    </div>
    
    <div class=\"content\">
        <div class=\"section\">
            <h3>👤 Kontaktdaten</h3>
            <div class=\"info-grid\">
                <span class=\"info-label\">Name:</span>
                <span>" . ($contactData['salutation'] ?? '') . " " . ($contactData['firstName'] ?? '') . " " . ($contactData['lastName'] ?? '') . "</span>
                
                <span class=\"info-label\">E-Mail:</span>
                <span><a href=\"mailto:" . ($contactData['email'] ?? '') . "\">" . ($contactData['email'] ?? '') . "</a></span>
                
                <span class=\"info-label\">Telefon:</span>
                <span><a href=\"tel:" . ($contactData['phone'] ?? '') . "\">" . ($contactData['phone'] ?? '') . "</a></span>
            </div>
        </div>
        
        <div class=\"section\">
            <h3>🛁 Badkonfiguration</h3>
            <div class=\"info-grid\">
                <span class=\"info-label\">Badgröße:</span>
                <span>" . ($bathroomData['bathroomSize'] ?? 'Nicht angegeben') . " m²</span>
                
                <span class=\"info-label\">Qualitätsstufe:</span>
                <span>" . ($bathroomData['qualityLevel']['name'] ?? 'Nicht ausgewählt') . "</span>
            </div>
            
            " . (!empty($selectedEquipment) ? "
            <h4>Gewählte Ausstattung:</h4>
            <div class=\"equipment-list\">
                " . implode('', array_map(function($item) {
                    return "<div class=\"equipment-item\">• {$item}</div>";
                }, $selectedEquipment)) . "
            </div>
            " : '') . "
        </div>
        
        <div class=\"section\">
            <h3>🎨 Fliesen & Heizung</h3>
            <div class=\"info-grid\">
                <span class=\"info-label\">Bodenfliesen:</span>
                <span>" . $this->safeJoin($bathroomData['floorTiles'] ?? []) . "</span>
                
                <span class=\"info-label\">Wandfliesen:</span>
                <span>" . $this->safeJoin($bathroomData['wallTiles'] ?? []) . "</span>
                
                <span class=\"info-label\">Heizung:</span>
                <span>" . $this->safeJoin($bathroomData['heating'] ?? []) . "</span>
            </div>
        </div>
        
        " . (!empty($additionalInfoList) ? "
        <div class=\"section\">
            <h3>📋 Gewünschte Informationen</h3>
            <ul>
                " . implode('', array_map(function($info) {
                    return "<li>{$info}</li>";
                }, $additionalInfoList)) . "
            </ul>
        </div>
        " : '') . "
        
        " . (!empty($comments) ? "
        <div class=\"section\">
            <h3>💬 Anmerkungen</h3>
            <p style=\"white-space: pre-line;\">{$comments}</p>
        </div>
        " : '') . "
        
        <div class=\"section\">
            <h3>ℹ️ System-Informationen</h3>
            <div class=\"info-grid\">
                <span class=\"info-label\">Referenz-ID:</span>
                <span>{$referenceId}</span>
                
                <span class=\"info-label\">Eingegangen am:</span>
                <span>{$timestamp}</span>
                
                <span class=\"info-label\">System:</span>
                <span>Mitra Sanitär Badkonfigurator v1.0</span>
                
                " . ($this->testMode ? "
                <span class=\"info-label\">Test-Modus:</span>
                <span style=\"color: #f59e0b; font-weight: bold;\">AKTIV - Keine echte E-Mail</span>
                " : '') . "
            </div>
        </div>
    </div>
    
    <div class=\"footer\">
        <p>Diese E-Mail wurde " . ($this->testMode ? 'simuliert' : 'automatisch') . " über den Badkonfigurator der {$company['name']} Website generiert.</p>
        <p>Bitte antworten Sie direkt an: <a href=\"mailto:" . ($contactData['email'] ?? '') . "\">" . ($contactData['email'] ?? '') . "</a></p>
        <p>PDF-Konfiguration " . ($this->testMode ? '(simuliert)' : 'im Anhang') . " | {$company['name']} | {$company['address']} | {$company['city']}</p>
    </div>
</body>
</html>";
    }
    
    /**
     * Text E-Mail Body für Badkonfigurator generieren
     */
    private function generateBathroomConfigurationTextBody(
        array $contactData,
        array $bathroomData,
        string $comments,
        array $additionalInfo,
        string $referenceId,
        string $timestamp
    ): string {
        $company = Config::getCompanyInfo();
        
        return "
NEUE BADKONFIGURATOR ANFRAGE - {$company['name']}
" . ($this->testMode ? "*** TEST MODUS AKTIV ***\n" : '') . "
=============================================

KONTAKTDATEN:
Name: " . ($contactData['salutation'] ?? '') . " " . ($contactData['firstName'] ?? '') . " " . ($contactData['lastName'] ?? '') . "
E-Mail: " . ($contactData['email'] ?? '') . "
Telefon: " . ($contactData['phone'] ?? '') . "

BADKONFIGURATION:
Badgröße: " . ($bathroomData['bathroomSize'] ?? 'Nicht angegeben') . " m²
Qualitätsstufe: " . ($bathroomData['qualityLevel']['name'] ?? 'Nicht ausgewählt') . "

" . (!empty($comments) ? "ANMERKUNGEN:\n{$comments}\n\n" : '') . "

SYSTEM-INFORMATIONEN:
Referenz-ID: {$referenceId}
Eingegangen am: {$timestamp}
" . ($this->testMode ? "Test-Modus: AKTIV\n" : '') . "

Bitte antworten Sie direkt an: " . ($contactData['email'] ?? '') . "
";
    }
    
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