<?php

declare(strict_types=1);

namespace SugarCraft\Log\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Log\PanicFormatter;
use SugarCraft\Style\Style;

final class PanicFormatterTest extends TestCase
{
    public function testPrettyCreatesInstance(): void
    {
        $f = PanicFormatter::pretty();
        $this->assertInstanceOf(PanicFormatter::class, $f);
    }

    public function testPlainCreatesInstance(): void
    {
        $f = PanicFormatter::plain();
        $this->assertInstanceOf(PanicFormatter::class, $f);
    }

    public function testPrettyWithOptions(): void
    {
        $f = PanicFormatter::pretty(showLocals: true, redactPaths: ['/etc/secrets']);
        $this->assertInstanceOf(PanicFormatter::class, $f);
    }

    public function testFormatContainsExceptionClass(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('something went wrong');
        $out = $f->format($e);
        $this->assertStringContainsString('RuntimeException', $out);
    }

    public function testFormatContainsExceptionMessage(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('something went wrong');
        $out = $f->format($e);
        $this->assertStringContainsString('something went wrong', $out);
    }

    public function testFormatContainsFileAndLine(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test', 0, new \Exception('inner'));
        $out = $f->format($e);
        $this->assertStringContainsString($e->getFile(), $out);
    }

    public function testFormatRedactsPrimaryFileAndFramesTogether(): void
    {
        // One policy, all sites: the primary exception file is redacted the
        // same way as frame paths (the old loop did it, the old comment did
        // not — and it silently skipped the control sweep this now pins).
        $f = PanicFormatter::plain(false, ['/etc/secrets']);
        $e = new \ErrorException('redaction sweep', 0, E_USER_ERROR, '/etc/secrets/primary.php', 12);
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => '/etc/secrets/config.php',
            'line' => 10,
            'function' => 'load',
        ]]);

        $out = $f->format($e);

        $this->assertStringNotContainsString('/etc/secrets', $out);
        $this->assertStringContainsString('[redacted]/primary.php', $out);
        $this->assertStringContainsString('[redacted]/config.php', $out);
    }

    public function testFormatCollapsesRepeatedFrames(): void
    {
        $f = PanicFormatter::plain();

        // Create an exception and inject a repeated trace via reflection.
        $e = new \RuntimeException('repeated frame test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $frame = ['file' => __FILE__, 'line' => 1, 'function' => 'repeated_func', 'class' => 'TestClass'];
        $traceProp->setValue($e, array_fill(0, 5, $frame));

        $out = $f->format($e);
        // With all 5 frames identical, algorithm shows 1 + "... N-1 more" = 1 + "... 4 more"
        // But if algorithm groups all into collapsed form, shows "... 5 more".
        $this->assertMatchesRegularExpression('/\.\.\. \d+ more/', $out);
    }

    public function testFormatReturnsString(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test');
        $this->assertIsString($f->format($e));
    }

    public function testFormatCarriesNeutralFooterNotInternalJargon(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test');
        $out = $f->format($e);
        $this->assertStringContainsString('report rendered by sugar-log', $out);
        $this->assertStringNotContainsString('caliber', $out);
    }

    public function testInternalFramesKeepQuestionPlaceholderNotZero(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('internal frame test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        // A call_user_func-style frame: no file, no line.
        $traceProp->setValue($e, [['function' => 'call_user_func', 'class' => 'X', 'type' => '::']]);

        $out = $f->format($e);
        $this->assertStringContainsString('?:? X::call_user_func()', $out);
        $this->assertStringNotContainsString(':0 X::call_user_func()', $out);
    }

    public function testPrettyHasAnsiEscapes(): void
    {
        $f = PanicFormatter::pretty();
        $e = new \RuntimeException('test');
        $out = $f->format($e);
        $this->assertStringContainsString("\x1b[", $out);
    }

    public function testPlainHasNoAnsiEscapes(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('test');
        $out = $f->format($e);
        $this->assertStringNotContainsString("\x1b[", $out);
    }

    public function testFormatErrorException(): void
    {
        $f = PanicFormatter::plain();
        $e = new \ErrorException('type error', 0, E_USER_ERROR, '/path/to/file.php', 42);
        $out = $f->format($e);
        $this->assertStringContainsString('ErrorException', $out);
        $this->assertStringContainsString('type error', $out);
        $this->assertStringContainsString('file.php', $out);
        $this->assertStringContainsString('42', $out);
    }

    // -------------------------------------------------------------------------
    // [SEC] showLocals secret exposure + redaction of dumped locals
    // -------------------------------------------------------------------------

    /** @return \Throwable A throwable whose top frame carries a secret arg. */
    private function exceptionWithSecretLocal(string $secret): \Throwable
    {
        $e = new \RuntimeException('boom');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => __FILE__,
            'line' => 5,
            'function' => 'connect',
            'class' => 'Db',
            'type' => '->',
            'args' => [$secret],
        ]]);
        return $e;
    }

    public function testShowLocalsDumpsScalarArgs(): void
    {
        // Documents the risk the redaction guards against: with showLocals ON
        // and no redaction configured, a secret argument leaks verbatim.
        $f = PanicFormatter::pretty(showLocals: true);
        $out = $f->format($this->exceptionWithSecretLocal('supersecret-token'));
        $this->assertStringContainsString('supersecret-token', $out);
    }

    public function testShowLocalsRedactsConfiguredSecretsInLocals(): void
    {
        // The fix: redactPaths substrings are now stripped from dumped locals,
        // not just from backtrace file paths.
        $f = PanicFormatter::pretty(showLocals: true, redactPaths: ['supersecret-token']);
        $out = $f->format($this->exceptionWithSecretLocal('supersecret-token'));

        $this->assertStringNotContainsString('supersecret-token', $out);
        $this->assertStringContainsString('[redacted]', $out);
    }

    public function testShowLocalsTruncatesLongScalarArgs(): void
    {
        $f = PanicFormatter::pretty(showLocals: true);

        // Create an exception with a backtrace frame carrying a long string arg (> 40 chars)
        $e = new \RuntimeException('long args test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $longString = str_repeat('x', 60); // 60 chars, exceeds 40 char limit
        $traceProp->setValue($e, [[
            'file' => __FILE__,
            'line' => 100,
            'function' => 'process',
            'class' => 'Test',
            'type' => '->',
            'args' => [$longString],
        ]]);

        $out = $f->format($e);

        // Long string should be truncated to 40 chars + ellipsis. The marker
        // is ASCII '...' since [N12] — the collapse rows already use '...' and
        // one report must not mix two ellipsis glyphs.
        $this->assertStringContainsString('xxxx', $out);
        $this->assertStringContainsString(str_repeat('x', 40) . '...', $out);
        $this->assertStringNotContainsString($longString, $out);
    }

    public function testShowLocalsHandlesNonScalarArgs(): void
    {
        $f = PanicFormatter::pretty(showLocals: true);

        // Create an exception with a backtrace frame carrying a non-scalar arg (array)
        $e = new \RuntimeException('non-scalar args test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => __FILE__,
            'line' => 100,
            'function' => 'process',
            'class' => 'Test',
            'type' => '->',
            'args' => [['key' => 'value']], // array arg
        ]]);

        $out = $f->format($e);

        // Non-scalar should be shown as its type, not cause error
        $this->assertIsString($out);
        $this->assertStringContainsString('array', $out);
    }

    public function testShowLocalsHandlesObjectArg(): void
    {
        $f = PanicFormatter::pretty(showLocals: true);

        // Create an exception with an object arg
        $e = new \RuntimeException('object args test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => __FILE__,
            'line' => 100,
            'function' => 'process',
            'class' => 'Test',
            'type' => '->',
            'args' => [new \stdClass()],
        ]]);

        $out = $f->format($e);

        // Object arg should be shown as "object"
        $this->assertIsString($out);
        $this->assertStringContainsString('object', $out);
    }

    public function testFormatBacktraceRedactsMultiplePaths(): void
    {
        $f = PanicFormatter::pretty(showLocals: false, redactPaths: ['/etc/secrets', '/var/token']);

        $e = new \RuntimeException('test');
        $traceProp = new \ReflectionProperty(\Exception::class, 'trace');
        $traceProp->setAccessible(true);
        $traceProp->setValue($e, [[
            'file' => '/etc/secrets/config.php',
            'line' => 10,
            'function' => 'load',
            'class' => 'Config',
            'type' => '->',
            'args' => [],
        ]]);

        $out = $f->format($e);

        // Path should be redacted
        $this->assertStringContainsString('[redacted]', $out);
        $this->assertStringNotContainsString('/etc/secrets', $out);
    }

    // -------------------------------------------------------------------------
    // [M4] the message is untrusted input: no smuggled rows, no secret echo
    // -------------------------------------------------------------------------

    public function testHostileMessageCannotForgeReportRows(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException("boom\x1b[2J\nFORGED row");
        $out = $f->format($e);

        // Plain report: zero escape bytes anywhere.
        $this->assertStringNotContainsString("\x1b", $out);

        // The embedded newline must not split the message across two report
        // lines: 'boom' and 'FORGED' land on ONE row, and 'boom' appears in
        // exactly one row of the report.
        $boomLines = array_values(array_filter(
            explode("\n", $out),
            static fn (string $row): bool => str_contains($row, 'boom'),
        ));
        $this->assertCount(1, $boomLines);
        $this->assertStringContainsString('FORGED', $boomLines[0]);
    }

    public function testMessageRespectsRedactPaths(): void
    {
        // [M4] redaction that only covers frames and locals still leaks a
        // secret quoted inside the exception message itself.
        $f = PanicFormatter::plain(false, ['/secret']);
        $e = new \RuntimeException('failed to read /secret/key.php');
        $out = $f->format($e);

        $this->assertStringContainsString('[redacted]', $out);
        $this->assertStringNotContainsString('/secret', $out);
    }

    public function testCollapseRowCountsHiddenFramesOnly(): void
    {
        // [m29] A run of 5 identical frames shows one and hides four: the
        // collapsed row must read '... 4 more', never the total.
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('collapse');
        $same = [[
            'file' => __FILE__,
            'line' => 777,
            'function' => 'recurse',
            'class' => 'Deep',
            'type' => '->',
        ]];
        $trace = array_merge(
            $same, $same, $same, $same, $same,
            [[
                'file' => __FILE__,
                'line' => 778,
                'function' => 'other',
            ]],
        );
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($e, $trace);

        $out = $f->format($e);
        $this->assertMatchesRegularExpression('/\.\.\. 4 more/', $out);
        $this->assertStringNotContainsString('... 5 more', $out);
    }

    public function testLocalsRenderOnSingleSweptLine(): void
    {
        $f = PanicFormatter::plain(showLocals: true);
        $e = new \RuntimeException('locals row');
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($e, [[
            'file' => __FILE__,
            'line' => 888,
            'function' => 'go',
            'args' => ["x\ny\t\x1b[2Jz"],
        ]]);

        $out = $f->format($e);
        $this->assertStringNotContainsString("\x1b", $out);

        $rows = array_values(array_filter(
            explode("\n", $out),
            // PHP's codepoint escape is \u{...}; \x{...} is PCRE-pattern-only.
            static fn (string $row): bool => str_contains($row, "\u{2192}"),
        ));
        $this->assertCount(1, $rows, 'the forged newline must not split the locals row');
        $this->assertMatchesRegularExpression('/\x{2192} x y\s+z/u', $rows[0]);
    }

    public function testAnonymousExceptionNameLosesItsCreationTail(): void
    {
        $f = PanicFormatter::plain();
        // Real PHP 8.3 anonymous classes are named 'RuntimeException@anonymous\0<file>:<line>$<counter>'
        // — NUL-separated, with the creating file path embedded in the name.
        $e = new class extends \RuntimeException {
        };
        $out = $f->format($e);

        $banner = array_values(array_filter(
            explode("\n", $out),
            static fn (string $row): bool => str_contains($row, 'PANIC'),
        ));
        $this->assertCount(1, $banner);
        // End-anchored: pre-fix the raw name kept '@anonymous' + creation path +
        // counter (the NUL alone was swept by ctrl), so nothing after '@anonymous'
        // may remain on the banner row.
        $this->assertMatchesRegularExpression('/PANIC\s+RuntimeException@anonymous\s*$/u', $banner[0]);
    }

    public function testFrameClassNamesLoseTheirCreationTail(): void
    {
        $f = PanicFormatter::plain();
        $e = new \RuntimeException('frame anon class');
        $anon = 'class@anonymous' . "\0" . '/secret/place/creating-file.php:7$1';
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($e, [[
            'file' => '/tmp/x.php',
            'line' => 991,
            'class' => $anon,
            'type' => '->',
            'function' => 'run',
        ]]);

        $out = $f->format($e);
        $this->assertStringNotContainsString('/secret/place/creating-file.php', $out);
        $this->assertStringNotContainsString("\0", $out);
        $this->assertStringContainsString('class@anonymous->run()', $out);
    }

    // -------------------------------------------------------------------------
    // [SL-07] unified redact/ctrl policy: primary-file sweep, Windows
    // case fold, empty-entry ignore, realpath both-spellings, byte order
    // -------------------------------------------------------------------------

    public function testHostilePrimaryFilePathCannotForgeRowsOrEscapes(): void
    {
        $f = PanicFormatter::plain();
        // ErrorException carries an explicit filename — the crafted-upload /
        // phar path shape that reaches getFile(). Pre-fix the primary file
        // row went to the terminal unswept: raw ESC and a split row.
        $e = new \ErrorException('oven jammed', 0, E_USER_ERROR, "/tmp/fo\x1b[2Jrge\nrow.php", 7);
        $out = $f->format($e);

        $this->assertStringNotContainsString("\x1b", $out);

        $rows = array_values(array_filter(
            explode("\n", $out),
            static fn (string $row): bool => str_contains($row, 'row.php'),
        ));
        $this->assertCount(1, $rows, 'the forged newline must not split the primary file row');
        // Sanitize::untrusted drops the WHOLE CSI sequence (ESC[2J), not just
        // the introducer; ctrl() folds the newline into a space.
        $this->assertStringContainsString('/tmp/forge row.php:7', $rows[0]);
    }

    public function testCaseFoldedRedactionMatchesWindowsPathSpelling(): void
    {
        // The seam: PHP_OS_FAMILY cannot be forked inside a process, so the
        // Windows arm is pinned through the private constructor (scope-bound
        // closure — reflection instantiation rejects private ctors), and the
        // POSIX arm is pinned by binding the same fixture with fold=false.
        $mk = static function (array $paths, bool $fold): PanicFormatter {
            $factory = \Closure::bind(
                static fn (Style $t, Style $x, Style $b, Style $m, bool $locals, array $redact, bool $caseFold): PanicFormatter
                    => new PanicFormatter($t, $x, $b, $m, $locals, $redact, $caseFold),
                null,
                PanicFormatter::class,
            );

            return $factory(Style::new(), Style::new(), Style::new(), Style::new(), false, $paths, $fold);
        };

        $winish = $mk(['c:\secrets\key'], true);
        $out = $winish->format(new \RuntimeException('cannot open C:\SeCREts\key.txt'));
        $this->assertStringNotContainsString('SeCREts', $out);
        $this->assertStringContainsString('[redacted]', $out);

        $posix = $mk(['c:\secrets\key'], false);
        $out = $posix->format(new \RuntimeException('cannot open C:\SeCREts\key.txt'));
        $this->assertStringContainsString('C:\SeCREts', $out, 'POSIX paths are case-sensitive: different bytes, different path, no fold');
    }

    public function testEmptyRedactEntryIsIgnoredNotSpliced(): void
    {
        // An empty configured substring is a config typo, not a policy: the
        // unguarded loops spliced '[redacted]' between every character of the
        // primary file and frame paths (PHP 8 str_replace semantics).
        $f = PanicFormatter::plain(false, ['', '/etc/secrets']);
        $e = new \ErrorException('cannot read /etc/secrets/app.env', 0, E_USER_ERROR, '/etc/secrets/primary.php', 3);
        $out = $f->format($e);

        $this->assertStringContainsString('cannot read [redacted]/app.env', $out);
        $this->assertStringContainsString('[redacted]/primary.php', $out);
        $this->assertStringNotContainsString('/etc/secrets', $out);
        $this->assertSame(2, substr_count($out, '[redacted]'), 'exactly one placeholder per real secret site — no fragmentation');
    }

    public function testRedactionComparesConfiguredAndRealpathSpellings(): void
    {
        // Traces report canonical paths while configs quote authored ones;
        // both spellings must match, and an unresolvable entry must survive
        // normalization rather than being dropped.
        $dir = sys_get_temp_dir() . '/sugar-log-rp-' . bin2hex(random_bytes(4));
        mkdir($dir . '/nested', 0o777, true);
        touch($dir . '/nested/secret.key');
        $authored = $dir . '/./nested/../nested/secret.key';
        $canonical = realpath($authored);
        $this->assertIsString($canonical);

        $f = PanicFormatter::plain(false, [$authored]);
        $viaCanonical = $f->format(new \RuntimeException("read $canonical denied"));
        $viaAuthored = $f->format(new \RuntimeException("read $authored denied"));

        @unlink($dir . '/nested/secret.key');
        @rmdir($dir . '/nested');
        @rmdir($dir);

        $this->assertStringContainsString('read [redacted] denied', $viaCanonical);
        $this->assertStringNotContainsString('secret.key', $viaCanonical);
        $this->assertStringContainsString('read [redacted] denied', $viaAuthored);
    }

    public function testTabBearingSecretRedactsOnRawBytesBeforeTheSweep(): void
    {
        // Order discriminator (redact-then-ctrl, never the reverse): a tab is
        // legal in a POSIX file name, so the configured secret itself carries
        // a byte ctrl() would fold. Sweep-first would fold the subject away
        // from the still-tabbed needle and leak it. Built with chr(9): a
        // "\t" literal immediately followed by 't' eats that 't' into the
        // escape sequence and quietly changes the needle.
        $secret = '/srv/ke' . \chr(9) . 'ty/file';
        $f = PanicFormatter::plain(false, [$secret]);
        $out = $f->format(new \RuntimeException('cannot read ' . $secret));

        $this->assertStringContainsString('[redacted]', $out);
        $this->assertStringNotContainsString('ty/file', $out);
    }
}
