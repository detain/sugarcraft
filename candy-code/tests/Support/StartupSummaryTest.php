<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\PermissionModeToggledMsg;
use SugarCraft\Code\App\StartupSummaryGatheredMsg;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Providers\ReportsServedModel;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Support\StartupSummary;
use SugarCraft\Code\Theme;
use SugarCraft\Core\Util\Sanitize;
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

    protected function setUp(): void
    {
        // The gathered store is process-global; sibling files that exercise
        // Chat::update()/init() fan-out can land a bag under the default
        // (null-session, cwd-rooted) key, and this file's stability pins must
        // not inherit it.
        StartupSummary::clearCacheForTesting();
        parent::setUp();
    }

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

    /**
     * C-SES-6.e: the update notice rides the cheap facts, so compose() paints
     * it as its own row between the place row and the knowledge row — never
     * merged into either, because it disappears on its own schedule (the next
     * self-update consumes it, nothing else does).
     */
    public function testComposePaintsTheUpdateRowBetweenPlaceAndKnowledge(): void
    {
        $notice = 'update available: v0.2.0 — run `candy-code self-update`';
        $rows = explode("\n", StartupSummary::compose([
            'version' => '1.2.3',
            'provider' => 'anthropic',
            'model' => 'claude-test-4',
            'mode' => 'plan',
            'cwd' => '/somewhere/else',
            'branch' => 'trunk',
            'update' => $notice,
            'memories' => 3,
            'tipIndex' => 2,
        ]));

        $this->assertCount(5, $rows);
        $this->assertSame('/somewhere/else' . self::SEP . 'trunk', $rows[1]);
        $this->assertSame($notice, $rows[2]);
        $this->assertStringContainsString(Lang::t('tui.startup.memory.many', ['count' => 3]), $rows[3]);
        $this->assertSame(Lang::t('tui.startup.tip_label', ['tip' => Lang::t('tui.startup.tip.2')]), $rows[4]);
    }

    /**
     * C-SES-6.e: a bag with no `update` string degrades exactly as it did
     * before the notice existed — every pre-C-SES-6.e compose() pin in this
     * file passes unchanged, which is the byte-safety this row owes them.
     */
    public function testTheUpdateFactDropsWhenItIsNotAString(): void
    {
        foreach ([7, [], null, ''] as $misshapen) {
            $rows = explode("\n", StartupSummary::compose([
                'version' => '0.0',
                'cwd' => '/bare/dir',
                'update' => $misshapen,
                'tipIndex' => 0,
            ]));
            $this->assertCount(3, $rows, 'misshapen update fact must paint no row of its own');
            $this->assertSame('/bare/dir', $rows[1]);
            $this->assertStringStartsWith('Tip: ', $rows[2]);
        }
    }

    /**
     * VIS-1-P1: /context lists the personal AGENTS.md but the startup summary
     * did not, because instructionFiles() only walked loadDocuments(). The
     * gathered bag now carries a `personal` provenance flag; compose() marks
     * those rows through the chat.startup.personal key and treats any
     * misshapen flag (a string, anything non-true) as "not personal".
     */
    public function testComposeMarksPersonalDocumentsAndToleratesMisshapenProvenance(): void
    {
        $rows = explode("\n", StartupSummary::compose([
            'cwd' => '/x',
            'tipIndex' => 0,
            'instructionFiles' => [
                ['name' => 'AGENTS.md', 'bytes' => 10, 'personal' => false],
                ['name' => 'CLAUDE.md', 'bytes' => 20, 'personal' => true],
                ['name' => 'MISTAKEN.md', 'bytes' => 30, 'personal' => 'yes'],
            ],
        ]));

        $this->assertCount(4, $rows);
        $this->assertSame(
            'AGENTS.md (10 B) · '
                . Lang::t('chat.startup.personal', ['name' => 'CLAUDE.md'])
                . ' (20 B) · MISTAKEN.md (30 B)',
            $rows[2],
        );
    }

    /**
     * The gather side of VIS-1-P1: instructionFiles() appends the loader's
     * personal document under its personal marker, and a file reachable twice
     * (once as a repo document, once as the personal one) is listed once,
     * repo-first, unmarked. Home is sandboxed with BOTH spellings per the
     * OneSidedHomeSandbox law; roots are fresh unique dirs because the loader
     * walks ancestor roots and memoizes emitted paths per instance.
     */
    public function testInstructionFilesListsPersonalDocumentsAndDeduplicatesByRealpath(): void
    {
        $method = new \ReflectionMethod(StartupSummary::class, 'instructionFiles');

        $home = getenv('HOME');
        $serverHome = $_SERVER['HOME'] ?? null;
        $scratch = '/tmp/opencode/ctui7a-instructions-' . \uniqid('', true);
        mkdir($scratch, 0700, true);
        try {
            // (A) distinct files: repo document and personal document both list.
            $rootA = $scratch . '/repoA';
            $homeA = $scratch . '/homeA';
            mkdir($rootA, 0700, true);
            mkdir($homeA . '/.candy-code', 0700, true);
            \file_put_contents($rootA . '/AGENTS.md', \str_repeat('r', 40));
            \file_put_contents($homeA . '/.candy-code/AGENTS.md', \str_repeat('p', 60));
            putenv('HOME=' . $homeA);
            $_SERVER['HOME'] = $homeA;

            $listed = $method->invoke(null, $rootA);
            $byPair = [];
            foreach ($listed as $entry) {
                $byPair[$entry['name'] . '|' . ($entry['personal'] ? 'p' : 'r')] = $entry;
            }
            $this->assertArrayHasKey('AGENTS.md|r', $byPair, 'the repo document must stay listed');
            $this->assertArrayHasKey('AGENTS.md|p', $byPair, 'the personal document must now be listed too (VIS-1-P1)');
            $this->assertSame(40, $byPair['AGENTS.md|r']['bytes']);
            $this->assertSame(60, $byPair['AGENTS.md|p']['bytes']);

            // (B) same file twice: HOME's personal directory IS the project
            // root passed in — one realpath reached as a repo document first,
            // so it must appear exactly once and unmarked. (A symlinked repo
            // copy would be refused by the loader's own containment before
            // any dedup could apply, so the identity is pinned through the
            // directory itself.)
            $personalRoot = $scratch . '/personal-home/.candy-code';
            mkdir($personalRoot, 0700, true);
            \file_put_contents($personalRoot . '/AGENTS.md', \str_repeat('s', 24));
            putenv('HOME=' . $scratch . '/personal-home');
            $_SERVER['HOME'] = $scratch . '/personal-home';

            $deduped = $method->invoke(null, $personalRoot);
            $agents = \array_values(\array_filter(
                $deduped,
                static fn (array $entry): bool => $entry['name'] === 'AGENTS.md',
            ));
            $this->assertCount(1, $agents, 'one file on disk is one row, however many names reach it');
            $this->assertFalse($agents[0]['personal'], 'the surviving entry is the repo one — provenance follows first sight');
            $this->assertSame(24, $agents[0]['bytes']);
        } finally {
            putenv($home === false ? 'HOME' : 'HOME=' . $home);
            if ($serverHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $serverHome;
            }
            foreach (\array_reverse(\iterator_to_array(new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($scratch, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ))) as $node) {
                if ($node->isDir()) {
                    @rmdir($node->getPathname());
                } else {
                    @unlink($node->getPathname());
                }
            }
            @rmdir($scratch);
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
     *
     * VIS-1-P2 re-derived the roster for the cheap/expensive split: the old
     * single gather() became cheapFacts() (on the paint path, version only)
     * and gatherExpensive() (off it, landed through store()). The wrapper
     * totals are unchanged — 1 + 4 where the single body held 5.
     *
     * C-SES-6.e re-derived the cheap roster: the cached update notice joined
     * the paint path (2 + 4), wrapped in the same fail-soft for the same
     * reason — a frame must never die on a fact read.
     */
    public function testEveryFactReadOnTheGatherPathIsWrappedInTheFailSoft(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Support/StartupSummary.php');

        $readers = [
            'private static function cheapFacts' => [
                'wraps' => 2,
                'reads' => [
                    'Help::versionString(',
                    'UpdateCheck::cachedNotice(',
                ],
            ],
            'private static function gatherExpensive' => [
                'wraps' => 4,
                'reads' => [
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

    /**
     * Round-1 review MAJOR: the expensive bag is keyed by session+root only.
     * Every gathered fact is mode/provider/model-independent, so a Shift+Tab
     * permission flip (the most common interaction on this surface) must not
     * strand a cheap-only paint forever: the frame memo re-keys, the bag
     * survives without any re-gather. Driven on the same gate/EngineBackend
     * pair as PlanModeToggleTest. The tail is the invalidation half of the
     * same law — store() drops EVERY memoized frame of the session+root, not
     * just the one it happened to run under, so a frame memoized before the
     * flip cannot serve a stale branch after it.
     */
    public function testTheGatheredBagSurvivesAPermissionModeFlipWithoutReGather(): void
    {
        $root = '/tmp/opencode/ctui7a-mode-' . \uniqid('', true);
        mkdir($root, 0700, true);
        $gate = new PermissionGate(PermissionMode::Default, [], null, 'the built-in default');
        $hooks = new HookManager(new HookRegistry());
        $hooks->register(new PermissionGateHook($gate));
        try {
            $chat = new Chat(
                projectRoot: $root,
                backend: EngineBackend::new(new ScriptedProvider([]), 'm')->withPermissionGate($gate),
                hooks: $hooks,
            );

            [$stored] = $chat->update(new StartupSummaryGatheredMsg([
                'branch' => 'delta',
                'instructionFiles' => [],
                'memories' => 0,
                'skills' => 0,
                'mcpServers' => 0,
            ]));
            $rows = explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', StartupSummary::render($stored)));
            $this->assertStringEndsWith(self::SEP . 'default', $rows[0]);
            $this->assertStringContainsString(self::SEP . 'delta', $rows[1], 'the landed bag renders full rows');

            [$plan] = $stored->update(new PermissionModeToggledMsg(PermissionMode::Plan));
            $this->assertSame(PermissionMode::Plan, $plan->currentPermissionMode(), 'the flip moved the full frame key');

            $rows = explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', StartupSummary::render($plan)));
            $this->assertStringEndsWith(self::SEP . 'plan', $rows[0], 'the header repaints with the toggled cheap mode fact');
            $this->assertStringContainsString(
                self::SEP . 'delta',
                $rows[1],
                'the gathered rows survived the mode flip with no re-gather — the bag must key on session+root, never the full frame key',
            );

            [$relanded] = $plan->update(new StartupSummaryGatheredMsg([
                'branch' => 'epsilon',
                'instructionFiles' => [],
                'memories' => 0,
                'skills' => 0,
                'mcpServers' => 0,
            ]));
            [$back] = $relanded->update(new PermissionModeToggledMsg(PermissionMode::Default));
            $rows = explode("\n", (string) preg_replace('/\e\[[0-9;]*m/', '', StartupSummary::render($back)));
            $this->assertStringContainsString(self::SEP . 'epsilon', $rows[1], 'the re-landed bag replaced every frame of the session+root');
            $this->assertStringNotContainsString(self::SEP . 'delta', $rows[1], 'the frame memoized under the pre-flip key did not survive store()');
        } finally {
            @rmdir($root);
        }
    }

    /**
     * M-C (plan-verify-1026b): the served model is text a REMOTE SERVER chose,
     * and the summary embeds it into the header row AND the memo key. The
     * status bar has always folded this same value through its one-line choke
     * (`Renderer::modelIndicator()` via `oneLine()`); the summary now shares
     * that exact boundary, so a hostile /v1/models answer cannot paint an
     * escape sequence, forge a U+E000/U+E001 click zone, or split the header
     * row — and cannot poison the memo every idle tick re-paints.
     */
    public function testAHostileServedModelIsSanitisedBeforeTheFrameAndTheMemo(): void
    {
        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, ReportsServedModel::class]);
        $provider->method('name')->willReturn('sglang');
        $provider->method('servedModel')->willReturn("evil\e[31m\u{E000}zone\u{E001}\x07\nid");
        $provider->method('contextWindow')->willReturn(100_000);

        $chat = (new Chat(backend: EngineBackend::new($provider, 'static-default-id')))->withSize(120, 30);
        $out = StartupSummary::render($chat);

        $this->assertStringNotContainsString("\e[31m", $out, 'the injected SGR must not ride the startup frame');
        $this->assertStringNotContainsString("\u{E000}", $out, 'a forged zone sentinel must not reach the frame');
        $this->assertStringNotContainsString("\x07", $out, 'the BEL must not survive into the frame');
        $this->assertStringNotContainsString('static-default-id', $out, 'the served id wins, same rule as the bar');
        // The canonical fold's exact output — the shape the bar's own hostile
        // pin expects for the identical input (StatusBarModelAndReadOnlyTest).
        $this->assertStringContainsString(' · evilzone id', $out, 'the id survives as one sanitised segment');

        // The header stays ONE row: strip the faint SGR and the fold's tail
        // must still close it — a smuggled LF would break the row before
        // 'id' and the raw ESC/sentinel bytes would sit in between.
        $header = (string) preg_replace('/\e\[[0-9;]*m/', '', explode("\n", $out)[0]);
        $this->assertStringEndsWith('evilzone id', $header, 'no break inside the id may split the header row');

        // The memo stores under the sanitised fact: a re-render of the same
        // chat is byte-identical, so the poisoned raw never resurfaces.
        $this->assertSame($out, StartupSummary::render($chat), 'the memo carries the sanitised frame');
    }

    /**
     * MINOR-1 (rev-2 on bdc8a85fb): the sibling pin above feeds WELL-FORMED
     * UTF-8, so PaneLabel::of() takes its /u branch, where the \p{C} class
     * sweeps U+E000/U+E001 (Co) all by itself — deleting oneLine()'s
     * stripZoneSentinels() leg would still leave that pin green on its
     * sentinel legs. The strip is load-bearing on the branch that pin never
     * reaches: malformed bytes arriving at of() make the /u pattern bail, and
     * the byte-wise fallback sweep knows only the ASCII control/space classes
     * — the sentinels' UTF-8 spellings (EE 80 80 / EE 80 81) are outside it,
     * so ONLY the str_replace-based stripZoneSentinels() removes them.
     *
     * Fixture law (measured, not guessed): the invalid pair is a lead byte
     * riding an ASCII byte — genuinely malformed, yet invisible to every
     * upstream sweep, so it survives to of() and forces the fallback; the raw
     * ESC rides directly before the first sentinel and is consumed as a lone
     * escape by the display sweep (its absence is pinned too, and its removal
     * must not re-well-form the string — the lead byte keeps that job); the
     * sentinel spellings stay clear of every byte-deleting leg so the strip
     * can match them byte-for-byte.
     */
    public function testAMalformedServedModelStillLosesItsForgedZoneSentinels(): void
    {
        $hostile = 'srv' . "\xC3" . "\x28" . "\x1B" . Sanitize::ZONE_SENTINEL_OPEN . 'zone' . Sanitize::ZONE_SENTINEL_CLOSE . 'id';

        $provider = $this->createMockForIntersectionOfInterfaces([ProviderInterface::class, ReportsServedModel::class]);
        $provider->method('name')->willReturn('sglang');
        $provider->method('servedModel')->willReturn($hostile);
        $provider->method('contextWindow')->willReturn(100_000);

        $chat = (new Chat(backend: EngineBackend::new($provider, 'static-default-id')))->withSize(120, 30);
        $out = StartupSummary::render($chat);

        $this->assertStringNotContainsString(Sanitize::ZONE_SENTINEL_OPEN, $out, 'byte-wise fallback cannot see the sentinel bytes as characters — only the strip leg can erase them');
        $this->assertStringNotContainsString(Sanitize::ZONE_SENTINEL_CLOSE, $out, 'the closing sentinel must not ride the frame either');
        $this->assertStringNotContainsString('static-default-id', $out, 'the served id wins, same rule as the bar');

        // The frame paints its own faint SGR (ESC-led), so the ESC leg is
        // judged on the unstyled block: strip every SGR sequence and no
        // escape byte may remain — the injected lone ESC had to die inside
        // the display sweep, not merely be re-styled.
        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $out);
        $this->assertStringNotContainsString("\x1B", $plain, 'beyond the frame\'s own SGR styling, no escape byte may survive the choke');

        // Digest of the CURRENT sanitised byte output — the malformed lead
        // byte stays as inert junk and no leg repairs or replaces it, which is
        // the disclosed behaviour this pin freezes: if the choke ever learns
        // repair (U+FFFD substitution, byte-wise PUA sweep), this goes red on
        // purpose so the new output is re-judged.
        $sanitised = 'srv' . chr(0xC3) . '(zoneid';
        $this->assertStringContainsString($sanitised, $out, 'the malformed id survives as exactly these sanitised bytes');

        // One logical header row even though the fold ran byte-wise.
        $header = (string) preg_replace('/\e\[[0-9;]*m/', '', explode("\n", $out)[0]);
        $this->assertStringEndsWith($sanitised, $header, 'the byte-wise fold must still close the header with the id');

        $this->assertSame($out, StartupSummary::render($chat), 'the memo carries the sanitised frame');
    }

    public function testTheEmptyAgentViewTranscriptKeepsThePlaceholder(): void
    {
        // renderHistory with no chat is the Agent View's path: an empty run
        // list is not THIS launch's story, so VIS-1's summary must not speak
        // for it — the faint placeholder stays.
        $out = (new \ReflectionMethod(Renderer::class, 'renderHistory'))
            ->invoke(null, [], Theme::default(), 100, [], new ImageLayer(), null, 10, null, null, null, Renderer::publishedFrame());

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
