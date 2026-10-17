<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\BuiltIn\AuditHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Code\Permissions\DenialKind;

/**
 * ISS-059: every excerpt is scrubbed BEFORE it is cut, in all three record
 * shapes, and the default log rotates by size.
 *
 * The redaction pins are paired — the marker must appear AND the secret must
 * not, because "contains [REDACTED]" alone is satisfied by a log that
 * redacts one pattern family and prints the rest. The straddle test carries
 * the ORDER law with a reflection-built pair: redact-then-cut and
 * cut-then-redact differ on exactly this fixture, so the pin discriminates
 * the sequence and not just the presence of the call.
 *
 * Writes go to an explicitly-named log path (the constructor argument) so
 * nothing here touches the production default; the rotation pair pins both
 * the arm's work and its `$ownsPath` gate.
 */
final class AuditHookRedactionRotationTest extends TestCase
{
    private string $tempDir;

    /**
     * 40 characters from the bearer pattern's alphabet, comfortably past
     * its `{16,}`. Deterministic: this fixture is diffed by eye.
     */
    private const TOKEN = 'Zk9q7Tm2LpVx8Rn4Wc6Yh1Bd3Sg5Fj7Kl0Pi2Qt';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/candy-code-iss059-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($walk as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->tempDir);

        parent::tearDown();
    }

    private function context(string $input = '{}', string $output = 'ok', string $sessionId = 'iss059-session'): HookContext
    {
        return new HookContext(
            sessionId: $sessionId,
            toolName: 'bash',
            toolArgs: [],
            toolInput: $input,
            toolOutput: $output,
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );
    }

    public function testTheOutputExcerptCarriesTheMarkerAndNotTheToken(): void
    {
        $log = $this->tempDir . '/audit.log';
        $hook = new AuditHook($log);

        $hook->execute($this->context(output: 'Authorization: Bearer ' . self::TOKEN . ' trailing noise'));

        $line = (string) file_get_contents($log);
        self::assertStringContainsString('Bearer ' . SecretRedactor::MARKER, $line);
        self::assertStringNotContainsString(self::TOKEN, $line);
    }

    /**
     * The ORDER law, both directions. A token that begins inside the first
     * 200 bytes and runs past them is redacted whole when redaction comes
     * first; cut first, the remainder is too short for the `{16,}` and the
     * characters that survive the cut are the leak.
     */
    public function testRedactionHappensBeforeTheCutNotAfterIt(): void
    {
        // The space is LOAD-BEARING: glued, the run of `a`s leaves no word
        // boundary before `Bearer`, the `{16,}` pattern never matches, and
        // both legs of this pair "pass" on an unscrubbed string.
        $padding = str_repeat('a', 189) . ' ';
        $text = $padding . 'Bearer ' . self::TOKEN;

        $redactThenCut = mb_strcut(
            SecretRedactor::new()->redact($text),
            0,
            AuditHook::OUTPUT_EXCERPT_BYTES,
            'UTF-8',
        );
        $cutThenRedact = SecretRedactor::new()->redact(
            mb_strcut($text, 0, AuditHook::OUTPUT_EXCERPT_BYTES, 'UTF-8'),
        );

        self::assertStringNotContainsString(self::TOKEN, $redactThenCut);
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 8), $redactThenCut, 'no bearer characters at all may survive the scrubbed-and-cut excerpt');
        self::assertStringContainsString('Bearer ' . substr(self::TOKEN, 0, 3), $cutThenRedact, 'the cut-first composition must be the one that leaks, or this pair pins nothing');
    }

    /**
     * The same order law through the live record: a token that starts inside
     * the excerpt window and runs past it must leave the written line with
     * the marker clipped mid-glyph ('Bearer [RE') and no characters of the
     * secret at all. Cut first, this same call writes 'Bearer Zk9' — the
     * three-token-character remainder the `{16,}` can no longer match.
     */
    public function testATokenStraddlingTheCutIsScrubbedInTheLiveRecord(): void
    {
        $log = $this->tempDir . '/audit.log';
        $hook = new AuditHook($log);

        $hook->execute($this->context(output: str_repeat('a', 189) . ' Bearer ' . self::TOKEN));

        $line = (string) file_get_contents($log);
        self::assertStringContainsString('Bearer [RE', $line, 'the marker must have been laid down before the blade fell');
        self::assertStringNotContainsString('Bearer ' . substr(self::TOKEN, 0, 3), $line, 'no token characters may ride out of a straddling excerpt');
    }

    public function testTheInputFieldIsScrubbed(): void
    {
        $log = $this->tempDir . '/audit.log';
        $hook = new AuditHook($log);

        $hook->execute($this->context(
            input: '{"command":"curl -H \\"Authorization: Bearer ' . self::TOKEN . '\\" https://api.example/v1"}',
        ));

        $line = (string) file_get_contents($log);
        self::assertStringContainsString('Bearer ' . SecretRedactor::MARKER, $line);
        self::assertStringNotContainsString(self::TOKEN, $line);
        // Ordinary content rides through untouched — the redactor is not a
        // deny-by-default mangler, and a test that only proves redaction
        // cannot tell that from over-redaction.
        self::assertStringContainsString('https://api.example/v1', $line);
    }

    public function testAnOrdinaryRecordIsUntouched(): void
    {
        $log = $this->tempDir . '/audit.log';
        $hook = new AuditHook($log);

        $hook->execute($this->context(input: 'echo "hello world"', output: 'hello world'));

        $line = (string) file_get_contents($log);
        self::assertStringNotContainsString(SecretRedactor::MARKER, $line);
        self::assertStringContainsString('echo "hello world"', $line);
        self::assertStringContainsString('=> hello world', $line);
    }

    public function testDenialAndWithheldReasonsAreScrubbed(): void
    {
        $log = $this->tempDir . '/audit.log';
        $hook = new AuditHook($log);
        $reason = 'classifier saw Authorization: Bearer ' . self::TOKEN . ' in the command line';

        self::assertTrue($hook->recordDenial($this->context(), DenialKind::Refused, $reason));
        self::assertTrue($hook->recordWithheld($this->context(), $reason, 'secret-scan'));

        $lines = file($log, FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines);
        foreach ($lines as $line) {
            self::assertStringContainsString('Bearer ' . SecretRedactor::MARKER, $line);
            self::assertStringNotContainsString(self::TOKEN, $line);
        }
        self::assertStringContainsString('=! DENY refused:', (string) $lines[0]);
        self::assertStringContainsString('=! WITHHELD "secret-scan":', (string) $lines[1]);
    }

    // =========================================================================
    // ISS-059 rotation: size-capped default, hands-off caller paths
    // =========================================================================

    public function testTheDefaultLogRotatesOnceItPassesTheSizeCap(): void
    {
        $directory = $this->tempDir . '/audit';
        mkdir($directory, 0o700);
        $log = $directory . '/audit.log';
        $installed = AuditHook::defaultLogDirectoryPin();
        AuditHook::pinDefaultLogDirectory($directory);

        try {
            $old = str_repeat('o', AuditHook::ROTATE_AT_BYTES + 1);
            file_put_contents($log, $old);

            (new AuditHook())->execute($this->context(sessionId: 'rotate-marker'));

            $rolled = $log . '.1';
            self::assertFileExists($rolled, 'a default log past the cap was not rolled into .1');
            self::assertSame($old, file_get_contents($rolled), 'the rolled generation is not what was there');
            $fresh = (string) file_get_contents($log);
            self::assertStringNotContainsString('oooo', $fresh, 'the new generation still carries the rolled content');
            self::assertStringContainsString('rotate-marker', $fresh);
            self::assertLessThanOrEqual(AuditHook::ROTATE_AT_BYTES + 4096, strlen($fresh));
        } finally {
            AuditHook::pinDefaultLogDirectory($installed);
        }
    }

    public function testBelowTheCapNothingMoves(): void
    {
        $directory = $this->tempDir . '/audit';
        mkdir($directory, 0o700);
        $log = $directory . '/audit.log';
        $installed = AuditHook::defaultLogDirectoryPin();
        AuditHook::pinDefaultLogDirectory($directory);

        try {
            file_put_contents($log, str_repeat('o', AuditHook::ROTATE_AT_BYTES - 1024));

            (new AuditHook())->execute($this->context(sessionId: 'under-cap'));

            self::assertFileDoesNotExist($log . '.1', 'rotation fired below the cap');
            self::assertStringContainsString('under-cap', (string) file_get_contents($log));
        } finally {
            AuditHook::pinDefaultLogDirectory($installed);
        }
    }

    /**
     * `$ownsPath` gates rotation exactly like it gates the directory guards:
     * a caller-named path belongs to a caller with their own retention plan,
     * and renaming it behind them is this class overruling its own API.
     */
    public function testACallerNamedPathIsNeverRotated(): void
    {
        $log = $this->tempDir . '/embedder.log';
        file_put_contents($log, str_repeat('e', AuditHook::ROTATE_AT_BYTES + 1));

        (new AuditHook($log))->execute($this->context(sessionId: 'handed-over'));

        self::assertFileDoesNotExist($log . '.1', 'a caller-supplied path was rotated');
        self::assertStringContainsString('handed-over', (string) file_get_contents($log));
    }
}
