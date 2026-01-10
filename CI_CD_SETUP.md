# OpenXE CI/CD Setup 🚀

Dieses Projekt nutzt GitHub Actions für Continuous Integration und Code Quality Checks.

## 📋 Workflows

### CI Workflow (`.github/workflows/ci.yml`)
Läuft bei jedem Push und Pull Request auf `main`, `master` und `develop`:

- **PHPUnit Tests**: Multi-Version Testing (PHP 8.1, 8.2, 8.3)
- **PHPStan**: Statische Code-Analyse (Level 5)
- **PHP CS Fixer**: Code-Style Prüfung (PSR-12)
- **PHP CodeSniffer**: Zusätzliche Style-Checks
- **Composer Audit**: Security-Prüfung der Dependencies

### Security Workflow (`.github/workflows/security.yml`)
- Läuft wöchentlich (jeden Montag)
- Bei Pull Requests
- Scannt Dependencies auf Sicherheitslücken
- NPM und Composer Audit
- Dependabot Auto-Merge für Patch/Minor Updates

## 🛠 Lokale Nutzung

### Installation der Dev-Dependencies
```bash
composer install
```

### Code Quality Tools lokal ausführen

#### PHPUnit Tests
```bash
composer test
```

#### PHPStan (Statische Analyse)
```bash
composer phpstan
```

#### PHP CS Fixer (Code Style)
```bash
# Nur prüfen
composer cs-check

# Automatisch fixen
composer cs-fix
```

#### PHP CodeSniffer
```bash
composer phpcs
```

#### Alle Quality Checks
```bash
composer quality
```

## 📊 Status Badges

Füge diese Badges zu eurer README.md hinzu:

```markdown
[![CI](https://github.com/OpenXE-org/OpenXE/workflows/CI/badge.svg)](https://github.com/OpenXE-org/OpenXE/actions)
[![Security](https://github.com/OpenXE-org/OpenXE/workflows/Security/badge.svg)](https://github.com/OpenXE-org/OpenXE/actions)
```

## ⚙️ Konfigurationsdateien

- **phpstan.neon**: PHPStan Konfiguration (Level 5)
- **.php-cs-fixer.php**: PHP CS Fixer Regeln (PSR-12 + Custom)
- **phpcs.xml**: PHP CodeSniffer Regeln
- **.github/dependabot.yml**: Automatische Dependency Updates

## 🔧 Anpassungen

### PHPStan Level erhöhen
In `phpstan.neon` den Level von 5 auf 6-9 erhöhen für strengere Checks:
```yaml
parameters:
    level: 6  # oder höher
```

### Code Style anpassen
In `.php-cs-fixer.php` die Regeln nach Bedarf ändern.

### Workflow Trigger anpassen
In den `.github/workflows/*.yml` Dateien die `on:` Sektion anpassen.

## 📝 Best Practices

1. **Pre-Commit**: Führe `composer quality` vor jedem Commit aus
2. **Pull Requests**: Warte auf grüne CI-Checks vor dem Merge
3. **Security**: Reagiere zeitnah auf Dependabot-PRs
4. **Legacy Code**: Schrittweise aufräumen (siehe PHPStan ignoreErrors)

## 🚦 Was wird geprüft?

### ✅ Code Quality
- PSR-12 Coding Standard
- Type Safety (PHPStan)
- Unused Imports
- Code Formatting
- Best Practices

### 🔒 Security
- Known Vulnerabilities in Dependencies
- Outdated Packages
- Security Best Practices

### 🧪 Testing
- Unit Tests
- Integration Tests
- Multi-PHP-Version Kompatibilität

## 📈 Nächste Schritte

1. Tests erstellen (siehe `phpunit.xml` Konfiguration)
2. PHPStan Level schrittweise erhöhen
3. Legacy Code modernisieren
4. Code Coverage einrichten
5. Weitere Security Scans (z.B. Psalm) hinzufügen
