<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\ClaudeSettingsHooks;
use SugarCraft\Code\Hooks\HookConfig;
use SugarCraft\Code\Hooks\ScriptHook;

/**
 * INT-4: the `hooks` block of Claude Code settings files, read into the SAME
 * entry rows `hooks.yaml` produces.
 *
 * The interesting assertion in this file is the equality in
 * {@see testTheNestedShapeFlattensToTheSameEntriesTheFlatShapeProduces()}:
 * the parser claims to be a shape adapter in front of {@see HookConfig}, not
 * a second validator. Equality against hand-written flat YAML is the direct
 * test of that claim; if the adapter ever grows its own opinion about what a
 * hook means, this is the test that goes red first.
 *
 * C-EXT-1 widened that claim by exactly one honest sentence, and the test
 * carries the widening rather than hiding it: the rows this parser returns are
 * the flat rows plus the two keys {@see ScriptHook} needs to read Claude Code's
 * exit-code dialect (`dialect`, `advisory`). Those two are not an opinion about
 * what a hook IS — they name the FILE the hook was written in — and a native
 * `hooks.yaml` cannot ask for them at all ({@see
 * testTheDialectStampIsNotSomethingANativeFileCanAskFor()}), so the equality is
 * now asserted after stripping them, with the stripped pair itself pinned to be
 * the ONLY difference. Anything else that diverges still reddens here first.
 *
 * @see ClaudeSettingsHooks
 */
final class ClaudeSettingsHooksTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cc_settings_hooks_' . uniqid((string) getmypid(), true);
        mkdir($this->dir . '/.claude', 0o700, true);
        ClaudeSettingsHooks::resetSkippedEventsForTesting();
    }

    protected function tearDown(): void
    {
        ClaudeSettingsHooks::resetSkippedEventsForTesting();
        foreach ([$this->dir . '/.claude/settings.json', $this->dir . '/.claude', $this->dir] as $path) {
            @chmod($path, 0o700);
            @unlink($path);
            @rmdir($path);
        }
    }

    // =========================================================================
    // Absence is silent; everything else is loud (the loadFromFile polarity)
    // =========================================================================

    public function testNoFileIsNoHooks(): void
    {
        $this->assertSame([], ClaudeSettingsHooks::fromFile($this->dir . '/.claude/settings.json'));
    }

    public function testAPathThatExistsWithoutBeingAFileRefuses(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exists but is not a readable file/');

        ClaudeSettingsHooks::fromFile($this->dir . '/.claude');
    }

    public function testAFileThatCannotBeReadRefuses(): void
    {
        $path = $this->dir . '/.claude/settings.json';
        file_put_contents($path, '{}');
        chmod($path, 0o000);
        clearstatcache();

        if (is_readable($path)) {
            $this->markTestSkipped('running as a user that ignores file permissions (root)');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        ClaudeSettingsHooks::fromFile($path);
    }

    public function testFromReadsTheRealFileThroughTheParser(): void
    {
        $path = $this->dir . '/.claude/settings.json';
        file_put_contents($path, (string) json_encode([
            'hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => './done.sh']]]]],
        ]));

        $rows = ClaudeSettingsHooks::fromFile($path);

        $this->assertSame(['cc:Stop:0'], array_column($rows, 'name'));
    }

    // =========================================================================
    // The one claim: shape adapter, not second validator
    // =========================================================================

    /**
     * The nested CC block and the flat YAML that MEANS the same hooks must
     * produce byte-identical rows. A divergence is not cosmetic: two
     * authorities over what a hook is is exactly the fork this loader promised
     * not to create.
     *
     * The one declared divergence is the dialect stamp C-EXT-1 adds AFTER
     * {@see HookConfig} has passed the rows — so it is stripped here, asserted
     * to be nothing but that stamp, and the remainder is held to the original
     * byte equality. A third key appearing on one side, or any value of the
     * shared eight drifting, fails this test exactly as it always did.
     */
    public function testTheNestedShapeFlattensToTheSameEntriesTheFlatShapeProduces(): void
    {
        $nested = (string) json_encode([
            'hooks' => [
                'PreToolUse' => [
                    [
                        'matcher' => 'Bash',
                        'hooks' => [
                            ['type' => 'command', 'command' => './guard.sh', 'timeout' => 5],
                            ['type' => 'command', 'command' => './second.sh'],
                        ],
                    ],
                    [
                        'hooks' => [
                            ['type' => 'command', 'command' => './no-matcher.sh'],
                        ],
                    ],
                ],
                'Stop' => [
                    ['hooks' => [['type' => 'command', 'command' => './stop.sh']]],
                ],
            ],
        ]);

        $flat = <<<'YAML'
hooks:
  PreToolUse:
    - name: cc:PreToolUse:0
      matcher: Bash
      command: ./guard.sh
      timeout: 5
    - name: cc:PreToolUse:1
      matcher: Bash
      command: ./second.sh
    - name: cc:PreToolUse:2
      command: ./no-matcher.sh
  Stop:
    - name: cc:Stop:0
      command: ./stop.sh
YAML;

        $flatRows = HookConfig::parse($flat, 'flat yaml saying the same thing');
        $nestedRows = ClaudeSettingsHooks::parse($nested, 'nested cc json');

        $stamped = [];
        $unstamped = [];
        foreach ($nestedRows as $row) {
            $stamped[$row['name']] = ['dialect' => $row['dialect'], 'advisory' => $row['advisory']];
            $unstamped[] = array_diff_key($row, array_flip(['dialect', 'advisory']));
        }

        $this->assertSame(
            $flatRows,
            $unstamped,
            'strip the two keys only this file format can carry and the nested shape must be the flat shape, key for key',
        );

        $this->assertSame([
            'cc:PreToolUse:0' => ['dialect' => ScriptHook::DIALECT_CLAUDE, 'advisory' => false],
            'cc:PreToolUse:1' => ['dialect' => ScriptHook::DIALECT_CLAUDE, 'advisory' => false],
            'cc:PreToolUse:2' => ['dialect' => ScriptHook::DIALECT_CLAUDE, 'advisory' => false],
            'cc:Stop:0' => ['dialect' => ScriptHook::DIALECT_CLAUDE, 'advisory' => false],
        ], $stamped, 'the stamp is the whole of the difference: a claude row, and none of these asked to be advisory');
    }

    /**
     * Type preservation through the YAML round-trip inside the adapter: a
     * command literally spelled `60` must not become the integer sixty and
     * then refuse its own validator.
     */
    public function testAStringThatLooksLikeANumberStaysACommand(): void
    {
        $rows = ClaudeSettingsHooks::parse((string) json_encode([
            'hooks' => ['PreToolUse' => [['hooks' => [['type' => 'command', 'command' => '60']]]]],
        ]));

        $this->assertSame('60', $rows[0]['command']);
    }

    /**
     * The eight native keys, in the validator's order, plus the two this file
     * format alone can add: {@see ClaudeSettingsHooks} is the only writer of
     * `dialect`/`advisory`, and {@see ScriptHook::fromConfig()} reads them.
     */
    public function testTheRowCarriesTheFullNativeShapePlusTheDialectStamp(): void
    {
        $rows = ClaudeSettingsHooks::parse((string) json_encode([
            'hooks' => ['PreToolUse' => [['matcher' => 'Edit', 'hooks' => [['type' => 'command', 'command' => './x.sh']]]]],
        ]));

        $this->assertSame([[
            'name' => 'cc:PreToolUse:0',
            'event' => 'PreToolUse',
            'matcher' => 'Edit',
            'command' => './x.sh',
            'description' => '',
            'disabled' => false,
            'timeout' => ScriptHook::defaultTimeoutSeconds(),
            'unattendedAsk' => false,
            'dialect' => ScriptHook::DIALECT_CLAUDE,
            'advisory' => false,
        ]], $rows);
    }

    // =========================================================================
    // C-EXT-1: the two keys that exist only in THIS file format
    // =========================================================================

    /**
     * Claude Code writes `statusMessage` on real hooks: the line it shows while
     * the command runs. candy-code has no spinner for a hook, but it does have
     * a `description` slot, and that is what `/hooks` shows — so the CC label
     * lands there rather than being dropped on the floor.
     */
    public function testStatusMessageBecomesTheDescriptionThePanelShows(): void
    {
        $rows = ClaudeSettingsHooks::parse((string) json_encode([
            'hooks' => ['PreToolUse' => [[
                'matcher' => 'Bash',
                'hooks' => [['type' => 'command', 'command' => './x.sh', 'statusMessage' => 'Checking the rm']],
            ]]],
        ]));

        $this->assertSame('Checking the rm', $rows[0]['description']);
    }

    /**
     * `async: true` means Claude Code does not wait for the hook, so its verdict
     * can never block anything. candy-code has no detached runner — the row runs
     * inline inside its timeout and is marked ADVISORY, and
     * {@see ScriptHook::claudeDialectVerdict()} is what turns that mark into
     * "never a block". The mark is per hook, not per file: the sibling without
     * `async` in the same group keeps the blockable reading.
     */
    public function testAsyncTrueMarksTheRowAdvisoryAndItsSiblingUnmarked(): void
    {
        $rows = ClaudeSettingsHooks::parse((string) json_encode([
            'hooks' => ['PreToolUse' => [[
                'matcher' => 'Bash',
                'hooks' => [
                    ['type' => 'command', 'command' => './fire-and-forget.sh', 'async' => true],
                    ['type' => 'command', 'command' => './gate.sh'],
                    ['type' => 'command', 'command' => './explicit-false.sh', 'async' => false],
                ],
            ]]],
        ]));

        $this->assertSame(
            ['cc:PreToolUse:0' => true, 'cc:PreToolUse:1' => false, 'cc:PreToolUse:2' => false],
            array_combine(array_column($rows, 'name'), array_column($rows, 'advisory')),
        );
        $this->assertSame(
            [ScriptHook::DIALECT_CLAUDE, ScriptHook::DIALECT_CLAUDE, ScriptHook::DIALECT_CLAUDE],
            array_column($rows, 'dialect'),
            'every row this parser returns is stamped claude, advisory or not',
        );
    }

    /**
     * The third member of the no-coercion family {@see HookConfig} runs on
     * `disabled` and `unattendedAsk`: a flag that had to be guessed at is a
     * file that means something else. And here the guess would decide whether a
     * hook may stop a tool call, so `"async": "true"` refuses the launch.
     */
    public function testANonBooleanAsyncRefusesTheLaunch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/an 'async' that is 'true'/");

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"type":"command","command":"./x.sh","async":"true"}]}]}}');
    }

    /**
     * Whether `statusMessage` is TEXT stays the native validator's verdict, not
     * this parser's — the delegation promise, tested from the other side. It
     * names the key by the SLOT the value landed in, which is honest: by the
     * time the row is refused, `statusMessage` IS `description`.
     */
    public function testAStatusMessageThatIsNotTextRefusesThroughTheNativeValidator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/has a 'description' that is not text/");

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"type":"command","command":"./x.sh","statusMessage":5}]}]}}');
    }

    /**
     * The asymmetry the stamp depends on, pinned: a hand-written `hooks.yaml`
     * CANNOT ask for either key, because {@see HookConfig} defines its own entry
     * keys and refuses any other. So a row carrying `dialect` arrived through a
     * Claude settings file, full stop — which is why
     * {@see ScriptHook::fromConfig()} can trust the pair without this loader
     * having to re-validate the row's shape for the second time.
     */
    public function testTheDialectStampIsNotSomethingANativeFileCanAskFor(): void
    {
        foreach (['dialect' => 'claude', 'advisory' => 'true'] as $key => $spelling) {
            try {
                HookConfig::parse("hooks:\n  PreToolUse:\n    - name: native-entry\n      command: ./x.sh\n      {$key}: {$spelling}\n", 'a hand-written hooks.yaml');
                $this->fail("a native hooks.yaml must not be able to carry '{$key}'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    'is not a key a hook entry under',
                    $e->getMessage(),
                    "'{$key}' must be refused by name by the native validator",
                );
            }
        }
    }

    /**
     * The docs list this parser's accepted keys, and the parser has a list of
     * them. Derived both ways from the const: the count word, the spelled list,
     * and the set of keys named anywhere in the section. A key added to
     * {@see ClaudeSettingsHooks} without the sentence — or counted in it without
     * the const — is a page teaching a format the loader does not read.
     *
     * @throws \RuntimeException when the page or its section cannot be found
     */
    public function testTheDocNamesExactlyTheKeysThisParserAccepts(): void
    {
        $doc = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $from = strpos($doc, '### What is read from a Claude settings file');
        $to = strpos($doc, '## The file format');
        $this->assertIsInt($from);
        $this->assertIsInt($to);
        $this->assertGreaterThan($from, $to, 'the section boundaries of this pin must exist');
        // Markdown wraps mid-sentence; a pinned sentence is matched flat.
        $section = (string) preg_replace('/\s+/', ' ', substr($doc, $from, $to - $from));

        $accepted = (new \ReflectionClassConstant(ClaudeSettingsHooks::class, 'ENTRY_KEYS'))->getValue();
        $words = ['three' => 3, 'four' => 4, 'five' => 5, 'six' => 6];

        $this->assertMatchesRegularExpression('/may carry (\w+) keys:/', $section, 'the section must count the entry keys');
        preg_match('/may carry (\w+) keys:/', $section, $counted);
        $this->assertArrayHasKey($counted[1], $words, "the count word '{$counted[1]}' is not one this pin knows");
        $this->assertSame(
            count($accepted),
            $words[$counted[1]],
            'the sentence counts keys this parser does not accept (or fails to count ones it does)',
        );

        $tail = \count($accepted) - 1;
        $spelled = implode('`, `', \array_slice($accepted, 0, $tail)) . '` and `' . $accepted[$tail] . '`';
        $this->assertStringContainsString(
            $spelled,
            $section,
            'the refusal message and the docs must spell the same list, in the same order',
        );
    }

    /**
     * The refusal on an off-dialect key has to list the accepted ones, because
     * that list is the only place a reader of a wrong file learns the format.
     * It is derived from the const, so growing the set without growing the
     * message fails here.
     */
    public function testTheRefusalNamesEveryKeyThisDialectAccepts(): void
    {
        try {
            ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"type":"command","command":"./x.sh","nope":1}]}]}}');
            $this->fail('an off-dialect hook key must refuse the launch');
        } catch (\InvalidArgumentException $e) {
            $this->assertMatchesRegularExpression('/is not a key a hook under/', $e->getMessage());
            $this->assertMatchesRegularExpression('/\(valid keys are ([^)]+)\)/', $e->getMessage());
            preg_match("/\(valid keys are ([^)]+)\)/", $e->getMessage(), $clause);
            preg_match_all("/'([A-Za-z]+)'/", $clause[1], $spelled);

            $accepted = (new \ReflectionClassConstant(ClaudeSettingsHooks::class, 'ENTRY_KEYS'))->getValue();
            $this->assertSame(
                $accepted,
                $spelled[1],
                'the refusal must spell exactly the keys the parser accepts, in the const order',
            );
        }
    }

    public function testTheIndexCountsHooksNotGroups(): void
    {
        $rows = ClaudeSettingsHooks::parse((string) json_encode([
            'hooks' => ['PostToolUse' => [
                ['hooks' => [['type' => 'command', 'command' => './a.sh'], ['type' => 'command', 'command' => './b.sh']]],
                ['matcher' => 'Read', 'hooks' => [['type' => 'command', 'command' => './c.sh']]],
            ]],
        ]));

        $this->assertSame(['cc:PostToolUse:0', 'cc:PostToolUse:1', 'cc:PostToolUse:2'], array_column($rows, 'name'));
        // ...and a second event restarts its own numbering.
        $rows = ClaudeSettingsHooks::parse((string) json_encode([
            'hooks' => [
                'Stop' => [['hooks' => [['type' => 'command', 'command' => './s.sh']]]],
                'SessionStart' => [['hooks' => [['type' => 'command', 'command' => './t.sh']]]],
            ],
        ]));

        $this->assertSame(['cc:Stop:0', 'cc:SessionStart:0'], array_column($rows, 'name'));
    }

    // =========================================================================
    // The hooks key is the only subject; the rest of the file is nobody's business here
    // =========================================================================

    public function testAFileWithoutTheHooksKeyIsSilentlyNotConfigured(): void
    {
        $this->assertSame([], ClaudeSettingsHooks::parse((string) json_encode([
            'permissions' => ['allow' => ['Bash'], 'deny' => ['Write']],
            'env' => ['SOMETHING' => 'set'],
            'model' => 'claude-somewhere',
        ])), 'the other keys belong to the layered settings authority — here they configure nothing and complain about nothing');
    }

    public function testANullDocumentIsSilenceNotComplaint(): void
    {
        $this->assertSame([], ClaudeSettingsHooks::parse('null'));
    }

    public function testAnEmptyHooksBlockIsNoHooks(): void
    {
        $this->assertSame([], ClaudeSettingsHooks::parse('{"hooks":{}}'));
    }

    // =========================================================================
    // Loud refusals
    // =========================================================================

    public function testJsonThatIsNotJsonRefusesAndNamesTheSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\/home\/u\/\.claude\/settings\.json is not usable JSON/');

        ClaudeSettingsHooks::parse('{ "hooks": ', '/home/u/.claude/settings.json');
    }

    public function testATopLevelListRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a JSON object/');

        ClaudeSettingsHooks::parse('[1,2]');
    }

    public function testAHooksValueThatIsNotAMappingRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/mapping event names/');

        ClaudeSettingsHooks::parse('{"hooks":["PreToolUse"]}');
    }

    public function testAGroupThatIsAListRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be an object with a .hooks. list/');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[["type","command"]]}}');
    }

    public function testAGroupKeyThatIsNotMatcherOrHooksRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a key a .hooks\.PreToolUse. group has/');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"matchers":"Bash","hooks":[]}]}}');
    }

    public function testAGroupWithoutAHooksListRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/carries no .hooks. list/');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"matcher":"Bash"}]}}');
    }

    /**
     * An off-dialect key still refuses the launch. C-EXT-1 moved the boundary
     * from three keys to five, so the example is a key NO dialect has —
     * `async` is accepted now, by
     * {@see testAsyncTrueMarksTheRowAdvisoryAndItsSiblingUnmarked()} — and the
     * law behind this test is unchanged: a key this loader has never heard of
     * means a hook it cannot know the meaning of, so nothing runs.
     */
    public function testAHookKeyOutsideTheDialectRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a key a hook under/');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"type":"command","command":"./x.sh","nope":1}]}]}}');
    }

    /**
     * THE polarity flip this item exists to make. CC grew `prompt`/`agent`
     * hooks — kinds with no exit-code verdict for this chain to read. Running
     * one would not be the guard the author wrote, and skipping it quietly is
     * the silently-shorter chain {@see ClaudeSettingsHooks} was built to
     * refuse. So: name it, name the file, stop the launch.
     */
    public function testAHookOfAnotherTypeRefusesLoudlyAndNamesItself(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/type 'prompt'/");

        ClaudeSettingsHooks::parse(
            '{"hooks":{"PreToolUse":[{"hooks":[{"type":"prompt","command":"be smart"}]}]}}',
            '/home/u/.claude/settings.json',
        );
    }

    public function testAMissingTypeRefusesRatherThanDefaulting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has type null;/');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"command":"./x.sh"}]}]}}');
    }

    /**
     * v1:F-1 (supersedes the R3 skip-list): every event this parser meets
     * without a {@see \SugarCraft\Code\Hooks\HookEvent} case is skipped AND
     * RECORDED — `Notification` is merely the member of that family that
     * forced the ruling. Known neighbours still load.
     */
    public function testTheNotificationEventIsSkippedWhileItsNeighboursLoad(): void
    {
        $rows = ClaudeSettingsHooks::parse((string) json_encode([
            'hooks' => [
                'Notification' => [['hooks' => [['type' => 'command', 'command' => './notify.sh']]]],
                'Stop' => [['hooks' => [['type' => 'command', 'command' => './stop.sh']]]],
            ],
        ]));

        $this->assertSame(['cc:Stop:0'], array_column($rows, 'name'));
        $this->assertSame(['Notification'], array_keys(ClaudeSettingsHooks::skippedEvents()));
        $this->assertSame(1, ClaudeSettingsHooks::skippedEvents()['Notification']['groups']);
    }

    public function testAnUnknownEventFromAForeignFileIsSkippedAndRecordedNotFatal(): void
    {
        $path = $this->dir . '/.claude/settings.json';
        file_put_contents($path, (string) json_encode([
            'hooks' => [
                'StopFailure' => [['hooks' => [['type' => 'command', 'command' => './fail.sh']]]],
                'PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => './guard.sh']]]],
            ],
        ]));

        // THE POLARITY v1:F-1 EXISTS FOR: a foreign file naming an event
        // candy-code has never heard of must not brick the launch.
        $rows = ClaudeSettingsHooks::fromFile($path);

        $this->assertSame(['cc:PreToolUse:0'], array_column($rows, 'name'));
        $skipped = ClaudeSettingsHooks::skippedEvents();
        $this->assertSame(['StopFailure'], array_keys($skipped));
        $this->assertArrayHasKey($path, $skipped['StopFailure']['files']);
    }

    public function testLowercaseEventSpellingsAreUnknownAndJoinTheRecord(): void
    {
        $rows = ClaudeSettingsHooks::parse(
            '{"hooks":{"preToolUse":[{"hooks":[{"type":"command","command":"./x.sh"}]}]}}',
            'vintage CC file',
        );

        // Case-sensitive like HookEvent::tryFrom: `preToolUse` is NOT the
        // PreToolUse event, it is an unmappable name — skipped, recorded,
        // never silently refused-to-launch and never silently run.
        $this->assertSame([], $rows);
        $skipped = ClaudeSettingsHooks::skippedEvents();
        $this->assertSame(['preToolUse'], array_keys($skipped));
        $this->assertArrayHasKey('vintage CC file', $skipped['preToolUse']['files']);
    }

    public function testTheNativeDialectKeepsItsHardEventRefusal(): void
    {
        // R3's BLAST RADIUS was the defect, not its REFUSE-UNKNOWN law — so
        // the law stands where it was written to stand: candy-code's OWN
        // hooks.yaml dialect, read by HookConfig directly, never reached by
        // the CC skip-and-record above. This is the refusal the class
        // doc-block promises.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a hook event/');

        HookConfig::parse("hooks:\n  preToolUse:\n    - name: g\n      command: ./x.sh\n", 'own hooks.yaml');
    }

    // =========================================================================
    // Shared-field validation is delegated, not duplicated
    // =========================================================================

    public function testAStringTimeoutRefusesThroughHookConfigNotHere(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/timeout/i');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"type":"command","command":"./x.sh","timeout":"60"}]}]}}');
    }

    public function testAZeroTimeoutRefusesThroughHookConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/timeout/i');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"type":"command","command":"./x.sh","timeout":0}]}]}}');
    }

    public function testAnUncompilableMatcherRefusesThroughHookConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/matcher/i');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"matcher":"[unclosed","hooks":[{"type":"command","command":"./x.sh"}]}]}}');
    }

    public function testAnEmptyCommandRefusesThroughHookConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/command/i');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"type":"command"}]}]}}');
    }

    // =========================================================================
    // Which paths are settings files
    // =========================================================================

    public function testThePathTestFollowsTheDirectoryNotJustTheName(): void
    {
        $this->assertTrue(ClaudeSettingsHooks::isClaudeSettingsPath('/home/u/.claude/settings.json'));
        $this->assertTrue(ClaudeSettingsHooks::isClaudeSettingsPath('/repo/.claude/settings.local.json'));
        $this->assertTrue(ClaudeSettingsHooks::isClaudeSettingsPath('.claude/settings.json'));
        $this->assertFalse(ClaudeSettingsHooks::isClaudeSettingsPath('/home/u/.candy-code/settings.json'));
        $this->assertFalse(ClaudeSettingsHooks::isClaudeSettingsPath('/repo/.claude/hooks.yaml'));
        $this->assertFalse(ClaudeSettingsHooks::isClaudeSettingsPath('/repo/.claude/settings.json.bak'));
        $this->assertFalse(ClaudeSettingsHooks::isClaudeSettingsPath('/repo/settings.json'));
    }

    public function testTheRecognisedFileNames(): void
    {
        $this->assertSame(['settings.json', 'settings.local.json'], ClaudeSettingsHooks::FILENAMES);
    }
}
