<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Hooks\HookConfig;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * @see HookConfig
 */
final class HookConfigTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/hook_config_test_' . uniqid((string) getmypid(), true);
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
     * to PreToolUse inside {@see \SugarCraft\Code\Hooks\ScriptHook::fromConfig()},
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
     * An uncompilable matcher registers a hook that {@see \SugarCraft\Code\Hooks\HookRegistry::matcherMatches()}
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
     * `disabled` IS a key this format has — {@see \SugarCraft\Code\Hooks\HookRegistry}
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

    /** ISS-040: `unattendedAsk` rides the entry with the same strictness. */
    public function testParseCarriesUnattendedAskThroughAndDefaultsToFalse(): void
    {
        $opted = HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      unattendedAsk: true\n");
        $plain = HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n");

        $this->assertTrue($opted[0]['unattendedAsk']);
        $this->assertFalse($plain[0]['unattendedAsk']);
    }

    public function testParseRefusesANonBooleanUnattendedAsk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/'unattendedAsk' that is not true or false/");

        HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n      unattendedAsk: 'yes'\n");
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
     * has to be the same one {@see \SugarCraft\Code\Hooks\HookRegistry} later
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

    /** W9 integration: the missing-timeout default is the hooksDefaultTimeoutSeconds setting. */
    public function testTheMissingTimeoutDefaultIsASetting(): void
    {
        $this->assertSame(15.0, ScriptHook::defaultTimeoutSeconds([ScriptHook::DEFAULT_TIMEOUT_SETTING => 15]));
        $this->assertSame(2.5, ScriptHook::defaultTimeoutSeconds([ScriptHook::DEFAULT_TIMEOUT_SETTING => 2.5]));
        foreach ([0, -1, 7200, 'soon', true, null] as $refused) {
            $this->assertSame(ScriptHook::DEFAULT_TIMEOUT_SECONDS, ScriptHook::defaultTimeoutSeconds([ScriptHook::DEFAULT_TIMEOUT_SETTING => $refused]), var_export($refused, true));
        }

        $home = $this->useHomeSandbox($this->tempDir . '/home');
        mkdir($home . '/.candy-code', 0o700, true);
        file_put_contents($home . '/.candy-code/config.json', json_encode([ScriptHook::DEFAULT_TIMEOUT_SETTING => 20]));
        try {
            $result = HookConfig::parse("hooks:\n  PreToolUse:\n    - name: g\n      command: 'guard.sh'\n    - name: h\n      command: 'h.sh'\n      timeout: 5\n");
        } finally {
            $this->restoreHomeSandbox();
            unlink($home . '/.candy-code/config.json');
            rmdir($home . '/.candy-code');
            rmdir($home);
        }

        $this->assertSame(20.0, $result[0]['timeout'], 'no timeout: the saved default');
        $this->assertSame(5.0, $result[1]['timeout'], 'the entry\'s own timeout still wins');
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
            'infinity IS a float greater than zero' => ['.inf'],
            'a literal that overflows to infinity' => ['1e400'],
            'negative infinity' => ['-.inf'],
            'NaN fails every comparison, including <= 0' => ['.nan'],
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
     * `.inf` AND `1e400` ARE THE ONES THAT GOT THROUGH, and they are the reason
     * the guard is `is_finite()` and not just `> 0`. `is_float(INF)` is true and
     * `INF > 0` is true, so `$deadline = microtime(true) + INF` is an instant
     * no clock ever reaches — the unbounded wait this key exists to remove, put
     * back behind an error message that said it could not be asked for.
     * MEASURED at fc597e81: `timeout: .inf` parsed to `INF`, and a real
     * ScriptHook on `sleep 300` under an 8-second external clock returned exit
     * 124 having never returned at all.
     *
     * `.nan` is here as a REFUSAL and not a coercion. It fails every
     * comparison, so it fell past `<= 0` here and then past
     * {@see ScriptHook::timeoutSeconds()}'s `> 0.0`, arriving at the 60-second
     * default by two accidents in a row with nothing printed — a number
     * silently substituted for one the user typed, which is what
     * `disabled: 'no'` is refused for and worse here because the substituted
     * value is a security bound.
     *
     * @dataProvider unusableTimeouts
     */
    public function testParseRefusesATimeoutThatIsNotAPositiveNumber(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/positive, FINITE number/');

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

    /**
     * The refusal has to reach the WHOLE parse, not just the entry — a hook file
     * carrying one `.inf` must stop the launch rather than register the hooks
     * around it.
     */
    public function testAnInfiniteTimeoutAnywhereInTheFileStopsTheLoad(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HookConfig::parse(
            "hooks:\n  PreToolUse:\n"
            . "    - name: fine\n      command: 'a.sh'\n      timeout: 5\n"
            . "    - name: forever\n      command: 'b.sh'\n      timeout: .inf\n",
        );
    }

    // =========================================================================
    // ISS-039: user-tier relative commands
    // =========================================================================

    /**
     * The documented user-tier example - a script that travels with the
     * config, spelled `./hooks/...` in `~/.candy-code/hooks.yaml` - resolves
     * against the config's OWN directory, not whichever repository happens to
     * be open. The name fallback keeps the raw text the user typed.
     */
    public function testAUserTierRelativeCommandResolvesAgainstTheUserConfigDirectory(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/home039');
        mkdir($home . '/.candy-code/hooks', 0o700, true);
        $file = $home . '/.candy-code/hooks.yaml';
        // Bootstrap reaches parse() only THROUGH loadFromFile(), so the file
        // is always there; the tier test follows suit and matches on the real
        // path rather than on a string that merely looks like one.
        file_put_contents($file, "hooks: {}\n");
        try {
            $entries = HookConfig::parse(
                "hooks:\n  PreToolUse:\n    - command: './hooks/confirm-deploy.sh --strict'\n",
                $file,
            );

            $this->assertSame(
                $home . '/.candy-code/hooks/confirm-deploy.sh --strict',
                $entries[0]['command'],
            );
            $this->assertSame(
                './hooks/confirm-deploy.sh --strict',
                $entries[0]['name'],
                'the chain label stays the text the user wrote',
            );
        } finally {
            $this->restoreHomeSandbox();
            rmdir($home . '/.candy-code/hooks');
            unlink($file);
            rmdir($home . '/.candy-code');
            rmdir($home);
        }
    }

    /**
     * THE PROJECT TIER IS NOT THE USER TIER. A repo's own hooks.yaml ships
     * with the checkout the cwd already points at, so its relative commands
     * are repository-relative BY CONTRACT and the rewrite must keep its hands
     * off - running a repo's guard against a snapshot of the home directory
     * would execute the wrong file under the right-looking name.
     */
    public function testAProjectTierFileKeepsRepoRelativeCommandsUntouched(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/home039p');
        mkdir($home . '/.candy-code', 0o700, true);
        file_put_contents($home . '/.candy-code/hooks.yaml', "hooks: {}\n");
        $project = $this->tempDir . '/repo039/.candy-code';
        mkdir($project, 0o700, true);
        $projectFile = $project . '/hooks.yaml';
        file_put_contents($projectFile, "hooks:\n  PreToolUse:\n    - command: './hooks/repo-guard.sh'\n");
        try {
            $entries = HookConfig::parse(
                "hooks:\n  PreToolUse:\n    - command: './hooks/repo-guard.sh'\n",
                $projectFile,
            );

            $this->assertSame('./hooks/repo-guard.sh', $entries[0]['command']);

            // And the same file CONTENT read AS the user's own file does get
            // rewritten - the tier is the path, never the text.
            $userEntries = HookConfig::parse(
                "hooks:\n  PreToolUse:\n    - command: './hooks/repo-guard.sh'\n",
                $home . '/.candy-code/hooks.yaml',
            );
            $this->assertSame(
                $home . '/.candy-code/hooks/repo-guard.sh',
                $userEntries[0]['command'],
            );
        } finally {
            $this->restoreHomeSandbox();
            unlink($projectFile);
            rmdir($project);
            rmdir($this->tempDir . '/repo039');
            unlink($home . '/.candy-code/hooks.yaml');
            rmdir($home . '/.candy-code');
            rmdir($home);
        }
    }

    /**
     * FIRST TOKENS THAT MEAN SOMETHING ELSE ARE NEVER REWRITTEN, NAKED OR
     * BEHIND `./`. `bash ./x.sh` hides the relative path behind an
     * interpreter this rewrite would have to parse around; `../x.sh` walks
     * out of whatever base either reading could claim; `x.sh` is a PATH
     * lookup in every shell's eyes. A bare token with a slash - `hooks/x.sh`
     * - stays untouched HERE for the third reason: no such file exists under
     * the config directory in this fixture (ISS-039's review fix widened the
     * rule to rewrite a bare token ONLY when it names a real contained file;
     * that positive polarity has its own test below). Guessing beyond that is
     * how a config rewrite starts running things the user did not name.
     */
    public function testTheRewriteTouchesOnlyALeadingDotSlashAndNothingElse(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/home039q');
        mkdir($home . '/.candy-code', 0o700, true);
        $file = $home . '/.candy-code/hooks.yaml';
        file_put_contents($file, "hooks: {}\n");
        $yaml = static fn (string $command): string =>
            "hooks:\n  PreToolUse:\n    - name: n\n      command: '" . $command . "'\n";
        try {
            $cases = [
                'bash ./x.sh' => 'bash ./x.sh',
                '../x.sh' => '../x.sh',
                'x.sh' => 'x.sh',
                'echo ./x.sh' => 'echo ./x.sh',
            ];
            foreach ($cases as $typed => $expected) {
                $entries = HookConfig::parse($yaml($typed), $file);
                $this->assertSame($expected, $entries[0]['command'], $typed);
            }

            // The one polarity that DOES rewrite, with a trailing `./` operand
            // that must survive untouched - first token only.
            $entries = HookConfig::parse($yaml('./run.sh --hook ./data'), $file);
            $this->assertSame($home . '/.candy-code/run.sh --hook ./data', $entries[0]['command']);
        } finally {
            $this->restoreHomeSandbox();
            unlink($file);
            rmdir($home . '/.candy-code');
            rmdir($home);
        }
    }

    /**
     * An undeterminable home is NO rewrite, not a wrong rewrite: with HOME
     * unset (or pointing at a directory ownership cannot establish) the tier
     * test has nothing to resolve against, and the command runs exactly as
     * before this feature - the pre-ISS-039 shape is the fail-into-keeping
     * side, since a guard silently re-rooted into the wrong directory is a
     * guard reading the wrong arguments.
     */
    public function testAnUnestablishableHomeLeavesUserSpelledCommandsAlone(): void
    {
        $missing = $this->tempDir . '/gone039';
        $this->useHomeSandbox($missing, create: false);
        try {
            $entries = HookConfig::parse(
                "hooks:\n  PreToolUse:\n    - command: './hooks/x.sh'\n",
                $missing . '/.candy-code/hooks.yaml',
            );

            $this->assertSame('./hooks/x.sh', $entries[0]['command']);
        } finally {
            $this->restoreHomeSandbox();
        }
    }

    /**
     * THE BARE SPELLING THE ORIGINAL RULE LEFT BEHIND (ISS-039 review fix).
     * `hooks/guard.sh` names the user's own script as surely as
     * `./hooks/guard.sh` does, and when that file really exists under the
     * config directory the rewrite must claim it - otherwise a cloned
     * repository that plants its own hooks/guard.sh runs the stranger's
     * script under the user's guard's authority, the same hijack class the
     * `./` arm closed. Both spellings anchor to the same path; the name
     * fallback still sees the raw text the user typed.
     */
    public function testABareRelativeCommandWithAConfigDirFileResolvesToIt(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/home039b');
        mkdir($home . '/.candy-code/hooks', 0o700, true);
        $guard = $home . '/.candy-code/hooks/guard.sh';
        file_put_contents($guard, "#!/bin/sh\nexit 0\n");
        $file = $home . '/.candy-code/hooks.yaml';
        file_put_contents($file, "hooks: {}\n");
        try {
            $entries = HookConfig::parse(
                "hooks:\n  PreToolUse:\n    - command: 'hooks/guard.sh --strict'\n",
                $file,
            );

            $this->assertSame($home . '/.candy-code/hooks/guard.sh --strict', $entries[0]['command']);
            $this->assertSame(
                'hooks/guard.sh --strict',
                $entries[0]['name'],
                'the chain label stays the text the user wrote',
            );

            // The `./` arm keeps rewriting unconditionally - widened, not
            // replaced: same anchor either way, dot or no dot.
            $dotted = HookConfig::parse(
                "hooks:\n  PreToolUse:\n    - name: n\n      command: './hooks/guard.sh --strict'\n",
                $file,
            );
            $this->assertSame($home . '/.candy-code/hooks/guard.sh --strict', $dotted[0]['command']);
        } finally {
            $this->restoreHomeSandbox();
            unlink($guard);
            rmdir($home . '/.candy-code/hooks');
            unlink($file);
            rmdir($home . '/.candy-code');
            rmdir($home);
        }
    }

    /**
     * THE GATE IS EXISTENCE PLUS CONTAINMENT, AND THE PASS-THROUGHS ARE THE
     * POINT. A bare relative token naming NOTHING under the config directory
     * keeps the pre-039 behaviour of running as typed - the rewrite never
     * invents a path. `../escape.sh` here DOES name a real file, one level
     * OUTSIDE the base, and must still pass through: existence alone is not
     * the gate, or `../` would re-root the guard into the parent. The
     * remaining shapes are refused by the token's own face - an absolute
     * path is already resolved, `sh hooks/x.sh` looks up `sh`, and `~` is
     * the shell's home expansion, not this directory.
     */
    public function testABareRelativeCommandWithoutAConfigDirFileRunsUnchanged(): void
    {
        $home = $this->useHomeSandbox($this->tempDir . '/home039m');
        mkdir($home . '/.candy-code', 0o700, true);
        $file = $home . '/.candy-code/hooks.yaml';
        file_put_contents($file, "hooks: {}\n");
        file_put_contents($home . '/escape.sh', "#!/bin/sh\nexit 0\n");
        // A DIRECTORY named `hooks/sub` resolves inside the base, so only
        // the is_file() half of the gate refuses it - the rewrite must not
        // anchor onto a directory any more than onto an escaping file.
        mkdir($home . '/.candy-code/hooks/sub', 0o700, true);
        try {
            $cases = [
                'hooks/missing.sh' => 'hooks/missing.sh',
                'hooks/sub' => 'hooks/sub',
                '../escape.sh' => '../escape.sh',
                '/opt/tools/hooks/x.sh' => '/opt/tools/hooks/x.sh',
                'sh hooks/x.sh' => 'sh hooks/x.sh',
                '~/hooks/escape.sh' => '~/hooks/escape.sh',
            ];
            foreach ($cases as $typed => $expected) {
                $entries = HookConfig::parse(
                    "hooks:\n  PreToolUse:\n    - name: n\n      command: '" . $typed . "'\n",
                    $file,
                );
                $this->assertSame($expected, $entries[0]['command'], $typed);
            }
        } finally {
            $this->restoreHomeSandbox();
            rmdir($home . '/.candy-code/hooks/sub');
            rmdir($home . '/.candy-code/hooks');
            unlink($home . '/escape.sh');
            unlink($file);
            rmdir($home . '/.candy-code');
            rmdir($home);
        }
    }

    // =========================================================================
    // C-EXT-6.c - per-type entry keys
    // =========================================================================

    public function testParseLoadsAnHttpEntryWithItsTarget(): void
    {
        $yaml = <<<'YAML'
hooks:
  PreToolUse:
    - type: http
      url: 'https://gate.example/api/hook'
      headers:
        X-Trace: $HOOK_TRACE
      allowedEnvVars:
        - HOOK_TRACE
YAML;

        $rows = HookConfig::parse($yaml);

        $this->assertCount(1, $rows);
        $this->assertSame('http', $rows[0]['type']);
        $this->assertSame('https://gate.example/api/hook', $rows[0]['url']);
        $this->assertSame(['X-Trace' => '$HOOK_TRACE'], $rows[0]['headers']);
        $this->assertSame(['HOOK_TRACE'], $rows[0]['allowedEnvVars']);
        $this->assertSame('', $rows[0]['command'], 'an http row must not arrive carrying a command');
        $this->assertSame('https://gate.example/api/hook', $rows[0]['name'],
            'an unnamed http row is named after its url, as a command row is after its command');
    }

    public function testParseDefaultsAPlainCommandRowToTheCommandKind(): void
    {
        $rows = HookConfig::parse("hooks:\n  PreToolUse:\n    - command: 'guard.sh'\n");

        $this->assertSame('command', $rows[0]['type']);
        $this->assertSame('', $rows[0]['url']);
        $this->assertSame([], $rows[0]['headers']);
        $this->assertSame([], $rows[0]['allowedEnvVars']);
    }

    public function testParseRefusesPerTypeKeysOnTheWrongKindOfEntry(): void
    {
        $cases = [
            'a command row carrying a url' => ["hooks:\n  PreToolUse:\n    - command: 'guard.sh'\n      url: 'https://gate.example/x'\n", '/refusing rather than ignoring it/'],
            'a command row carrying headers' => ["hooks:\n  PreToolUse:\n    - command: 'guard.sh'\n      headers:\n        X-Trace: t\n", '/refusing rather than ignoring it/'],
            'a command row carrying an allow-list' => ["hooks:\n  PreToolUse:\n    - command: 'guard.sh'\n      allowedEnvVars:\n        - HOOK_TOKEN\n", '/refusing rather than ignoring it/'],
            'an http row that also names a command' => ["hooks:\n  PreToolUse:\n    - type: http\n      url: 'https://gate.example/x'\n      command: 'guard.sh'\n", '/refusing rather than choosing between them/'],
            'an http row with no url' => ["hooks:\n  PreToolUse:\n    - type: http\n      name: 'the gate'\n", '/hook with no/'],
            'an http row with no url and no name' => ["hooks:\n  PreToolUse:\n    - type: http\n", "/empty 'name'/"],
            'a kind this build does not run' => ["hooks:\n  PreToolUse:\n    - type: gopher\n      command: 'guard.sh'\n", '/rather than run as the kind you did not write/'],
            'a kind that is not even a word' => ["hooks:\n  PreToolUse:\n    - type: [1, 2]\n      command: 'guard.sh'\n", '/rather than run as the kind you did not write/'],
        ];

        foreach ($cases as $label => [$yaml, $needle]) {
            try {
                HookConfig::parse($yaml);
                $this->fail($label . ': the row loaded');
            } catch (\InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression($needle, $e->getMessage(), $label);
            }
        }
    }

    public function testParseRefusesATargetThisHostWouldNotDial(): void
    {
        // Judged at LOAD, not at call time: a settings file that names the
        // metadata address has to fail the launch that would have dialled it.
        foreach ([
            'a non-http scheme' => 'ftp://gate.example/x',
            'credentials in the url' => 'https://user:sekret@gate.example/x',
            'loopback' => 'http://127.0.0.1:9000/x',
            'the cloud metadata address' => 'http://169.254.169.254/latest/meta-data/',
            'a private range' => 'http://10.4.4.4/x',
        ] as $label => $url) {
            try {
                HookConfig::parse("hooks:\n  PreToolUse:\n    - type: http\n      url: '" . $url . "'\n");
                $this->fail($label . ': an undiallable target loaded');
            } catch (\InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/this host will not dial/', $e->getMessage(), $label);
                $this->assertStringContainsString('refused here rather than at call time', $e->getMessage(), $label);
            }
        }
    }

    public function testParseRefusesAHeaderOrEnvNameThatCannotBeUsed(): void
    {
        $cases = [
            'a field the transport owns' => ["      headers:\n        Host: evil.example\n", '/cannot set them|header field/'],
            'a field with a space in it' => ["      headers:\n        'Bad Name': x\n", '/header field/'],
            'a value that is not a string' => ["      headers:\n        X-Trace: [1, 2]\n", '/header that is not a string/'],
            'a value carrying a newline' => ["      headers:\n        X-Trace: \"up\\r\\nInjected: 1\"\n", '/request smuggling/'],
            // A list reads as a field named `0`, and that is refused too — the
            // two spellings are named apart only because both are refusals.
            'headers that are a list' => ["      headers:\n        - X-Trace\n", '/is not a name|not a mapping/'],
            'an env name that is not a name' => ["      allowedEnvVars:\n        - 'BAD-NAME'\n", '/shell identifier/'],
            'an allow-list that is not a list' => ["      allowedEnvVars: HOOK_TOKEN\n", '/allowedEnvVars/'],
        ];

        foreach ($cases as $label => [$tail, $needle]) {
            $yaml = "hooks:\n  PreToolUse:\n    - type: http\n      url: 'https://gate.example/x'\n" . $tail;
            try {
                HookConfig::parse($yaml);
                $this->fail($label . ': the row loaded');
            } catch (\InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression($needle, $e->getMessage(), $label);
            }
        }
    }
}
