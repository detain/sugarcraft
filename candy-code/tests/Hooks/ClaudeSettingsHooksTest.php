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

        $this->assertSame(
            HookConfig::parse($flat, 'flat yaml saying the same thing'),
            ClaudeSettingsHooks::parse($nested, 'nested cc json'),
        );
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

    public function testTheRowCarriesTheFullNativeShape(): void
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
        ]], $rows);
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

    public function testAHookKeyThatIsNotTypeCommandOrTimeoutRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a key a hook under/');

        ClaudeSettingsHooks::parse('{"hooks":{"PreToolUse":[{"hooks":[{"type":"command","command":"./x.sh","async":true}]}]}}');
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
