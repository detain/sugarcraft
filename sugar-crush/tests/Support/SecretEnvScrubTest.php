<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tools\BuiltIn\Bash;

/**
 * Audit F-E1: Bash, Grep and every script hook inherited the whole launch
 * environment, so `env | grep API_KEY` handed the model the session's own
 * provider key (and any other credential in the operator's shell).
 *
 * The fixture plants FAKE credentials with putenv() — never a real one — and
 * reads them back through the two model-visible spawn paths. Each planted name
 * is restored in tearDown, as is the process-wide allowlist.
 *
 * @see ProcessContainment::scrubbedEnv()
 */
final class SecretEnvScrubTest extends TestCase
{
    /** Credential-shaped names this file plants, each with a fake value. */
    private const PLANTED_SECRETS = [
        'OPENAI_API_KEY' => 'sk-FAKE-f-e1-openai',
        'ANTHROPIC_API_KEY' => 'sk-ant-FAKE-f-e1',
        'ANTHROPIC_AUTH_TOKEN' => 'FAKE-f-e1-bearer',
        'GITHUB_TOKEN' => 'ghp_FAKE_f_e1',
        'AWS_SECRET_ACCESS_KEY' => 'FAKE/f-e1/aws',
        'AWS_PROFILE' => 'f-e1-profile',
        'ACME_SECRET' => 'FAKE-f-e1-acme',
        'acme_api_key' => 'FAKE-f-e1-lowercase',
    ];

    /** An ordinary variable, which must still be inherited. */
    private const PLAIN = ['SUGARCRUSH_F_E1_PLAIN' => 'plain-survives'];

    /**
     * The probe every spawn runs: `env` narrowed to the names this file
     * asserts on, so an operator's long LS_COLORS cannot push a planted line
     * past the hook-output cap. Case-insensitive, like the scrub.
     */
    private const ENV_PROBE_COMMAND = "env | grep -iE '^(PATH|GIT_TERMINAL_PROMPT|CRUSH_TOOL_NAME|SUGARCRUSH_F_E1_PLAIN|OPENAI|ANTHROPIC|GITHUB|AWS_|ACME)'";

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([...self::PLANTED_SECRETS, ...self::PLAIN] as $name => $value) {
            $this->saved[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        ProcessContainment::useSecretEnvAllowlist([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        ProcessContainment::useSecretEnvAllowlist([]);
        parent::tearDown();
    }

    public function testBashEnvCarriesNoCredentialButKeepsTheOrdinaryEnvironment(): void
    {
        $listing = $this->bashEnv();

        foreach (self::PLANTED_SECRETS as $name => $value) {
            self::assertStringNotContainsString($value, $listing, "Bash inherited {$name}");
        }
        self::assertStringContainsString('SUGARCRUSH_F_E1_PLAIN=plain-survives', $listing);
        self::assertMatchesRegularExpression('/^PATH=/m', $listing, 'PATH must still be inherited');
        self::assertStringContainsString('GIT_TERMINAL_PROMPT=0', $listing, 'the fail-fast block still applies');
    }

    public function testInteractiveBashIsScrubbedToo(): void
    {
        if (!ProcessContainment::interactiveAvailable()) {
            self::markTestSkipped('this host has no pty mechanism, so the interactive path refuses before spawning');
        }

        $result = (new Bash(null))->execute(['id' => 'call_pty', 'command' => self::ENV_PROBE_COMMAND, 'interactive' => true]);

        foreach (self::PLANTED_SECRETS as $name => $value) {
            self::assertStringNotContainsString($value, $result->content(), "interactive Bash inherited {$name}");
        }
        self::assertStringContainsString('SUGARCRUSH_F_E1_PLAIN=plain-survives', $result->content());
    }

    public function testAScriptHookSeesNoCredentialButStillGetsItsCrushKeys(): void
    {
        $listing = $this->hookEnv();

        foreach (self::PLANTED_SECRETS as $name => $value) {
            self::assertStringNotContainsString($value, $listing, "a script hook inherited {$name}");
        }
        self::assertStringContainsString('CRUSH_TOOL_NAME=Bash', $listing);
        self::assertStringContainsString('SUGARCRUSH_F_E1_PLAIN=plain-survives', $listing);
    }

    /**
     * The scrub is for the MODEL-VISIBLE paths only. LSP, the claude-code
     * client and the command backends authenticate as the operator through
     * env(), and must keep doing so. (MCP stdio left this list in 0.14-b: it
     * spawns through ProcessContainment::mcpEnv() — see
     * tests/MCP/StdioMcpEnvScrubTest.php.)
     */
    public function testTheSharedEnvBlockItselfIsNotScrubbed(): void
    {
        $env = ProcessContainment::env();

        self::assertSame('sk-FAKE-f-e1-openai', $env['OPENAI_API_KEY'] ?? null);
        self::assertSame('ghp_FAKE_f_e1', $env['GITHUB_TOKEN'] ?? null);
    }

    public function testASitesOwnOverrideIsNeverCaughtByAPattern(): void
    {
        $env = ProcessContainment::scrubbedEnv(['CRUSH_SESSION_TOKEN' => 'site-owned']);

        self::assertSame('site-owned', $env['CRUSH_SESSION_TOKEN'] ?? null);
        self::assertArrayNotHasKey('OPENAI_API_KEY', $env);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'api key suffix' => ['STRIPE_API_KEY', true];
        yield 'token suffix' => ['GH_TOKEN', true];
        yield 'secret suffix' => ['CLIENT_SECRET', true];
        yield 'aws prefix' => ['AWS_ACCESS_KEY_ID', true];
        yield 'lower case' => ['github_token', true];
        yield 'provider key' => ['SGLANG_API_KEY', true];
        yield 'path' => ['PATH', false];
        yield 'home' => ['HOME', false];
        yield 'token mid-name' => ['TOKENIZER_PARALLELISM', false];
        yield 'git prompt switch' => ['GIT_TERMINAL_PROMPT', false];
        yield 'ssh agent socket' => ['SSH_AUTH_SOCK', false];
    }

    /**
     * @dataProvider names
     */
    public function testWhichNamesAreCredentialShaped(string $name, bool $secret): void
    {
        self::assertSame($secret, ProcessContainment::isSecretEnvName($name));
    }

    public function testAnExactAllowlistEntryLetsThatOneVariableThrough(): void
    {
        ProcessContainment::useSecretEnvAllowlist(['GITHUB_TOKEN']);

        $listing = $this->bashEnv();

        self::assertStringContainsString('GITHUB_TOKEN=ghp_FAKE_f_e1', $listing);
        self::assertStringNotContainsString('sk-FAKE-f-e1-openai', $listing);
        self::assertStringNotContainsString('FAKE/f-e1/aws', $listing);
    }

    public function testTheAllowlistReachesScriptHooksToo(): void
    {
        ProcessContainment::useSecretEnvAllowlist(['ACME_SECRET']);

        self::assertStringContainsString('ACME_SECRET=FAKE-f-e1-acme', $this->hookEnv());
    }

    /**
     * A glob written for the operator's own tooling must not also release the
     * key the SESSION authenticates with — that would reopen F-E1 through the
     * setting meant to be its narrow exception.
     */
    public function testAGlobNeverReleasesAProviderKeyButAnExactNameDoes(): void
    {
        ProcessContainment::useSecretEnvAllowlist(['*_TOKEN', '*']);

        $env = ProcessContainment::scrubbedEnv();
        self::assertSame('ghp_FAKE_f_e1', $env['GITHUB_TOKEN'] ?? null);
        self::assertSame('FAKE/f-e1/aws', $env['AWS_SECRET_ACCESS_KEY'] ?? null);
        self::assertArrayNotHasKey('ANTHROPIC_AUTH_TOKEN', $env);
        self::assertArrayNotHasKey('OPENAI_API_KEY', $env);

        ProcessContainment::useSecretEnvAllowlist(['anthropic_auth_token']);
        $env = ProcessContainment::scrubbedEnv();
        self::assertSame('FAKE-f-e1-bearer', $env['ANTHROPIC_AUTH_TOKEN'] ?? null, 'an exact entry, compared case-insensitively, releases a provider key');
        self::assertArrayNotHasKey('GITHUB_TOKEN', $env, 'installing a new list replaces the old one');
    }

    public function testInstallingDropsBlankAndNonStringEntries(): void
    {
        ProcessContainment::useSecretEnvAllowlist(['  GH_TOKEN ', '', '   ', 42, null, ['X'], 'GH_TOKEN']);

        self::assertSame(['GH_TOKEN'], ProcessContainment::secretEnvAllowlist());

        ProcessContainment::useSecretEnvAllowlist([]);
        self::assertSame([], ProcessContainment::secretEnvAllowlist());
    }

    private function bashEnv(): string
    {
        $result = (new Bash(null))->execute(['id' => 'call_env', 'command' => self::ENV_PROBE_COMMAND]);
        self::assertFalse($result->isError(), $result->content());

        return $result->content();
    }

    private function hookEnv(): string
    {
        $hook = new ScriptHook(
            name: 'env_probe',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: self::ENV_PROBE_COMMAND,
            description: 'prints its environment',
        );
        $result = $hook->execute(new HookContext(
            sessionId: 's',
            toolName: 'Bash',
            toolArgs: [],
            toolInput: '{"command":"ls"}',
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: sys_get_temp_dir(),
        ));

        return (string) $result->additionalContext;
    }
}
