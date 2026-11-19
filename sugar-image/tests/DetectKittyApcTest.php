<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Detect;
use SugarCraft\Image\TermDb;

/**
 * P1-13b: Capability::kitty() is true only on an APC `a=q` reply. An env claim
 * (KITTY_WINDOW_ID, TERM=xterm-kitty, ...) is a hypothesis that the terminal
 * must confirm through the query.
 *
 * @covers \SugarCraft\Image\Detect::probe
 */
final class DetectKittyApcTest extends TestCase
{
    private const KITTY_OK = "\x1b_Gi=31;OK\x1b\\";
    private const DA1_ANSWER = "\x1b[?62;4c";

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    /** @var resource|null */
    private $capture = null;

    /** @var resource|null */
    private $input = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (TermDb::ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        Detect::reset();
        Detect::clearTmuxTermFeaturesOverride();
        Detect::setTerminfoSixelOverride(false);
        $this->capture = fopen('php://temp', 'r+b');
        Detect::setProbeStdout($this->capture);
        putenv('KITTY_WINDOW_ID=1');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
        Detect::setProbeStdin(null);
        Detect::setProbeStdout(null);
        Detect::reset();
        Detect::clearTmuxTermFeaturesOverride();
        Detect::setTerminfoSixelOverride(false);
        if (is_resource($this->capture)) {
            fclose($this->capture);
        }
        if (is_resource($this->input)) {
            fclose($this->input);
        }
        parent::tearDown();
    }

    /** Inject a stdin that already holds $reply, as a terminal would have answered. */
    private function answerWith(string $reply): void
    {
        if (is_resource($this->input)) {
            fclose($this->input);
        }
        $this->input = fopen('php://temp', 'r+b');
        fwrite($this->input, $reply);
        rewind($this->input);
        Detect::setProbeStdin($this->input);
    }

    private function queriesWritten(): string
    {
        rewind($this->capture);

        return (string) stream_get_contents($this->capture);
    }

    public function testAnOkReplyConfirmsTheKittyClaim(): void
    {
        $this->answerWith(self::KITTY_OK . self::DA1_ANSWER);

        $cap = Detect::probe();

        $this->assertTrue($cap->kitty);
        $this->assertFalse($cap->iterm2);
    }

    public function testTheQueryIsAnApcActionQueryForImageThirtyOne(): void
    {
        $this->answerWith(self::KITTY_OK . self::DA1_ANSWER);

        Detect::probe();

        $written = $this->queriesWritten();
        $this->assertStringContainsString("\x1b_Gi=31,", $written);
        $this->assertStringContainsString('a=q', $written);
        $this->assertGreaterThan(
            strpos($written, 'a=q'),
            strpos($written, "\x1b[c"),
            'the DA1 closing the reply window follows the Kitty query',
        );
    }

    public function testADa1AnswerWithoutTheOkReplyDropsTheKittyClaim(): void
    {
        $this->answerWith(self::DA1_ANSWER);

        $cap = Detect::probe();

        $this->assertFalse($cap->kitty, 'the claim needs a reply, not just the environment');
    }

    public function testAConfirmedKittyWindowStillKeepsTheDa1AnswerForLastDa1Reply(): void
    {
        // The OK reply and the DA1 answer share one window; the DA1 answer is
        // still the last DA1 reply even though the Kitty claim was confirmed.
        $this->answerWith(self::KITTY_OK . self::DA1_ANSWER);

        Detect::probe();

        $reply = Detect::lastDa1Reply();
        $this->assertNotNull($reply, 'a confirmed Kitty window that also carries DA1 keeps the DA1 answer');
        $this->assertStringContainsString(self::DA1_ANSWER, $reply);
    }

    public function testInsideTmuxAKittyEnvClaimWithoutAnOkReplyIsDropped(): void
    {
        // A Kitty env claim inside tmux gets no exception: the pane's env is not
        // the outer terminal's answer, so only the APC reply confirms it.
        putenv('TMUX=/tmp/tmux-1000/default,1,0');
        Detect::setTmuxTermFeaturesOverride(null); // AUTO: no tmux process is spawned
        $this->answerWith(self::DA1_ANSWER);

        $cap = Detect::probe();

        $this->assertFalse($cap->kitty, 'a tmux pane with a Kitty env claim and no APC reply is not Kitty');
        $this->assertFalse($cap->iterm2);
    }

    public function testInsideTmuxAnOkReplyStillConfirmsTheKittyClaim(): void
    {
        putenv('TMUX=/tmp/tmux-1000/default,1,0');
        Detect::setTmuxTermFeaturesOverride(null);
        $this->answerWith(self::KITTY_OK . self::DA1_ANSWER);

        $cap = Detect::probe();

        $this->assertTrue($cap->kitty, 'the APC reply is the confirmation inside tmux too');
    }

    public function testInsideTmuxTheKittyQueryIsWrittenRawWithoutADcsWrap(): void
    {
        // The README promises the query goes out raw; a DCS wrap would change what tmux forwards.
        putenv('TMUX=/tmp/tmux-1000/default,1,0');
        Detect::setTmuxTermFeaturesOverride(null);
        $this->answerWith(self::KITTY_OK . self::DA1_ANSWER);

        Detect::probe();

        $written = $this->queriesWritten();
        $this->assertStringStartsWith("\x1b_Gi=31,", $written);
        $this->assertStringNotContainsString("\x1bPtmux;", $written, 'no tmux DCS passthrough wrap around the query');
    }

    public function testADa1AnswerWithSixelKeepsTheSixelVerdictWithoutASecondQuery(): void
    {
        // The DA1 answer the Kitty query already read carries the sixel bit; the
        // ladder reads it from there and does not ask DA1 a second time.
        $this->answerWith(self::DA1_ANSWER);

        $cap = Detect::probe();

        $this->assertFalse($cap->kitty);
        $this->assertTrue($cap->sixel, 'the DA1 answer still speaks for sixel');
        $this->assertSame(1, substr_count($this->queriesWritten(), "\x1b[c"), 'DA1 is written once, by the Kitty query');
    }

    public function testNoAnswerInTheWindowDropsTheKittyClaim(): void
    {
        $this->answerWith('');

        $cap = Detect::probe();

        $this->assertFalse($cap->kitty);
        $this->assertSame(1, substr_count($this->queriesWritten(), "\x1b[c"), 'a silent window is not asked for DA1 a second time');
    }

    public function testASilentWindowDoesNotInheritAnEarlierDa1Reply(): void
    {
        // Probe #1 hears a DA1 answer; probe #2 in the same process (no reset)
        // hears nothing. The silent window must clear the earlier answer, as
        // probeDa1() does, rather than leave it readable as this probe's reply.
        $this->answerWith(self::DA1_ANSWER);
        Detect::probe();
        $this->assertSame(self::DA1_ANSWER, Detect::lastDa1Reply());

        $this->answerWith('');
        Detect::probe();

        $this->assertSame('', Detect::lastDa1Reply(), 'a silent window reports no DA1 answer, not the previous probe\'s');
    }

    public function testWithoutATtyTheClaimCannotBeConfirmedAndIsDropped(): void
    {
        // No injected stdin and no interactive stdin: there is no reply
        // channel, so the environment alone does not make Capability::kitty().
        $GLOBALS['__candy_non_interactive'] = true;
        try {
            $cap = Detect::probe();
        } finally {
            unset($GLOBALS['__candy_non_interactive']);
        }

        $this->assertFalse($cap->kitty);
        $this->assertSame('', $this->queriesWritten(), 'nothing is written without a tty');
    }

    public function testTheOkReplyIsConsumedAndNeverReplayedAsInput(): void
    {
        $this->answerWith(self::KITTY_OK . self::DA1_ANSWER);

        Detect::probe();

        $this->assertSame('', Detect::takeStrayInputBytes(), 'the Kitty reply is not a keystroke');
    }

    public function testFromEnvNeverClaimsKittyAndNeverAsks(): void
    {
        // fromEnv() is the pure env ladder: it never asks, so the env claim stays unconfirmed.
        $cap = Detect::fromEnv(['KITTY_WINDOW_ID' => '1']);

        $this->assertFalse($cap->kitty);
        $this->assertSame('', $this->queriesWritten());
    }
}
