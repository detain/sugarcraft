<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Support\ProcessContainment;

/**
 * rv-sec-4 (crush-review-1014): the `` !`cmd` `` substitution in a custom
 * command's template runs shell text whose STDOUT IS HANDED TO THE MODEL —
 * it lands verbatim in the expanded prompt. That is audit F-E1's class
 * exactly ("the spawns whose output the MODEL reads"), so the spawn must
 * take {@see ProcessContainment::scrubbedEnv()} and not the bare inherited
 * block: before this fix, `` !`echo $OPENAI_API_KEY` `` minted the session's
 * own provider credential into the context window.
 *
 * The fixture plants FAKE credentials with putenv() — never a real one — and
 * restores both the environment and the process-wide allowlist in tearDown,
 * mirroring the discipline of tests/Support/SecretEnvScrubTest.php.
 */
final class CommandSpecScrubbedEnvTest extends TestCase
{
    private const SECRET = 'FIXPERMS_SECRET_API_KEY';
    private const SECRET_VALUE = 'sk-fake-not-a-real-credential';
    private const PLAIN = 'FIXPERMS_PLAIN_NAME';
    private const PLAIN_VALUE = 'plain-visible';

    /** @var array<string, string|false> */
    private array $saved = [];

    /** @var list<string> */
    private array $savedAllowlist = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::SECRET => self::SECRET_VALUE, self::PLAIN => self::PLAIN_VALUE] as $name => $value) {
            $this->saved[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->savedAllowlist = ProcessContainment::secretEnvAllowlist();
        ProcessContainment::useSecretEnvAllowlist([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        ProcessContainment::useSecretEnvAllowlist($this->savedAllowlist);
        parent::tearDown();
    }

    private function spec(): CommandSpec
    {
        return CommandSpec::new(name: 'x', description: 'x', category: 'Custom', template: 'x', tier: 'user');
    }

    public function testASubstitutionCannotEchoAPlantedCredentialBackIntoThePrompt(): void
    {
        $out = $this->spec()->runShellSubstitution('echo "[' . '$' . self::SECRET . ']"', null, 5.0);

        self::assertStringNotContainsString(self::SECRET_VALUE, $out, 'the scrubbed env still carried the secret to the child');
        self::assertSame('[]', trim($out), 'the variable should expand to empty and the substitution deliver its brackets');
    }

    public function testNonCredentialNamesStillReachTheSubstitution(): void
    {
        // The polarity twin: the previous test would also pass if the child
        // got NO environment at all (or never ran). A plain name must survive
        // the scrub, proving the [] above is the scrub and not a blank-out.
        $out = $this->spec()->runShellSubstitution('echo "[' . '$' . self::PLAIN . ']"', null, 5.0);

        self::assertSame('[' . self::PLAIN_VALUE . ']', trim($out));
    }

    public function testTheOperatorAllowlistReGrantsANameToTheSubstitution(): void
    {
        // The re-grant lever is honored without per-command threading because
        // scrubbedEnv() consults the process-global allowlist Bootstrap
        // installs — exact-name entry, the narrowest spelling.
        ProcessContainment::useSecretEnvAllowlist([self::SECRET]);

        $out = $this->spec()->runShellSubstitution('echo "[' . '$' . self::SECRET . ']"', null, 5.0);

        self::assertSame('[' . self::SECRET_VALUE . ']', trim($out), 'an allowlisted credential must reach the child');
    }

    public function testTheSpawnSiteCallsScrubbedEnvAndNotTheBareInherit(): void
    {
        // Structural pin: the three behavioural tests above could be satisfied
        // by any env-builder that scrubs; this pins THAT THIS SITE uses THE
        // canonical one, so a future edit cannot quietly revert the call.
        // COMMENT-STRIPPED: the fix's own docblock cites ProcessContainment::env()
        // to explain what it replaced, and prose must not count as a call site.
        $source = php_strip_whitespace(__DIR__ . '/../../src/Commands/CommandSpec.php');
        $start = strpos($source, 'function runShellSubstitution');
        self::assertIsInt($start, 'the substitution method moved or was renamed — re-anchor this pin');
        $body = substr($source, (int) $start, 4000);

        self::assertSame(1, substr_count($body, 'ProcessContainment::scrubbedEnv()'));
        self::assertSame(0, substr_count($body, 'ProcessContainment::env('));
    }
}
