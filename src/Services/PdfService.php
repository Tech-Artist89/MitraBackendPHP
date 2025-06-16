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
     * Badkonfigurator PDF generieren - MODERN & SCHÖN
     */
    public function generateBathroomConfigurationPDF(array $data): array
    {
        try {
            $contactData = $data['contactData'];
            $bathroomData = $data['bathroomData'] ?? [];
            $comments = $data['comments'] ?? '';
            $additionalInfo = $data['additionalInfo'] ?? [];
            
            $timestamp = Carbon::now()->format('Y-m-d_H-i-s');
            $customerName = trim(($contactData['firstName'] ?? '') . '_' . ($contactData['lastName'] ?? ''));
            $customerName = preg_replace('/[^a-zA-Z0-9_-]/', '', $customerName); // Sichere Dateinamen
            $filename = 'Badkonfigurator_' . ($customerName ?: 'Unknown') . '_' . $timestamp . '.pdf';
            $filePath = $this->outputDir . '/' . $filename;
            
            Logger::pdf('PDF Generierung gestartet', [
                'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? ''),
                'filename' => $filename
            ]);
            
            // mPDF mit PERFEKTER UTF-8 Konfiguration
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
                'margin_top' => 25,
                'margin_bottom' => 25,
                'margin_header' => 10,
                'margin_footer' => 10,
                'fontDir' => array_merge($fontDirs, [
                    __DIR__ . '/../../storage/fonts'
                ]),
                'fontdata' => $fontData + [
                    'opensans' => [
                        'R' => 'OpenSans-Regular.ttf',
                        'B' => 'OpenSans-Bold.ttf',
                        'I' => 'OpenSans-Italic.ttf',
                        'BI' => 'OpenSans-BoldItalic.ttf'
                    ],
                    'dejavusans' => [
                        'R' => 'DejaVuSans.ttf',
                        'B' => 'DejaVuSans-Bold.ttf',
                        'I' => 'DejaVuSans-Oblique.ttf',
                        'BI' => 'DejaVuSans-BoldOblique.ttf'
                    ]
                ],
                'default_font' => 'dejavusans',
                'autoScriptToLang' => true,
                'autoLangToFont' => true
            ]);
            
            // PDF Metadaten
            $mpdf->SetTitle('Badkonfigurator - ' . ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? ''));
            $mpdf->SetAuthor(Config::getCompanyInfo()['name']);
            $mpdf->SetSubject('Individuelle Badplanung');
            $mpdf->SetKeywords('Bad, Badezimmer, Sanitär, Konfiguration');
            
            // Header und Footer setzen
            $mpdf->SetHTMLHeader($this->generateModernPdfHeader());
            $mpdf->SetHTMLFooter($this->generateModernPdfFooter());
            
            // MODERNES HTML Content generieren
            $htmlContent = $this->generateModernBathroomConfigurationHTML(
                $contactData, $bathroomData, $comments, $additionalInfo
            );
            
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
                'path' => $filePath,
                'customer' => ($contactData['firstName'] ?? '') . ' ' . ($contactData['lastName'] ?? '')
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
     * MODERNER PDF Header
     */
    private function generateModernPdfHeader(): string
    {
        $company = Config::getCompanyInfo();
        
        return '
        <table width="100%" style="border-bottom: 2px solid #1e40af; padding-bottom: 8px; margin-bottom: 15px;">
            <tr>
                <td style="width: 70%; font-size: 12px; font-weight: bold; color: #1e40af;">
                    ' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . ' - Badkonfigurator
                </td>
                <td style="width: 30%; text-align: right; font-size: 10px; color: #6b7280;">
                    Seite {PAGENO} von {nbpg}
                </td>
            </tr>
        </table>
        ';
    }
    
    /**
     * MODERNER PDF Footer
     */
    private function generateModernPdfFooter(): string
    {
        $company = Config::getCompanyInfo();
        $now = Carbon::now()->format('d.m.Y H:i');
        
        return '
        <table width="100%" style="border-top: 1px solid #e5e7eb; padding-top: 8px; margin-top: 15px; font-size: 9px; color: #6b7280;">
            <tr>
                <td style="width: 50%; text-align: left;">
                    Erstellt am ' . $now . ' Uhr
                </td>
                <td style="width: 50%; text-align: right;">
                    ' . htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') . ' | ' . 
                    htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') . '
                </td>
            </tr>
            <tr>
                <td colspan="2" style="text-align: center; padding-top: 5px; font-size: 8px;">
                    ' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . ' | ' . 
                    htmlspecialchars($company['address'], ENT_QUOTES, 'UTF-8') . ' | ' . 
                    htmlspecialchars($company['city'], ENT_QUOTES, 'UTF-8') . '
                </td>
            </tr>
        </table>
        ';
    }
    
    /**
     * MODERNES HTML für Badkonfigurator PDF - WIE DEIN FRONTEND!
     */
    private function generateModernBathroomConfigurationHTML(
        array $contactData,
        array $bathroomData,
        string $comments,
        array $additionalInfo
    ): string {
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
        $floorTiles = $this->processTileSelection($bathroomData['floorTiles'] ?? []);
        $wallTiles = $this->processTileSelection($bathroomData['wallTiles'] ?? []);
        $heating = $this->processTileSelection($bathroomData['heating'] ?? []);
        
        $company = Config::getCompanyInfo();
        $currentDate = Carbon::now()->format('d.m.Y');
        $currentTime = Carbon::now()->format('H:i');
        
        return '<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Badkonfigurator - ' . $firstName . ' ' . $lastName . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "DejaVu Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background-color: #ffffff;
            font-size: 11px;
        }
        
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 0;
        }
        
        /* MODERNER HEADER - WIE DEIN FRONTEND */
        .hero-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);
            color: white;
            padding: 30px 25px;
            text-align: center;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }
        
        .hero-header::before {
            content: "";
            position: absolute;
            top: -50px;
            right: -50px;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        
        .hero-header::after {
            content: "";
            position: absolute;
            bottom: -30px;
            left: -30px;
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
        }
        
        .company-logo {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 8px;
            opacity: 0.9;
        }
        
        .hero-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        
        .hero-subtitle {
            font-size: 12px;
            opacity: 0.85;
            position: relative;
            z-index: 1;
        }
        
        /* MODERNE SECTIONS */
        .section {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .section-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            padding: 12px 15px;
            margin: -20px -20px 15px -20px;
            border-radius: 8px 8px 0 0;
            font-weight: bold;
            font-size: 14px;
        }
        
        .section-title {
            color: #1e40af;
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        /* SCHÖNE TABELLEN */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        .info-table tr {
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-table tr:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #374151;
            padding: 8px 12px 8px 0;
            width: 140px;
            vertical-align: top;
        }
        
        .info-value {
            color: #1f2937;
            padding: 8px 0;
            word-break: break-word;
        }
        
        /* EQUIPMENT LISTE */
        .equipment-list {
            margin-top: 15px;
        }
        
        .equipment-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 8px;
            display: block;
        }
        
        .equipment-name {
            font-weight: bold;
            color: #1e40af;
            font-size: 12px;
            margin-bottom: 4px;
        }
        
        .equipment-option {
            color: #6b7280;
            font-size: 10px;
            line-height: 1.4;
        }
        
        /* BADGES & HIGHLIGHTS */
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: black;
        }
        
        .badge-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: black;
        }
        
        .badge-info {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
        }
        
        /* TILES CONTAINER */
        .tiles-container {
            margin-top: 15px;
        }
        
        .tile-category {
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            margin-bottom: 10px;
        }
        
        .tile-category h4 {
            color: #1e40af;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .tile-list {
            color: #6b7280;
            font-size: 10px;
            line-height: 1.5;
        }
        
        /* COMMENTS BOX */
        .comments-box {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            font-size: 11px;
            line-height: 1.6;
            color: #374151;
        }
        
        /* NEXT STEPS - CALL TO ACTION */
        .next-steps {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 2px solid #10b981;
            border-radius: 8px;
            padding: 20px;
            margin-top: 15px;
        }
        
        .next-steps h3 {
            color: #065f46;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .next-steps p {
            color: #047857;
            margin-bottom: 8px;
        }
        
        /* QUALITY LEVEL HIGHLIGHT */
        .quality-highlight {
            background: linear-gradient(135deg, #fef3cd 0%, #fde68a 100%);
            border: 2px solid #f59e0b;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
        }
        
        .quality-highlight h4 {
            color: #92400e;
            margin-bottom: 8px;
        }
        
        .quality-highlight p {
            color: #b45309;
            font-size: 10px;
        }
        
        /* RESPONSIVE TABLE LAYOUT */
        .responsive-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .responsive-table td {
            padding: 8px;
            vertical-align: top;
            border-bottom: 1px solid #e5e7eb;
        }
        
        /* NO SELECTION PLACEHOLDER */
        .no-selection {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 6px;
            padding: 12px;
            color: #991b1b;
            font-style: italic;
            text-align: center;
            font-size: 10px;
        }
        
        /* UTILITY CLASSES */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .text-blue { color: #1e40af; }
        .text-gray { color: #6b7280; }
        .mb-10 { margin-bottom: 10px; }
        .mt-15 { margin-top: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <!-- HERO HEADER -->
        <div class="hero-header">
            <div class="company-logo">' . htmlspecialchars($company['name'], ENT_QUOTES, 'UTF-8') . '</div>
            <h1 class="hero-title">🛁 Ihr Traumbad-Konfigurator</h1>
            <div class="hero-subtitle">Individuelle Badplanung vom ' . $currentDate . ' um ' . $currentTime . ' Uhr</div>
        </div>

        <!-- KONTAKTDATEN SECTION -->
        <div class="section">
            <div class="section-header">
                👤 Ihre Kontaktdaten
            </div>
            <table class="info-table">
                <tr>
                    <td class="info-label">Name:</td>
                    <td class="info-value"><strong>' . $salutation . ' ' . $firstName . ' ' . $lastName . '</strong></td>
                </tr>
                <tr>
                    <td class="info-label">E-Mail:</td>
                    <td class="info-value">' . $email . '</td>
                </tr>
                <tr>
                    <td class="info-label">Telefon:</td>
                    <td class="info-value">' . ($phone ?: '<em>Nicht angegeben</em>') . '</td>
                </tr>
            </table>
        </div>

        <!-- BADKONFIGURATION SECTION -->
        <div class="section">
            <div class="section-header">
                🛁 Ihre Badkonfiguration
            </div>
            
            <table class="info-table">
                <tr>
                    <td class="info-label">Badezimmergröße:</td>
                    <td class="info-value">
                        <span class="badge badge-primary">' . ($bathroomData['bathroomSize'] ?? 'Nicht angegeben') . ' m²</span>
                    </td>
                </tr>
                <tr>
                    <td class="info-label">Qualitätsstufe:</td>
                    <td class="info-value">
                        <span class="badge badge-success">' . ($bathroomData['qualityLevel']['name'] ?? 'Nicht ausgewählt') . '</span>
                    </td>
                </tr>
            </table>
            
            ' . (isset($bathroomData['qualityLevel']['description']) && !empty($bathroomData['qualityLevel']['description']) ? '
            <div class="quality-highlight">
                <h4>Qualitätsbeschreibung:</h4>
                <p>' . htmlspecialchars($bathroomData['qualityLevel']['description'], ENT_QUOTES, 'UTF-8') . '</p>
            </div>
            ' : '') . '

            ' . (!empty($selectedEquipment) ? '
            <h3 class="section-title mt-15">⚡ Gewählte Ausstattung</h3>
            <div class="equipment-list">
                ' . $this->renderEquipmentList($selectedEquipment) . '
            </div>
            ' : '
            <div class="no-selection mt-15">
                💡 <strong>Hinweis:</strong> Keine spezifische Ausstattung ausgewählt.<br>
                Wir beraten Sie gerne zu den passenden Optionen für Ihr Traumbad!
            </div>
            ') . '
        </div>

        <!-- FLIESEN & HEIZUNG SECTION -->
        <div class="section">
            <div class="section-header">
                🎨 Fliesen & Heizung
            </div>
            
            <div class="tiles-container">
                <div class="tile-category">
                    <h4>🏠 Bodenfliesen</h4>
                    <div class="tile-list">
                        ' . ($floorTiles ?: '<em class="text-gray">Keine spezifische Auswahl</em>') . '
                    </div>
                </div>
                
                <div class="tile-category">
                    <h4>🖼️ Wandfliesen</h4>
                    <div class="tile-list">
                        ' . ($wallTiles ?: '<em class="text-gray">Keine spezifische Auswahl</em>') . '
                    </div>
                </div>
                
                <div class="tile-category">
                    <h4>🔥 Heizung</h4>
                    <div class="tile-list">
                        ' . ($heating ?: '<em class="text-gray">Keine spezifische Auswahl</em>') . '
                    </div>
                </div>
            </div>
        </div>

        ' . (!empty($additionalInfoList) ? '
        <!-- ZUSÄTZLICHE INFORMATIONEN -->
        <div class="section">
            <div class="section-header">
                📋 Gewünschte Informationen
            </div>
            <div style="margin-top: 10px;">
                ' . implode('', array_map(function($info) {
                    return '<div style="background: white; padding: 8px 12px; margin-bottom: 5px; border-radius: 4px; border-left: 3px solid #10b981;">
                        ✓ <strong>' . htmlspecialchars($info, ENT_QUOTES, 'UTF-8') . '</strong>
                    </div>';
                }, $additionalInfoList)) . '
            </div>
        </div>
        ' : '') . '

        ' . (!empty($comments) ? '
        <!-- ANMERKUNGEN SECTION -->
        <div class="section">
            <div class="section-header">
                💬 Ihre Anmerkungen
            </div>
            <div class="comments-box">
                ' . nl2br(htmlspecialchars($comments, ENT_QUOTES, 'UTF-8')) . '
            </div>
        </div>
        ' : '') . '

        <!-- NÄCHSTE SCHRITTE -->
        <div class="next-steps">
            <h3>🚀 So geht es weiter</h3>
            <p><strong>Wir melden uns innerhalb von 24 Stunden bei Ihnen!</strong></p>
            <p>📞 Unser Expertenteam erstellt Ihnen ein maßgeschneidertes Angebot basierend auf Ihrer Konfiguration.</p>
            
            <table class="responsive-table mt-15">
                <tr>
                    <td style="width: 30%; font-weight: bold; color: #065f46;">Direkter Kontakt:</td>
                    <td style="color: #047857;">' . htmlspecialchars($company['phone'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
                <tr>
                    <td style="font-weight: bold; color: #065f46;">E-Mail:</td>
                    <td style="color: #047857;">' . htmlspecialchars($company['email'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
                <tr>
                    <td style="font-weight: bold; color: #065f46;">Adresse:</td>
                    <td style="color: #047857;">' . htmlspecialchars($company['address'], ENT_QUOTES, 'UTF-8') . '<br>' . htmlspecialchars($company['city'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>';
    }
    
    /**
     * Ausgewählte Ausstattung verarbeiten
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
     * Equipment Liste rendern (vereinfacht für PDF)
     */
    private function renderEquipmentList(array $equipment): string
    {
        $html = '';
        
        foreach ($equipment as $item) {
            $html .= '
            <div class="equipment-item">
                <div class="equipment-name">' . $item['name'] . '</div>
                <div class="equipment-option">' . $item['option'] . '</div>
                ' . ($item['description'] ? '<div class="equipment-option" style="margin-top: 3px; font-style: italic;">' . $item['description'] . '</div>' : '') . '
            </div>';
        }
        
        return $html;
    }
    
    /**
     * Zusätzliche Informationen verarbeiten
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
     * Fliesen-Auswahl verarbeiten
     */
    private function processTileSelection(array $tiles): string
    {
        if (empty($tiles)) {
            return '';
        }
        
        $filtered = array_filter($tiles, function($tile) {
            return !empty($tile) && $tile !== null;
        });
        
        if (empty($filtered)) {
            return '';
        }
        
        return implode('<br>', array_map(function($tile) {
            return htmlspecialchars($tile, ENT_QUOTES, 'UTF-8');
        }, $filtered));
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