<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Support\StartupSummary;
use SugarCraft\Code\Theme;
use SugarCraft\Image\ImageLayer;

/**
 * VIS-1 (plan_comparison 6.2): the startup summary that replaces the
 * empty-conversation placeholder.
 *
 * {@see StartupSummary::compose()} is the seam with all the layout decisions,
 * so it gets the exact-text pins; {@see StartupSummary::render()} contributes
 * the fail-soft fact reads and the memo, pinned for shape and stability
 * rather than for the machine-specific facts they return.
 */
final class StartupSummaryTest extends TestCase
{
    /** The block's row separator, pinned literally — it is display wire. */
    private const SEP = ' · ';

    protected function tearDown(): void
    {
        StartupSummary::clearCacheForTesting();
        parent::tearDown();
    }

    public function testComposeLaysOutTheFullFactBag(): void
    {
        $rows = explode("\n", StartupSummary::compose([
            'version' => '1.2.3',
            'provider' => 'anthropic',
            'model' => 'claude-test-4',
            'mode' => 'plan',
            'cwd' => '/somewhere/else',
            'branch' => 'trunk',
            'instructionFiles' => [
                ['name' => 'AGENTS.md', 'bytes' => 2048],
                ['name' => 'CLAUDE.md', 'bytes' => 512],
            ],
            'memories' => 3,
            'skills' => 1,
            'mcpServers' => 0,
            'tipIndex' => 2,
        ]));

        $this->assertCount(4, $rows);
        $this->assertSame(
            Lang::t('tui.startup.app_line', ['version' => '1.2.3'])
                . self::SEP . 'anthropic' . self::SEP . 'claude-test-4' . self::SEP . 'plan',
            $rows[0],
        );
        $this->assertSame('/somewhere/else' . self::SEP . 'trunk', $rows[1]);
        $this->assertSame(
            'AGENTS.md (2 KB) · CLAUDE.md (512 B) · '
                . Lang::t('tui.startup.memory.many', ['count' => 3]) . self::SEP
                . Lang::t('tui.startup.skill.one', ['count' => 1]) . self::SEP
                . Lang::t('tui.startup.mcp.many', ['count' => 0]),
            $rows[2],
        );
        $this->assertSame(Lang::t('tui.startup.tip_label', ['tip' => Lang::t('tui.startup.tip.2')]), $rows[3]);
    }

    public function testComposeOmitsEveryUnmeasuredFact(): void
    {
        $rows = explode("\n", StartupSummary::compose([
            'version' => '0.0',
            'cwd' => '/bare/dir',
            'tipIndex' => 0,
        ]));

        // No provider, no model, no mode, no branch, and no third row at all:
        // the block degrades by omission, never by spelling a failure.
        $this->assertCount(3, $rows);
        $this->assertSame(Lang::t('tui.startup.app_line', ['version' => '0.0']), $rows[0]);
        $this->assertStringNotContainsString(self::SEP, $rows[0]);
        $this->assertSame('/bare/dir', $rows[1]);
        $this->assertStringStartsWith('Tip: ', $rows[2]);
    }

    public function testComposeParsesAMisshapenBagIntoNothingWorse(): void
    {
        $rows = explode("\n", StartupSummary::compose([
            // The bag the type system does not police — every wrong shape
            // must read as "not measured", not as output.
            'provider' => 5,
            'model' => '',
            'mode' => [],
            'branch' => 7,
            'instructionFiles' => [
                ['name' => 'good.md', 'bytes' => 10],
                ['name' => null, 'bytes' => 10],
                ['bytes' => 3],
                'not-even-a-file',
            ],
            'memories' => 'three',
            'skills' => 2,
            'tipIndex' => 7,
        ]));

        $this->assertSame(Lang::t('tui.startup.app_line', ['version' => 'unknown']), $rows[0]);
        // The place row is empty (no cwd parsed) and empty rows drop.
        $this->assertCount(3, $rows);
        $this->assertSame(
            'good.md (10 B) · ' . Lang::t('tui.startup.skill.many', ['count' => 2]),
            $rows[1],
        );
        // tipIndex 7 wraps to 7 % 6 == 1.
        $this->assertSame(Lang::t('tui.startup.tip_label', ['tip' => Lang::t('tui.startup.tip.1')]), $rows[2]);
    }

    public function testComposeShortensHomeToTilde(): void
    {
        // Move BOTH spellings: $_SERVER['HOME'] does not follow putenv(), so a
        // half-moved sandbox leaks the developer's real home to superglobal-reading
        // code (the OneSidedHomeSandboxTest census rule: migrate, never add a row).
        // HomeDirectory::resolved() prefers getenv(), so the pins below are unaffected.
        $home = getenv('HOME');
        $serverHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=/home/pinned-user');
        $_SERVER['HOME'] = '/home/pinned-user';
        try {
            $rows = explode("\n", StartupSummary::compose([
                'cwd' => '/home/pinned-user/projects/app',
                'tipIndex' => 0,
            ]));
            $this->assertSame('~/projects/app', $rows[1]);

            $outside = explode("\n", StartupSummary::compose([
                'cwd' => '/etc/other/place',
                'tipIndex' => 0,
            ]));
            $this->assertSame('/etc/other/place', $outside[1]);
        } finally {
            putenv($home === false ? 'HOME' : 'HOME=' . $home);
            if ($serverHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $serverHome;
            }
        }
    }

    public function testTipIndexIsDeterministicWithinTheRoster(): void
    {
        $seen = [];
        foreach (['ses_first', 'ses_second', 'ses_third', '', '/some/root'] as $seed) {
            $index = StartupSummary::tipIndex($seed);
            $this->assertSame($index, StartupSummary::tipIndex($seed), 'the same seed always lands on the same tip');
            $this->assertGreaterThanOrEqual(0, $index);
            $this->assertLessThan(count(StartupSummary::TIP_KEYS), $index);
            // The crc32 is over the session id itself — modulo the roster, so
            // a re-spawned test process (different ids) still agrees here.
            $this->assertSame(crc32($seed) % count(StartupSummary::TIP_KEYS), $index);
            $seen[$index] = true;
        }
        $this->assertNotEmpty($seen);
    }

    public function testEveryTipInTheRosterResolvesToDistinctText(): void
    {
        $texts = [];
        foreach (StartupSummary::TIP_KEYS as $key) {
            $text = Lang::t($key);
            // Lang's miss fallback starts with a dot; a resolved string does not.
            $this->assertStringStartsNotWith('.', $text, "{$key} is missing from lang/en.php");
            $this->assertNotSame($key, $text);
            $this->assertNotContains($text, $texts, 'two tips share wording — one of them is dead weight');
            $texts[] = $text;
        }
    }

    /**
     * rev-w1d MAJOR-1: every raw subsystem read on the gather path must be
     * routed through the fail-soft wrapper. This is pinned STRUCTURALLY —
     * from the method-body text of the real source file, not from behaviour —
     * because GitRunner degrades a failed probe via ok=false rather than a
     * throw, so a behavioural throw-pin would need an injection seam the
     * class deliberately does not have. Deleting a `self::tryFact(` wrap (the
     * rev-w1d M1 shape) leaves every functional test green — the summary
     * only ever asks for facts it can survive losing — so this arm is the
     * only reader of the fail-soft contract. The rule it enforces: every
     * rostered read token must lie inside the paren-bounded argument of a
     * `self::tryFact(` call in its own method slice, and the rostered
     * methods must still contain each token exactly once (a renamed or
     * relocated read fails loudly here, not silently green). The contract is
     * BIDIRECTIONAL (rev-w1d2 MAJOR-1): each body's wrapper count is pinned
     * numerically, and every `self::tryFact(` span that no roster entry
     * justifies stays visible to an impure-vocabulary denylist swept over the
     * span-stripped remainder — so deleting a roster token cannot silently
     * shrink the pin either. New impure sources must extend BOTH the roster
     * and the denylist vocabulary below; covering one without the other is
     * exactly the rot this arm refuses to allow.
     */
    public function testEveryFactReadOnTheGatherPathIsWrappedInTheFailSoft(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Support/StartupSummary.php');

        $readers = [
            'private static function gather' => [
                'wraps' => 5,
                'reads' => [
                    'Help::versionString(',
                    'self::instructionFiles(',
                    'self::memoryCount(',
                    'self::skillCount(',
                    'self::mcpCount(',
                ],
            ],
            'private static function providerAndModel' => [
                'wraps' => 1,
                'reads' => [
                    '$chat->backend(',
                    '->provider()->name(',
                    '->servedModel(',
                    '->model()',
                ],
            ],
            'private static function branch' => [
                'wraps' => 1,
                'reads' => [
                    'GitRunner::available(',
                    'GitRunner::new(',
                ],
            ],
        ];

        // Every name an unpinned impure read would surface with; roster and
        // vocabulary move together (class docblock above). Names only — no
        // wildcard shapes, GlobDialect-safe by construction.
        $impure = [
            'Bootstrap::',
            'GitRunner::',
            'SkillManager',
            'SkillRegistry',
            'SkillLoader',
            'ProjectMemoryWriter',
            'Help::',
            'file_get_contents(',
            'self::instructionFiles(',
            'self::memoryCount(',
            'self::skillCount(',
            'self::mcpCount(',
        ];

        foreach ($readers as $signature => $pin) {
            $reads = $pin['reads'];
            $start = strpos($src, $signature);
            $this->assertIsInt($start, "StartupSummary no longer declares {$signature}() — re-derive this pin, do not delete it");

            $end = strpos($src, "\n    }\n", $start);
            $this->assertIsInt($end, "Could not find the end of {$signature}()");
            $body = substr($src, $start, $end - $start);

            // rev-w1d2 MAJOR-1(a): the wrapper count is pinned outright, so an
            // unwrapping can never be answered by de-rostering its read — the
            // number disagrees even when every remaining token still matches.
            $this->assertSame(
                $pin['wraps'],
                substr_count($body, 'self::tryFact('),
                "{$signature}() no longer holds exactly {$pin['wraps']} self::tryFact() wrappers — a fact read was unwrapped or added; re-derive this pin either way",
            );

            // Every wrapped call-site: from each 'self::tryFact(' opener to
            // the matching close, found by plain paren depth.
            $spans = [];
            $at = 0;
            while (($open = strpos($body, 'self::tryFact(', $at)) !== false) {
                $depth = 0;
                $close = -1;
                for ($i = $open + \strlen('self::tryFact'); $i < \strlen($body); ++$i) {
                    $depth += match ($body[$i]) { '(' => 1, ')' => -1, default => 0 };
                    if ($depth === 0) {
                        $close = $i;
                        break;
                    }
                }
                $this->assertGreaterThan(-1, $close, "unbalanced self::tryFact( in {$signature}() — re-derive this pin");
                $spans[] = [$open, $close];
                $at = $open + 1;
            }

            foreach ($reads as $read) {
                $count = substr_count($body, $read);
                $this->assertSame(1, $count, "{$signature}() must contain exactly one call site for {$read} — a rename or duplication leaves this census blind");

                $occurrence = (int) strpos($body, $read);
                $wrapped = false;
                foreach ($spans as [$from, $to]) {
                    if ($occurrence > $from && $occurrence < $to) {
                        $wrapped = true;
                        break;
                    }
                }
                $this->assertTrue($wrapped, "{$signature}() reads {$read} outside a self::tryFact() wrapper — the fail-soft contract of VIS-1 is broken");
            }

            // rev-w1d2 MAJOR-1(b): the source-side direction. Rebuild the body
            // without the tryFact spans that a roster entry justifies; any
            // span nobody rostered survives the strip, and so would any read
            // inlined past the wrapper — if its name is impure. Dropping a
            // roster token alone therefore reddens here: its wrapper remains
            // in the source, becomes unjustified, and shows the denylist.
            $stripped = '';
            $cursor = 0;
            foreach ($spans as [$from, $to]) {
                $span = substr($body, $from, $to - $from + 1);
                $rostered = false;
                foreach ($reads as $read) {
                    if (str_contains($span, $read)) {
                        $rostered = true;
                        break;
                    }
                }
                $stripped .= substr($body, $cursor, $from - $cursor);
                if (!$rostered) {
                    $stripped .= $span;
                }
                $cursor = $to + 1;
            }
            $stripped .= substr($body, $cursor);

            foreach ($impure as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $stripped,
                    "{$signature}() holds an impure read no roster entry justifies — de-rostering or inlining a fact read silently shrinks this pin; add it to the roster above (and keep it wrapped) rather than letting the coverage rot",
                );
            }
        }
    }

    public function testRenderPaintsFaintRowsAndStaysStableAcrossFrames(): void
    {
        $chat = (new Chat())->withSize(100, 30);

        $first = StartupSummary::render($chat);
        $this->assertSame($first, StartupSummary::render($chat), 'an idle repaint paints the identical block');

        $rows = explode("\n", $first);
        $this->assertStringStartsWith("\e[2m", $rows[0]);
        $this->assertStringStartsWith("\e[2m", $rows[count($rows) - 1], 'every row is styled, not just the first');
        $this->assertStringContainsString(Lang::t('tui.startup.app_line', ['version' => '']), $first);
        $this->assertStringStartsWith('Tip: ', trim(preg_replace('/\e\[[0-9;]*m/', '', $rows[count($rows) - 1]) ?? ''));

        StartupSummary::clearCacheForTesting();
        $this->assertSame($first, StartupSummary::render($chat), 're-derivation is deterministic, facts included');
    }

    public function testTheEmptyAgentViewTranscriptKeepsThePlaceholder(): void
    {
        // renderHistory with no chat is the Agent View's path: an empty run
        // list is not THIS launch's story, so VIS-1's summary must not speak
        // for it — the faint placeholder stays.
        $out = (new \ReflectionMethod(Renderer::class, 'renderHistory'))
            ->invoke(null, [], Theme::default(), 100, [], new ImageLayer(), null, 10, null, null, null);

        $this->assertStringContainsString('empty conversation', $out);
        $this->assertStringStartsWith("\e[2m", $out);
        $this->assertStringNotContainsString('Tip:', $out);
    }

    public function testTheCallSiteHandsTheSummaryOnlyToTheParentTranscript(): void
    {
        // The renderHistory fallback above pins what a null chat paints; this
        // pins that renderView actually passes null for an open Agent View —
        // a one-line ternary the frame tests never exercise with an EMPTY
        // child run, so its contract is pinned from source, the house way for
        // wiring a behavioural fixture cannot reach cheaply.
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Renderer.php');
        $this->assertSame(
            1,
            substr_count($source, '$openView === null ? $chat : null,'),
            'the startup summary of the transcript is parent-only by construction',
        );
    }

    public function testRenderIsReachableFromTheShellOnlyWhileHistoryIsEmpty(): void
    {
        $framed = Renderer::render((new Chat())->withSize(100, 30));
        $this->assertStringContainsString('Tip: ', $framed);

        $chat = (new Chat(history: [\SugarCraft\Code\Message::assistant('first reply', 0)]))->withSize(100, 30);
        $spoken = Renderer::render($chat);
        $this->assertStringNotContainsString('Tip: ', $spoken, 'the first message replaces the summary');
        $this->assertStringContainsString('first reply', $spoken);
    }
}
