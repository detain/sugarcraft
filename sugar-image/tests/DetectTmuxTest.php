<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Image\Detect;

/**
 * F-04: inside tmux, a termfeatures veto outranks any sixel claim.
 *
 * tmux answers the pane's DA1 with the sixel capability bit ON on its own
 * behalf (chafa chicle-options.c:1755-1764: "tmux claims sixel support in
 * its own DA1 response regardless, so probing the pane can't tell us"), so
 * before F-04 every tmux session looked sixel-capable to {@see Detect} —
 * including sessions attached to terminals that cannot display a single
 * sixel byte. Chafa's remedy is to ask the SERVER what the OUTER terminal
 * negotiated, via `tmux display-message -p '#{client_termfeatures}'`
 * (chicle-options.c:1766-1796), and to veto only on a DEFINITE no.
 *
 * ## The tri-state is the whole feature
 *
 * true  — the outer terminal features sixel → claim it without querying;
 * false — a definite list WITHOUT sixel → veto DA1, terminfo, and env hints;
 * null  — AUTO: anything else (old tmux rejecting the format, no client,
 * empty/unknown reply, dead binary, over-budget round-trip). No feature
 * string is NOT a veto — fail-soft keeps tmux<3.3 users on the pre-F-04
 * ladder instead of silently degrading them to half-block.
 *
 * ## Hermetic law
 *
 * No test contacts a real tmux server: the tri-state is either forced
 * through the {@see Detect::setTmuxTermFeaturesOverride()} seam (and the
 * zero-process cost is pinned with PATH pointed at a directory with no tmux
 * in it) or answered by a fake `tmux` shell script planted FIRST on PATH —
 * the TmuxPassthroughTest discipline, including the sidecar log that pins
 * the exact argv our probe sends. $TMUX itself is always this test's own
 * made-up socket string; the fake never connects anywhere.
 */
final class DetectTmuxTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    private ?string $dir = null;

    private ?string $log = null;

    /** @var list<resource> */
    private array $open = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = ['TMUX', 'TERM', 'TERM_PROGRAM', 'KITTY_WINDOW_ID', 'XTERM_VERSION', 'LC_TERMINAL', 'PATH'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        $this->dir = sys_get_temp_dir() . '/s25-fake-tmux-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0700, true);
        $this->log = $this->dir . '/calls.log';
        putenv('FAKE_TMUX_LOG=' . $this->log);
        putenv('TMUX=/tmp/s25-made-up.sock,99999,0');
        Detect::reset();
        Detect::clearTmuxTermFeaturesOverride();
        Detect::setTerminfoSixelOverride(false);
        Detect::setAutoPassthrough(false); // no F-05 transaction from these probes
    }

    protected function tearDown(): void
    {
        Detect::reset();
        Detect::setProbeStdin(null);
        Detect::clearTmuxTermFeaturesOverride();
        Detect::clearTerminfoSixelOverride();
        Detect::setAutoPassthrough(true);
        foreach ($this->open as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        $this->open = [];
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
        putenv('FAKE_TMUX_LOG');
        foreach ([$this->dir . '/tmux', $this->log] as $path) {
            if ($path !== null && \is_file($path)) {
                unlink($path);
            }
        }
        if ($this->dir !== null && \is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    // ------------------------------------------------- tri-state via override

    public function testDefiniteFeatureNoVetoesPositiveDa1Claim(): void
    {
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        // The pane's DA1 answers sixel-capable — exactly the tmux lie F-04
        // exists for. The feature list says definite NO.
        $this->preload($pair, "\x1b[?62;4;0c");
        Detect::setTmuxTermFeaturesOverride(false);

        $cap = Detect::probe();

        $this->assertFalse($cap->sixel, 'a definite termfeatures no must veto the DA1 sixel claim');
        $this->assertTrue($cap->halfblock);
        $this->assertTrue($cap->inTmux);
        $this->assertNull(Detect::lastDa1Reply(), 'the veto skips the DA1 round-trip it distrusts');
    }

    public function testFeatureYesClaimsSixelWithoutQuerying(): void
    {
        // No usable tmux binary anywhere on PATH: reaching sixel=true proves
        // the short-circuit consumed the override WITHOUT spawning — the
        // yes answer never pays for a round-trip it already has.
        putenv('PATH=' . $this->dir . '/empty');
        Detect::setTmuxTermFeaturesOverride(true);

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel);
        $this->assertNull(Detect::lastDa1Reply());
    }

    public function testAutoFailSoftKeepsTheLegacyLadder(): void
    {
        // "No feature string ≠ veto": under an explicit AUTO override the old
        // trust-DA1-in-tmux behavior must survive intact (the documented
        // tmux<3.3 residual), not silently degrade to half-block.
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, "\x1b[?62;4;0c");
        Detect::setTmuxTermFeaturesOverride(null);

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel, 'AUTO must not veto');
        $this->assertSame("\x1b[?62;4;0c", Detect::lastDa1Reply());
    }

    public function testEnvHintSixelIsDemotedUnderDefiniteVeto(): void
    {
        // A leaked $TERM=mlterm (or $XTERM_VERSION) claims sixel inside the
        // pane before ANY query runs; the veto covers env-seeded claims too,
        // because the outer terminal still cannot display them.
        putenv('TERM=mlterm');
        Detect::setTmuxTermFeaturesOverride(false);

        $cap = Detect::probe();

        $this->assertFalse($cap->sixel);
        $this->assertTrue($cap->halfblock);
    }

    // ------------------------------------------- live runner via planted fake

    public function testPlantedListWithoutSixelVetsAndSendsTheChicleArgv(): void
    {
        // Measured shape on tmux next-3.6 (comma list, no sixel token).
        $this->plantFakeTmuxReply("printf '%s\\n' 'bpaste,ccolour,clipboard,cstyle,focus,title'", 0);
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, "\x1b[?62;4;0c");

        $cap = Detect::probe();

        $this->assertFalse($cap->sixel);
        $this->assertNull(Detect::lastDa1Reply());
        $this->assertSame(
            ["display-message -p #{client_termfeatures}"],
            $this->calls(),
            'the probe must be exactly the chicle query command, once'
        );
    }

    public function testPlantedSemicolonListWithSixelClaimsIt(): void
    {
        $this->plantFakeTmuxReply("printf '%s\\n' 'us;clipboard;sixel;title'", 0);

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel);
    }

    public function testOldTmuxNonZeroExitFailsSoftToAuto(): void
    {
        // tmux < 3.3 rejects the unknown format and exits non-zero: the whole
        // point of the tri-state is that THIS answers AUTO, and the legacy
        // DA1 trust (documented residual) keeps the capability.
        $this->plantFakeTmuxReply('exit 1', 1);
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, "\x1b[?62;4;0c");

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel, 'a failed feature query must not veto');
    }

    public function testUnknownFeatureAnswerFailsSoftToAuto(): void
    {
        // tmux's own "I never asked the outer terminal" marker: a list was
        // reported but not trustworthily — vetoing on it would punish a user
        // whose tmux simply has not negotiated yet.
        $this->plantFakeTmuxReply("printf '%s\\n' 'bpaste,unknown,clipboard'", 0);
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, "\x1b[?62;4;0c");

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel, 'the unknown token is AUTO, not a veto');
    }

    public function testEmptyAnswerFailsSoftToAuto(): void
    {
        $this->plantFakeTmuxReply('exit 0', 0);
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, "\x1b[?62;4;0c");

        $cap = Detect::probe();

        $this->assertTrue($cap->sixel, 'silence is not a veto');
    }

    public function testOverBudgetReplyIsKilledBoundedAndFailsSoft(): void
    {
        // exec- replaces the shell with sleep so TERM reaches the sleeper
        // directly (TmuxPassthroughTest's hang-fake lesson); 10× the probe's
        // budget. The deadline must bound the WAIT, not inherit the child's.
        $this->plantFakeTmuxReply("exec sleep 1; printf '%s\\n' 'sixel'", 0);
        $pair = $this->createStreamPair();
        Detect::setProbeStdin($pair['read']);
        $this->preload($pair, "\x1b[?62;4;0c");

        $start = hrtime(true);
        $cap   = Detect::probe();
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertTrue($cap->sixel, 'an over-budget feature query is AUTO, never a veto');
        $this->assertLessThan(0.9, $elapsed, 'the 100 ms deadline + reap must fire long before the fake answers');
    }

    // ---------------------------------------------------- parser unit pins

    public function testParseTermFeaturesTriStates(): void
    {
        $parse = new ReflectionMethod(Detect::class, 'parseTermFeatures');

        $cases = [
            // [reply, expected]
            ['bpaste,ccolour,clipboard,cstyle,focus,title', false], // measured next-3.6, no sixel
            ['bpaste,sixel,title', true],                             // sixel among the commas
            ['us;clipboard;sixel;title', true],                       // semicolon lists too
            ['clipboard;no-sixel;256', false],                        // 3.4-era explicit negative
            ['us;suspend;titles', false],                             // 'us' must not fake-match 'sixel'
            ['x-sixel-wide,something', false],                        // whole-token match only
            ['unknown', null],
            ['', null],
            ["\n \t", null],
        ];

        foreach ($cases as [$reply, $expected]) {
            $this->assertSame(
                $expected,
                $parse->invoke(null, $reply),
                'parse polarity wrong for: ' . var_export($reply, true)
            );
        }
    }

    // ------------------------------------------------------------ harness

    /**
     * Plant a fake `tmux` whose display-message answers $stdout with $exit.
     * FIRST on PATH, argv logged to the sidecar (TmuxPassthroughTest law:
     * a real server is never contacted by this file).
     */
    private function plantFakeTmuxReply(string $body, int $exit): void
    {
        $script = <<<SH
#!/bin/sh
echo "\$*" >> "\$FAKE_TMUX_LOG"
case "\$1" in
  display-message) $body; exit $exit ;;
esac
exit 0
SH;
        file_put_contents($this->dir . '/tmux', $script . "\n");
        chmod($this->dir . '/tmux', 0700);
        putenv('PATH=' . $this->dir . ($this->savedEnv['PATH'] === false ? '' : ':' . $this->savedEnv['PATH']));
    }

    /** @return list<string> every recorded invocation, in order */
    private function calls(): array
    {
        $this->assertFileExists($this->log);
        $raw = (string) file_get_contents($this->log);
        return array_values(array_filter(explode("\n", $raw), static fn (string $line): bool => $line !== ''));
    }

    /** @param array{read: resource, write: resource} $pair */
    private function preload(array $pair, string $bytes): void
    {
        $written = @fwrite($pair['write'], $bytes);
        $this->assertIsInt($written);
        @fflush($pair['write']);
    }

    /**
     * Create a blocking stream pair for simulated stdin I/O.
     *
     * @return array{read: resource, write: resource}
     */
    private function createStreamPair(): array
    {
        $pair = @stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP,
        );
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair not available');
        }

        // Disable buffering so writes appear immediately.
        stream_set_read_buffer($pair[0], 0);
        stream_set_write_buffer($pair[1], 0);

        return ['read' => $pair[0], 'write' => $pair[1]];
    }
}
