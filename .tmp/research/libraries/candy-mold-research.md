# Candy-Mold Research: PHP Skeleton/Scaffold Libraries

**Date:** 2026-05-13
**Subject:** PHP skeleton/scaffold tools for improving `candy-mold`
**Sources:** GitHub, web search, Context7 (rate-limited)

---

## Executive Summary

This document analyzes PHP skeleton/scaffold tools and provides recommendations for improving `candy-mold`, the SugarCraft TUI app bootstrapper. Current candy-mold is minimal (4 files + tests) — a solid foundation but lacking polish, CI/CD, interactive setup, and extensibility patterns.

---

## 1. Current State of Candy-Mold

### 1.1 Structure
```
candy-mold/
├── composer.json          # 130 lines, 24 VCS repos, dev dep on phpunit ^10.0
├── phpunit.xml            # 16 lines, basic bootstrap + colors
├── bin/start              # 39 lines, entry point
├── src/Counter.php        # 67 lines, demo Model (immutable, 3 methods)
├── tests/CounterTest.php  # 67 lines, 8 tests
├── README.md              # 91 lines, walkthrough + badges
├── .vhs/start.tape        # Demo tape
├── .vhs/start.gif         # Rendered demo
├── .assets/icon.png       # 256x256 icon
└── vendor/                # Locked deps
```

### 1.2 Strengths
- ✅ Minimal, focused structure
- ✅ Excellent README with visual demo and "next steps" table
- ✅ Proper PSR-4 autoload
- ✅ Immutable Model pattern well-documented
- ✅ Tests demonstrate pure `update()` testing without event loop

### 1.3 Gaps
- ❌ No CI/CD workflow files
- ❌ No static analysis (PHPStan/Psalm)
- ❌ No code style enforcement (Pint/CS-Fixer)
- ❌ No interactive configuration script (unlike spatie/php-skeleton)
- ❌ No `.editorconfig`, `.gitattributes`
- ❌ No security policy template
- ❌ No contributing guidelines
- ❌ No codecov integration beyond badges
- ❌ No `composer.json` scripts for quality checks
- ❌ Repository URLs hardcoded to github.com/sugarcraft (not template-friendly)

---

## 2. PHP Skeleton/Scaffold Landscape

### 2.1 Major Skeletons Analyzed

| Skeleton | Stars | Type | Interactive | Key Feature |
|----------|-------|------|-------------|-------------|
| **spatie/package-skeleton-php** | 550 | Package | Yes (configure.php) | Placeholder replacement, Pest support |
| **ramsey/php-library-starter-kit** | 260 | Library | Yes (Wizard) | Comprehensive tooling, psalm/phpstan |
| **laravel/laravel** | 84k | App | No | Full-stack, artisan, routing |
| **symfony/skeleton** | 603 | Micro | No | Minimal, composer.json only |
| **pekral/php-skeleton** | recent | Package | Yes | PHPStan max, Rector, Pest |
| **nunomaduro/skeleton-php** | older | Package | No | Minimal |
| **koriym/php-skeleton** | older | Package | Yes | Interactive prompts |

### 2.2 Laravel Application Skeleton Structure

**Source:** `laravel/laravel` (13.x branch)

```
laravel/
├── app/                    # Application classes (Models, Controllers, etc.)
├── bootstrap/              # App bootstrap
├── config/                 # Configuration files
├── database/               # Migrations, seeders, factories
├── public/                 # Web root
├── resources/              # Views, assets, lang
├── routes/                 # Route definitions
├── storage/                # Logs, cache, uploads
├── tests/                  # Test directories (Unit, Feature, etc.)
├── .github/workflows/      # CI/CD workflows
├── .env.example            # Environment template
├── artisan                 # CLI entry point
├── composer.json           # Full deps including dev
├── phpunit.xml             # Comprehensive config
└── vite.config.js          # Asset bundler
```

**Key Patterns:**
- Bootstrap discovers autoloader from multiple candidate paths
- `artisan` CLI entry point with help system
- `.env.example` for environment configuration
- Comprehensive test suite structure (Unit/Feature/Browser)
- GitHub Actions workflows for CI

### 2.3 Symfony Skeleton Structure

**Source:** `symfony/skeleton` (7.3)

```
symfony/skeleton/
├── composer.json           # 1 file, skeleton-only
├── LICENSE
├── README.md
└── code_of_conduct_md/
```

**Philosophy:** Minimal. The skeleton is just a `composer.json` that pulls in `symfony/framework-bundle`. No interactive setup.

### 2.4 Spatie Package Skeleton (Most Relevant)

**Source:** `spatie/package-skeleton-php`

```
package-skeleton-php/
├── .github/
│   └── workflows/
│       ├── run-tests.yml           # PHPUnit or Pest depending on choice
│       ├── run-tests-phpunit.yml
│       ├── run-tests-pest.yml
│       ├── fix-php-code-style-issues-pint.yml
│       └── fix-php-code-style-issues-cs-fixer.yml
├── src/
│   └── SkeletonClass.php           # Example class with placeholders
├── tests/
│   ├── ExampleTest.php             # PHPUnit
│   ├── ExampleTestPest.php         # Pest alternative
│   ├── ArchTest.php                # Pest architecture tests
│   └── Pest.php                    # Pest bootstrap
├── .editorconfig
├── .gitattributes
├── .gitignore
├── .php-cs-fixer.dist.php
├── CHANGELOG.md
├── LICENSE.md
├── README.md                       # Template placeholders throughout
├── composer.json                   # Placeholders: :vendor_slug/, etc.
├── configure.php                   # Interactive setup script
└── phpunit.xml.dist
```

**Key Features:**
1. **Placeholder System:** Files contain tokens like `:vendor_slug/`, `:package_slug`, `VendorName`, `Skeleton` that get replaced by `configure.php`

2. **Interactive `configure.php` Script:**
   - Reads `git config user.name/email` for defaults
   - Asks about testing library (Pest vs PHPUnit)
   - Asks about code style (Pint vs CS-Fixer)
   - Replaces placeholders in all files
   - Deletes itself after completion
   - Optionally runs `composer install && composer test`

3. **Testing Options:** Configurable between Pest and PHPUnit with separate workflow files

4. **Code Style Options:** Configurable between Pint and PHP-CS-Fixer

---

## 3. Comparison Matrix

| Feature | Laravel | Symfony | Spatie | Ramsey | Candy-Mold |
|---------|---------|---------|--------|--------|------------|
| **Project Type** | App | Micro | Package | Library | App/Skeleton |
| **Interactive Setup** | No | No | Yes | Yes | No |
| **Placeholder Tokens** | No | No | Yes | Yes | No |
| **Multiple Testing Options** | N/A | N/A | Yes (Pest/PHPUnit) | No | No |
| **Code Style Options** | Pint | N/A | Yes (Pint/CS-Fixer) | CS-Fixer | No |
| **Static Analysis** | N/A | N/A | No | Yes (Psalm/PHPStan) | No |
| **CI/CD Workflows** | Yes | No | Yes | Yes | No |
| **Coverage Reports** | Yes | No | Yes (clover/html/text) | Yes | No |
| **Environment Template** | Yes (.env.example) | No | No | No | No |
| **License File** | Yes | No | Yes | Yes | No |
| **Security Policy** | Yes | No | Yes | Yes | No |
| **Contributing Guide** | Yes | No | Yes | Yes | No |
| **EditorConfig** | Yes | No | Yes | Yes | No |
| **GitAttributes** | Yes | No | Yes | Yes (.template) | No |
| **CHANGELOG** | Yes | No | Yes | Yes | No |
| **Pre-commit Hooks** | No | No | No | Yes (CaptainHook) | No |

---

## 4. Specific Improvements for Candy-Mold

### 4.1 High Priority (Low Effort, High Impact)

#### 4.1.1 Add `.editorconfig`
**Source:** `spatie/package-skeleton-php/.editorconfig:L1-L25`

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
insert_final_newline = true
indent_style = space
indent_size = 4

[*.md]
trim_trailing_whitespace = false
```

**Effort:** 10 minutes
**Benefit:** Consistent editor formatting across contributors

#### 4.1.2 Add `.gitattributes`
**Source:** `spatie/package-skeleton-php/.gitattributes`

```gitattributes
# Auto detect text files and perform LF normalization
* text=auto

# Force Unix line endings
*.php text eol=lf
*.md text eol=lf
*.yml text eol=lf
*.yaml text eol=lf
*.json text eol=lf
*.xml text eol=lf

# Denote all files that are truly binary and should not be modified.
*.png binary
*.gif binary
*.jpg binary
```

**Effort:** 10 minutes
**Benefit:** Consistent line endings, proper binary handling

#### 4.1.3 Add Composer Scripts
**Current:**
```json
"scripts": {}
```

**Proposed:**
```json
"scripts": {
    "test": "vendor/bin/phpunit",
    "test:coverage": "vendor/bin/phpunit --coverage-text"
}
```

**Effort:** 5 minutes
**Benefit:** Standardized test execution

#### 4.1.4 Add `failOnWarning` to PHPUnit
**Source:** `candy-core/phpunit.xml` pattern

```xml
<phpunit failOnWarning="true"
         failOnRisky="true"
         failOnEmptyTestSuite="true"
         beStrictAboutOutputDuringTests="true"
         cacheDirectory=".phpunit.cache"
         backupStaticProperties="false">
```

**Effort:** 5 minutes
**Benefit:** Catches problematic tests early

#### 4.1.5 Add `.env.example` (for TUI config)
**Source:** `laravel/laravel/.env.example`

```env
# SugarCraft TUI Application Configuration
# Copy to .env and customize for your environment

# Terminal Settings
TERM=xterm-256color

# Optional: Enable debug mode
DEBUG=false

# Optional: Log file path
LOG_PATH=

# Optional: Panic handler enabled (requires candy-log)
PANIC_HANDLER=false
```

**Effort:** 15 minutes
**Benefit:** Documents configuration options, easy onboarding

---

### 4.2 Medium Priority (Moderate Effort)

#### 4.2.1 Add Interactive `configure.php`
**Reference:** `spatie/package-skeleton-php/configure.php` (250+ lines)

**Proposed simplified version for candy-mold:**

```php
#!/usr/bin/env php
<?php
/**
 * Configure SugarCraft TUI app after scaffold.
 *
 * Usage: php configure.php
 */

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

(new Application('candy-mold', '1.0.0'))
    ->register('configure')
    ->addArgument('name', InputArgument::OPTIONAL, 'App name (from dir)')
    ->setCode(function (InputInterface $input, OutputInterface $output) use (&$name): int {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name') ?? basename(getcwd());

        $io->title('Configure SugarCraft TUI App');

        // 1. Read git config for defaults
        $authorName = trim(shell_exec('git config user.name') ?? '');
        $authorEmail = trim(shell_exec('git config user.email') ?? '');

        // 2. Ask for author info
        $authorName = $io->ask('Author name', $authorName);
        $authorEmail = $io->ask('Author email', $authorEmail);

        // 3. Ask for app name
        $appName = $io->ask('App name', $name);
        $namespace = ucfirst($appName);
        $namespace = $io->ask('PHP namespace', $namespace);

        // 4. Summary
        $io->table(
            ['Setting', 'Value'],
            [
                ['Author', "{$authorName} <{$authorEmail}>"],
                ['App name', $appName],
                ['Namespace', $namespace],
            ]
        );

        if (!$io->confirm('Proceed with configuration?', true)) {
            return Command::FAILURE;
        }

        // 5. Replace placeholders in files
        $files = getFilesWithPlaceholders();
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $content = str_replace(
                ['CandyMold', 'candy-mold', 'App\\', 'sugarcraft/candy-mold'],
                [$namespace, $appName, $namespace . '\\', "author/{$appName}"],
                $content
            );
            file_put_contents($file, $content);
        }

        $io->success("Configured {$appName}! Run `composer install` to get started.");

        return Command::SUCCESS;
    })
    ->getApplication()
    ->run();
```

**Effort:** 2-3 hours
**Benefit:** Personalized scaffold, proper namespace/variable replacement

#### 4.2.2 Add GitHub Actions CI Workflow
**Source:** `spatie/package-skeleton-php/.github/workflows/run-tests.yml`

```yaml
name: Tests

on:
  push:
    branches: [main, master]
  pull_request:
    branches: [main, master]

jobs:
  test:
    runs-on: ubuntu-latest
    name: test

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.3
          coverage: pcov
          extensions: mbstring, xml
          ini-values: memory=256M

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run tests
        run: vendor/bin/phpunit --coverage-text

      - name: Upload coverage
        uses: codecov/codecov-action@v4
        with:
          flags: candy-mold
```

**Effort:** 1 hour
**Benefit:** Automated testing, coverage reporting

#### 4.2.3 Add Code Style Workflow
```yaml
name: Code Style

on:
  push:
    branches: [main, master]
  pull_request:
    branches: [main, master]

jobs:
  pint:
    runs-on: ubuntu-latest
    name: pint

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.3

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist

      - name: Run Pint
        run: vendor/bin/pint --test
```

**Effort:** 1 hour
**Benefit:** Consistent code style

---

### 4.3 Lower Priority (Higher Effort)

#### 4.3.1 Add Static Analysis (PHPStan/Psalm)

**Source:** `ramsey/php-library-starter-kit/phpstan.neon.dist`

```neon
parameters:
    level: max
    paths:
        - src
        - tests
    excludePaths:
        - src/LibraryStarterKit
    checkGenericClassInNonGenericObjectType: false
    checkMissingIterableValueType: false
```

**Effort:** 2-3 hours (config + CI integration)
**Benefit:** Catch type errors before runtime

#### 4.3.2 Add Pest Testing Option

**Source:** `spatie/package-skeleton-php` Pest configuration

```
tests/
├── Pest.php          # Bootstrap
├── ExampleTestPest.php
└── ArchTest.php      # Architecture tests
```

**Effort:** 3-4 hours
**Benefit:** More expressive tests, simpler syntax

#### 4.3.3 Add Makefile for Common Tasks
**Source:** `JBZoo/Skeleton-PHP` Makefile pattern

```makefile
.COMPOSER := $(shell which composer)

install:
	composer install

test:
	vendor/bin/phpunit --coverage-text

format:
	vendor/bin/pint

analyse:
	vendor/bin/phpstan analyse

.PHONY: install test format analyse
```

**Effort:** 1 hour
**Benefit:** Simple shortcuts for common operations

#### 4.3.4 Add Pre-commit Hooks
**Source:** `ramsey/php-library-starter-kit/captainhook.json`

```json
{
    "pre-commit": {
        "enabled": true,
        "actions": [
            "composer test",
            "composer analyse"
        ]
    }
}
```

**Effort:** 2 hours
**Benefit:** Catch issues before commit

---

## 5. Recommendations Summary

### 5.1 Recommended Implementation Order

| Priority | Item | Effort | Impact |
|----------|------|--------|--------|
| 1 | `.editorconfig` | 10m | Low effort, consistent editors |
| 2 | `.gitattributes` | 10m | Cross-platform line endings |
| 3 | PHPUnit `failOnWarning` | 5m | Better test hygiene |
| 4 | Composer test script | 5m | Standardized runs |
| 5 | CI workflow (tests) | 1h | Automated quality |
| 6 | CI workflow (pint) | 1h | Code style automation |
| 7 | `configure.php` | 2-3h | Personalized setup |
| 8 | `.env.example` | 15m | Documentation |
| 9 | PHPStan config | 2h | Static analysis |
| 10 | Pre-commit hooks | 2h | Local quality gates |

### 5.2 Key Learnings from Other Skeletons

1. **Placeholder tokens** are essential for re-usable skeletons (spatie, ramsey)
2. **Interactive configuration** significantly improves user experience (spatie's `configure.php`)
3. **CI/CD is table stakes** — even minimal skeletons should have test workflows
4. **Multiple tooling options** (Pest vs PHPUnit, Pint vs CS-Fixer) are expected
5. **Self-destructing configuration scripts** prevent pollution (spatie deletes `configure.php`)
6. **Git-aware defaults** (author name/email from git config) reduce friction

### 5.3 TUI-Specific Considerations

Unlike web applications (Laravel) or packages (spatie), TUI apps have unique needs:

1. **Terminal detection** in `bin/start` (already good — checks multiple autoload paths)
2. **Signal handling** for graceful shutdown (SIGINT, SIGTERM)
3. **Raw mode / alt-screen lifecycle** (Program harness handles this)
4. **Panic handler** for styled crash backtraces (optional, documented)
5. **VHS demos** for visual documentation (already present)

Consider adding:
- **System requirements check** in `configure.php` (TTY support, color support)
- **Example of optional panic handler** integration
- **Example of multiple Models** for complex apps

---

## 6. Conclusion

Candy-mold is a solid foundation but benefits from modernization. The most impactful improvements are:

1. **CI/CD workflows** (immediate quality gains)
2. **Interactive configuration** (improved DX)
3. **Static analysis** (prevent runtime errors)
4. **Better PHPUnit configuration** (catch problems early)
5. **Documentation improvements** (`.env.example`, contributing guide)

Start with the high-priority items (10-15 minutes each) and iterate toward the more complex features.

---

## References

- Spatie Package Skeleton: https://github.com/spatie/package-skeleton-php
- Ramsey PHP Library Starter Kit: https://github.com/ramsey/php-library-skeleton
- Laravel Framework: https://github.com/laravel/laravel
- Symfony Skeleton: https://github.com/symfony/skeleton
- Pekral PHP Skeleton: https://github.com/pekral/php-skeleton
- JBZoo Skeleton PHP: https://github.com/JBZoo/Skeleton-PHP
- Laravel News Package Tutorial: https://laravel-news.com/create-a-php-package-from-scratch
