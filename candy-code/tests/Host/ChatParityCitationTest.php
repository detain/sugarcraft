<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;

/**
 * Guard minted by reviews-1103 G2-L6 (OD-18 program prerequisite).
 *
 * SessionHost is the served-side twin of Chat, and its prose carries parity
 * claims in the shape `Chat::method()`: citations naming the Chat method a
 * host step mirrors. Until this file existed, nothing enforced them — the
 * contract "SessionHost mirrors Chat, method for method" was unverifiable
 * narrative, and a Chat refactor could silently strand a citation naming a
 * method that no longer exists. That is precisely the hazard every static
 * extraction lane is about to walk into, which is why the guard lands before
 * any extraction claims parity rather than after.
 *
 * The law pinned here: every lowercase `Chat::name(` citation in
 * src/Host/SessionHost.php must resolve via method_exists() on Chat.
 * Constant citations (`Chat::CANCELLED_TOOL_CALL`) are excluded by the
 * lowercase anchor — a bare `::CONSTANT` reference is code, not prose, and
 * cannot be a method claim. Citations without an open paren are likewise not
 * method claims; the paren is what makes the sentence "Chat has a method
 * called X".
 *
 * The join leg (minted by L-ARCH, TUIARCH-04 queue-minor cure): a live
 * method is only half the contract — testEveryCitationMapsToANamedParityRow
 * requires each cited name to map to a NAMED test row, in an evidence file
 * that mentions the citation back. An extraction that single-homes a
 * judgement keeps the
 * prose honest only while something proves both hosts still honour it;
 * the roster below is that proof, and set equality means a new citation
 * must arrive with a row and a retired citation must leave with its entry.
 *
 * Vacuity control: testTheScanSeesADeadCitationPlantedInAFixture proves the
 * walker names a citation that does not resolve, so a broken regex cannot
 * turn this guard green by matching nothing.
 */
final class ChatParityCitationTest extends TestCase
{
    /**
     * The distinct lowercase `Chat::method(` citations found when this guard
     * was minted. Growth past the floor is fine (new parity prose); a shrink
     * through it would mean the scan lost the file, and the non-empty floor
     * turns that into a loud failure instead of a quietly vacuous pass.
     */
    private const CITATION_FLOOR = 13;

    /**
     * The join: every cited method name maps to [test file, named row] that
     * documents the parity claim. Each row's docblock names the citation so
     * a reader of either side finds the other; the guard checks both tokens
     * at whole-file granularity — that the named row is declared somewhere
     * in the file and the cited name occurs somewhere in it — not scoped to
     * the row's own docblock.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PARITY_EVIDENCE = [
        'applyCommandResult' => ['tests/Host/SessionHostCommandsTest.php', 'testTheTuiAndTheHostAnswerOneCommandWithTheSameRows'],
        'dispatchTurnHooks' => ['tests/Chat/SessionStartHookWireTest.php', 'testTheSessionStartContextChatBuildsCarriesThePromptAndTheStartupSource'],
        'endGoal' => ['tests/Host/GoalHostLoopTest.php', 'testAMetVerdictEndsTheLoopAtOnce'],
        'goalEnded' => ['tests/Host/GoalHostLoopTest.php', 'testTheUsersQueuedPromptGoesFirstAndTheGoalWaits'],
        'handleKeepWarmTick' => ['tests/Host/KeepWarmTest.php', 'testTheServedAlarmWarmsWhereTheFoldDeclined'],
        'landModelCompaction' => ['tests/Host/CompactionFallbackParityTest.php', 'testATypedFoldRefusesAHeldSafeGuardTheWayChatLandModelCompactionDoes'],
        'pumpLiveToolEvents' => ['tests/Backend/LiveLedgerFrameTest.php', 'testTheChatPumpTakesTheEventWithoutTouchingTheTranscript'],
        'pumpRuntimeNotices' => ['tests/Host/ServedRuntimeNoticeDrainTest.php', 'testTheServedDrainIsWakeDrivenNotTimerDriven'],
        'runtimeNoticeWake' => ['tests/Chat/HostedRuntimeNoticeWakeTest.php', 'testThePumpMsgDeliversTheNoticeAndHandsBackARearm'],
        'scheduleBackendCompletion' => ['tests/Chat/MidTurnSpendCapTranscriptTest.php', 'testTheProductionLaunchFeedsTheCapAndTheSessionBaselineIntoTheBackend'],
        'scheduleGoalJudge' => ['tests/Host/GoalHostLoopTest.php', 'testTheSpendCapStopsTheLoopBeforeTheJudgeIsDialed'],
        'submit' => ['tests/Host/CompactionFallbackParityTest.php', 'testTheServedTurnProceedsOnAHeuristicFoldAfterARefusal'],
        'usageRecordSink' => ['tests/Host/UsageModelThreadingTest.php', 'testTheProductionWiresThreadTheAuxiliaryEnginesModel'],
    ];

    /**
     * @return list<string> the cited method names, de-duplicated in file order
     */
    private static function citedMethods(string $source): array
    {
        $pattern = '/Chat::([a-z]\w*)\s*\(/';
        if (preg_match_all($pattern, $source, $matches) === false) {
            self::fail('the citation pattern failed to compile');
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * The composition unit of SessionHost: the class body plus every file in
     * its companion directory. The CWPD-14 split (OD-30) moves methods - and
     * with them the parity prose this law reads - into trait files under
     * src/Host/SessionHost/; the scan follows the prose so the floor law
     * covers the whole host no matter how the units are filed.
     */
    private static function sessionHostSource(): string
    {
        $base = \dirname(__DIR__, 2) . '/src/Host';
        $source = file_get_contents($base . '/SessionHost.php');
        self::assertIsString($source, 'SessionHost.php must be readable for the parity scan');
        if (is_dir($base . '/SessionHost')) {
            foreach (scandir($base . '/SessionHost') as $entry) {
                if (str_ends_with($entry, '.php')) {
                    $companion = file_get_contents($base . '/SessionHost/' . $entry);
                    self::assertIsString($companion, 'SessionHost composition file ' . $entry . ' must be readable');
                    $source .= "\n" . $companion;
                }
            }
        }

        return $source;
    }

    public function testEveryChatCitationInSessionHostNamesALiveMethod(): void
    {
        $citations = self::citedMethods(self::sessionHostSource());

        self::assertGreaterThanOrEqual(
            self::CITATION_FLOOR,
            count($citations),
            'the scan found fewer distinct Chat citations than the mint-time roster; '
                . 'if SessionHost really lost parity prose, lower CITATION_FLOOR deliberately',
        );

        $dead = [];
        foreach ($citations as $method) {
            if (!method_exists(Chat::class, $method)) {
                $dead[] = $method;
            }
        }

        self::assertSame(
            [],
            $dead,
            'SessionHost prose cites ' . implode(', ', array_map(
                static fn (string $m): string => 'Chat::' . $m . '()',
                $dead,
            )) . ' but Chat has no such method — the parity claim is stale; '
                . 'fix the citation (or restore the method), never re-word the guard',
        );
    }

    public function testTheScanSeesADeadCitationPlantedInAFixture(): void
    {
        $fixture = " * Mirrors Chat::submit() exactly, unlike Chat::thisMethodNeverExisted() which rots.\n";

        self::assertSame(
            ['submit', 'thisMethodNeverExisted'],
            self::citedMethods($fixture),
            'the walker must name both the live and the planted citation',
        );

        $dead = array_values(array_filter(
            self::citedMethods($fixture),
            static fn (string $m): bool => !method_exists(Chat::class, $m),
        ));
        self::assertSame(['thisMethodNeverExisted'], $dead, 'a planted dead citation must be the thing the guard reports');
    }

    public function testConstantReferencesAreNotMistakenForMethodCitations(): void
    {
        $source = self::sessionHostSource();

        // The real file carries Chat::CANCELLED_TOOL_CALL as a code reference.
        // The guard must not accuse it: uppercase anchors are excluded by design.
        self::assertStringContainsString('Chat::CANCELLED_TOOL_CALL', $source);
        self::assertNotContains('CANCELLED_TOOL_CALL', self::citedMethods($source));
    }

    /**
     * The join leg: the citation set and the evidence roster must be the SAME
     * set — a new citation is born with a named parity row, a retired one
     * takes its roster entry with it. Each evidence file must exist, declare
     * the named row, and mention the cited method somewhere in the file; the
     * mention is a whole-file check, deliberately not scoped to the row's own
     * docblock.
     */
    public function testEveryCitationMapsToANamedParityRow(): void
    {
        $citations = self::citedMethods(self::sessionHostSource());
        sort($citations);
        $roster = array_keys(self::PARITY_EVIDENCE);
        sort($roster);
        self::assertSame(
            $roster,
            $citations,
            'every Chat citation must carry exactly one PARITY_EVIDENCE row, and every row '
                . 'one live citation; the join breaks in-step, never silently',
        );

        $gaps = [];
        foreach (self::PARITY_EVIDENCE as $method => [$file, $row]) {
            $path = \dirname(__DIR__, 2) . '/' . $file;
            if (!is_file($path)) {
                $gaps[] = $method . ': evidence file ' . $file . ' is gone';
                continue;
            }

            $source = (string) file_get_contents($path);
            if (!str_contains($source, 'function ' . $row)) {
                $gaps[] = $method . ': named row ' . $row . ' is missing from ' . $file;
            } elseif (!str_contains($source, $method)) {
                $gaps[] = $method . ': ' . $file . '::' . $row . ' does not name the citation it joins';
            }
        }

        self::assertSame([], $gaps, 'the parity join is broken: ' . implode('; ', $gaps));
    }
}
