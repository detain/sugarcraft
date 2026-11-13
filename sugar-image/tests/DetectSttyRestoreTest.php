<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Image\Detect;
use SugarCraft\Image\Support\SttyBinary;

/**
 * The verified-restore ladder of Detect::exitQueryMode() (finding 011).
 *
 * A capability probe puts the terminal into raw mode (echo off, no
 * canonicalisation) and MUST land it back where it found it: the old code
 * was fire-and-forget, so a lost write stranded users in a dead-echo tty
 * with nobody the wiser. The ladder is restore → re-read → (retry once) →
 * `stty sane` + one stderr notice.
 *
 * Hermetic law: the ladder runs through a fake `stty` named by the
 * absolute-path override — no real tty, no PATH search. The sidecar log
 * pins the exact call sequence, which is what mutation-proves the ladder:
 * neuter the verification compare and the success arm pays a retry it
 * should not; neuter the retry and the failure arm never reaches `sane`.
 */
final class DetectSttyRestoreTest extends TestCase
{
    private ?string $dir = null;

    private ?string $log = null;

    private ?string $state = null;

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['PATH', SttyBinary::ENV_OVERRIDE] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        $this->dir   = sys_get_temp_dir() . '/sib-stty-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0700, true);
        $this->log   = $this->dir . '/calls.log';
        $this->state = $this->dir . '/state';

        $fake = <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$FAKE_STTY_LOG"
if [ "$1" = "-g" ]; then
    cat "$FAKE_STTY_STATE"
fi
exit 0
SH;
        file_put_contents($this->dir . '/stty', $fake . "\n");
        chmod($this->dir . '/stty', 0700);

        putenv('FAKE_STTY_LOG=' . $this->log);
        putenv('FAKE_STTY_STATE=' . $this->state);
        putenv(SttyBinary::ENV_OVERRIDE . '=' . $this->dir . '/stty');
        SttyBinary::reset();
    }

    protected function tearDown(): void
    {
        SttyBinary::reset();
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
        putenv('FAKE_STTY_LOG');
        putenv('FAKE_STTY_STATE');
        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
        }
    }

    private function exitQueryMode(?string $saved): void
    {
        $method = new ReflectionMethod(Detect::class, 'exitQueryMode');
        $method->setAccessible(true);
        $method->invoke(null, $saved);
    }

    /** @return list<string> every recorded stty invocation, in order */
    private function calls(): array
    {
        $this->assertFileExists($this->log);
        $raw = (string) file_get_contents($this->log);

        return array_values(array_filter(explode("\n", $raw), static fn (string $l): bool => $l !== ''));
    }

    public function testVerifiedRestoreStopsAfterTheFirstConfirmedWrite(): void
    {
        $settings = 'isig -icanon -echo min 1 time 0';
        file_put_contents($this->state, $settings); // re-read answers the snapshot

        $this->exitQueryMode($settings);

        $this->assertSame(
            [$settings, '-g'],
            $this->calls(),
            'a confirmed restore must not pay for the retry or the sane retreat',
        );
    }

    public function testADivergingReReadRetriesOnceAndStillStopsOnAgreement(): void
    {
        $settings = 'isig -icanon -echo min 1 time 0';
        $fake     = $this->dir . '/stty';
        // First re-read diverges, second agrees: state flips after the
        // retry's write by making -g answer from an occurrence counter.
        file_put_contents($this->state, 'still-raw');
        $counter = $this->dir . '/n';
        file_put_contents($fake, <<<SH
#!/bin/sh
printf '%s\\n' "\$*" >> "\$FAKE_STTY_LOG"
if [ "\$1" = "-g" ]; then
    n=\$(cat "$counter" 2>/dev/null || echo 0)
    echo \$((n+1)) > "$counter"
    if [ "\$n" -ge 1 ]; then printf '%s' "$settings"; else printf 'still-raw'; fi
fi
exit 0
SH);
        chmod($this->dir . '/stty', 0700);

        $this->exitQueryMode($settings);

        $this->assertSame([$settings, '-g', $settings, '-g'], $this->calls(), 'retry exactly once, then the confirmation ends the ladder');
    }

    public function testATwoTimesFailedRestoreRetreatsToSane(): void
    {
        $settings = 'isig -icanon';
        file_put_contents($this->state, 'still-raw'); // -g can never confirm

        $this->exitQueryMode($settings);

        $this->assertSame(
            [$settings, '-g', $settings, '-g', 'sane'],
            $this->calls(),
            'two failed verifications must end in stty sane — defaults beat a wedged tty',
        );
    }

    public function testANullSnapshotTouchesNothing(): void
    {
        $this->exitQueryMode(null);

        $this->assertFileDoesNotExist($this->log, 'enter never ran, so exit owes no restore');
    }

    public function testAnUnresolvableSttySkipsTheLadderSilently(): void
    {
        putenv(SttyBinary::ENV_OVERRIDE . '=' . $this->dir . '/gone');

        $this->exitQueryMode('isig -icanon');

        $this->assertFileDoesNotExist($this->log, 'a broken override is refused; no exec, no retry storm');
    }
}
