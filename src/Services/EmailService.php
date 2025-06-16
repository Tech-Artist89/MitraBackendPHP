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
     * Badkonfigurator E-Mail senden - ERWEITERTE VERSION
     * Sendet E-Mails sowohl an das Unternehmen als auch an den Kunden
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
            
            $config = Config::getEmailConfig();
            $customerEmail = $contactData['email'] ?? '';
            $customerName = ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '');
            
            // Validierung der Kunden-E-Mail
            if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Ungültige Kunden-E-Mail-Adresse: ' . $customerEmail);
            }
            
            $results = [];
            
            // 1. E-MAIL AN DAS UNTERNEHMEN SENDEN
            try {
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                $this->mailer->clearReplyTos();
                
                // CHARACTER ENCODING RICHTIG SETZEN
                $this->mailer->CharSet = 'UTF-8';
                $this->mailer->Encoding = 'base64';
                
                $this->mailer->setFrom($config['from_address'], $config['from_name']);
                $this->mailer->addAddress($config['to']); // An Unternehmen
                $this->mailer->addReplyTo($customerEmail, $customerName);
                
                // PDF anhängen wenn vorhanden
                if ($pdfPath && file_exists($pdfPath)) {
                    $this->mailer->addAttachment($pdfPath, $pdfFilename);
                }
                
                $this->mailer->isHTML(true);
                $this->mailer->Subject = 'Badkonfigurator Anfrage - ' . $customerName;
                $this->mailer->Body = $this->generateBathroomConfigurationEmailBody(
                    $contactData, $bathroomData, $comments, $additionalInfo, $referenceId, $timestamp
                );
                $this->mailer->AltBody = $this->generateBathroomConfigurationTextBody(
                    $contactData, $bathroomData, $comments, $additionalInfo, $referenceId, $timestamp
                );
                
                $companyResult = $this->mailer->send();
                
                $results['company'] = [
                    'success' => $companyResult,
                    'recipient' => $config['to'],
                    'message' => $companyResult ? 'E-Mail an Unternehmen erfolgreich versendet' : 'Fehler beim Versenden an Unternehmen'
                ];
                
                // Logging für Unternehmens-E-Mail
                if ($this->testMode) {
                    Logger::email('🧪 Mock Badkonfiguration an Unternehmen versendet', [
                        'referenceId' => $referenceId,
                        'to' => $config['to'],
                        'customer' => $customerName,
                        'mockMode' => true
                    ]);
                } else {
                    Logger::email('📧 Badkonfiguration an Unternehmen versendet', [
                        'referenceId' => $referenceId,
                        'to' => $config['to'],
                        'customer' => $customerName,
                        'pdfAttached' => !empty($pdfPath)
                    ]);
                }
                
            } catch (Exception $e) {
                $results['company'] = [
                    'success' => false,
                    'recipient' => $config['to'],
                    'message' => 'Fehler beim Versenden an Unternehmen: ' . $e->getMessage()
                ];
                Logger::error('Fehler beim Senden der Badkonfiguration an Unternehmen: ' . $e->getMessage());
            }
            
            // 2. BESTÄTIGUNGS-E-MAIL AN DEN KUNDEN SENDEN
            try {
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                $this->mailer->clearReplyTos();
                
                // CHARACTER ENCODING RICHTIG SETZEN
                $this->mailer->CharSet = 'UTF-8';
                $this->mailer->Encoding = 'base64';
                
                $this->mailer->setFrom($config['from_address'], $config['from_name']);
                $this->mailer->addAddress($customerEmail, $customerName); // An Kunden
                $this->mailer->addReplyTo($config['from_address'], $config['from_name']);
                
                // PDF auch an Kunden anhängen
                if ($pdfPath && file_exists($pdfPath)) {
                    $this->mailer->addAttachment($pdfPath, $pdfFilename);
                }
                
                $this->mailer->isHTML(true);
                $this->mailer->Subject = 'Ihre Badkonfigurator Anfrage - ' . $config['from_name'];
                $this->mailer->Body = $this->generateCustomerConfirmationEmailBody(
                    $contactData, $bathroomData, $comments, $additionalInfo, $referenceId, $timestamp
                );
                $this->mailer->AltBody = $this->generateCustomerConfirmationTextBody(
                    $contactData, $bathroomData, $comments, $additionalInfo, $referenceId, $timestamp
                );
                
                $customerResult = $this->mailer->send();
                
                $results['customer'] = [
                    'success' => $customerResult,
                    'recipient' => $customerEmail,
                    'message' => $customerResult ? 'Bestätigungs-E-Mail an Kunden erfolgreich versendet' : 'Fehler beim Versenden der Bestätigung an Kunden'
                ];
                
                // Logging für Kunden-E-Mail
                if ($this->testMode) {
                    Logger::email('🧪 Mock Bestätigung an Kunden versendet', [
                        'referenceId' => $referenceId,
                        'to' => $customerEmail,
                        'customer' => $customerName,
                        'mockMode' => true
                    ]);
                } else {
                    Logger::email('📧 Bestätigung an Kunden versendet', [
                        'referenceId' => $referenceId,
                        'to' => $customerEmail,
                        'customer' => $customerName,
                        'pdfAttached' => !empty($pdfPath)
                    ]);
                }
                
            } catch (Exception $e) {
                $results['customer'] = [
                    'success' => false,
                    'recipient' => $customerEmail,
                    'message' => 'Fehler beim Versenden der Bestätigung an Kunden: ' . $e->getMessage()
                ];
                Logger::error('Fehler beim Senden der Bestätigung an Kunden: ' . $e->getMessage());
            }
            
            // GESAMTERGEBNIS BESTIMMEN
            $overallSuccess = ($results['company']['success'] ?? false) || ($results['customer']['success'] ?? false);
            $failedRecipients = [];
            $successfulRecipients = [];
            
            foreach ($results as $type => $result) {
                if ($result['success']) {
                    $successfulRecipients[] = $result['recipient'];
                } else {
                    $failedRecipients[] = $result['recipient'] . ' (' . $type . ')';
                }
            }
            
            $message = '';
            if ($overallSuccess) {
                $message = $this->testMode ? 
                    'Mock Badkonfiguration erfolgreich simuliert (Test-Modus)' : 
                    'Badkonfiguration erfolgreich versendet';
                
                if (!empty($successfulRecipients)) {
                    $message .= ' an: ' . implode(', ', $successfulRecipients);
                }
                
                if (!empty($failedRecipients)) {
                    $message .= '. Fehler bei: ' . implode(', ', $failedRecipients);
                }
            } else {
                $message = 'Badkonfiguration konnte nicht versendet werden';
                if (!empty($failedRecipients)) {
                    $message .= '. Alle E-Mails fehlgeschlagen: ' . implode(', ', $failedRecipients);
                }
            }
            
            return [
                'success' => $overallSuccess,
                'message' => $message,
                'referenceId' => $referenceId,
                'results' => $results,
                'successfulRecipients' => $successfulRecipients,
                'failedRecipients' => $failedRecipients,
                'testMode' => $this->testMode
            ];
            
        } catch (Exception $e) {
            Logger::error('Allgemeiner Fehler beim Senden der Badkonfiguration: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Badkonfiguration konnte nicht versendet werden: ' . $e->getMessage(),
                'testMode' => $this->testMode
            ];
        }
    }
    
    /**
     * Kontaktformular senden - ERWEITERTE VERSION
     * Sendet E-Mails sowohl an das Unternehmen als auch an den Kunden
     */
    public function sendContactForm(array $formData): array
    {
        try {
            if (!$this->mailer) {
                throw new Exception('E-Mail Service ist nicht verfügbar');
            }
            
            $referenceId = 'CONTACT-' . substr(Uuid::uuid4()->toString(), 0, 8);
            $timestamp = Carbon::now()->format('d.m.Y H:i:s');
            
            $config = Config::getEmailConfig();
            $customerEmail = $formData['email'] ?? '';
            $customerName = ($formData['firstName'] ?? '') . ' ' . ($formData['lastName'] ?? '');
            
            // Validierung der Kunden-E-Mail
            if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Ungültige Kunden-E-Mail-Adresse: ' . $customerEmail);
            }
            
            $results = [];
            
            // 1. E-MAIL AN DAS UNTERNEHMEN SENDEN
            try {
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                $this->mailer->clearReplyTos();
                
                // CHARACTER ENCODING RICHTIG SETZEN
                $this->mailer->CharSet = 'UTF-8';
                $this->mailer->Encoding = 'base64';
                
                $this->mailer->setFrom($config['from_address'], $config['from_name']);
                $this->mailer->addAddress($config['to']); // An Unternehmen
                $this->mailer->addReplyTo($customerEmail, $customerName);
                
                $this->mailer->isHTML(true);
                $this->mailer->Subject = 'Kontaktanfrage: ' . $formData['subject'];
                $this->mailer->Body = $this->generateContactFormEmailBody($formData, $referenceId, $timestamp);
                $this->mailer->AltBody = $this->generateContactFormTextBody($formData, $referenceId, $timestamp);
                
                $companyResult = $this->mailer->send();
                
                $results['company'] = [
                    'success' => $companyResult,
                    'recipient' => $config['to'],
                    'message' => $companyResult ? 'E-Mail an Unternehmen erfolgreich versendet' : 'Fehler beim Versenden an Unternehmen'
                ];
                
                // Logging für Unternehmens-E-Mail
                if ($this->testMode) {
                    Logger::email('🧪 Mock Kontaktformular an Unternehmen versendet', [
                        'referenceId' => $referenceId,
                        'to' => $config['to'],
                        'customer' => $customerName,
                        'mockMode' => true
                    ]);
                } else {
                    Logger::email('📧 Kontaktformular an Unternehmen versendet', [
                        'referenceId' => $referenceId,
                        'to' => $config['to'],
                        'customer' => $customerName
                    ]);
                }
                
            } catch (Exception $e) {
                $results['company'] = [
                    'success' => false,
                    'recipient' => $config['to'],
                    'message' => 'Fehler beim Versenden an Unternehmen: ' . $e->getMessage()
                ];
                Logger::error('Fehler beim Senden des Kontaktformulars an Unternehmen: ' . $e->getMessage());
            }
            
            // 2. BESTÄTIGUNGS-E-MAIL AN DEN KUNDEN SENDEN
            try {
                $this->mailer->clearAddresses();
                $this->mailer->clearAttachments();
                $this->mailer->clearReplyTos();
                
                // CHARACTER ENCODING RICHTIG SETZEN
                $this->mailer->CharSet = 'UTF-8';
                $this->mailer->Encoding = 'base64';
                
                $this->mailer->setFrom($config['from_address'], $config['from_name']);
                $this->mailer->addAddress($customerEmail, $customerName); // An Kunden
                $this->mailer->addReplyTo($config['from_address'], $config['from_name']);
                
                $this->mailer->isHTML(true);
                $this->mailer->Subject = 'Ihre Kontaktanfrage - ' . $config['from_name'];
                $this->mailer->Body = $this->generateContactFormCustomerEmailBody($formData, $referenceId, $timestamp);
                $this->mailer->AltBody = $this->generateContactFormCustomerTextBody($formData, $referenceId, $timestamp);
                
                $customerResult = $this->mailer->send();
                
                $results['customer'] = [
                    'success' => $customerResult,
                    'recipient' => $customerEmail,
                    'message' => $customerResult ? 'Bestätigungs-E-Mail an Kunden erfolgreich versendet' : 'Fehler beim Versenden der Bestätigung an Kunden'
                ];
                
                // Logging für Kunden-E-Mail
                if ($this->testMode) {
                    Logger::email('🧪 Mock Bestätigung an Kunden versendet', [
                        'referenceId' => $referenceId,
                        'to' => $customerEmail,
                        'customer' => $customerName,
                        'mockMode' => true
                    ]);
                } else {
                    Logger::email('📧 Bestätigung an Kunden versendet', [
                        'referenceId' => $referenceId,
                        'to' => $customerEmail,
                        'customer' => $customerName
                    ]);
                }
                
            } catch (Exception $e) {
                $results['customer'] = [
                    'success' => false,
                    'recipient' => $customerEmail,
                    'message' => 'Fehler beim Versenden der Bestätigung an Kunden: ' . $e->getMessage()
                ];
                Logger::error('Fehler beim Senden der Bestätigung an Kunden: ' . $e->getMessage());
            }
            
            // GESAMTERGEBNIS BESTIMMEN
            $overallSuccess = ($results['company']['success'] ?? false) || ($results['customer']['success'] ?? false);
            $failedRecipients = [];
            $successfulRecipients = [];
            
            foreach ($results as $type => $result) {
                if ($result['success']) {
                    $successfulRecipients[] = $result['recipient'];
                } else {
                    $failedRecipients[] = $result['recipient'] . ' (' . $type . ')';
                }
            }
            
            $message = '';
            if ($overallSuccess) {
                $message = $this->testMode ? 
                    'Mock Kontaktanfrage erfolgreich simuliert (Test-Modus)' : 
                    'Kontaktanfrage erfolgreich versendet';
                
                if (!empty($successfulRecipients)) {
                    $message .= ' an: ' . implode(', ', $successfulRecipients);
                }
                
                if (!empty($failedRecipients)) {
                    $message .= '. Fehler bei: ' . implode(', ', $failedRecipients);
                }
            } else {
                $message = 'Kontaktanfrage konnte nicht versendet werden';
                if (!empty($failedRecipients)) {
                    $message .= '. Alle E-Mails fehlgeschlagen: ' . implode(', ', $failedRecipients);
                }
            }
            
            return [
                'success' => $overallSuccess,
                'message' => $message,
                'referenceId' => $referenceId,
                'results' => $results,
                'successfulRecipients' => $successfulRecipients,
                'failedRecipients' => $failedRecipients,
                'testMode' => $this->testMode
            ];
            
        } catch (Exception $e) {
            Logger::error('Allgemeiner Fehler beim Senden des Kontaktformulars: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Kontaktanfrage konnte nicht versendet werden: ' . $e->getMessage(),
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
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #1f2937; background-color: #f9fafb; margin: 0; padding: 20px;">
    ' . $testModeHeader . '
    
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1); border-radius: 12px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%); color: white; padding: 40px 30px; text-align: center;">
            <div style="font-size: 24px; font-weight: 800; margin-bottom: 5px; color: #ffffff;">' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . '</div>
            <h1 style="margin: 10px 0 0 0; font-size: 18px;">📧 Neue Kontaktanfrage</h1>
            <p style="margin: 5px 0 0 0; opacity: 0.9;">Eingang: ' . $timestamp . '</p>
            ' . ($urgentText ? '<div style="background: #ef4444; padding: 8px 16px; border-radius: 20px; display: inline-block; margin-top: 10px; font-weight: bold;">' . $urgentText . '</div>' : '') . '
        </div>
        
        <div style="padding: 30px;">
            <div style="margin-bottom: 25px;">
                <h3 style="color: #1e40af; margin-bottom: 15px; font-size: 16px; display: flex; align-items: center; gap: 10px;">👤 Kontaktdaten</h3>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr><td style="padding: 8px 0; font-weight: bold; width: 120px;">Name:</td><td>' . $firstName . ' ' . $lastName . '</td></tr>
                    <tr><td style="padding: 8px 0; font-weight: bold;">E-Mail:</td><td><a href="mailto:' . $email . '" style="color: #2563eb; text-decoration: none; font-weight: 500;">' . $email . '</a></td></tr>
                    ' . ($phone ? '<tr><td style="padding: 8px 0; font-weight: bold;">Telefon:</td><td><a href="tel:' . $phone . '" style="color: #2563eb; text-decoration: none; font-weight: 500;">' . $phone . '</a></td></tr>' : '') . '
                    <tr><td style="padding: 8px 0; font-weight: bold;">Service:</td><td><span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; text-transform: uppercase;">' . $selectedService . '</span></td></tr>
                    <tr><td style="padding: 8px 0; font-weight: bold;">Betreff:</td><td><strong>' . $subject . '</strong></td></tr>
                    <tr><td style="padding: 8px 0; font-weight: bold;">Priorität:</td><td>' . 
                        (($formData['urgent'] ?? false) ? 
                            '<span style="background: #f59e0b; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; text-transform: uppercase;">Dringend</span>' : 
                            '<span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600; text-transform: uppercase;">Normal</span>') . 
                    '</td></tr>
                </table>
            </div>
            
            <div style="margin-bottom: 25px;">
                <h3 style="color: #1e40af; margin-bottom: 15px; font-size: 16px;">💬 Nachricht</h3>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; font-size: 16px; line-height: 1.6; color: #374151;">
                    ' . $message . '
                </div>
            </div>
            
            <div style="background: #f1f5f9; border-radius: 8px; padding: 15px;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 4px 0; font-weight: bold; color: #6b7280; font-size: 12px; width: 120px;">Referenz-ID:</td>
                        <td style="padding: 4px 0; font-size: 12px; color: #6b7280;"><code>' . $referenceId . '</code></td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; font-weight: bold; color: #6b7280; font-size: 12px;">Eingegangen:</td>
                        <td style="padding: 4px 0; font-size: 12px; color: #6b7280;">' . $timestamp . '</td>
                    </tr>
                    ' . ($this->testMode ? '
                    <tr>
                        <td style="padding: 4px 0; font-weight: bold; color: #6b7280; font-size: 12px;">Status:</td>
                        <td style="padding: 4px 0; font-size: 12px;"><span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; text-transform: uppercase;">Test-Modus</span></td>
                    </tr>
                    ' : '') . '
                </table>
            </div>
        </div>
        
        <div style="background: linear-gradient(135deg, #1f2937 0%, #374151 100%); color: #d1d5db; padding: 20px; text-align: center; font-size: 12px;">
            <p style="margin: 0;"><strong>Diese E-Mail wurde ' . ($this->testMode ? 'simuliert' : 'automatisch') . ' über das Kontaktformular generiert.</strong></p>
            <p style="margin: 10px 0 0 0;">
                Antworten Sie direkt an: <a href="mailto:' . $email . '" style="color: #60a5fa; text-decoration: none; font-weight: 500;">' . $email . '</a>
            </p>
            <p style="margin: 10px 0 0 0; opacity: 0.8;">
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
    
    /**
     * HTML E-Mail Body für Badkonfiguration generieren (Unternehmen)
     */
    private function generateBathroomConfigurationEmailBody(
        array $contactData,
        array $bathroomData,
        string $comments,
        array $additionalInfo,
        string $referenceId,
        string $timestamp
    ): string {
        $company = Config::getCompanyInfo();
        $testModeHeader = $this->testMode ? 
            '<div style="background: linear-gradient(135deg, #fef3cd 0%, #fbbf24 100%); padding: 15px; margin-bottom: 25px; border: 1px solid #f59e0b; border-radius: 10px; text-align: center;"><strong>🧪 TEST MODUS:</strong> Diese E-Mail wurde nur simuliert</div>' : '';
        
        // Sichere Datenaufbereitung
        $firstName = htmlspecialchars($contactData['firstName'] ?? '', ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars($contactData['lastName'] ?? '', ENT_QUOTES, 'UTF-8');
        $salutation = htmlspecialchars($contactData['salutation'] ?? '', ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($contactData['email'] ?? '', ENT_QUOTES, 'UTF-8');
        $phone = htmlspecialchars($contactData['phone'] ?? '', ENT_QUOTES, 'UTF-8');
        
        // Ausgewählte Ausstattung verarbeiten
        $selectedEquipment = $this->processSelectedEquipment($bathroomData['equipment'] ?? []);
        
        // Zusätzliche Informationen verarbeiten
        $additionalInfoList = $this->processAdditionalInfo($additionalInfo);
        
        // Fliesen und Heizung verarbeiten
        $floorTiles = $this->safeJoin($bathroomData['floorTiles'] ?? []);
        $wallTiles = $this->safeJoin($bathroomData['wallTiles'] ?? []);
        $heating = $this->safeJoin($bathroomData['heating'] ?? []);
        
        return '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Badkonfigurator Anfrage - ' . $firstName . ' ' . $lastName . '</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px;">
    ' . $testModeHeader . '
    
    <div style="max-width: 700px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); color: white; padding: 40px 30px; text-align: center;">
            <div style="font-size: 20px; font-weight: bold; margin-bottom: 8px;">' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . '</div>
            <h1 style="margin: 0; font-size: 28px;">🛁 Badkonfigurator Anfrage</h1>
            <div style="margin-top: 10px; opacity: 0.9;">Eingang: ' . $timestamp . '</div>
        </div>
        
        <div style="padding: 30px;">
            <!-- Kontaktdaten -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 20px 0; font-size: 18px;">👤 Kontaktdaten</h2>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151; width: 140px;">Name:</td>
                        <td style="padding: 12px 0;"><strong>' . $salutation . ' ' . $firstName . ' ' . $lastName . '</strong></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151;">E-Mail:</td>
                        <td style="padding: 12px 0;"><a href="mailto:' . $email . '" style="color: #2563eb; text-decoration: none;">' . $email . '</a></td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151;">Telefon:</td>
                        <td style="padding: 12px 0;">' . ($phone ?: '<em style="color: #6b7280;">Nicht angegeben</em>') . '</td>
                    </tr>
                </table>
            </div>
            
            <!-- Badkonfiguration -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 20px 0; font-size: 18px;">🛁 Badkonfiguration</h2>
                
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151; width: 140px;">Badezimmergröße:</td>
                        <td style="padding: 12px 0;">
                            <span style="background: #3b82f6; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600;">' . ($bathroomData['bathroomSize'] ?? 'Nicht angegeben') . ' m²</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151;">Qualitätsstufe:</td>
                        <td style="padding: 12px 0;">
                            <span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600;">' . ($bathroomData['qualityLevel']['name'] ?? 'Nicht ausgewählt') . '</span>
                        </td>
                    </tr>
                </table>
                
                ' . (!empty($selectedEquipment) ? '
                <h3 style="color: #1e40af; margin: 20px 0 15px 0; font-size: 16px;">⚡ Gewählte Ausstattung</h3>
                <div>
                    ' . $this->renderEquipmentListHtml($selectedEquipment) . '
                </div>
                ' : '
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 15px; margin-top: 15px; text-align: center;">
                    <span style="color: #991b1b; font-style: italic;">💡 <strong>Hinweis:</strong> Keine spezifische Ausstattung ausgewählt.<br>
                    Wir beraten Sie gerne zu den passenden Optionen für Ihr Traumbad!</span>
                </div>
                ') . '
            </div>
            
            <!-- Fliesen & Heizung -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 20px 0; font-size: 18px;">🎨 Fliesen & Heizung</h2>
                
                <div style="margin-bottom: 15px;">
                    <div style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #e5e7eb; margin-bottom: 10px;">
                        <h4 style="color: #1e40af; margin: 0 0 8px 0; font-size: 14px;">🏠 Bodenfliesen</h4>
                        <div style="color: #6b7280; font-size: 14px;">' . ($floorTiles ?: '<em>Keine spezifische Auswahl</em>') . '</div>
                    </div>
                    
                    <div style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #e5e7eb; margin-bottom: 10px;">
                        <h4 style="color: #1e40af; margin: 0 0 8px 0; font-size: 14px;">🖼️ Wandfliesen</h4>
                        <div style="color: #6b7280; font-size: 14px;">' . ($wallTiles ?: '<em>Keine spezifische Auswahl</em>') . '</div>
                    </div>
                    
                    <div style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #e5e7eb;">
                        <h4 style="color: #1e40af; margin: 0 0 8px 0; font-size: 14px;">🔥 Heizung</h4>
                        <div style="color: #6b7280; font-size: 14px;">' . ($heating ?: '<em>Keine spezifische Auswahl</em>') . '</div>
                    </div>
                </div>
            </div>
            
            ' . (!empty($additionalInfoList) ? '
            <!-- Zusätzliche Informationen -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 15px 0; font-size: 18px;">📋 Gewünschte Informationen</h2>
                <div>
                    ' . implode('', array_map(function($info) {
                        return '<div style="background: white; padding: 8px 12px; margin-bottom: 5px; border-radius: 4px; border-left: 3px solid #10b981;">
                            ✓ <strong>' . htmlspecialchars($info, ENT_QUOTES, 'UTF-8') . '</strong>
                        </div>';
                    }, $additionalInfoList)) . '
                </div>
            </div>
            ' : '') . '
            
            ' . (!empty($comments) ? '
            <!-- Anmerkungen -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 15px 0; font-size: 18px;">💬 Anmerkungen</h2>
                <div style="background: white; border: 2px solid #e5e7eb; border-radius: 8px; padding: 15px; font-size: 14px; line-height: 1.6; color: #374151;">
                    ' . nl2br(htmlspecialchars($comments, ENT_QUOTES, 'UTF-8')) . '
                </div>
            </div>
            ' : '') . '
            
            <!-- Nächste Schritte -->
            <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 2px solid #10b981; border-radius: 8px; padding: 20px;">
                <h3 style="color: #065f46; margin: 0 0 10px 0; font-size: 16px;">🚀 So geht es weiter</h3>
                <p style="color: #047857; margin: 0 0 8px 0;"><strong>Wir melden uns innerhalb von 24 Stunden bei Ihnen!</strong></p>
                <p style="color: #047857; margin: 0 0 15px 0;">📞 Unser Expertenteam erstellt Ihnen ein maßgeschneidertes Angebot basierend auf Ihrer Konfiguration.</p>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 8px; font-weight: bold; color: #065f46; width: 30%;">Direkter Kontakt:</td>
                        <td style="padding: 8px; color: #047857;">' . htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold; color: #065f46;">E-Mail:</td>
                        <td style="padding: 8px; color: #047857;">' . htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') . '</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px; font-weight: bold; color: #065f46;">Adresse:</td>
                        <td style="padding: 8px; color: #047857;">' . htmlspecialchars($company['address'], ENT_QUOTES, 'UTF-8') . '<br>' . htmlspecialchars($company['city'], ENT_QUOTES, 'UTF-8') . '</td>
                    </tr>
                </table>
            </div>
            
            <!-- System Info -->
            <div style="background: #f1f5f9; border-radius: 8px; padding: 15px; margin-top: 20px;">
                <p style="margin: 0; font-size: 12px; color: #6b7280;"><strong>Referenz-ID:</strong> ' . $referenceId . '</p>
                <p style="margin: 5px 0 0 0; font-size: 12px; color: #6b7280;">Eingegangen am: ' . $timestamp . '</p>
                ' . ($this->testMode ? '<p style="margin: 5px 0 0 0; font-size: 12px; color: #f59e0b;"><strong>Status:</strong> Test-Modus</p>' : '') . '
            </div>
        </div>
        
        <!-- Footer -->
        <div style="background: #1f2937; color: #d1d5db; padding: 25px 30px; text-align: center; font-size: 14px;">
            <p style="margin: 0;"><strong>Diese E-Mail wurde ' . ($this->testMode ? 'simuliert' : 'automatisch') . ' über den Badkonfigurator generiert.</strong></p>
            <p style="margin: 10px 0 0 0;">Antworten Sie direkt an: <a href="mailto:' . $email . '" style="color: #60a5fa; text-decoration: none;">' . $email . '</a></p>
            <p style="margin: 15px 0 0 0; font-size: 12px; opacity: 0.8;">
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
     * Text E-Mail Body für Badkonfiguration generieren (Unternehmen)
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
        
        // Ausgewählte Ausstattung verarbeiten
        $selectedEquipment = $this->processSelectedEquipment($bathroomData['equipment'] ?? []);
        $equipmentText = !empty($selectedEquipment) 
            ? implode("\n  ", array_map(function($item) {
                return $item['name'] . ': ' . $item['option'] . ($item['description'] ? ' (' . $item['description'] . ')' : '');
              }, $selectedEquipment))
            : 'Keine Ausstattung ausgewählt';
        
        // Zusätzliche Informationen verarbeiten
        $additionalInfoList = $this->processAdditionalInfo($additionalInfo);
        $additionalInfoText = !empty($additionalInfoList) ? implode(', ', $additionalInfoList) : 'Keine';
        
        // Fliesen und Heizung
        $floorTiles = $this->safeJoin($bathroomData['floorTiles'] ?? []);
        $wallTiles = $this->safeJoin($bathroomData['wallTiles'] ?? []);
        $heating = $this->safeJoin($bathroomData['heating'] ?? []);
        
        return "
BADKONFIGURATOR ANFRAGE - " . $company['name'] . "
" . ($this->testMode ? "*** TEST MODUS - SIMULIERTE E-MAIL ***\n" : '') . "
========================================

KONTAKTDATEN:
" . ($contactData['salutation'] ?? '') . " " . ($contactData['firstName'] ?? '') . " " . ($contactData['lastName'] ?? '') . "
E-Mail: " . ($contactData['email'] ?? '') . "
Telefon: " . ($contactData['phone'] ?? 'Nicht angegeben') . "

BADKONFIGURATION:
Badezimmergröße: " . ($bathroomData['bathroomSize'] ?? 'Nicht angegeben') . " m²
Qualitätsstufe: " . ($bathroomData['qualityLevel']['name'] ?? 'Nicht ausgewählt') . "
" . (isset($bathroomData['qualityLevel']['description']) ? "Beschreibung: " . $bathroomData['qualityLevel']['description'] . "\n" : '') . "

GEWÄHLTE AUSSTATTUNG:
  {$equipmentText}

FLIESEN & HEIZUNG:
Bodenfliesen: {$floorTiles}
Wandfliesen: {$wallTiles}
Heizung: {$heating}

ZUSÄTZLICHE INFORMATIONEN:
{$additionalInfoText}

" . (!empty($comments) ? "ANMERKUNGEN:\n{$comments}\n\n" : '') . "SYSTEM-INFORMATIONEN:
Referenz-ID: {$referenceId}
Eingegangen am: {$timestamp}
" . ($this->testMode ? "Test-Modus: AKTIV\n" : '') . "

SO GEHT ES WEITER:
Wir melden uns innerhalb von 24 Stunden bei Ihnen!
Unser Expertenteam erstellt Ihnen ein maßgeschneidertes Angebot.

KONTAKT:
Telefon: " . $company['phone'] . "
E-Mail: " . $company['email'] . "
Adresse: " . $company['address'] . ", " . $company['city'] . "

---
Vielen Dank für Ihr Interesse an " . $company['name'] . "!
Bitte antworten Sie direkt an: " . ($contactData['email'] ?? '') . "
";
    }
    
    /**
     * NEUE METHODE: Bestätigungs-E-Mail Body für Kunden generieren
     */
    private function generateCustomerConfirmationEmailBody(
        array $contactData,
        array $bathroomData,
        string $comments,
        array $additionalInfo,
        string $referenceId,
        string $timestamp
    ): string {
        $company = Config::getCompanyInfo();
        $testModeHeader = $this->testMode ? 
            '<div style="background: linear-gradient(135deg, #fef3cd 0%, #fbbf24 100%); padding: 15px; margin-bottom: 25px; border: 1px solid #f59e0b; border-radius: 10px; text-align: center;"><strong>🧪 TEST MODUS:</strong> Diese E-Mail wurde nur simuliert</div>' : '';
        
        $firstName = htmlspecialchars($contactData['firstName'] ?? '', ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars($contactData['lastName'] ?? '', ENT_QUOTES, 'UTF-8');
        $salutation = htmlspecialchars($contactData['salutation'] ?? '', ENT_QUOTES, 'UTF-8');
        
        // Ausgewählte Ausstattung verarbeiten
        $selectedEquipment = $this->processSelectedEquipment($bathroomData['equipment'] ?? []);
        
        return '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Bestätigung Ihrer Badkonfigurator Anfrage</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px;">
    ' . $testModeHeader . '
    
    <div style="max-width: 700px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 40px 30px; text-align: center;">
            <div style="font-size: 20px; font-weight: bold; margin-bottom: 8px;">' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . '</div>
            <h1 style="margin: 0; font-size: 28px;">✅ Anfrage erhalten!</h1>
            <div style="margin-top: 10px; opacity: 0.9;">Vielen Dank für Ihr Vertrauen</div>
        </div>
        
        <div style="padding: 30px;">
            <!-- Persönliche Begrüßung -->
            <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 2px solid #10b981; border-radius: 12px; padding: 25px; margin-bottom: 25px; text-align: center;">
                <h2 style="color: #065f46; margin: 0 0 15px 0; font-size: 20px;">Liebe/r ' . $salutation . ' ' . $firstName . ' ' . $lastName . '!</h2>
                <p style="color: #047857; margin: 0; font-size: 16px;">Ihre Badkonfigurator Anfrage ist bei uns eingegangen. Wir freuen uns sehr über Ihr Interesse und werden uns <strong>innerhalb von 24 Stunden</strong> bei Ihnen melden!</p>
            </div>
            
            <!-- Ihre Konfiguration -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 20px 0; font-size: 18px;">🛁 Ihre Badkonfiguration</h2>
                
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151; width: 140px;">Badezimmergröße:</td>
                        <td style="padding: 12px 0;">
                            <span style="background: #3b82f6; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600;">' . ($bathroomData['bathroomSize'] ?? 'Nicht angegeben') . ' m²</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151;">Qualitätsstufe:</td>
                        <td style="padding: 12px 0;">
                            <span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600;">' . ($bathroomData['qualityLevel']['name'] ?? 'Nicht ausgewählt') . '</span>
                        </td>
                    </tr>
                </table>
                
                ' . (!empty($selectedEquipment) ? '
                <h3 style="color: #1e40af; margin: 20px 0 15px 0; font-size: 16px;">⚡ Ihre gewählte Ausstattung</h3>
                <div>
                    ' . $this->renderEquipmentListHtml($selectedEquipment) . '
                </div>
                ' : '') . '
            </div>
            
            <!-- Nächste Schritte -->
            <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 2px solid #10b981; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                <h3 style="color: #065f46; margin: 0 0 15px 0; font-size: 16px;">🚀 So geht es weiter</h3>
                <div style="color: #047857;">
                    <p style="margin: 0 0 10px 0;"><strong>1. Kontaktaufnahme (innerhalb 24h)</strong><br>
                    Unser Expertenteam meldet sich bei Ihnen für ein unverbindliches Beratungsgespräch.</p>
                    
                    <p style="margin: 0 0 10px 0;"><strong>2. Vor-Ort-Termin</strong><br>
                    Wir vereinbaren einen Termin zur Besichtigung und detaillierten Planung.</p>
                    
                    <p style="margin: 0;"><strong>3. Maßgeschneidertes Angebot</strong><br>
                    Sie erhalten ein individuelles Angebot basierend auf Ihren Wünschen.</p>
                </div>
            </div>
            
            <!-- Kontakt -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 15px 0; font-size: 18px;">📞 Haben Sie Fragen?</h2>
                <p style="color: #374151; margin: 0 0 15px 0;">Zögern Sie nicht, uns direkt zu kontaktieren:</p>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 8px 15px 8px 0; font-weight: bold; color: #374151; width: 30%;">Telefon:</td>
                        <td style="padding: 8px 0; color: #047857;"><a href="tel:' . htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') . '" style="color: #047857; text-decoration: none;">' . htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') . '</a></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 15px 8px 0; font-weight: bold; color: #374151;">E-Mail:</td>
                        <td style="padding: 8px 0; color: #047857;"><a href="mailto:' . htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') . '" style="color: #047857; text-decoration: none;">' . htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') . '</a></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 15px 8px 0; font-weight: bold; color: #374151;">Adresse:</td>
                        <td style="padding: 8px 0; color: #047857;">' . htmlspecialchars($company['address'], ENT_QUOTES, 'UTF-8') . '<br>' . htmlspecialchars($company['city'], ENT_QUOTES, 'UTF-8') . '</td>
                    </tr>
                </table>
            </div>
            
            <!-- Referenz Info -->
            <div style="background: #f1f5f9; border-radius: 8px; padding: 15px;">
                <p style="margin: 0; font-size: 12px; color: #6b7280;"><strong>Ihre Referenz-ID:</strong> ' . $referenceId . '</p>
                <p style="margin: 5px 0 0 0; font-size: 12px; color: #6b7280;">Eingegangen am: ' . $timestamp . '</p>
                ' . ($this->testMode ? '<p style="margin: 5px 0 0 0; font-size: 12px; color: #f59e0b;"><strong>Status:</strong> Test-Modus</p>' : '') . '
            </div>
        </div>
        
        <!-- Footer -->
        <div style="background: #1f2937; color: #d1d5db; padding: 25px 30px; text-align: center; font-size: 14px;">
            <p style="margin: 0;"><strong>Vielen Dank für Ihr Vertrauen in ' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . '!</strong></p>
            <p style="margin: 10px 0 0 0; font-size: 12px; opacity: 0.8;">
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
     * NEUE METHODE: Bestätigungs-E-Mail Text Body für Kunden generieren
     */
    private function generateCustomerConfirmationTextBody(
        array $contactData,
        array $bathroomData,
        string $comments,
        array $additionalInfo,
        string $referenceId,
        string $timestamp
    ): string {
        $company = Config::getCompanyInfo();
        $firstName = $contactData['firstName'] ?? '';
        $lastName = $contactData['lastName'] ?? '';
        $salutation = $contactData['salutation'] ?? '';
        
        return "
BESTÄTIGUNG IHRER BADKONFIGURATOR ANFRAGE
" . $company['name'] . "
" . ($this->testMode ? "*** TEST MODUS - SIMULIERTE E-MAIL ***\n" : '') . "
========================================

Liebe/r {$salutation} {$firstName} {$lastName}!

Vielen Dank für Ihre Anfrage über unseren Badkonfigurator!
Ihre Anfrage ist bei uns eingegangen und wir werden uns innerhalb von 24 Stunden bei Ihnen melden.

IHRE KONFIGURATION:
Badezimmergröße: " . ($bathroomData['bathroomSize'] ?? 'Nicht angegeben') . " m²
Qualitätsstufe: " . ($bathroomData['qualityLevel']['name'] ?? 'Nicht ausgewählt') . "

SO GEHT ES WEITER:
1. Kontaktaufnahme (innerhalb 24h)
   Unser Expertenteam meldet sich bei Ihnen für ein unverbindliches Beratungsgespräch.

2. Vor-Ort-Termin
   Wir vereinbaren einen Termin zur Besichtigung und detaillierten Planung.

3. Maßgeschneidertes Angebot
   Sie erhalten ein individuelles Angebot basierend auf Ihren Wünschen.

KONTAKT:
Telefon: " . $company['phone'] . "
E-Mail: " . $company['email'] . "
Adresse: " . $company['address'] . ", " . $company['city'] . "

IHRE REFERENZ-ID: {$referenceId}
Eingegangen am: {$timestamp}
" . ($this->testMode ? "Test-Modus: AKTIV\n" : '') . "

Vielen Dank für Ihr Vertrauen in " . $company['name'] . "!

Bei Fragen können Sie uns jederzeit kontaktieren.
";
    }
    
    /**
     * Ausgewählte Ausstattung verarbeiten - HILFSMETHODE
     */
    private function processSelectedEquipment(array $equipment): array
    {
        $selected = [];
        
        foreach ($equipment as $item) {
            if (!isset($item['selected']) || !$item['selected']) {
                continue;
            }
            
            $selectedOption = null;
            if (isset($item['popupDetails']['options']) && is_array($item['popupDetails']['options'])) {
                foreach ($item['popupDetails']['options'] as $opt) {
                    if (isset($opt['selected']) && $opt['selected']) {
                        $selectedOption = $opt;
                        break;
                    }
                }
            }
            
            $selected[] = [
                'name' => htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8'),
                'option' => $selectedOption ? htmlspecialchars($selectedOption['name'] ?? 'Standard', ENT_QUOTES, 'UTF-8') : 'Standard',
                'description' => $selectedOption ? htmlspecialchars($selectedOption['description'] ?? '', ENT_QUOTES, 'UTF-8') : ''
            ];
        }
        
        return $selected;
    }
    
    /**
     * Equipment Liste für HTML rendern - HILFSMETHODE
     */
    private function renderEquipmentListHtml(array $equipment): string
    {
        $html = '';
        
        foreach ($equipment as $item) {
            $html .= '
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; margin-bottom: 8px;">
                <div style="font-weight: bold; color: #1e40af; font-size: 14px; margin-bottom: 4px;">' . $item['name'] . '</div>
                <div style="color: #6b7280; font-size: 12px; line-height: 1.4;">' . $item['option'] . '</div>
                ' . ($item['description'] ? '<div style="color: #6b7280; font-size: 12px; margin-top: 3px; font-style: italic;">' . $item['description'] . '</div>' : '') . '
            </div>';
        }
        
        return $html;
    }
    
    /**
     * Zusätzliche Informationen verarbeiten - HILFSMETHODE
     */
    private function processAdditionalInfo(array $additionalInfo): array
    {
        $labels = [
            'projektablauf' => 'Projektablauf',
            'garantie' => 'Garantie & Gewährleistung',
            'referenzen' => 'Referenzen',
            'foerderung' => 'Förderungsmöglichkeiten'
        ];
        
        $selected = [];
        foreach ($additionalInfo as $key => $value) {
            if ($value) {
                $selected[] = $labels[$key] ?? $key;
            }
        }
        
        return $selected;
    }
    
    /**
     * Sichere Array-Join Funktion - HILFSMETHODE
     */
    private function safeJoin(array $array, string $separator = ', '): string
    {
        if (empty($array)) {
            return '';
        }
        
        $filtered = array_filter($array, function($item) {
            return $item !== null && $item !== '';
        });
        
        return !empty($filtered) ? implode($separator, $filtered) : '';
    }
    
    /**
     * HTML E-Mail Body für Kontaktformular Kundenbestätigung generieren
     */
    private function generateContactFormCustomerEmailBody(array $formData, string $referenceId, string $timestamp): string
    {
        $company = Config::getCompanyInfo();
        $testModeHeader = $this->testMode ? 
            '<div style="background: linear-gradient(135deg, #fef3cd 0%, #fbbf24 100%); padding: 15px; margin-bottom: 25px; border: 1px solid #f59e0b; border-radius: 10px; text-align: center;"><strong>🧪 TEST MODUS:</strong> Diese E-Mail wurde nur simuliert</div>' : '';
        
        $firstName = htmlspecialchars($formData['firstName'] ?? '', ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars($formData['lastName'] ?? '', ENT_QUOTES, 'UTF-8');
        $subject = htmlspecialchars($formData['subject'] ?? '', ENT_QUOTES, 'UTF-8');
        $message = nl2br(htmlspecialchars($formData['message'] ?? '', ENT_QUOTES, 'UTF-8'));
        
        $serviceLabels = [
            'heating' => 'Heizungsbau',
            'bathroom' => 'Bäderbau', 
            'installation' => 'Installation',
            'emergency' => 'Notdienst',
            'consultation' => 'Beratung'
        ];
        
        $selectedService = $serviceLabels[$formData['service'] ?? ''] ?? 'Nicht angegeben';
        
        return '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Bestätigung Ihrer Kontaktanfrage</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 20px;">
    ' . $testModeHeader . '
    
    <div style="max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 40px 30px; text-align: center;">
            <div style="font-size: 20px; font-weight: bold; margin-bottom: 8px;">' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . '</div>
            <h1 style="margin: 0; font-size: 28px;">✅ Anfrage erhalten!</h1>
            <div style="margin-top: 10px; opacity: 0.9;">Vielen Dank für Ihre Nachricht</div>
        </div>
        
        <div style="padding: 30px;">
            <!-- Persönliche Begrüßung -->
            <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 2px solid #10b981; border-radius: 12px; padding: 25px; margin-bottom: 25px; text-align: center;">
                <h2 style="color: #065f46; margin: 0 0 15px 0; font-size: 20px;">Liebe/r ' . $firstName . ' ' . $lastName . '!</h2>
                <p style="color: #047857; margin: 0; font-size: 16px;">Ihre Kontaktanfrage ist bei uns eingegangen. Wir werden uns <strong>schnellstmöglich</strong> bei Ihnen melden!</p>
            </div>
            
            <!-- Ihre Anfrage -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 20px 0; font-size: 18px;">📋 Ihre Anfrage</h2>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151; width: 120px;">Service:</td>
                        <td style="padding: 12px 0;">
                            <span style="background: #3b82f6; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600;">' . $selectedService . '</span>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #e2e8f0;">
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151;">Betreff:</td>
                        <td style="padding: 12px 0;"><strong>' . $subject . '</strong></td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 15px 12px 0; font-weight: 600; color: #374151;">Priorität:</td>
                        <td style="padding: 12px 0;">' . 
                            (($formData['urgent'] ?? false) ? 
                                '<span style="background: #f59e0b; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600;">DRINGEND</span>' : 
                                '<span style="background: #10b981; color: white; padding: 4px 12px; border-radius: 15px; font-size: 12px; font-weight: 600;">Normal</span>') . 
                        '</td>
                    </tr>
                </table>
                
                <div style="margin-top: 20px;">
                    <h3 style="color: #1e40af; margin: 0 0 10px 0; font-size: 16px;">Ihre Nachricht:</h3>
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 6px; padding: 15px; font-size: 14px; line-height: 1.6; color: #374151;">
                        ' . $message . '
                    </div>
                </div>
            </div>
            
            <!-- Nächste Schritte -->
            <div style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 2px solid #10b981; border-radius: 8px; padding: 20px; margin-bottom: 25px;">
                <h3 style="color: #065f46; margin: 0 0 15px 0; font-size: 16px;">🚀 So geht es weiter</h3>
                <div style="color: #047857;">
                    ' . (($formData['urgent'] ?? false) ? 
                        '<p style="margin: 0 0 10px 0; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 10px; color: #991b1b;">
                            <strong>🚨 Da Ihre Anfrage als DRINGEND markiert wurde, werden wir uns prioritär um Ihr Anliegen kümmern!</strong>
                        </p>' : '') . '
                    <p style="margin: 0 0 10px 0;"><strong>📞 Wir melden uns bei Ihnen</strong><br>
                    Unser Team wird Ihre Anfrage prüfen und sich schnellstmöglich bei Ihnen melden.</p>
                    
                    <p style="margin: 0;"><strong>💡 Individuelle Beratung</strong><br>
                    Wir besprechen Ihr Anliegen persönlich und finden die beste Lösung für Sie.</p>
                </div>
            </div>
            
            <!-- Kontakt -->
            <div style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h2 style="color: #1e40af; margin: 0 0 15px 0; font-size: 18px;">📞 Bei dringenden Fragen</h2>
                <p style="color: #374151; margin: 0 0 15px 0;">Sie können uns auch direkt erreichen:</p>
                
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="padding: 8px 15px 8px 0; font-weight: bold; color: #374151; width: 30%;">Telefon:</td>
                        <td style="padding: 8px 0; color: #047857;"><a href="tel:' . htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') . '" style="color: #047857; text-decoration: none;">' . htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') . '</a></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 15px 8px 0; font-weight: bold; color: #374151;">E-Mail:</td>
                        <td style="padding: 8px 0; color: #047857;"><a href="mailto:' . htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') . '" style="color: #047857; text-decoration: none;">' . htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') . '</a></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 15px 8px 0; font-weight: bold; color: #374151;">Adresse:</td>
                        <td style="padding: 8px 0; color: #047857;">' . htmlspecialchars($company['address'], ENT_QUOTES, 'UTF-8') . '<br>' . htmlspecialchars($company['city'], ENT_QUOTES, 'UTF-8') . '</td>
                    </tr>
                </table>
            </div>
            
            <!-- Referenz Info -->
            <div style="background: #f1f5f9; border-radius: 8px; padding: 15px;">
                <p style="margin: 0; font-size: 12px; color: #6b7280;"><strong>Ihre Referenz-ID:</strong> ' . $referenceId . '</p>
                <p style="margin: 5px 0 0 0; font-size: 12px; color: #6b7280;">Eingegangen am: ' . $timestamp . '</p>
                ' . ($this->testMode ? '<p style="margin: 5px 0 0 0; font-size: 12px; color: #f59e0b;"><strong>Status:</strong> Test-Modus</p>' : '') . '
            </div>
        </div>
        
        <!-- Footer -->
        <div style="background: #1f2937; color: #d1d5db; padding: 25px 30px; text-align: center; font-size: 14px;">
            <p style="margin: 0;"><strong>Vielen Dank für Ihr Vertrauen in ' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . '!</strong></p>
            <p style="margin: 10px 0 0 0; font-size: 12px; opacity: 0.8;">
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
     * Text E-Mail Body für Kontaktformular Kundenbestätigung generieren
     */
    private function generateContactFormCustomerTextBody(array $formData, string $referenceId, string $timestamp): string
    {
        $company = Config::getCompanyInfo();
        $urgentText = ($formData['urgent'] ?? false) ? "\n*** IHRE ANFRAGE WURDE ALS DRINGEND MARKIERT ***\n" : '';
        
        $serviceLabels = [
            'heating' => 'Heizungsbau',
            'bathroom' => 'Bäderbau', 
            'installation' => 'Installation',
            'emergency' => 'Notdienst',
            'consultation' => 'Beratung'
        ];
        
        $selectedService = $serviceLabels[$formData['service'] ?? ''] ?? 'Nicht angegeben';
        
        return "
BESTÄTIGUNG IHRER KONTAKTANFRAGE
" . $company['name'] . "
" . ($this->testMode ? "*** TEST MODUS - SIMULIERTE E-MAIL ***\n" : '') . "
========================================

Liebe/r " . $formData['firstName'] . " " . $formData['lastName'] . "!

Vielen Dank für Ihre Kontaktanfrage!
Ihre Nachricht ist bei uns eingegangen und wir werden uns schnellstmöglich bei Ihnen melden.
{$urgentText}
IHRE ANFRAGE:
Service: " . $selectedService . "
Betreff: " . $formData['subject'] . "
Priorität: " . (($formData['urgent'] ?? false) ? 'DRINGEND' : 'Normal') . "

Ihre Nachricht:
" . $formData['message'] . "

SO GEHT ES WEITER:
1. Wir prüfen Ihre Anfrage
2. Ein Mitarbeiter meldet sich bei Ihnen
3. Gemeinsam finden wir die beste Lösung für Sie

BEI DRINGENDEN FRAGEN:
Telefon: " . $company['phone'] . "
E-Mail: " . $company['email'] . "
Adresse: " . $company['address'] . ", " . $company['city'] . "

IHRE REFERENZ-ID: {$referenceId}
Eingegangen am: {$timestamp}
" . ($this->testMode ? "Test-Modus: AKTIV\n" : '') . "

Vielen Dank für Ihr Vertrauen in " . $company['name'] . "!

Mit freundlichen Grüßen
Ihr " . $company['name'] . " Team
";
    }
}