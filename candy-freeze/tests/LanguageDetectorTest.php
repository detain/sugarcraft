<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\LanguageDetector;
use PHPUnit\Framework\TestCase;

final class LanguageDetectorTest extends TestCase
{
    public function testDetectsPhpFromShebang(): void
    {
        $this->assertSame('php', LanguageDetector::detect("#!/usr/bin/env php\n<?php\necho 'hello';\n"));
    }

    public function testDetectsBashFromShebang(): void
    {
        $this->assertSame('bash', LanguageDetector::detect("#!/bin/bash\necho 'hello'\n"));
    }

    public function testDetectsPythonFromShebang(): void
    {
        $this->assertSame('python', LanguageDetector::detect("#!/usr/bin/env python3\nprint('hello')\n"));
    }

    public function testDetectsJavascriptFromShebang(): void
    {
        $this->assertSame('javascript', LanguageDetector::detect("#!/usr/bin/env node\nconsole.log('hello');\n"));
    }

    public function testDetectsRubyFromShebang(): void
    {
        $this->assertSame('ruby', LanguageDetector::detect("#!/usr/bin/env ruby\nputs 'hello'\n"));
    }

    public function testDetectsPhpFromContent(): void
    {
        $this->assertSame('php', LanguageDetector::detect("<?php\ndeclare(strict_types=1);\nnamespace Test;\n"));
    }

    public function testDetectsPhpFromNamespace(): void
    {
        $this->assertSame('php', LanguageDetector::detect("namespace SugarCraft\\Freeze;\nuse SugarCraft\\Core\\Util\\Ansi;\n"));
    }

    public function testDetectsJavascriptFromContent(): void
    {
        $this->assertSame('javascript', LanguageDetector::detect("const x = 42;\nconsole.log(x);\n"));
    }

    public function testDetectsPythonFromContent(): void
    {
        $this->assertSame('python', LanguageDetector::detect("def foo():\n    print('hello')\n    return 42\n"));
    }

    public function testDetectsBashFromContent(): void
    {
        $this->assertSame('bash', LanguageDetector::detect("if [ -z \"\$VAR\" ]; then\necho 'empty'\nfi\n"));
    }

    public function testDetectsSqlFromContent(): void
    {
        $this->assertSame('sql', LanguageDetector::detect("SELECT id, name FROM users WHERE active = 1\n"));
    }

    public function testDetectsHtmlFromContent(): void
    {
        $this->assertSame('html', LanguageDetector::detect("<html>\n<head><title>Test</title></head>\n<body>Hello</body>\n</html>\n"));
    }

    public function testDetectsCssFromContent(): void
    {
        $this->assertSame('css', LanguageDetector::detect("body {\n    color: #333;\n    background: #fff;\n}\n"));
    }

    public function testDetectsJsonFromContent(): void
    {
        $this->assertSame('json', LanguageDetector::detect('{"name": "test", "value": 42}'));
    }

    public function testDetectsYamlFromContent(): void
    {
        $this->assertSame('yaml', LanguageDetector::detect("---\nname: test\nvalue: 42\n"));
    }

    public function testDetectsMarkdownFromContent(): void
    {
        $this->assertSame('markdown', LanguageDetector::detect("# Hello\n\nThis is **bold** and *italic*.\n\n```php\n<?php\n```\n"));
    }

    public function testDetectsUnknownContentAsText(): void
    {
        $this->assertSame('text', LanguageDetector::detect("Lorem ipsum dolor sit amet\n"));
    }

    public function testDetectFromFilenamePhp(): void
    {
        $this->assertSame('php', LanguageDetector::detectFromFilename('test.php'));
        $this->assertSame('php', LanguageDetector::detectFromFilename('Test.PHP'));
    }

    public function testDetectFromFilenameJavascript(): void
    {
        $this->assertSame('javascript', LanguageDetector::detectFromFilename('app.js'));
        $this->assertSame('javascript', LanguageDetector::detectFromFilename('module.mjs'));
        $this->assertSame('javascript', LanguageDetector::detectFromFilename('config.cjs'));
    }

    public function testDetectFromFilenamePython(): void
    {
        $this->assertSame('python', LanguageDetector::detectFromFilename('script.py'));
        $this->assertSame('python', LanguageDetector::detectFromFilename('main.pyw'));
    }

    public function testDetectFromFilenameRuby(): void
    {
        $this->assertSame('ruby', LanguageDetector::detectFromFilename('app.rb'));
    }

    public function testDetectFromFilenameBash(): void
    {
        $this->assertSame('bash', LanguageDetector::detectFromFilename('script.sh'));
        $this->assertSame('bash', LanguageDetector::detectFromFilename('install.bash'));
    }

    public function testDetectFromFilenameSql(): void
    {
        $this->assertSame('sql', LanguageDetector::detectFromFilename('query.sql'));
    }

    public function testDetectFromFilenameHtml(): void
    {
        $this->assertSame('html', LanguageDetector::detectFromFilename('index.html'));
        $this->assertSame('html', LanguageDetector::detectFromFilename('page.htm'));
    }

    public function testDetectFromFilenameJson(): void
    {
        $this->assertSame('json', LanguageDetector::detectFromFilename('config.json'));
    }

    public function testDetectFromFilenameYaml(): void
    {
        $this->assertSame('yaml', LanguageDetector::detectFromFilename('config.yaml'));
        $this->assertSame('yaml', LanguageDetector::detectFromFilename('deploy.yml'));
    }

    public function testDetectFromFilenameGo(): void
    {
        $this->assertSame('go', LanguageDetector::detectFromFilename('main.go'));
    }

    public function testDetectFromFilenameRust(): void
    {
        $this->assertSame('rust', LanguageDetector::detectFromFilename('lib.rs'));
    }

    public function testDetectFromFilenameUnknown(): void
    {
        $this->assertSame('text', LanguageDetector::detectFromFilename('file.txt'));
        $this->assertSame('text', LanguageDetector::detectFromFilename('Makefile'));
        $this->assertSame('text', LanguageDetector::detectFromFilename('.gitignore'));
    }

    public function testDetectsHighestScoringLanguage(): void
    {
        // Both PHP and JS signatures present, but PHP has more
        $content = "<?php\nnamespace Test;\nconst x = 42;\nconsole.log(x);\n";
        $this->assertSame('php', LanguageDetector::detect($content));
    }

    public function testEmptyContentReturnsText(): void
    {
        $this->assertSame('text', LanguageDetector::detect(""));
        $this->assertSame('text', LanguageDetector::detect("   \n\n  "));
    }

    public function testShebangTakesPrecedenceOverContent(): void
    {
        $content = "#!/bin/bash\n<?php\necho 'hello';\n";
        $this->assertSame('bash', LanguageDetector::detect($content));
    }
}
