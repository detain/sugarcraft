<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Store;

/**
 * Regression: the headless-report example used a fixed path in the shared temp
 * directory and skipped seeding whenever that path already held beats, so a
 * file another local user left there was reported as the example's output.
 */
final class HeadlessReportExampleTest extends TestCase
{
    private const LEGACY_DIR_NAME = 'candy-codetime-headless-report';

    public function testReportIgnoresBeatsAtTheLegacySharedPath(): void
    {
        // The child process reads its temp directory from TMPDIR, so the planted
        // legacy directory lives under a root this test creates and owns. The real
        // shared temp directory is never touched, and the test never skips.
        $root = \sys_get_temp_dir() . '/candy-codetime-test-' . \bin2hex(\random_bytes(8));
        Store::ensurePrivateDir($root, 'test temp root');
        try {
            $legacy = $root . '/' . self::LEGACY_DIR_NAME;
            Store::ensurePrivateDir($legacy, 'test planted directory');
            $planted = (new DateTimeImmutable('today'))->setTime(8, 0);
            (new Store($legacy))->append(new Heartbeat(
                time: $planted->getTimestamp(),
                project: 'intruder',
                language: 'php',
                file: 'planted.php',
                duration: 99999,
            ));

            $script = \dirname(__DIR__) . '/examples/headless-report.php';
            \exec(
                'TMPDIR=' . \escapeshellarg($root) . ' '
                . \PHP_BINARY . ' ' . \escapeshellarg($script) . ' < /dev/null 2>&1',
                $lines,
                $rc,
            );
            $out = \implode("\n", $lines);

            $this->assertSame(0, $rc, $out);
            $this->assertStringNotContainsString('intruder', $out, 'the report must cover only the beats this run seeded');
            $this->assertStringContainsString('total: 1h 15m', $out);
        } finally {
            \exec('rm -rf ' . \escapeshellarg($root));
        }
    }
}
