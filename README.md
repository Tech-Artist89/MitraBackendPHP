# Mitra Sanitär Backend - PHP Version

Backend für die Mitra Sanitär Website mit Kontaktformular und Badkonfigurator in PHP.

## ✨ Features

- 📧 **Kontaktformular** - Normale Kontaktanfragen mit E-Mail-Versendung
- 🛁 **Badkonfigurator** - Komplexe Badkonfigurationen mit PDF-Generierung
- 📄 **PDF-Generierung** - Automatische PDF-Erstellung mit mPDF
- 🔒 **Sicherheit** - Rate Limiting, Input Validation, CORS
- 📊 **Logging** - Umfassendes Logging System mit Monolog
- 🐛 **Debug-Modus** - PDF Debug-Features für Entwicklung
- 📧 **Outlook-Integration** - Optimiert für Microsoft Outlook SMTP

## 🛠️ Installation unter Windows 11

### 1. Voraussetzungen installieren

**PHP 8.2+ installieren:**
```powershell
# Via Chocolatey (empfohlen)
choco install php

# Oder XAMPP herunterladen
# https://www.apachefriends.org/
```

**Composer installieren:**
```powershell
# Download: https://getcomposer.org/download/
# Windows Installer verwenden
```

**mPDF Dependencies (optional):**
```powershell
# Für erweiterte PDF-Features
choco install imagemagick
```

### 2. Projekt Setup

```bash
# In den Backend-Ordner wechseln
cd mitra-sanitaer-backend-php

# Dependencies installieren
composer install

# Interactive Setup ausführen
php scripts/setup.php
```

### 3. Microsoft Outlook E-Mail Setup

1. **App-Passwort erstellen:**
   - Gehe zu [Microsoft Account Security](https://account.microsoft.com/security)
   - Aktiviere 2-Faktor-Authentifizierung
   - Erstelle ein App-Passwort für "Mail"

2. **Environment konfigurieren:**
   ```env
   EMAIL_HOST=smtp-mail.outlook.com
   EMAIL_PORT=587
   EMAIL_SECURE=true
   EMAIL_USERNAME=ihre-email@outlook.com
   EMAIL_PASSWORD=ihr-app-passwort
   ```

### 4. Development Server starten

```bash
# PHP Built-in Server (für Development)
php -S localhost:8000 -t public

# Oder mit XAMPP/WAMP
# Document Root auf /public setzen
```

Das Backend läuft jetzt auf `http://localhost:8000`

## 📡 API Endpunkte

### Health Check
```
GET /api/health
```
Prüft ob das Backend läuft und zeigt Service-Status.

### Kontaktformular
```
POST /api/contact
```
Sendet normale Kontaktanfragen.

**Request Body:**
```json
{
  "firstName": "Max",
  "lastName": "Mustermann",
  "email": "max@beispiel.de",
  "phone": "030 123456789",
  "subject": "Beratungsanfrage",
  "message": "Ich hätte gerne...",
  "service": "bathroom",
  "urgent": false
}
```

### Badkonfigurator
```
POST /api/send-bathroom-configuration
```
Sendet Badkonfigurationen mit PDF-Anhang.

**Request Body:**
```json
{
  "contactData": {
    "salutation": "Herr",
    "firstName": "Max",
    "lastName": "Mustermann", 
    "phone": "030 123456789",
    "email": "max@beispiel.de"
  },
  "bathroomData": {
    "equipment": [...],
    "bathroomSize": 8,
    "qualityLevel": {...},
    "floorTiles": [...],
    "wallTiles": [...],
    "heating": [...]
  },
  "comments": "Zusätzliche Anmerkungen",
  "additionalInfo": {
    "projektablauf": true,
    "garantie": false
  }
}
```

### PDF Test (nur Development)
```
POST /api/generate-pdf-only
```
Generiert nur ein PDF ohne E-Mail zu senden.

### Debug PDFs (nur Development)
```
GET /api/debug-pdfs
DELETE /api/debug-pdfs
```
Listet generierte PDFs auf oder löscht sie.

## 🔧 Konfiguration

### E-Mail Provider

**Microsoft Outlook (empfohlen):**
```env
EMAIL_HOST=smtp-mail.outlook.com
EMAIL_PORT=587
EMAIL_SECURE=true
EMAIL_USERNAME=ihre-email@outlook.com
EMAIL_PASSWORD=app-passwort
```

**Gmail:**
```env
EMAIL_HOST=smtp.gmail.com
EMAIL_PORT=587
EMAIL_SECURE=true
EMAIL_USERNAME=ihre-email@gmail.com
EMAIL_PASSWORD=app-passwort
```

**Custom SMTP:**
```env
EMAIL_HOST=ihr-smtp-server.de
EMAIL_PORT=587
EMAIL_SECURE=true
EMAIL_USERNAME=ihr-username
EMAIL_PASSWORD=ihr-passwort
```

### PDF-Konfiguration

```env
# PDF Engine (mpdf empfohlen)
PDF_ENGINE=mpdf
PDF_OUTPUT_DIR=storage/generated-pdfs

# Debug Modus (PDFs über HTTP verfügbar)
PDF_DEBUG_MODE=true
```

### Rate Limiting

```env
# 10 Requests pro 15 Minuten
RATE_LIMIT_WINDOW_MINUTES=15
RATE_LIMIT_MAX_REQUESTS=10
```

## 🛠️ Development

### Logs anzeigen

```bash
# Live Logs verfolgen (Windows)
Get-Content storage/logs/combined.log -Wait -Tail 10

# Error Logs
Get-Content storage/logs/error.log -Wait -Tail 10

# Linux/Mac
tail -f storage/logs/combined.log
tail -f storage/logs/error.log
```

### Debug PDFs verwalten

Im Debug-Modus sind PDFs unter `http://localhost:8000/debug/pdfs/` verfügbar.

```bash
# Alle Debug PDFs auflisten
curl http://localhost:8000/api/debug-pdfs

# Debug PDFs löschen
curl -X DELETE http://localhost:8000/api/debug-pdfs
```

### API testen

**Health Check:**
```bash
curl http://localhost:8000/api/health
```

**Kontaktformular:**
```bash
curl -X POST http://localhost:8000/api/contact \
  -H "Content-Type: application/json" \
  -d '{
    "firstName": "Test",
    "lastName": "User",
    "email": "test@beispiel.de",
    "subject": "Test Anfrage",
    "message": "Das ist eine Test-Nachricht"
  }'
```

**Test Script verwenden:**
```bash
# Alle Tests
php scripts/test.php

# Spezifische Tests
php scripts/test.php health
php scripts/test.php email
php scripts/test.php contact
php scripts/test.php bathroom
php scripts/test.php pdf
```

## 📂 Projektstruktur

```
mitra-sanitaer-backend-php/
├── public/
│   ├── index.php                    # Entry Point
│   ├── .htaccess                    # Apache URL Rewriting
│   └── debug/pdfs/                  # PDF Debug Files
├── src/
│   ├── Config/
│   │   └── Config.php               # Konfiguration
│   ├── Controllers/
│   │   └── ApiController.php        # API Controller
│   ├── Services/
│   │   ├── EmailService.php         # E-Mail Service (Outlook)
│   │   └── PdfService.php           # PDF Service (mPDF)
│   ├── Middleware/
│   │   ├── ValidationMiddleware.php # Validierung
│   │   ├── CorsMiddleware.php       # CORS Handler
│   │   └── RateLimitMiddleware.php  # Rate Limiting
│   └── Utils/
│       └── Logger.php               # Logging System
├── storage/
│   ├── logs/                        # Log Files
│   ├── generated-pdfs/              # Generierte PDFs
│   └── cache/                       # Cache Files
├── scripts/
│   ├── setup.php                    # Setup Script
│   └── test.php                     # Test Script
├── composer.json                    # PHP Dependencies
├── .env.example                     # Environment Template
├── .env                            # Environment Variables
└── README.md                       # Diese Datei
```

## 🚨 Troubleshooting

### Backend startet nicht

1. **Port bereits belegt:**
   ```bash
   # Windows
   netstat -ano | findstr :8000
   
   # Anderen Port verwenden
   php -S localhost:8001 -t public
   ```

2. **PHP Version prüfen:**
   ```bash
   php --version  # Mindestens PHP 8.2 erforderlich
   ```

3. **Composer Dependencies:**
   ```bash
   composer install
   composer dump-autoload
   ```

### E-Mails kommen nicht an

1. **Outlook App-Passwort prüfen:**
   - 2FA muss aktiviert sein
   - 16-stelliges App-Passwort verwenden
   - Nicht das normale Outlook-Passwort

2. **SMTP-Einstellungen testen:**
   ```bash
   php scripts/test.php email
   ```

3. **Logs prüfen:**
   ```bash
   Get-Content storage/logs/error.log -Tail 20
   ```

### PDF-Generierung schlägt fehl

1. **mPDF Dependencies:**
   ```bash
   composer require mpdf/mpdf
   ```

2. **Speicherplatz prüfen:**
   ```bash
   # Windows
   dir storage\generated-pdfs
   
   # Verzeichnis-Berechtigungen prüfen
   ```

3. **Memory Limit erhöhen:**
   ```ini
   ; In php.ini
   memory_limit = 256M
   max_execution_time = 60
   ```

### Frontend kann Backend nicht erreichen

1. **CORS prüfen:**
   ```env
   FRONTEND_URL=http://localhost:4200
   ```

2. **Backend läuft:**
   ```bash
   curl http://localhost:8000/api/health
   ```

3. **Firewall/Antivirus:**
   - Port 8000 in Windows Firewall freigeben
   - Antivirus-Software temporär deaktivieren

## 📧 Support

Bei Problemen:

1. Logs prüfen: `storage/logs/error.log`
2. Health-Check aufrufen: `http://localhost:8000/api/health`
3. Test Script ausführen: `php scripts/test.php`
4. Dependencies aktualisieren: `composer update`

## 🔄 Updates

```bash
# Dependencies aktualisieren
composer update

# Sicherheitsupdates
composer audit

# Cache leeren
rm -rf storage/cache/*
```

## 📋 Produktions-Deployment auf Strato

### 1. Dateien hochladen

1. **FTP/SFTP Upload:**
   - Alle Dateien außer `vendor/` hochladen
   - `composer install --no-dev` auf dem Server ausführen

2. **Webserver-Konfiguration:**
   ```apache
   # .htaccess in Document Root
   RewriteEngine On
   RewriteRule ^(.*)$ public/$1 [L]
   ```

### 2. Umgebungsvariablen setzen

```env
APP_ENV=production
APP_DEBUG=false
PDF_DEBUG_MODE=false
LOG_LEVEL=error
```

### 3. Strato-spezifische Anpassungen

```env
# Strato SMTP (falls vorhanden)
EMAIL_HOST=smtp.strato.de
EMAIL_PORT=587
EMAIL_SECURE=true

# Oder externe SMTP verwenden
EMAIL_HOST=smtp-mail.outlook.com
```

### 4. Berechtigungen setzen

```bash
chmod 755 storage/
chmod 755 storage/logs/
chmod 755 storage/generated-pdfs/
chmod 644 .env
```

---

**Erstellt für Mitra Sanitär GmbH** 🛁