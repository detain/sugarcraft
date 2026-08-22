<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;

/**
 * Round 39 built {@see Bootstrap::warnPermissionConfigInTranscript()} and
 * migrated ONE caller onto it. This file is the guard for the other fourteen
 * — thirteen until round 42 (E78) routed Bootstrap::reportPrunedSessions()'s
 * retention summary onto the seam as the fifteenth call site.
 *
 * WHAT IS PINNED, AND WHY IT IS NOT "THE NOTICE EXISTS". A test asserting that
 * the transcript list is non-empty passes while the sentence is wrong, and a
 * test asserting only the transcript passes while stderr has silently stopped
 * saying it — which would be a regression for `-p` and for the scrollback a user
 * gets back after quitting. So every case below reads BOTH channels off ONE
 * child launch and asserts the same fact on each: `[$stderr, $notices]`.
 *
 * EVERY CASE IS A REAL LAUNCH IN A CHILD PROCESS under a sandboxed HOME, not a
 * reflection call on the seam. The seam is three lines; the thing that can break
 * is which call sites reach it, and only a launch measures that.
 *
 * @see \SugarCraft\Crush\Tests\Chat\LaunchNoticesTest for the Chat end.
 * @see BootstrapToolAndPermissionSettingsTest for the round-39 caller's own
 *      block, whose harness this file's runInChildLaunch() is modelled on.
 */
final class BootstrapLaunchNoticeRoutingTest extends TestCase
{
    private string $tmpDir = '';
    private string $home = '';
    private string $configDir = '';
    private string $projectRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/bootstrap_noticeroute_' . uniqid('', true);
        $this->home = $this->tmpDir . '/home';
        $this->configDir = $this->home . '/.sugar-crush';
        $this->projectRoot = $this->tmpDir . '/repo';

        mkdir($this->configDir, 0o700, true);
        mkdir($this->projectRoot . '/' . LayeredSettings::dir(), 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);

        parent::tearDown();
    }

    /**
     * THE MOST USER-VISIBLE DEGRADATION THIS CLASS REPORTS, and the one whose
     * stderr-only routing was hardest to defend: a launch whose provider threw
     * runs on {@see \SugarCraft\Crush\Providers\EchoProvider}, so every reply is
     * a parrot, and the only line saying why was painted over 0.47s later.
     *
     * BOTH TIERS IN ONE LAUNCH, which is not a contrivance — the env-var tier
     * falling through is exactly what makes the persisted tier run, so a real
     * misconfiguration reaches both. Asserted as two DISTINCT sentences, because
     * the per-launch de-dup keys on the message and a routing that collapsed
     * them would report the wrong one.
     */
    public function testBothProviderFallbacksReachBothChannels(): void
    {
        $this->writeUserConfig(['provider' => 'nope-persisted']);

        [$stderr, $notices] = $this->launch(
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::backend(null);\n",
            ['SUGARCRUSH_PROVIDER' => 'nope-env'],
        );

        self::assertSame(
            [
                "provider 'nope-env' unavailable (Unknown provider type: nope-env); falling back to echo",
                "persisted provider 'nope-persisted' unavailable "
                . '(Unknown provider type: nope-persisted); falling back to echo',
            ],
            $notices,
        );
        self::assertStringContainsString(
            "sugarcrush: provider 'nope-env' unavailable (Unknown provider type: nope-env); "
            . "falling back to echo.\n",
            $stderr,
        );
        self::assertStringContainsString(
            "sugarcrush: persisted provider 'nope-persisted' unavailable "
            . "(Unknown provider type: nope-persisted); falling back to echo.\n",
            $stderr,
        );
    }

    /**
     * A DROPPED RULE IS A SILENT WIDENING — {@see \SugarCraft\Crush\Permissions\PermissionRule}
     * degrades a malformed pattern to a name no real tool matches, so a `deny`
     * typo'd this way denies NOTHING for the whole session.
     *
     * AND IT USED TO BE SAID TWICE. {@see Bootstrap::chat()} reaches
     * {@see Bootstrap::permissionRules()} through BOTH
     * {@see Bootstrap::permissionGate()} and {@see Bootstrap::agentManager()},
     * and these were raw {@see Bootstrap::warnPermissionConfig()} calls with no
     * de-duplication — measured at 8add627b, two bad rules printed FOUR stderr
     * lines. The `substr_count` assertions are the regression guard for that,
     * and they are the reason this case drives both entry points rather than
     * just the gate.
     */
    public function testDroppedPermissionRulesReachBothChannelsExactlyOnce(): void
    {
        $this->writeUserConfig(['permissionRules' => [
            ['pattern' => '((', 'action' => 'deny'],
            ['pattern' => 'Bash', 'action' => 'nonsense'],
        ]]);

        [$stderr, $notices] = $this->launch(
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::permissionGate();\n"
            . '\\SugarCraft\\Crush\\Cli\\Bootstrap::agentManager(' . var_export($this->projectRoot, true) . ");\n",
        );

        self::assertCount(2, $notices);
        self::assertStringContainsString("permissionRules[0] ('((')", $notices[0]);
        self::assertStringContainsString('rule skipped rather than loaded', $notices[0]);
        self::assertStringContainsString("permissionRules[1] ('Bash') has no valid 'action'", $notices[1]);

        self::assertSame(1, substr_count($stderr, "permissionRules[0] ('((')"));
        self::assertSame(1, substr_count($stderr, "permissionRules[1] ('Bash')"));
    }

    /**
     * The strictly more severe sibling of the report round 39 migrated: that one
     * says which tools a project took, this one says there are none left. An
     * operator watching a model refuse to read a file has no other way to learn
     * why — and `disabledTools: ["*"]` is a SUPPORTED configuration, so there is
     * no refusal to fall back on.
     */
    public function testAnEmptyToolSetReachesBothChannels(): void
    {
        $this->writeUserSettings(['disabledTools' => ['*']]);

        [$stderr, $notices] = $this->launch(
            '\\SugarCraft\\Crush\\Cli\\Bootstrap::tools(' . var_export($this->projectRoot, true) . ");\n",
        );

        self::assertSame(
            ['allowedTools/disabledTools left no tools at all, so the model will be given an empty '
                . 'tool set and can do nothing but talk'],
            $notices,
        );
        self::assertStringContainsString('left no tools at all', $stderr);
    }

    /**
     * A project `hooks.yaml` this user has not opted into is shell the session
     * will NOT run — the repository author expects it to, and the operator
     * finds out by a guard silently not firing.
     *
     * The stderr count is pinned at 1 because {@see Bootstrap::chat()} builds
     * two hook chains; that was already guarded per PATH, and the assertion is
     * here so the seam's own message-keyed de-dup cannot be mistaken for it.
     */
    public function testARefusedProjectHookFileReachesBothChannels(): void
    {
        file_put_contents($this->projectRoot . '/.sugar-crush/hooks.yaml', "hooks: []\n");

        // A REAL chat(), not a direct hooks() call: chat() builds TWO hook
        // chains (its own and the engine backend's), which is the shape the
        // stderr count below is guarding against.
        [$stderr, $notices] = $this->launch(
            '\\SugarCraft\\Crush\\Cli\\Bootstrap::chat(' . var_export($this->projectRoot, true) . ");\n",
        );

        self::assertCount(1, $notices);
        self::assertStringContainsString('hooks.yaml was NOT loaded', $notices[0]);
        self::assertStringContainsString('trustedProjectHooks', $notices[0]);
        self::assertSame(1, substr_count($stderr, 'was NOT loaded'));
    }

    /**
     * A skill file that could not be read is a capability the session does not
     * have, and the user meets it as `/skill` not offering something they wrote.
     *
     * ONE ROW WHATEVER THE COUNT — this message is an aggregate, and that is
     * what makes it safe to seat in a transcript that also carries fourteen
     * other sources. Two unreadable files, one notice, and the notice says two.
     */
    public function testSkippedSkillFilesReachBothChannelsAsOneAggregateRow(): void
    {
        foreach (['alpha', 'beta'] as $name) {
            mkdir($this->projectRoot . '/.sugar-crush/skills/' . $name, 0o700, true);
            $file = $this->projectRoot . '/.sugar-crush/skills/' . $name . '/SKILL.md';
            file_put_contents($file, "---\nname: {$name}\n---\nbody\n");
            chmod($file, 0o000);
        }

        [$stderr, $notices] = $this->launch(
            '\\SugarCraft\\Crush\\Cli\\Bootstrap::chat(' . var_export($this->projectRoot, true) . ");\n",
        );

        self::assertCount(1, $notices);
        self::assertStringContainsString('2 skill files could not be read and were skipped', $notices[0]);
        self::assertSame(1, substr_count($stderr, 'could not be read'));
    }

    /**
     * A directory this REPOSITORY committed and the launch refused: the
     * project's skills, commands, agents or workflows are absent from the
     * session, and the operator meets that by typing a name that does not
     * resolve. The path plus the reason is the whole of what they need, which is
     * why this stayed one row per path rather than a count — and why it is the
     * one per-PATH source on the seam, which is what
     * {@see Bootstrap::LAUNCH_NOTICE_LIMIT} exists for.
     */
    public function testARefusedProjectDirectoryReachesBothChannels(): void
    {
        $outside = $this->tmpDir . '/outside/skills';
        mkdir($outside, 0o700, true);
        symlink($outside, $this->projectRoot . '/.sugar-crush/skills');

        [$stderr, $notices] = $this->launch(
            '\\SugarCraft\\Crush\\Cli\\Bootstrap::chat(' . var_export($this->projectRoot, true) . ");\n",
        );

        self::assertCount(1, $notices);
        self::assertStringStartsWith(
            'ignoring ' . $this->projectRoot . '/.sugar-crush/skills — resolves to ' . $outside,
            $notices[0],
        );
        self::assertStringContainsString('outside the directory it is anchored to', $notices[0]);
        self::assertSame(1, substr_count($stderr, 'ignoring ' . $this->projectRoot));
    }

    /**
     * THE CAP, measured rather than restated. Thirty malformed rules is a real
     * shape for a hand-edited config, and it is the per-ENTRY fan-out
     * {@see Bootstrap::LAUNCH_NOTICE_LIMIT} exists for: these rows are part of
     * the CONVERSATION, so an unbounded list is a per-token cost on every turn
     * for the whole session, not a scrolling nuisance.
     *
     * THE OVERFLOW IS SAID, NOT SWALLOWED. A bare truncation would be the same
     * silence the seam was built to end, so the tail row carries the count and
     * names the channel that has the rest — and stderr really does have all
     * thirty, which is the half that makes the tail row honest.
     */
    public function testAFanOutOfNoticesIsCappedAndTheOverflowIsCounted(): void
    {
        $rules = [];
        for ($i = 0; $i < 30; $i++) {
            $rules[] = ['pattern' => "Bogus{$i}", 'action' => 'nonsense'];
        }
        $this->writeUserConfig(['permissionRules' => $rules]);

        [$stderr, $notices] = $this->launch(
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::permissionGate();\n",
        );

        self::assertCount(25, $notices, '24 notices plus one overflow row');
        self::assertStringContainsString("permissionRules[23] ('Bogus23')", $notices[23]);
        self::assertSame(
            '…and 6 more launch warnings this transcript could not fit; the full list is on stderr',
            $notices[24],
        );

        // …and not vacuously: the channel the tail row points at really does
        // carry every one of the thirty, including the six the transcript
        // could not fit.
        self::assertSame(30, substr_count($stderr, 'rule skipped rather than coerced'));
        self::assertStringContainsString("permissionRules[29] ('Bogus29')", $stderr);

        // AND THROUGH A REAL chat(), which is the half that pins the plumbing
        // rather than the cap: {@see Bootstrap::chat()} has to read the
        // ACCESSOR, because reading the raw list would hand the transcript 24
        // rows with no indication that six more existed — a silent truncation
        // inside the seam built to end silence. Measured without this: the
        // assertions above all still pass.
        [, $history] = $this->launch(
            '$chat = \\SugarCraft\\Crush\\Cli\\Bootstrap::chat('
            . var_export($this->projectRoot, true) . ");\n"
            . '$rows = [];' . "\n"
            . 'foreach ($chat->history as $m) { $rows[] = $m->content; }' . "\n"
            . 'echo json_encode($rows);' . "\n",
        );

        self::assertCount(25, $history);
        self::assertStringContainsString('…and 6 more launch warnings', $history[24]);
    }

    /**
     * THE PER-MESSAGE CLIP. A `pattern` is a string out of the user's config and
     * nothing bounds its length, so one entry could otherwise contribute a
     * kilobyte to every prompt of the session.
     *
     * Asserted on the TRANSCRIPT copy only, because the stderr copy is
     * deliberately not clipped — that channel is the complete record, and it is
     * what the clip marker points the reader at. Both halves are checked here,
     * since a clip that also truncated stderr would destroy the thing it
     * advertises.
     */
    public function testAnOverlongNoticeIsClippedForTheTranscriptButNotForStderr(): void
    {
        $pattern = '(' . str_repeat('z', 900);
        $this->writeUserConfig(['permissionRules' => [['pattern' => $pattern, 'action' => 'deny']]]);

        [$stderr, $notices] = $this->launch(
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::permissionGate();\n",
        );

        self::assertCount(1, $notices);
        self::assertSame(400, mb_strlen($notices[0], 'UTF-8'));
        self::assertStringEndsWith('… (clipped; full text on stderr)', $notices[0]);

        // The full 900-character pattern survives on the channel the marker
        // names — otherwise the marker is a lie.
        self::assertStringContainsString($pattern, $stderr);
        self::assertStringNotContainsString('clipped; full text on stderr', $stderr);
    }

    /**
     * {@see Bootstrap::app()} — the entry point `bin/sugarcrush` actually runs —
     * calls {@see Bootstrap::reportSkillSkips()} and
     * {@see Bootstrap::reportProjectTierRefusals()} a SECOND time, AFTER
     * {@see Bootstrap::chat()} has already read the notice list on its way out.
     *
     * So the hosted chat has to be given the DELTA, and only the delta:
     * {@see \SugarCraft\Crush\Chat::withLaunchNotices()} appends, so handing it
     * the whole list a second time puts every launch warning in the transcript
     * TWICE. That is the failure this case measures — the notice count in the
     * App's hosted chat against the notice count in a bare `chat()`, from two
     * launches of the same fixture.
     */
    public function testTheAppShellDoesNotDoubleTheNoticesChatAlreadySeeded(): void
    {
        $this->writeUserSettings(['disabledTools' => ['*']]);

        $root = var_export($this->projectRoot, true);
        $rows = static fn (string $expr): string => "\$chat = {$expr};\n"
            . "\$out = [];\n"
            . "foreach (\$chat->history as \$m) { \$out[] = \$m->content; }\n"
            . "echo json_encode(\$out);\n";

        [, $viaChat] = $this->launch($rows("\\SugarCraft\\Crush\\Cli\\Bootstrap::chat({$root})"));
        [, $viaApp] = $this->launch(
            $rows("\\SugarCraft\\Crush\\Cli\\Bootstrap::app({$root})->chat"),
        );

        self::assertSame(
            ['allowedTools/disabledTools left no tools at all, so the model will be given an empty '
                . 'tool set and can do nothing but talk'],
            $viaChat,
            'the bare chat() transcript is the baseline the shell must not change',
        );
        self::assertSame($viaChat, $viaApp, 'app() must add the delta, never re-apply the whole list');
    }

    /**
     * The delta's TWO SIDES MUST READ THE SAME LIST, and only an overflowed
     * launch can tell whether they do.
     *
     * {@see Bootstrap::app()} takes its offset before the second scan and
     * slices at the end; {@see Bootstrap::launchNotices()} SYNTHESISES the
     * "and N more" row rather than storing it, so the accessor is one longer
     * than the raw property exactly when a launch has overflowed. Take the
     * offset from the raw property and slice from the accessor and the two
     * bases disagree by that one row — the slice then carries an "and N more"
     * the hosted chat ALREADY had, and the transcript says it twice.
     *
     * {@see testTheAppShellDoesNotDoubleTheNoticesChatAlreadySeeded()} cannot
     * see this: its fixture raises one notice, so nothing overflows, the
     * accessor and the raw property are the same length, and the two bases
     * agree by accident. MEASURED — with the offset changed to
     * `\count(self::$launchNotices)`, every other case in this file still
     * passes and only this one fails.
     */
    public function testTheAppShellDeltaSurvivesALaunchThatOverflowedBeforeTheShellWasBuilt(): void
    {
        $rules = [];
        for ($i = 0; $i < 30; $i++) {
            $rules[] = ['pattern' => "Bogus{$i}", 'action' => 'nonsense'];
        }
        $this->writeUserConfig(['permissionRules' => $rules]);

        $root = var_export($this->projectRoot, true);
        $rows = static fn (string $expr): string => "\$chat = {$expr};\n"
            . "\$out = [];\n"
            . "foreach (\$chat->history as \$m) { \$out[] = \$m->content; }\n"
            . "echo json_encode(\$out);\n";

        [, $viaChat] = $this->launch($rows("\\SugarCraft\\Crush\\Cli\\Bootstrap::chat({$root})"));
        [, $viaApp] = $this->launch(
            $rows("\\SugarCraft\\Crush\\Cli\\Bootstrap::app({$root})->chat"),
        );

        // 24 notices + exactly ONE overflow row, through both entry points.
        self::assertCount(25, $viaChat, 'the cap holds through a bare chat()');
        self::assertSame($viaChat, $viaApp, 'the shell must not re-append the overflow row');

        // Not vacuous: the row that would be duplicated is really present, and
        // really appears once.
        self::assertSame(
            1,
            substr_count(implode("\n", $viaApp), 'more launch warnings this transcript could not fit'),
            'the overflow row must appear exactly once in the hosted transcript',
        );
    }

    /**
     * RETENTION DELETED CONVERSATIONS, and this is the only thing on the seam
     * whose subject is data the launch destroyed rather than a setting it
     * declined. Migrated in round 42 (E78).
     *
     * WHAT THE ROUTING RULE USED TO SAY ABOUT IT, in
     * {@see Bootstrap::warnPermissionConfigInTranscript()}: stderr only,
     * because this is "about history already deleted, not about this session's
     * capabilities". WHAT IS TRUE NOW: the rule is "iff it names something the
     * session can no longer DO", and after a prune the session can no longer
     * resume, branch, rename or rewind the rows named here — `/resume` and
     * `session list` are shorter than the user left them. The 0.47s alternate-
     * screen window the seam's doc-block measures applies to this line exactly
     * as it does to a provider fallback.
     *
     * THE SPLIT IS THE POINT: ONE summary row on the transcript whatever the
     * prune deleted, and the per-session ids on stderr ONLY. A prune of fifty
     * sessions must not put fifty rows into a list that is re-sent to the model
     * on every turn — that is the per-ENTRY fan-out
     * {@see Bootstrap::LAUNCH_NOTICE_LIMIT} exists to refuse. So this fixture
     * prunes TWO sessions and asserts the transcript carries one row naming
     * neither id, while stderr carries both.
     *
     * WHAT THIS DOC-BLOCK USED TO CLAIM: that the split is "asserted from both
     * sides".
     * WHAT IS TRUE NOW, and round 42's review is what worked it out: only the
     * stderr side is asserted by a clause that can fail first. The transcript
     * side is pinned by an exact `assertSame` on the whole notice list, and an
     * exact equality already excludes every string an
     * `assertStringNotContainsString` below it could catch — PHPUnit stops at
     * the first failure, so those calls are unreachable as failures.
     * WHY THEY STILL EARN THEIR PLACE: they are the clause that survives the
     * `assertSame` being loosened. An exact-equality assertion on a formatted
     * message is the first thing a later change relaxes to a `assertCount` or a
     * `assertStringContainsString` — and the moment it is, "the ids are not in
     * the transcript" stops being implied and starts being the only thing
     * saying so. They are documentation with a trigger, not a second
     * measurement, and this doc-block now says which is which. The LIVE stderr
     * clause is the `'<id> (last used …'` containment: emptying the detail loop
     * reds it and nothing else.
     *
     * THE EXEMPTION IS EXERCISED, which it previously was not. `keeper` is the
     * row {@see Bootstrap::sessionStore()} passes to `pruneSessions()` as
     * `$exemptSessionId` (it takes `listSessions(1)[0]['id']`, and
     * `LIST_SESSIONS_SQL` orders `updated_at DESC`). The first version of this
     * fixture left `keeper` at a current timestamp, so it survived on AGE and
     * deleting the exemption outright would not have moved this test. It is now
     * aged to 2021 — a year past the 30-day cutoff, and still the newest of the
     * three, so it is both a prune candidate and the resumable row. It survives
     * for exactly one reason now, and the assertion that says so is placed
     * BEFORE the summary so that reason is what a failure names.
     *
     * DRIVEN THROUGH Bootstrap::sessionStore() rather than chat(): that is the
     * production caller (chat() calls it early and reads launchNotices() last,
     * which is what makes the notice reachable at all), and it keeps the
     * fixture to a session.db instead of a whole project tree.
     */
    public function testRetentionSummaryReachesBothChannelsWhileTheIdsStayOnStderr(): void
    {
        $db = $this->configDir . '/session.db';
        $store = new \SugarCraft\Crush\Session\EnhancedSessionStore($db);
        // All three are unnamed and all three are aged past the cutoff, so all
        // three are prune candidates. `keeper` is aged to 2021 rather than 2020
        // so it is the NEWEST — which is what makes it `listSessions(1)[0]` and
        // therefore the row sessionStore() hands pruneSessions() as its
        // exemption. It survives on the exemption alone; see the doc-block.
        foreach (['keeper', 'gone-one', 'gone-two'] as $id) {
            $store->createSession($id, 'p', 'm', null, null);
        }
        $store->addMessage('gone-one', ['role' => 'user', 'content' => 'a month of work']);
        unset($store);

        $pdo = new \PDO('sqlite:' . $db);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE sessions SET updated_at = ? WHERE id IN (?, ?)')
            ->execute(['2020-01-01 00:00:00', 'gone-one', 'gone-two']);
        $pdo->prepare('UPDATE sessions SET updated_at = ? WHERE id = ?')
            ->execute(['2021-01-01 00:00:00', 'keeper']);
        unset($pdo);

        [$stderr, $notices] = $this->launch(
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::sessionStore();\n",
            ['SUGARCRUSH_SESSION_RETENTION_DAYS' => '30'],
        );

        // FIRST, so that a lost exemption is what the failure names. `keeper`
        // is a full year past the cutoff and unnamed; the only thing standing
        // between it and deletion is sessionStore() passing the resumable id to
        // pruneSessions(). Drop that argument and this reds here, before the
        // summary's arithmetic reds two lines down.
        self::assertStringNotContainsString(
            'keeper',
            $stderr,
            'the session the launch would resume must survive retention however old it is',
        );

        self::assertSame(
            ['retention removed 2 unnamed sessions untouched for 30+ days (ids on stderr)'],
            $notices,
            'the transcript must carry exactly one aggregate row for the prune',
        );
        self::assertStringContainsString(
            "sugarcrush: retention removed 2 unnamed sessions untouched for 30+ days (ids on stderr).\n",
            $stderr,
            'the stderr half of the seam must still say it',
        );

        // The ids are the unbounded half, and they are on stderr ONLY. The
        // containment is the live clause; the exclusion is the one the
        // assertSame above already implies — kept for the day that assertSame
        // is loosened, and labelled as such in the doc-block rather than sold
        // as a second measurement.
        foreach (['gone-one', 'gone-two'] as $id) {
            self::assertStringContainsString($id . ' (last used 2020-01-01 00:00:00 UTC,', $stderr);
            self::assertStringNotContainsString($id, $notices[0]);
        }
    }

    /**
     * The complement, and the reason the case above is not just "a string
     * appears": a launch that pruned NOTHING must seed no row at all. Without
     * this, a seam that unconditionally announced retention would pass every
     * assertion above and put a line about deleted history into the transcript
     * of every single launch.
     */
    public function testALaunchThatPrunedNothingSeedsNoRetentionRow(): void
    {
        $store = new \SugarCraft\Crush\Session\EnhancedSessionStore($this->configDir . '/session.db');
        $store->createSession('fresh', 'p', 'm', null, null);
        unset($store);

        [$stderr, $notices] = $this->launch(
            "\\SugarCraft\\Crush\\Cli\\Bootstrap::sessionStore();\n",
            ['SUGARCRUSH_SESSION_RETENTION_DAYS' => '30'],
        );

        self::assertSame([], $notices);
        self::assertStringNotContainsString('retention removed', $stderr);
    }

    /**
     * @param array<string, string> $env
     * @return array{0: string, 1: mixed} stderr, decoded stdout
     */
    private function launch(string $body, array $env = []): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $name = 'launch' . count(glob($this->tmpDir . '/launch*.php') ?: []);
        $script = $this->tmpDir . '/' . $name . '.php';
        $errFile = $this->tmpDir . '/' . $name . '-stderr.txt';
        $outFile = $this->tmpDir . '/' . $name . '-stdout.txt';

        // Only the cases that build a Chat print their own payload; the rest
        // report the notice list, so it is appended when the body has not
        // already echoed something.
        $emitsOwnPayload = str_contains($body, 'echo json_encode');

        file_put_contents(
            $script,
            "<?php\nrequire " . var_export($autoload, true) . ";\n" . $body
            . ($emitsOwnPayload ? '' : "echo json_encode(\\SugarCraft\\Crush\\Cli\\Bootstrap::launchNotices());\n"),
        );

        $prefix = 'HOME=' . escapeshellarg($this->home) . ' SUGARCRUSH_PERMISSION_MODE= ';
        foreach ($env as $key => $value) {
            $prefix .= $key . '=' . escapeshellarg($value) . ' ';
        }

        exec(sprintf(
            '%stimeout -s KILL 60 %s %s >%s 2>%s',
            $prefix,
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ));

        $stdout = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

        self::assertNotSame('', $stdout, "child launch produced no stdout; stderr was:\n" . $stderr);

        return [$stderr, json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)];
    }

    /** @param array<string, mixed> $data */
    private function writeUserConfig(array $data): void
    {
        $path = $this->configDir . '/config.json';
        file_put_contents($path, (string) json_encode($data));
        chmod($path, 0o600);
    }

    /** @param array<string, mixed> $data */
    private function writeUserSettings(array $data): void
    {
        $path = $this->configDir . '/' . LayeredSettings::USER_FILE;
        file_put_contents($path, (string) json_encode($data));
        chmod($path, 0o600);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
                continue;
            }

            @chmod($entry->getPathname(), 0o600);
            @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
