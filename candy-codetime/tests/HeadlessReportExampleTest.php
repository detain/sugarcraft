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
        $legacy = \sys_get_temp_dir() . '/' . self::LEGACY_DIR_NAME;
        if (\file_exists($legacy)) {
            $this->markTestSkipped('The legacy shared path already exists; planting a beat there would touch data this test does not own.');
        }

        Store::ensurePrivateDir($legacy, 'test planted directory');
        try {
            $planted = (new DateTimeImmutable('today'))->setTime(8, 0);
            (new Store($legacy))->append(new Heartbeat(
                time: $planted->getTimestamp(),
                project: 'intruder',
                language: 'php',
                file: 'planted.php',
                duration: 99999,
            ));

            $script = \dirname(__DIR__) . '/examples/headless-report.php';
            \exec(\PHP_BINARY . ' ' . \escapeshellarg($script) . ' < /dev/null 2>&1', $lines, $rc);
            $out = \implode("\n", $lines);

            $this->assertSame(0, $rc, $out);
            $this->assertStringNotContainsString('intruder', $out, 'the report must cover only the beats this run seeded');
            $this->assertStringContainsString('total: 1h 15m', $out);
        } finally {
            \exec('rm -rf ' . \escapeshellarg($legacy));
        }
    }
}
