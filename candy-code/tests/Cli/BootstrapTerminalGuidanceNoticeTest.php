<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;

/**
 * v2:a11y-F3: a launch on a TTY whose terminal identifies itself as the
 * dumb family gets ONE plain-text row pointing at the paths that work
 * there — the `-p` one-shot and the mouse opt-outs — through the same
 * launch drain as every other boot fact.
 *
 * The gate is a pure function, pinned by a table, so no test has to mint
 * a pty; the feeder takes the same truth as an injection with the real
 * stdout answer as its default (the sugar-readline idleSleeper pattern).
 * Normal terminals are byte-unchanged — every existing transcript pin
 * runs on a pipe and the negative halves here pin the gate itself.
 */
final class BootstrapTerminalGuidanceNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetNoticeStatics();
    }

    protected function tearDown(): void
    {
        $this->resetNoticeStatics();
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function terminalShapes(): array
    {
        return [
            'dumb on a tty' => ['dumb', true, true],
            'empty TERM on a tty' => ['', true, true],
            'unknown on a tty' => ['unknown', true, true],
            'dumb family prefix' => ['dumb-emacs', true, true],
            'case is not a gate' => ['DUMB', true, true],
            'surrounding space is not a gate' => [' unknown ', true, true],
            'dumb on a pipe gets nothing' => ['dumb', false, false],
            'a real terminal gets nothing' => ['xterm-256color', true, false],
            'screen gets nothing' => ['screen', true, false],
            'vt100 gets nothing' => ['vt100', true, false],
        ];
    }

    /**
     * @dataProvider terminalShapes
     */
    public function testTheGateFiresExactlyOnTheDumbFamilyOnATty(string $term, bool $tty, bool $expectNotice): void
    {
        $notice = Bootstrap::terminalGuidanceNotice($term, $tty);

        if ($expectNotice) {
            $this->assertSame(Bootstrap::TERMINAL_GUIDANCE_NOTICE_TEXT, $notice);
        } else {
            $this->assertNull($notice, "TERM='{$term}' tty=" . var_export($tty, true) . ' must not add a row');
        }
    }

    public function testTheRowNamesTheOneShotPathAndBothMouseOptOuts(): void
    {
        $notice = Bootstrap::terminalGuidanceNotice('dumb', true);
        $this->assertIsString($notice);
        $this->assertStringContainsString('candy-code -p', $notice);
        $this->assertStringContainsString('CANDY_CODE_DISABLE_MOUSE', $notice);
        $this->assertStringContainsString('"mouse": false', $notice);
    }

    public function testTheFeederDrainsTheRowWhenTheTerminalAndTtyAgree(): void
    {
        $term = getenv('TERM');

        try {
            putenv('TERM=dumb');
            Bootstrap::reportTerminalGuidance(tty: true);

            $this->assertSame([Bootstrap::TERMINAL_GUIDANCE_NOTICE_TEXT], Bootstrap::launchNotices());
        } finally {
            $term === false ? putenv('TERM') : putenv('TERM=' . $term);
        }
    }

    public function testTheFeederStaysSilentOnAPipeEvenForADumbTerm(): void
    {
        // The REAL shape of every scripted and CI launch: no tty, no row —
        // this is what keeps the existing transcript pins byte-identical.
        $term = getenv('TERM');

        try {
            putenv('TERM=dumb');
            Bootstrap::reportTerminalGuidance(tty: false);
            Bootstrap::reportTerminalGuidance(tty: false);

            $this->assertSame([], Bootstrap::launchNotices(), 'a pipe gets no advice it cannot use');
        } finally {
            $term === false ? putenv('TERM') : putenv('TERM=' . $term);
        }
    }

    public function testTheRepeatDrainAddsNothingBecauseTheRowIsStable(): void
    {
        $term = getenv('TERM');

        try {
            putenv('TERM=unknown');
            Bootstrap::reportTerminalGuidance(tty: true);
            Bootstrap::reportTerminalGuidance(tty: true);

            $this->assertCount(1, Bootstrap::launchNotices());
        } finally {
            $term === false ? putenv('TERM') : putenv('TERM=' . $term);
        }
    }

    public function testTheDrainRunsFromChatAndNothingElseNeedsToKnow(): void
    {
        $lines = file(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        $method = new \ReflectionMethod(Bootstrap::class, 'chat');
        $slice = implode('', \array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertSame(
            1,
            substr_count($slice, 'self::reportTerminalGuidance();'),
            'chat() must drain the guidance row exactly once, defaulting to the live stdout answer',
        );
    }

    private function resetNoticeStatics(): void
    {
        foreach ([
            'launchNotices' => [],
            'launchNoticesDropped' => [],
            'reportedPermissionConfigWarnings' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(LaunchNotices::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}
