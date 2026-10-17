<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tools\BuiltIn\Grep;
use SugarCraft\Code\Tools\ToolLimits;

/**
 * ISS-061: one Grep search has a wall-clock deadline.
 *
 * Pins the default (`60` is the schema default AND the tool constant the
 * roster test cross-checks), the use-site read — `Grep::searchTimeoutSeconds()`
 * answers through `ToolLimits::current()`, so a saved `grepTimeoutSeconds`
 * lands on the very next search with no rebind — and the wire behaviour: a run
 * killed at the deadline comes back as a partial answer with a marker naming
 * the ceiling and the knob, NOT as an error, while exit codes above 1 that are
 * not the killer's 124 still are.
 *
 * The E2E hang is a SPARSE giant: a 32 GiB file whose real bytes are one 4 KiB
 * line per MiB (~128 MiB on disk). `grep -r` must read every hole to prove
 * they do not match — hole-filling runs at several GB/s on any host, so the
 * whole scan lands between one and a few seconds: over a 1 s deadline, under
 * the 60 s default. A FIFO or device inside the searched directory is skipped
 * by `grep -r` itself (measured), and a FIFO as the path operand is refused by
 * this tool's `is_dir` guard, so neither can pin the deadline end to end.
 * Because the hit file and the giant can be read in either readdir order, no
 * test asserts a partial HIT survived; it pins the marker, the verdict, and
 * the clock.
 */
final class GrepTimeoutTest extends TestCase
{
    use HomeSandboxTrait;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_grep_timeout_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->useHomeSandbox($this->dir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testTheDefaultDeadlineIsTheToolConstantAndTheSchemaAgrees(): void
    {
        self::assertSame(60, Grep::DEFAULT_TIMEOUT_SECONDS);
        self::assertSame(Grep::DEFAULT_TIMEOUT_SECONDS, SettingsSchema::byKey(ToolLimits::GREP_TIMEOUT_KEY)?->default);
    }

    /** The seam answers 60.0 with nothing set and the saved whole number with it. */
    public function testTheDeadlineIsReadAtTheUseSiteFromTheMergedConfig(): void
    {
        $seam = new \ReflectionMethod(Grep::class, 'searchTimeoutSeconds');

        self::assertSame(60.0, $seam->invoke(null));

        Bootstrap::writeUserConfig([ToolLimits::GREP_TIMEOUT_KEY => 1]);

        self::assertSame(1.0, $seam->invoke(null));
    }

    /** A configured deadline of 1 s fires on the sparse giant: marker, no error, clock bound. */
    public function testASearchPastTheDeadlineComesBackPartialAndNotFailed(): void
    {
        $search = $this->sparseFixture();
        Bootstrap::writeUserConfig([ToolLimits::GREP_TIMEOUT_KEY => 1]);

        $started = microtime(true);
        $result = (new Grep())->execute(['pattern' => 'nomatchxyzzy', 'path' => $search]);
        $elapsed = microtime(true) - $started;

        self::assertStringContainsString('[timed out after 1 s', $result->content());
        self::assertStringContainsString('grepTimeoutSeconds]', $result->content(), 'the marker names the knob to raise');
        self::assertFalse($result->isError(), 'a partial answer is not a failure: ' . substr($result->content(), 0, 200));
        self::assertLessThan(30.0, $elapsed, 'the deadline did not bound the run');
    }

    /** The same fixture under the shipped 60 s default answers without a marker. */
    public function testAComfortableDeadlineLetsTheSameSearchFinish(): void
    {
        $search = $this->sparseFixture();

        $result = (new Grep())->execute(['pattern' => 'nomatchxyzzy', 'path' => $search]);

        self::assertStringNotContainsString('[timed out after', $result->content());
        self::assertFalse($result->isError());
    }

    /** A real failure — grep exits 2 on a broken pattern — is still an error (ISS-061 kept honest). */
    public function testABrokenPatternIsStillAnErrorAndCarriesNoTimeoutMarker(): void
    {
        mkdir($this->dir . '/quiet', 0700, true);
        file_put_contents($this->dir . '/quiet/a.txt', "alpha\n");

        $result = (new Grep())->execute(['pattern' => '[', 'path' => $this->dir . '/quiet']);

        self::assertTrue($result->isError());
        self::assertStringNotContainsString('[timed out after', $result->content());
    }

    /**
     * 32 GiB of hole with one 4 KiB line per MiB, plus a small text file with
     * a name the deadline search never matches anyway.
     */
    private function sparseFixture(): string
    {
        $search = $this->dir . '/haystack';
        mkdir($search, 0700, true);
        file_put_contents($search . '/small.txt', 'nothing the pattern names\n');

        $giant = $search . '/giant.bin';
        $handle = fopen($giant, 'w+b');
        self::assertNotFalse($handle);
        $span = 32 * 1024 ** 3;
        self::assertNotFalse(ftruncate($handle, $span));
        $line = str_repeat('x', 4095) . "\n";
        for ($offset = 0; $offset < $span; $offset += 1024 * 1024) {
            self::assertSame(0, fseek($handle, $offset));
            self::assertNotFalse(fwrite($handle, $line));
        }
        fclose($handle);

        return $search;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
