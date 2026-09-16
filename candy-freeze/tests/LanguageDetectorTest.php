<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\LanguageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function extensionProvider(): array
    {
        return [
            'typescript ts' => ['test.ts', 'typescript'],
            'typescript tsx' => ['Component.tsx', 'typescript'],
            'css scss' => ['style.scss', 'css'],
            'css sass' => ['style.sass', 'css'],
            'css less' => ['style.less', 'css'],
            'xml' => ['data.xml', 'xml'],
            'cpp cpp' => ['main.cpp', 'cpp'],
            'cpp cc' => ['main.cc', 'cpp'],
            'cpp cxx' => ['main.cxx', 'cpp'],
            'cpp hpp' => ['main.hpp', 'cpp'],
            'c c' => ['main.c', 'c'],
            'c h' => ['main.h', 'c'],
            'java' => ['Main.java', 'java'],
            'csharp' => ['Program.cs', 'csharp'],
            'swift' => ['main.swift', 'swift'],
            'kotlin kt' => ['main.kt', 'kotlin'],
            'kotlin kts' => ['main.kts', 'kotlin'],
            'scala' => ['main.scala', 'scala'],
            'r' => ['script.r', 'r'],
            'lua' => ['script.lua', 'lua'],
            'perl pl' => ['script.pl', 'perl'],
            'perl pm' => ['script.pm', 'perl'],
            'tcl' => ['script.tcl', 'tcl'],
            'elixir ex' => ['main.ex', 'elixir'],
            'elixir exs' => ['main.exs', 'elixir'],
            'erlang' => ['main.erl', 'erlang'],
            'haskell' => ['main.hs', 'haskell'],
            'clojure clj' => ['main.clj', 'clojure'],
            'clojure cljs' => ['main.cljs', 'clojure'],
            'ocaml ml' => ['main.ml', 'ocaml'],
            'ocaml mli' => ['main.mli', 'ocaml'],
            'julia' => ['main.jl', 'julia'],
            'zsh' => ['script.zsh', 'zsh'],
            'fish' => ['script.fish', 'fish'],
            'powershell' => ['script.ps1', 'powershell'],
            'markdown md' => ['README.md', 'markdown'],
            'markdown markdown' => ['README.markdown', 'markdown'],
            'go' => ['main.go', 'go'],
            'rust' => ['main.rs', 'rust'],
        ];
    }

    #[DataProvider('extensionProvider')]
    public function testDetectFromFilenameWithDataProvider(string $filename, string $expected): void
    {
        $this->assertSame($expected, LanguageDetector::detectFromFilename($filename));
    }

    public function testShFilenameResolvesToBash(): void
    {
        $this->assertSame('bash', LanguageDetector::detectFromFilename('script.sh'));
    }

    public function testJsonContentNotConfusedWithProse(): void
    {
        $this->assertNotSame('json', LanguageDetector::detect('This is some prose that mentions null and true and false values in a sentence.'));
    }

    public function testOversizedContentReturnsText(): void
    {
        // Content over 1,000,000 bytes is treated as plain text without analysis.
        $large = str_repeat('<?php', 200_001); // 1,000,005 bytes total
        $this->assertLessThanOrEqual(1_000_000, strlen($large) - 5);
        $this->assertSame('text', LanguageDetector::detect($large));
    }

    public function testJustUnderSizeLimitIsAnalysed(): void
    {
        // Just under the 1,000,000 byte limit should still detect PHP.
        $content = str_repeat('<?php', 166_666); // ~999,996 bytes
        $this->assertSame('php', LanguageDetector::detect($content));
    }

    public function testDetectsFromShebangWithBinSh(): void
    {
        $this->assertSame('sh', LanguageDetector::detect("#!/bin/sh\necho hello\n"));
    }

    public function testDetectsFromShebangWithUsrBinEnvBash(): void
    {
        $this->assertSame('bash', LanguageDetector::detect("#!/usr/bin/env bash\necho hello\n"));
    }

    public function testDetectsFromShebangWithUsrBinEnvPerl(): void
    {
        $this->assertSame('perl', LanguageDetector::detect("#!/usr/bin/env perl\nprint 'hello';\n"));
    }

    public function testDetectsFromDirectPathInterpreter(): void
    {
        // Direct path interpreter: #!/usr/bin/php → php
        $this->assertSame('php', LanguageDetector::detect("#!/usr/bin/php\n<?php\n"));
    }

    public function testDetectsFromDirectPathPython(): void
    {
        $this->assertSame('python', LanguageDetector::detect("#!/usr/bin/python3\nprint('hello')\n"));
    }

    public function testDetectsFromDirectPathRuby(): void
    {
        $this->assertSame('ruby', LanguageDetector::detect("#!/usr/bin/ruby\nputs 'hello'\n"));
    }

    public function testDetectsZshFromFilename(): void
    {
        $this->assertSame('zsh', LanguageDetector::detectFromFilename('script.zsh'));
    }

    public function testDetectsFishFromFilename(): void
    {
        $this->assertSame('fish', LanguageDetector::detectFromFilename('script.fish'));
    }

    public function testDetectsPowershellFromFilename(): void
    {
        $this->assertSame('powershell', LanguageDetector::detectFromFilename('script.ps1'));
    }

    public function testDetectsTypeScriptFromFilename(): void
    {
        $this->assertSame('typescript', LanguageDetector::detectFromFilename('app.ts'));
        $this->assertSame('typescript', LanguageDetector::detectFromFilename('Component.tsx'));
    }

    public function testDetectsElixirFromFilename(): void
    {
        $this->assertSame('elixir', LanguageDetector::detectFromFilename('main.ex'));
        $this->assertSame('elixir', LanguageDetector::detectFromFilename('main.exs'));
    }

    public function testDetectsErlangFromFilename(): void
    {
        $this->assertSame('erlang', LanguageDetector::detectFromFilename('main.erl'));
    }

    public function testDetectsHaskellFromFilename(): void
    {
        $this->assertSame('haskell', LanguageDetector::detectFromFilename('main.hs'));
    }

    public function testDetectsClojureFromFilename(): void
    {
        $this->assertSame('clojure', LanguageDetector::detectFromFilename('main.clj'));
        $this->assertSame('clojure', LanguageDetector::detectFromFilename('main.cljs'));
    }

    public function testDetectsOcamlFromFilename(): void
    {
        $this->assertSame('ocaml', LanguageDetector::detectFromFilename('main.ml'));
        $this->assertSame('ocaml', LanguageDetector::detectFromFilename('main.mli'));
    }

    public function testDetectsJuliaFromFilename(): void
    {
        $this->assertSame('julia', LanguageDetector::detectFromFilename('main.jl'));
    }

    public function testDetectsScalaFromFilename(): void
    {
        $this->assertSame('scala', LanguageDetector::detectFromFilename('main.scala'));
    }

    public function testDetectsRFromFilename(): void
    {
        $this->assertSame('r', LanguageDetector::detectFromFilename('script.r'));
    }

    public function testDetectsLuaFromFilename(): void
    {
        $this->assertSame('lua', LanguageDetector::detectFromFilename('script.lua'));
    }

    public function testDetectsTclFromFilename(): void
    {
        $this->assertSame('tcl', LanguageDetector::detectFromFilename('script.tcl'));
    }

    public function testTieBreakByPriorityOrder(): void
    {
        // When two languages score equally, SIGNATURE_PRIORITY determines winner.
        // PHP comes before JavaScript in SIGNATURE_PRIORITY.
        // This content has signatures for both: '<?php' (php) and 'const ' (js).
        $content = "<?php\nconst x = 1;\n";
        $this->assertSame('php', LanguageDetector::detect($content));
    }

    // --- E739: the two shebang preg arms, live for the first time -------------------
    //
    // Both arms used to be `#`-delimited, so the shebang's own `#` closed the delimiter
    // and `!` parsed as an unknown modifier: preg_match() warned and returned false, and
    // every fixture below was decided by CONTENT scoring instead. Each assertion here is
    // chosen so the answer can only come from the shebang arm — the bodies are crafted so
    // content scoring disagrees, which is what makes a re-broken regex go red.

    public function testE739EnvArmNamesInterpretersTheMapLacks(): void
    {
        // SHEBANG_MAP only lists bare names; the arm covers the versioned spellings. The
        // bodies all say "bash" to content scoring, so a non-bash answer is arm-only.
        $this->assertSame('python', LanguageDetector::detect("#!/usr/bin/env python2\necho hi\n"));
        $this->assertSame('perl', LanguageDetector::detect("#!/usr/bin/env perl5\necho hi\n"));
        $this->assertSame('javascript', LanguageDetector::detect("#!/usr/bin/env nodejs\necho hi\n"));
        $this->assertSame('ruby', LanguageDetector::detect("#!/usr/bin/env rbenv\necho hi\n"));
    }

    public function testE739EnvArmReadsInterpreterAheadOfItsFlags(): void
    {
        // A flag after the interpreter makes the line stop matching SHEBANG_MAP exactly;
        // the arm only needs the first word.
        $this->assertSame('python', LanguageDetector::detect("#!/usr/bin/env python3 -E\necho hi\n"));
    }

    public function testE739EnvArmDecidesAnOtherwiseEmptyBody(): void
    {
        // The interpreter name is off-map (so the exact-match tier cannot answer) and the
        // body is empty (so content scoring cannot either): only the arm can produce this.
        $this->assertSame('python', LanguageDetector::detect("#!/usr/bin/env python2\n"));
    }

    public function testE739DirectPathArmNamesPhpAgainstMisleadingContent(): void
    {
        // The flagship repair: `echo "hi";` scores as bash, the shebang says php.
        $this->assertSame('php', LanguageDetector::detect("#!/usr/bin/php\necho \"hi\";\n"));
    }

    public function testE739DirectPathArmWalksNestedInterpreterPaths(): void
    {
        // Greedy first segment keeps /usr/local/bin/... reachable, not just /usr/bin.
        $this->assertSame('python', LanguageDetector::detect("#!/usr/local/bin/python3\necho hi\n"));
        $this->assertSame('javascript', LanguageDetector::detect("#!/opt/node/bin/node\n"));
    }

    public function testE739EnvArmReportsTextForAnUnknownInterpreter(): void
    {
        // Written intent (default => 'text'): a shebang is authoritative even when this
        // library cannot name it, so it must not be second-guessed by content scoring.
        // Pre-E739 this returned 'bash' from the body.
        $this->assertSame('text', LanguageDetector::detect("#!/usr/bin/env tcsh\necho hi\n"));
    }

    public function testE739DirectPathArmReportsTextForAnUnknownInterpreter(): void
    {
        // Asymmetry surfaced by the fix, kept as written: the env arm knows zsh, the
        // direct-path arm's shorter list does not.
        $this->assertSame('text', LanguageDetector::detect("#!/usr/bin/zsh\necho hi\n"));
    }

    public function testE739DirectPathArmReportsTextForAFlaggedShell(): void
    {
        // `#!/bin/sh` is an exact SHEBANG_MAP hit; adding a flag drops to the arm, whose
        // list has no shell entries at all.
        $this->assertSame('text', LanguageDetector::detect("#!/bin/sh -e\necho hi\n"));
    }

    public function testE739BareEnvShebangIsText(): void
    {
        // No interpreter to extract: the direct-path arm reads the literal 'env' as the
        // program name, which is not a language this library claims.
        $this->assertSame('text', LanguageDetector::detect("#!/usr/bin/env\necho hi\n"));
    }

    public function testE739ArmsStandDownForASingleSegmentShebang(): void
    {
        // `#!/onlyone` matches neither arm (the direct-path arm needs a directory and a
        // program), so content scoring still decides.
        $this->assertSame('bash', LanguageDetector::detect("#!/onlyone\necho hi\n"));
    }

    public function testE739ArmsStandDownForASpaceSeparatedShebang(): void
    {
        // Both arms anchor on the exact `#!/` prefix; a space keeps them out, and the body
        // (not the interpreter name) decides again.
        $this->assertSame('bash', LanguageDetector::detect("#! /usr/bin/env php\necho hi\n"));
    }

    public function testE739ExactMapEntryStillWinsAheadOfTheArms(): void
    {
        // Ordering pin: the same line would answer 'text' if the arms got there first.
        $this->assertSame('sh', LanguageDetector::detect("#!/bin/sh\necho hi\n"));
    }

    public function testE739ShebangPatternsCarryNoHashDelimiter(): void
    {
        // Literal guard for the defect class: `#` cannot delimit a shebang pattern, and a
        // behavioural suite can only prove the fix for the inputs it happens to try.
        $source = file_get_contents(__DIR__ . '/../src/LanguageDetector.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString("'#^#!/", $source);
        $this->assertSame(
            2,
            substr_count($source, "preg_match('~^#!/"),
            'both shebang arms must stay ~-delimited (E739)',
        );
    }
}
