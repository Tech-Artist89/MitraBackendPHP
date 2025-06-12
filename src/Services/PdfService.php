<?php
declare(strict_types=1);

namespace MitraSanitaer\Services;

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use MitraSanitaer\Config\Config;
use MitraSanitaer\Utils\Logger;
use Ramsey\Uuid\Uuid;
use Carbon\Carbon;

class PdfService
{
    private string $outputDir;
    
    public function __construct()
    {
        $config = Config::getPdfConfig();
        $this->outputDir = $config['output_dir'];
        $this->ensureOutputDirectory();
    }
    
    /**
     * Output-Verzeichnis erstellen
     */
    private function ensureOutputDirectory(): void
    {
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
            Logger::pdf('Output-Verzeichnis erstellt', ['path' => $this->outputDir]);
        }
    }
    
    /**
     * Badkonfigurator PDF generieren
     */
    public function generateBathroomConfigurationPDF(array $data): array
    {
        try {
            $contactData = $data['contactData'];
            $bathroomData = $data['bathroomData'] ?? [];
            $comments = $data['comments'] ?? '';
            $additionalInfo = $data['additionalInfo'] ?? [];
            
            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $filename = 'Badkonfigurator_' . ($contactData['lastName'] ?? 'Unknown') . '_' . $timestamp . '.pdf';
            $filePath = $this->outputDir . '/' . $filename;
            
            Logger::pdf('PDF Generierung gestartet', [
                'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? ''),
                'filename' => $filename
            ]);
            
            // mPDF konfigurieren
            $defaultConfig = (new ConfigVariables())->getDefaults();
            $fontDirs = $defaultConfig['fontDir'];
            
            $defaultFontConfig = (new FontVariables())->getDefaults();
            $fontData = $defaultFontConfig['fontdata'];
            
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'orientation' => 'P',
                'margin_left' => 15,
                'margin_right' => 15,
                'margin_top' => 20,
                'margin_bottom' => 20,
                'margin_header' => 10,
                'margin_footer' => 10,
                'fontDir' => array_merge($fontDirs, [
                    __DIR__ . '/../../storage/fonts'
                ]),
                'fontdata' => $fontData + [
                    'dejavusans' => [
                        'R' => 'DejaVuSans.ttf',
                        'B' => 'DejaVuSans-Bold.ttf',
                        'I' => 'DejaVuSans-Oblique.ttf',
                        'BI' => 'DejaVuSans-BoldOblique.ttf'
                    ]
                ],
                'default_font' => 'dejavusans'
            ]);
            
            // HTML Content generieren
            $htmlContent = $this->generateBathroomConfigurationHTML(
                $contactData, $bathroomData, $comments, $additionalInfo
            );
            
            // Header und Footer setzen
            $company = Config::getCompanyInfo();
            $mpdf->SetHTMLHeader($this->generatePdfHeader());
            $mpdf->SetHTMLFooter($this->generatePdfFooter());
            
            // HTML zu PDF konvertieren
            $mpdf->WriteHTML($htmlContent);
            
            // PDF speichern
            $mpdf->Output($filePath, 'F');
            
            // File Stats
            $fileSize = filesize($filePath);
            $fileSizeKB = round($fileSize / 1024, 2);
            
            Logger::pdf('PDF erfolgreich generiert', [
                'filename' => $filename,
                'size' => $fileSizeKB . ' KB',
                'path' => $filePath
            ]);
            
            return [
                'success' => true,
                'message' => 'PDF erfolgreich generiert',
                'filename' => $filename,
                'filePath' => $filePath,
                'size' => $fileSizeKB . ' KB',
                'saved' => true,
                'downloadUrl' => Config::get('PDF_DEBUG_MODE') ? 
                    Config::get('APP_URL', 'http://localhost:8000') . '/debug/pdfs/' . $filename : null
            ];
            
        } catch (\Exception $e) {
            Logger::error('Fehler bei PDF-Generierung: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'PDF konnte nicht generiert werden: ' . $e->getMessage(),
                'filename' => null,
                'filePath' => null,
                'saved' => false
            ];
        }
    }
    
    /**
     * PDF Header generieren
     */
    private function generatePdfHeader(): string
    {
        $company = Config::getCompanyInfo();
        
        return '
        <table width="100%" style="font-size: 10px; color: #666;">
            <tr>
                <td style="text-align: left;">' . $company['name'] . ' - Badkonfigurator</td>
                <td style="text-align: right;">Seite {PAGENO} von {nbpg}</td>
            </tr>
        </table>
        ';
    }
    
    /**
     * PDF Footer generieren
     */
    private function generatePdfFooter(): string
    {
        $company = Config::getCompanyInfo();
        
        return '
        <table width="100%" style="font-size: 10px; color: #666; border-top: 1px solid #ccc; padding-top: 5px;">
            <tr>
                <td style="text-align: center;">
                    Erstellt am ' . Carbon::now()->format('d.m.Y H:i:s') . ' | ' . 
                    $company['name'] . ' | ' . 
                    $company['phone'] . ' | ' . 
                    $company['email'] . '
                </td>
            </tr>
        </table>
        ';
    }
    
    /**
     * HTML Content für Badkonfigurator PDF generieren
     */
    private function generateBathroomConfigurationHTML(
        array $contactData,
        array $bathroomData,
        string $comments,
        array $additionalInfo
    ): string {
        // Ausgewählte Ausstattung formatieren
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
                    
                    $selectedEquipment[] = [
                        'name' => $item['name'] ?? '',
                        'option' => $selectedOption ? ($selectedOption['name'] ?? 'Standard') : 'Standard',
                        'description' => $selectedOption ? ($selectedOption['description'] ?? '') : ''
                    ];
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
        
        $company = Config::getCompanyInfo();
        
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Badkonfigurator - ' . ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '') . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #fff;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: bold;
        }
        
        .header .subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .section-title {
            color: #1e3a8a;
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .info-grid {
            width: 100%;
            margin-bottom: 15px;
        }
        
        .info-row {
            display: table-row;
        }
        
        .info-label {
            display: table-cell;
            font-weight: bold;
            color: #4a5568;
            width: 150px;
            padding: 5px 10px 5px 0;
        }
        
        .info-value {
            display: table-cell;
            color: #2d3748;
            padding: 5px 0;
        }
        
        .equipment-list {
            margin-top: 15px;
        }
        
        .equipment-item {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 8px;
        }
        
        .equipment-name {
            font-weight: bold;
            color: #2d3748;
        }
        
        .equipment-option {
            color: #4a5568;
            font-size: 12px;
            margin-top: 3px;
        }
        
        .tiles-grid {
            width: 100%;
        }
        
        .tiles-row {
            display: table-row;
        }
        
        .tile-category {
            display: table-cell;
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            width: 50%;
            vertical-align: top;
        }
        
        .tile-category:first-child {
            margin-right: 10px;
        }
        
        .tile-category h4 {
            color: #1e3a8a;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .tile-list {
            color: #4a5568;
            font-size: 12px;
            line-height: 1.5;
        }
        
        .comments-section {
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-top: 15px;
        }
        
        .additional-info-list {
            margin-top: 15px;
        }
        
        .info-item {
            background: white;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
        }
        
        .next-steps {
            background: white;
            padding: 20px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-top: 15px;
        }
        
        .company-logo {
            font-size: 20px;
            font-weight: bold;
            color: #1e3a8a;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="company-logo">' . $company['name'] . '</div>
            <h1>🛁 Ihr Badkonfigurator</h1>
            <div class="subtitle">Individuelle Badplanung - Erstellt am ' . Carbon::now()->format('d.m.Y H:i:s') . '</div>
        </div>

        <div class="section">
            <h2 class="section-title">👤 Kontaktdaten</h2>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Name:</div>
                    <div class="info-value">' . ($contactData['salutation'] ?? '') . ' ' . ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '') . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">E-Mail:</div>
                    <div class="info-value">' . ($contactData['email'] ?? '') . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Telefon:</div>
                    <div class="info-value">' . ($contactData['phone'] ?? '') . '</div>
                </div>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">🛁 Badkonfiguration</h2>
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Badezimmergröße:</div>
                    <div class="info-value">' . ($bathroomData['bathroomSize'] ?? 'Nicht angegeben') . ' m²</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Qualitätsstufe:</div>
                    <div class="info-value">' . ($bathroomData['qualityLevel']['name'] ?? 'Nicht ausgewählt') . '</div>
                </div>
            </div>
            
            ' . (isset($bathroomData['qualityLevel']['description']) && !empty($bathroomData['qualityLevel']['description']) ? '
            <div style="margin-top: 15px; padding: 15px; background: white; border-radius: 6px; border: 1px solid #e2e8f0;">
                <strong>Qualitätsbeschreibung:</strong><br>
                ' . $bathroomData['qualityLevel']['description'] . '
            </div>
            ' : '') . '

            ' . (!empty($selectedEquipment) ? '
            <h3 style="margin-top: 25px; margin-bottom: 15px; color: #1e3a8a;">Gewählte Ausstattung:</h3>
            <div class="equipment-list">
                ' . implode('', array_map(function($item) {
                    return '
                    <div class="equipment-item">
                        <div class="equipment-name">' . $item['name'] . '</div>
                        <div class="equipment-option">' . $item['option'] . '</div>
                    </div>';
                }, $selectedEquipment)) . '
            </div>
            ' : '
            <div style="margin-top: 15px; padding: 15px; background: #fef3cd; border-radius: 6px; border: 1px solid #f59e0b;">
                <strong>Hinweis:</strong> Keine spezifische Ausstattung ausgewählt. Wir beraten Sie gerne zu den passenden Optionen.
            </div>
            ') . '
        </div>

        <div class="section">
            <h2 class="section-title">🎨 Fliesen & Heizung</h2>
            <div class="tiles-grid">
                <div class="tiles-row">
                    <div class="tile-category">
                        <h4>Bodenfliesen</h4>
                        <div class="tile-list">
                            ' . (!empty($bathroomData['floorTiles']) ? 
                                implode('<br>', $bathroomData['floorTiles']) : 
                                '<em>Keine spezifischen Bodenfliesen ausgewählt</em>') . '
                        </div>
                    </div>
                    <div class="tile-category">
                        <h4>Wandfliesen</h4>
                        <div class="tile-list">
                            ' . (!empty($bathroomData['wallTiles']) ? 
                                implode('<br>', $bathroomData['wallTiles']) : 
                                '<em>Keine spezifischen Wandfliesen ausgewählt</em>') . '
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 20px;">
                <h4 style="color: #1e3a8a; margin-bottom: 10px;">🔥 Heizung</h4>
                <div class="tile-list">
                    ' . (!empty($bathroomData['heating']) ? 
                        implode('<br>', $bathroomData['heating']) : 
                        '<em>Keine spezifische Heizung ausgewählt</em>') . '
                </div>
            </div>
        </div>

        ' . (!empty($additionalInfoList) ? '
        <div class="section">
            <h2 class="section-title">📋 Gewünschte Informationen</h2>
            <div class="additional-info-list">
                ' . implode('', array_map(function($info) {
                    return '<div class="info-item">✓ ' . $info . '</div>';
                }, $additionalInfoList)) . '
            </div>
        </div>
        ' : '') . '

        ' . (!empty($comments) ? '
        <div class="section">
            <h2 class="section-title">💬 Anmerkungen</h2>
            <div class="comments-section">
                ' . nl2br(htmlspecialchars($comments)) . '
            </div>
        </div>
        ' : '') . '

        <div class="section">
            <h2 class="section-title">📞 Nächste Schritte</h2>
            <div class="next-steps">
                <h4 style="color: #1e3a8a; margin-bottom: 15px;">Wir melden uns bei Ihnen!</h4>
                <p style="margin-bottom: 15px;">
                    Basierend auf Ihrer Konfiguration erstellen wir Ihnen ein individuelles Angebot. 
                    Unser Expertenteam wird sich innerhalb der nächsten 24 Stunden bei Ihnen melden.
                </p>
                <div class="info-grid" style="margin-top: 15px;">
                    <div class="info-row">
                        <div class="info-label">Kontakt:</div>
                        <div class="info-value">' . $company['phone'] . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">E-Mail:</div>
                        <div class="info-value">' . $company['email'] . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Adresse:</div>
                        <div class="info-value">' . $company['address'] . '<br>' . $company['city'] . '</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
    }
    
    /**
     * Debug PDFs auflisten
     */
    public function listDebugPDFs(): array
    {
        try {
            if (!is_dir($this->outputDir)) {
                return [
                    'pdfs' => [],
                    'totalSize' => '0 KB',
                    'outputDirectory' => $this->outputDir
                ];
            }
            
            $files = glob($this->outputDir . '/*.pdf');
            $pdfs = [];
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $filename = basename($file);
                    $fileSize = filesize($file);
                    $created = filemtime($file);
                    
                    $pdfs[] = [
                        'filename' => $filename,
                        'size' => round($fileSize / 1024, 2) . ' KB',
                        'created' => Carbon::createFromTimestamp($created)->format('d.m.Y H:i:s'),
                        'downloadUrl' => Config::get('PDF_DEBUG_MODE') ? 
                            Config::get('APP_URL', 'http://localhost:8000') . '/debug/pdfs/' . $filename : null
                    ];
                }
            }
            
            // Nach Erstellungsdatum sortieren (neueste zuerst)
            usort($pdfs, function($a, $b) {
                return strtotime($b['created']) - strtotime($a['created']);
            });
            
            $totalSize = array_reduce($pdfs, function($sum, $pdf) {
                return $sum + floatval(str_replace(' KB', '', $pdf['size']));
            }, 0);
            
            return [
                'pdfs' => $pdfs,
                'totalSize' => round($totalSize, 2) . ' KB',
                'outputDirectory' => $this->outputDir
            ];
            
        } catch (\Exception $e) {
            Logger::error('Fehler beim Auflisten der Debug-PDFs: ' . $e->getMessage());
            return [
                'pdfs' => [],
                'totalSize' => '0 KB',
                'outputDirectory' => $this->outputDir
            ];
        }
    }
    
    /**
     * Debug PDFs löschen
     */
    public function clearDebugPDFs(): array
    {
        try {
            if (!is_dir($this->outputDir)) {
                return [
                    'success' => true,
                    'deletedCount' => 0
                ];
            }
            
            $files = glob($this->outputDir . '/*.pdf');
            $deletedCount = 0;
            
            foreach ($files as $file) {
                if (is_file($file) && unlink($file)) {
                    $deletedCount++;
                }
            }
            
            Logger::pdf('Debug PDFs gelöscht', ['count' => $deletedCount]);
            
            return [
                'success' => true,
                'deletedCount' => $deletedCount
            ];
            
        } catch (\Exception $e) {
            Logger::error('Fehler beim Löschen der Debug-PDFs: ' . $e->getMessage());
            return [
                'success' => false,
                'deletedCount' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * PDF für Download bereitstellen
     */
    public function servePdfDownload(string $filename): array
    {
        $filePath = $this->outputDir . '/' . $filename;
        
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'message' => 'PDF nicht gefunden'
            ];
        }
        
        // Sicherheitsprüfung: Nur PDF-Dateien
        if (pathinfo($filename, PATHINFO_EXTENSION) !== 'pdf') {
            return [
                'success' => false,
                'message' => 'Ungültiger Dateityp'
            ];
        }
        
        // PDF Headers setzen und ausgeben
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        readfile($filePath);
        
        return [
            'success' => true,
            'message' => 'PDF bereitgestellt'
        ];
    }
}