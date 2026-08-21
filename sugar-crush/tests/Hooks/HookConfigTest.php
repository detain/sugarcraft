<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookConfig;
use SugarCraft\Crush\Hooks\ScriptHook;

/**
 * @see HookConfig
 */
final class HookConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/hook_config_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    // =========================================================================
    // loadFromFile Tests
    // =========================================================================

    public function testLoadFromFileNotFound(): void
    {
        $result = HookConfig::loadFromFile('/nonexistent/path/hooks.yaml');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testLoadFromFileReturnsEmptyOnReadFailure(): void
    {
        // Create a file that exists but is not readable (if we can control perms)
        // Since we run as same user, just use invalid path
        $result = HookConfig::loadFromFile('/dev/null/hooks.yaml');

        $this->assertIsArray($result);
    }

    /**
     * A file that IS there and cannot be parsed names itself in the report —
     * the whole reason loadFromFile() passes the path down to parse().
     */
    public function testLoadFromFileNamesTheFileItCouldNotParse(): void
    {
        $path = $this->tempDir . '/hooks.yaml';
        file_put_contents($path, "hooks:\n  PreToolUse:\n    - matcher: '^Read\$\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($path, '/') . '/');

        HookConfig::loadFromFile($path);
    }

    /**
     * "Not a hook file" is not the same as "no hook file": a directory sitting
     * where the hook file should be is a misconfiguration, not an absence.
     */
    public function testLoadFromFileRefusesAPathThatIsNotAReadableFile(): void
    {
        $path = $this->tempDir . '/hooks.yaml';
        mkdir($path, 0700);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/not a readable file/');

            HookConfig::loadFromFile($path);
        } finally {
            rmdir($path);
        }
    }

    public function testLoadFromFileReadsAValidFile(): void
    {
        $path = $this->tempDir . '/hooks.yaml';
        file_put_contents($path, "hooks:\n  PreToolUse:\n    - command: 'true'\n");

        $result = HookConfig::loadFromFile($path);

        $this->assertCount(1, $result);
        $this->assertSame('true', $result[0]['command']);
    }

    // =========================================================================
    // parse Tests - Valid YAML
    // =========================================================================

    public function testParseValidYaml(): void
    {
        // The two PreToolUse entries carry explicit names: they share a
        // command, and without names they would both be keyed by it — the
        // silent collapse `name` exists to prevent, now a parse error.
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - name: allow-read
      matcher: '^Read$'
      command: 'echo allowed'
      description: 'Allow read operations'
    - name: allow-write
      matcher: '^Write$'
      command: 'echo allowed'
      description: 'Allow write operations'
  PostToolUse:
    - matcher: '.*'
      command: 'echo post'
      description: 'Log all operations'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(3, $result);

        // First PreToolUse hook
        $this->assertSame('PreToolUse', $result[0]['event']);
        $this->assertSame('^Read$', $result[0]['matcher']);
        $this->assertSame('echo allowed', $result[0]['command']);
        $this->assertSame('Allow read operations', $result[0]['description']);

        // Second PreToolUse hook
        $this->assertSame('PreToolUse', $result[1]['event']);
        $this->assertSame('^Write$', $result[1]['matcher']);

        // PostToolUse hook
        $this->assertSame('PostToolUse', $result[2]['event']);
        $this->assertSame('.*', $result[2]['matcher']);
    }

    public function testParseWithDefaults(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - command: 'my_script.sh'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(1, $result);
        $this->assertSame('PreToolUse', $result[0]['event']);
        $this->assertSame('.*', $result[0]['matcher']); // default
        $this->assertSame('my_script.sh', $result[0]['command']);
        $this->assertSame('', $result[0]['description']); // default
    }

    // =========================================================================
    // parse Tests - Empty/Null Cases
    // =========================================================================

    public function testParseEmptyHooks(): void
    {
        $yaml = <<<'YAML'
hooks: {}
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * A TYPO'D TOP-LEVEL KEY INSTALLS NO GUARDS, so it may not be silent.
     * `$data['hooks'] ?? []` used to read `hook:` as "nothing configured": the
     * user believed they had a guard, had none, and was told nothing — the one
     * failure mode {@see HookConfig}'s "absence is silent, everything else is
     * loud" contract exists to rule out.
     */
    public function testParseRefusesAnUnknownTopLevelKey(): void
    {
        $yaml = <<<'YAML'
hook:
  PreToolUse:
    - command: 'guard.sh'
YAML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'hook' is not a key this file has/");

        HookConfig::parse($yaml);
    }

    public function testParseRefusesAnyOtherUnknownTopLevelKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/only top-level key is .hooks./');

        HookConfig::parse("some_other_key: value\n");
    }

    /**
     * `is_array()` is true for a YAML LIST, whose entries `$data['hooks']`
     * then threw away — the same fail-open, reached without a typo. The
     * message has to name the shape, since "it parsed fine" is exactly what
     * the user is looking at.
     */
    public function testParseRefusesATopLevelList(): void
    {
        $yaml = <<<'YAML'
- name: guard
  matcher: '^Bash$'
  command: 'guard.sh'
YAML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is a YAML list/');

        HookConfig::parse($yaml);
    }

    /**
     * The tolerance the two refusals above may not cost: everything that is
     * GENUINELY empty still parses to no hooks, because a fresh install and a
     * commented-out file are not mistakes.
     */
    #[DataProvider('genuinelyEmptyHookFiles')]
    public function testParseStillAcceptsEveryGenuinelyEmptyShape(string $yaml): void
    {
        $this->assertSame([], HookConfig::parse($yaml));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function genuinelyEmptyHookFiles(): array
    {
        return [
            'empty file' => [''],
            'whitespace only' => ["  \n\n   \n"],
            'comment only' => ["# no hooks yet\n"],
            'hooks with nothing under it' => ["hooks:\n"],
            'empty flow mapping' => ["hooks: {}\n"],
            'empty document' => ["{}\n"],
            'event with an empty list' => ["hooks:\n  PreToolUse: []\n"],
        ];
    }

    public function testParseEmptyYaml(): void
    {
        $result = HookConfig::parse('');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testParseNullLikeValue(): void
    {
        $result = HookConfig::parse('null');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // parse Tests - Invalid YAML
    //
    // Every case below used to come back as "no hooks", which is the fail-open
    // this file's contract now refuses: a hook chain silently shorter than the
    // configured one is invisible until the call the missing hook existed to
    // stop is the one that runs.
    // =========================================================================

    public function testParseInvalidYaml(): void
    {
        $invalidYaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '^Read$
      command: [invalid array structure
YAML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not usable YAML/');

        HookConfig::parse($invalidYaml);
    }

    public function testParseMalformedYaml(): void
    {
        $malformedYaml = "this is not: [yaml at all really::: invalid";

        $this->expectException(\InvalidArgumentException::class);

        HookConfig::parse($malformedYaml);
    }

    public function testParseRejectsATopLevelThatIsNotAMapping(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/top-level 'hooks:' key/");

        HookConfig::parse('just a string');
    }

    public function testParseRejectsAHooksKeyThatIsNotAMapping(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HookConfig::parse("hooks: nope\n");
    }

    /**
     * The one that matters most: `postToolUse` (or any typo) used to fall back
     * to PreToolUse inside {@see \SugarCraft\Crush\Hooks\ScriptHook::fromConfig()},
     * quietly turning an observer into a gate on every tool call.
     */
    public function testParseRejectsAnUnknownEventName(): void
    {
        $yaml = <<<'YAML'
hooks:
  postToolUse:
    - command: 'audit.sh'
YAML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a hook event/');

        HookConfig::parse($yaml);
    }

    public function testParseRejectsAnEventWhoseValueIsNotAList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HookConfig::parse("hooks:\n  PreToolUse: 'audit.sh'\n");
    }

    /**
     * An uncompilable matcher registers a hook that {@see \SugarCraft\Crush\Hooks\HookRegistry::matcherMatches()}
     * can never match — present in every listing, gating nothing.
     */
    public function testParseRejectsAMatcherThatCannotCompile(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '([unclosed'
      command: 'guard.sh'
YAML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/regular expression/');

        HookConfig::parse($yaml);
    }

    /**
     * Two entries sharing a name on one event: the registry keys by name, so
     * the second silently replaced the first — which is also how a later entry
     * could disarm an earlier one.
     */
    public function testParseRejectsTwoHooksSharingANameOnOneEvent(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - name: guard
      command: 'a.sh'
    - name: guard
      command: 'b.sh'
YAML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/both named/');

        HookConfig::parse($yaml);
    }

    /**
     * ...but the same name on two DIFFERENT events is fine, because the
     * registry keys by event AND name.
     */
    public function testParseAllowsTheSameNameOnTwoDifferentEvents(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - name: guard
      command: 'a.sh'
  PostToolUse:
    - name: guard
      command: 'b.sh'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(2, $result);
    }

    // =========================================================================
    // parse Tests - Edge Cases
    // =========================================================================

    public function testParseMultipleHooksSameEvent(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '^A$'
      command: 'cmd_a'
    - matcher: '^B$'
      command: 'cmd_b'
    - matcher: '^C$'
      command: 'cmd_c'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(3, $result);
        foreach ($result as $hook) {
            $this->assertSame('PreToolUse', $hook['event']);
        }
    }

    public function testParsePreservesDescription(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '.*'
      command: 'audit.sh'
      description: 'Security audit hook'
YAML;

        $result = HookConfig::parse($yaml);

        $this->assertCount(1, $result);
        $this->assertSame('Security audit hook', $result[0]['description']);
    }

    /**
     * An empty command produced a hook whose `proc_open('')` always fails, so
     * it denied every call it matched — a config typo turning into a blanket
     * refusal nobody could trace back to this file.
     */
    public function testParseRejectsAnEmptyCommand(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - matcher: '.*'
      command: ''
YAML;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/no 'command' to run/");

        HookConfig::parse($yaml);
    }

    public function testParseRejectsAMissingCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HookConfig::parse("hooks:\n  PreToolUse:\n    - matcher: '.*'\n");
    }

    public function testParseNamesAHookAfterItsCommandWhenNoNameIsGiven(): void
    {
        $result = HookConfig::parse("hooks:\n  PreToolUse:\n    - command: 'guard.sh'\n");

        $this->assertSame('guard.sh', $result[0]['name']);
    }

    public function testParseKeepsAnExplicitName(): void
    {
        $result = HookConfig::parse("hooks:\n  PreToolUse:\n    - name: my-guard\n      command: 'guard.sh'\n");

        $this->assertSame('my-guard', $result[0]['name']);
    }

    // =========================================================================
    // A typo'd ENTRY key is the same failure as a typo'd top-level one
    // =========================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownEntryKeys(): array
    {
        return [
            'a misspelled matcher silently became the .* default' => ["mather: '^Bash$'"],
            'a misspelled timeout is not the timeout key' => ['timeut: 5'],
            'an event field is the key this entry is nested under' => ['event: PostToolUse'],
            'enabled: false read as "off" and the hook ran' => ['enabled: false'],
        ];
    }

    /**
     * @dataProvider unknownEntryKeys
     */
    public function testParseRefusesAnUnknownEntryKey(string $line): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a key a hook entry/');

        HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      {$line}\n");
    }

    /**
     * `disabled` IS a key this format has — {@see \SugarCraft\Crush\Hooks\HookRegistry}
     * has a first-class disable()/isDisabled() pair, so it is the natural thing
     * for a user to write, and it did exactly nothing before.
     */
    public function testParseCarriesDisabledThrough(): void
    {
        $result = HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      disabled: true\n");

        $this->assertTrue($result[0]['disabled']);
    }

    public function testParseDefaultsDisabledToFalse(): void
    {
        $result = HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n");

        $this->assertFalse($result[0]['disabled']);
    }

    /** A truthy string is not a boolean: `disabled: 'no'` may not switch a guard off. */
    public function testParseRefusesANonBooleanDisabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'disabled' that is not true or false/");

        HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      disabled: 'no'\n");
    }

    // =========================================================================
    // The matcher delimiter
    // =========================================================================

    /**
     * A `/` in a matcher is a valid regex and used to be a LAUNCH-STOPPING
     * exit 2, because the pattern was wrapped in `/`-delimiters unescaped.
     */
    public function testAMatcherContainingASlashIsAccepted(): void
    {
        $result = HookConfig::parse(
            "hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      matcher: 'Read|Write/Edit'\n",
        );

        $this->assertSame('Read|Write/Edit', $result[0]['matcher']);
        $this->assertSame(1, preg_match(HookConfig::pattern('Read|Write/Edit'), 'Write/Edit'));
    }

    /**
     * The fallback arm: a matcher containing ALL ELEVEN candidate delimiters
     * has no unused one to wrap it in, so the first is escaped back instead.
     *
     * Covered because it was not: `'([unclosed'` — the other matcher test on
     * this page — fails to COMPILE and never reaches the delimiter loop at all,
     * so the branch that decides what an exotic-but-valid matcher becomes had
     * no test of its own. The pair of assertions is the point: the pattern
     * {@see HookConfig::pattern()} hands {@see HookConfig::parse()}'s validation
     * has to be the same one {@see \SugarCraft\Crush\Hooks\HookRegistry} later
     * matches under, or a hook loads and never fires.
     */
    public function testAMatcherContainingEveryCandidateDelimiterIsStillCompiled(): void
    {
        $matcher = 'a/b#c~d%e[!@;:|+=]f';

        $this->assertSame('/a\/b#c~d%e[!@;:|+=]f/i', HookConfig::pattern($matcher));
        $this->assertSame(1, preg_match(HookConfig::pattern($matcher), 'a/b#c~d%e!f'));

        // ...and it is accepted by parse(), which validates through the very
        // same call.
        $result = HookConfig::parse(
            "hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      matcher: '" . $matcher . "'\n",
        );
        $this->assertSame($matcher, $result[0]['matcher']);
    }

    /** ...and one that genuinely will not compile is still refused. */
    public function testAnUncompilableMatcherIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid regular expression/');

        HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      matcher: '([unclosed'\n");
    }

    /**
     * `hooks:` with nothing under it decodes to null, which `??` already turns
     * into the empty list — the legitimate "configured nothing" case.
     */
    public function testAValuelessHooksKeyConfiguresNothing(): void
    {
        $this->assertSame([], HookConfig::parse("hooks:\n"));
    }

    /**
     * `timeout` IS a key this format has, as of the round that gave
     * {@see ScriptHook} a deadline at all. It was previously refused as
     * unknown — see {@see unknownEntryKeys()}, which used to carry it and now
     * carries a MISSPELLING of it instead, because "the key is not real" and
     * "the key is real and you typed it wrong" are different failures and only
     * the second one is still true.
     */
    public function testParseCarriesTimeoutThrough(): void
    {
        $result = HookConfig::parse(
            "hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      timeout: 5\n",
        );

        $this->assertSame(5.0, $result[0]['timeout']);
    }

    /** A fractional timeout is a number too — sub-second hooks are a real shape. */
    public function testParseAcceptsAFractionalTimeout(): void
    {
        $result = HookConfig::parse(
            "hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      timeout: 0.25\n",
        );

        $this->assertSame(0.25, $result[0]['timeout']);
    }

    /**
     * An entry that says nothing gets {@see ScriptHook::DEFAULT_TIMEOUT_SECONDS},
     * asserted against the constant rather than the literal 60 so the two
     * cannot drift apart.
     */
    public function testParseDefaultsTimeoutToTheScriptHookDefault(): void
    {
        $result = HookConfig::parse(
            "hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n",
        );

        $this->assertSame(ScriptHook::DEFAULT_TIMEOUT_SECONDS, $result[0]['timeout']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableTimeouts(): array
    {
        return [
            'zero is the unbounded wait wearing a number' => ['0'],
            'negative is a deadline already in the past' => ['-1'],
            'prose is not a number of seconds' => ["'none'"],
            'YAML yes decodes to true, and true > 0 is true' => ['yes'],
            'an empty value decodes to null' => [''],
        ];
    }

    /**
     * A NON-POSITIVE TIMEOUT IS REFUSED, NOT READ AS "NO TIMEOUT". Every value
     * here is one a user writes MEANING "do not time this hook out", and
     * honouring any of them restores exactly the unbounded wait the timeout was
     * added to remove — a hook that never exits with nothing to end it.
     *
     * `yes` is in the list because it is the trap the `disabled` check next to
     * it does not have: `is_int(true)` is false, but `true > 0` is TRUE, so an
     * ordering that tested the comparison first would have accepted it and
     * cast it to 1.0.
     *
     * @dataProvider unusableTimeouts
     */
    public function testParseRefusesATimeoutThatIsNotAPositiveNumber(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/positive number of seconds/');

        HookConfig::parse(
            "hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      timeout: {$value}\n",
        );
    }

    /**
     * The parsed entry is what {@see ScriptHook::fromConfig()} consumes, so the
     * round trip is the property that matters — a `timeout` carried through the
     * parser and dropped by the constructor would leave every assertion above
     * passing over a hook that still runs on the default.
     */
    public function testAParsedTimeoutReachesTheHookItConfigures(): void
    {
        $result = HookConfig::parse(
            "hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      timeout: 3\n",
        );

        $this->assertSame(3.0, ScriptHook::fromConfig($result[0])->timeoutSeconds());
    }
}
