<?php
declare(strict_types=1);

namespace MitraSanitaer\Controllers;

use MitraSanitaer\Config\Config;
use MitraSanitaer\Services\EmailService;
use MitraSanitaer\Services\PdfService;
use MitraSanitaer\Middleware\ValidationMiddleware;
use MitraSanitaer\Utils\Logger;

class ApiController
{
    private EmailService $emailService;
    private PdfService $pdfService;

    public function __construct()
    {
        $this->emailService = new EmailService();
        $this->pdfService = new PdfService();
    }

    /**
     * Health Check Endpoint
     */
    public function healthCheck(): void
    {
        Logger::api('/health', 'GET');

        $healthCheck = [
            'status' => 'OK',
            'timestamp' => date('c'),
            'service' => 'Mitra Sanitär Backend PHP',
            'version' => '1.0.0',
            'uptime' => $this->getUptime(),
            'environment' => Config::get('APP_ENV', 'development'),
            'endpoints' => [
                'health' => '/api/health',
                'contact' => '/api/contact',
                'bathroomConfiguration' => '/api/send-bathroom-configuration',
                'pdfTest' => '/api/generate-pdf-only',
                'debugPdfs' => '/api/debug-pdfs'
            ],
            'services' => [
                'email' => $this->emailService->getServiceInfo(),
                'pdf' => ['available' => true]
            ]
        ];

        Logger::info('Health check aufgerufen');
        $this->jsonResponse($healthCheck);
    }

    /**
     * Kontaktformular verarbeiten (mit Bestätigungsmail für Kunden)
     */
    public function contact(): void
    {
        try {
            Logger::api('/contact', 'POST');

            // JSON Input validieren
            $inputValidation = ValidationMiddleware::validateJsonInput();
            if (!$inputValidation['valid']) {
                $this->errorResponse($inputValidation['error'], 400);
                return;
            }

            $formData = ValidationMiddleware::sanitizeInput($inputValidation['data']);

            // Kontaktformular validieren
            $validation = ValidationMiddleware::validateContactForm($formData);
            if (!$validation['valid']) {
                Logger::warning('Kontaktformular Validierungsfehler', [
                    'errors' => $validation['errors'],
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);

                $this->errorResponse('Validierungsfehler in den Formulardaten', 400, [
                    'errors' => $validation['errors']
                ]);
                return;
            }

            // Sicherheitsprüfung auf gefährliche Inhalte
            foreach (['message', 'subject'] as $field) {
                if (isset($formData[$field]) && ValidationMiddleware::containsDangerousContent($formData[$field])) {
                    Logger::security('Dangerous content detected in contact form', [
                        'field' => $field,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);

                    $this->errorResponse('Ungültiger Inhalt erkannt', 400);
                    return;
                }
            }

            // E-Mail an Admin senden + Kunden-Bestätigungsmail (beides erledigt sendContactForm)
            $result = $this->emailService->sendContactForm($formData);

            // Kunden-Resultat extrahieren (Bestätigungsmail)
            $customerConfirmationResult = $result['results']['customer'] ?? null;

            if ($result['success']) {
                Logger::email('Kontaktformular gesendet', [
                    'referenceId' => $result['referenceId'] ?? null,
                    'successfulRecipients' => $result['successfulRecipients'] ?? []
                ]);

                $response = [
                    'success' => true,
                    'message' => 'Ihre Nachricht wurde erfolgreich versendet. Wir melden uns schnellstmöglich bei Ihnen zurück.',
                    'timestamp' => date('c'),
                    'referenceId' => $result['referenceId'],
                    'customerConfirmation' => $customerConfirmationResult ? $customerConfirmationResult['success'] : false,
                    'testMode' => $result['testMode'] ?? false
                ];
                $this->jsonResponse($response);
            } else {
                throw new \Exception($result['message']);
            }

        } catch (\Exception $e) {
            Logger::error('Fehler beim Senden des Kontaktformulars: ' . $e->getMessage());

            $this->errorResponse(
                'Fehler beim Senden der E-Mail. Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.',
                500,
                Config::get('APP_ENV') === 'development' ? ['debug' => $e->getMessage()] : []
            );
        }
    }

    /**
     * Badkonfigurator verarbeiten (mit Bestätigungsmail für Kunden)
     */
    public function sendBathroomConfiguration(): void
    {
        try {
            Logger::api('/send-bathroom-configuration', 'POST');

            // JSON Input validieren
            $inputValidation = ValidationMiddleware::validateJsonInput();
            if (!$inputValidation['valid']) {
                $this->errorResponse($inputValidation['error'], 400);
                return;
            }

            $data = ValidationMiddleware::sanitizeInput($inputValidation['data']);
            $contactData = $data['contactData'] ?? [];
            $bathroomData = $data['bathroomData'] ?? [];
            $comments = $data['comments'] ?? '';
            $additionalInfo = $data['additionalInfo'] ?? [];

            Logger::api('/send-bathroom-configuration', 'POST', [
                'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? ''),
                'bathroomSize' => $bathroomData['bathroomSize'] ?? 'not set',
                'equipmentCount' => isset($bathroomData['equipment']) ? 
                    count(array_filter($bathroomData['equipment'], fn($e) => $e['selected'] ?? false)) : 0
            ]);

            // Badkonfigurator validieren (ultra-robust)
            $validation = ValidationMiddleware::validateBathroomConfiguration($data);
            if (!$validation['valid']) {
                // In ultra-robustem Modus: Nur warnen, nicht blockieren
                Logger::info('⚠️ Badkonfigurator Validierungswarnungen (werden ignoriert)', [
                    'warnings' => $validation['errors'] ?? [],
                    'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? 'Unknown')
                ]);
            }

            $results = [];

            // 1. PDF GENERIEREN
            Logger::pdf('Generierung gestartet', [
                'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '')
            ]);

            try {
                $pdfResult = $this->pdfService->generateBathroomConfigurationPDF([
                    'contactData' => $contactData,
                    'bathroomData' => $bathroomData,
                    'comments' => $comments,
                    'additionalInfo' => $additionalInfo
                ]);

                $results['pdf'] = $pdfResult;

                if (!$pdfResult['success']) {
                    Logger::warning('PDF Generierung fehlgeschlagen: ' . $pdfResult['message']);
                }

            } catch (\Exception $e) {
                $results['pdf'] = [
                    'success' => false,
                    'message' => 'PDF-Service nicht verfügbar: ' . $e->getMessage()
                ];
                Logger::warning('PDF-Service Fehler: ' . $e->getMessage());
            }

            // 2. E-MAILS SENDEN (sowohl an Unternehmen als auch an Kunden)
            try {
                $emailData = [
                    'contactData' => $contactData,
                    'bathroomData' => $bathroomData,
                    'comments' => $comments,
                    'additionalInfo' => $additionalInfo
                ];

                // PDF anhängen wenn erfolgreich generiert
                if ($results['pdf']['success'] ?? false) {
                    $emailData['pdfPath'] = $results['pdf']['filePath'];
                    $emailData['pdfFilename'] = $results['pdf']['filename'];
                }

                // WICHTIG: Nur EINE Methode aufrufen - sendBathroomConfiguration sendet BEIDE E-Mails!
                $emailResult = $this->emailService->sendBathroomConfiguration($emailData);
                $results['email'] = $emailResult;

                Logger::email('Badkonfigurator E-Mail-Versand abgeschlossen', [
                    'success' => $emailResult['success'],
                    'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? ''),
                    'referenceId' => $emailResult['referenceId'] ?? 'N/A',
                    'successfulRecipients' => $emailResult['successfulRecipients'] ?? [],
                    'failedRecipients' => $emailResult['failedRecipients'] ?? []
                ]);

            } catch (\Exception $e) {
                $results['email'] = [
                    'success' => false,
                    'message' => 'E-Mail-Service nicht verfügbar: ' . $e->getMessage(),
                    'testMode' => false
                ];
                Logger::error('E-Mail-Service Fehler: ' . $e->getMessage());
            }

            // 3. GESAMTERGEBNIS BESTIMMEN
            $emailSuccess = $results['email']['success'] ?? false;
            $pdfSuccess = $results['pdf']['success'] ?? false;

            // Erfolg wenn mindestens E-Mail funktioniert hat
            $overallSuccess = $emailSuccess;

            $message = 'Badkonfigurator-Anfrage verarbeitet';
            if ($overallSuccess) {
                $testMode = $results['email']['testMode'] ?? false;
                $message = $testMode ? 
                    'Badkonfigurator-Anfrage erfolgreich simuliert (Test-Modus)' : 
                    'Ihre Badkonfiguration wurde erfolgreich versendet. Wir erstellen Ihnen gerne ein individuelles Angebot.';

                // Erfolgreiche Empfänger hinzufügen
                if (!empty($results['email']['successfulRecipients'])) {
                    $message .= ' (E-Mails versendet an: ' . implode(', ', $results['email']['successfulRecipients']) . ')';
                }

                // PDF-Info hinzufügen
                if ($pdfSuccess) {
                    $message .= ' (PDF: ' . ($results['pdf']['filename'] ?? 'generiert') . ')';
                } else {
                    $message .= ' (PDF konnte nicht generiert werden)';
                }
            } else {
                $message = 'Badkonfigurator-Anfrage konnte nicht vollständig verarbeitet werden';
            }

            // 4. ANTWORT SENDEN
            $response = [
                'success' => $overallSuccess,
                'message' => $message,
                'timestamp' => date('c'),
                'referenceId' => $results['email']['referenceId'] ?? null,
                'pdfGenerated' => $pdfSuccess,
                'emailSent' => $emailSuccess,
                'customerConfirmation' => isset($results['email']['results']['customer']) ? 
                    $results['email']['results']['customer']['success'] : false,
                'testMode' => $results['email']['testMode'] ?? false
            ];

            // Debug-Informationen hinzufügen wenn aktiviert
            if (Config::get('PDF_DEBUG_MODE') && $pdfSuccess) {
                $response['debug'] = [
                    'filename' => $results['pdf']['filename'] ?? null,
                    'downloadUrl' => $results['pdf']['downloadUrl'] ?? null,
                    'pdfSize' => $results['pdf']['size'] ?? null,
                    'pdfSaved' => $results['pdf']['saved'] ?? false
                ];
            }

            $statusCode = $overallSuccess ? 200 : 500;
            $this->jsonResponse($response, $statusCode);

        } catch (\Exception $e) {
            Logger::error('Unbekannter Fehler in sendBathroomConfiguration: ' . $e->getMessage());

            $this->errorResponse(
                'Fehler beim Verarbeiten Ihrer Badkonfiguration. Bitte versuchen Sie es erneut.',
                500,
                [
                    'pdfGenerated' => false,
                    'emailSent' => false,
                    'debug' => Config::get('APP_ENV') === 'development' ? $e->getMessage() : null
                ]
            );
        }
    }

    /**
     * PDF Test ohne E-Mail
     */
    public function generatePdfOnly(): void
    {
        try {
            Logger::api('/generate-pdf-only', 'POST');

            // JSON Input validieren
            $inputValidation = ValidationMiddleware::validateJsonInput();
            if (!$inputValidation['valid']) {
                $this->errorResponse($inputValidation['error'], 400);
                return;
            }

            $data = ValidationMiddleware::sanitizeInput($inputValidation['data']);
            $contactData = $data['contactData'] ?? [];
            $bathroomData = $data['bathroomData'] ?? [];
            $comments = $data['comments'] ?? '';
            $additionalInfo = $data['additionalInfo'] ?? [];

            Logger::api('/generate-pdf-only', 'POST', [
                'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '')
            ]);

            $result = $this->pdfService->generateBathroomConfigurationPDF([
                'contactData' => $contactData,
                'bathroomData' => $bathroomData,
                'comments' => $comments,
                'additionalInfo' => $additionalInfo
            ]);

            if ($result['success']) {
                Logger::pdf('Test PDF erfolgreich generiert', [
                    'filename' => $result['filename'],
                    'size' => $result['size']
                ]);

                $this->jsonResponse([
                    'success' => true,
                    'message' => 'PDF wurde erfolgreich generiert',
                    'timestamp' => date('c'),
                    'debug' => [
                        'filename' => $result['filename'],
                        'downloadUrl' => $result['downloadUrl'],
                        'pdfSize' => $result['size'],
                        'pdfSaved' => $result['saved'],
                        'outputPath' => Config::get('APP_ENV') === 'development' ? $result['filePath'] : null
                    ]
                ]);
            } else {
                throw new \Exception($result['message']);
            }

        } catch (\Exception $e) {
            Logger::error('Fehler bei PDF-Test: ' . $e->getMessage());

            $this->errorResponse(
                'Fehler beim Generieren des Test-PDFs',
                500,
                Config::get('APP_ENV') === 'development' ? ['debug' => $e->getMessage()] : []
            );
        }
    }

    /**
     * Debug PDFs auflisten
     */
    public function debugPdfs(): void
    {
        try {
            if (!Config::get('PDF_DEBUG_MODE')) {
                $this->errorResponse('Debug Modus ist nicht aktiviert', 403, [
                    'debugMode' => false
                ]);
                return;
            }

            Logger::api('/debug-pdfs', 'GET');

            $result = $this->pdfService->listDebugPDFs();

            $this->jsonResponse([
                'success' => true,
                'debugMode' => true,
                'count' => count($result['pdfs']),
                'pdfs' => $result['pdfs'],
                'totalSize' => $result['totalSize'],
                'outputDirectory' => $result['outputDirectory'],
                'timestamp' => date('c')
            ]);

        } catch (\Exception $e) {
            Logger::error('Fehler beim Auflisten der Debug-PDFs: ' . $e->getMessage());

            $this->errorResponse(
                'Fehler beim Auflisten der Debug-PDFs',
                500,
                [
                    'debugMode' => Config::get('PDF_DEBUG_MODE'),
                    'debug' => Config::get('APP_ENV') === 'development' ? $e->getMessage() : null
                ]
            );
        }
    }

    /**
     * Debug PDFs löschen
     */
    public function clearDebugPdfs(): void
    {
        try {
            if (!Config::get('PDF_DEBUG_MODE')) {
                $this->errorResponse('Debug Modus ist nicht aktiviert', 403);
                return;
            }

            Logger::api('/debug-pdfs', 'DELETE');

            $result = $this->pdfService->clearDebugPDFs();

            Logger::pdf('Debug PDFs gelöscht', ['count' => $result['deletedCount']]);

            $this->jsonResponse([
                'success' => true,
                'message' => $result['deletedCount'] . ' Debug-PDFs wurden gelöscht',
                'deletedCount' => $result['deletedCount'],
                'timestamp' => date('c')
            ]);

        } catch (\Exception $e) {
            Logger::error('Fehler beim Löschen der Debug-PDFs: ' . $e->getMessage());

            $this->errorResponse(
                'Fehler beim Löschen der Debug-PDFs',
                500,
                Config::get('APP_ENV') === 'development' ? ['debug' => $e->getMessage()] : []
            );
        }
    }

    /**
     * Debug PDF Download
     */
    public function downloadDebugPdf(string $filename): void
    {
        try {
            if (!Config::get('PDF_DEBUG_MODE')) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'message' => 'Debug Modus ist nicht aktiviert'
                ]);
                return;
            }

            Logger::api('/debug/pdfs/' . $filename, 'GET');

            $result = $this->pdfService->servePdfDownload($filename);

            if (!$result['success']) {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => $result['message']
                ]);
            }
            // PDF wird direkt von servePdfDownload() ausgegeben

        } catch (\Exception $e) {
            Logger::error('Fehler beim PDF Download: ' . $e->getMessage());

            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Fehler beim PDF Download'
            ]);
        }
    }

    /**
     * JSON Response senden
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Error Response senden
     */
    private function errorResponse(string $message, int $statusCode = 500, array $additional = []): void
    {
        $response = array_merge([
            'success' => false,
            'message' => $message,
            'timestamp' => date('c')
        ], $additional);

        $this->jsonResponse($response, $statusCode);
    }

    /**
     * Server Uptime berechnen
     */
    private function getUptime(): int
    {
        if (function_exists('sys_getloadavg')) {
            // Für Unix-Systeme
            $uptime = shell_exec('uptime');
            if ($uptime && preg_match('/up\s+(\d+)/', $uptime, $matches)) {
                return (int) $matches[1];
            }
        }

        // Fallback: Geschätzte Uptime basierend auf Script-Start
        return time() - $_SERVER['REQUEST_TIME'];
    }
}