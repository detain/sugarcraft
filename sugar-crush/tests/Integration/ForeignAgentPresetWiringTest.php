<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * crush_code.md Phase 1 item 3, measured FROM THE ENTRY POINT.
 *
 * {@see ForeignAgentPresetRegistry} shipped a two-dialect frontmatter mapper, a
 * {@see SkillSource} tag, a lossy-mapping warning channel, two containment gates
 * and a refusal seam — and nothing in `src/` or `bin/` constructed it, so an agent
 * authored for Claude Code or opencode had no effect on any run. The class said so
 * in its own doc-block, which is the only reason the gap was cheap to find.
 *
 * So the assertions here start at {@see Bootstrap::agentRoster()} and
 * {@see Bootstrap::agentManager()} — the roster a launch registers into the manager
 * `/agents`, Ctrl+A and the agent strip all read — rather than at
 * `discover()`, which the class's own suite already drives and which was green
 * throughout the period the feature was unreachable.
 *
 * DOMAIN of what these prove: that an imported preset becomes a REGISTERED
 * {@see Agent} on the live roster, carrying the fields
 * {@see Agent::fromPreset()} reads (name, description, prompt, model, tools,
 * skills). They do NOT prove that `permissionMode:`, `maxTurns:`, `memory:` or
 * `effort:` travel — `Agent::fromPreset()` drops all four for native presets too —
 * and they do not prove a palette badge exists, because {@see Agent} has no source
 * field for one to read.
 */
final class ForeignAgentPresetWiringTest extends TestCase
{
    use HomeSandboxTrait;
    use TemporaryDirectoryTrait;

    private string $tempDir;
    private string $home;
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugarcrush_foreign_agent_wiring_' . uniqid('', true);
        $this->repo = $this->tempDir . '/repo';
        mkdir($this->repo, 0755, true);

        // 0700 and owned by this process: both the native preset tier and the
        // foreign one refuse a home they cannot attribute to this user, so an
        // unowned sandbox home would refuse the launch outright and every
        // assertion below would fail for an unrelated reason.
        $this->home = $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * A Claude-Code subagent in the checkout reaches the launch roster, prompt and
     * all.
     *
     * The BODY is asserted, not merely the name: Claude Code writes a subagent's
     * system prompt as the markdown body, so a roster entry whose prompt were empty
     * would be a registered agent that does nothing — reachable and useless, which
     * is a different defect wearing the same green.
     */
    public function testAClaudeAuthoredProjectAgentReachesTheLaunchRoster(): void
    {
        $this->writeClaudeAgent($this->repo . '/.claude/agents', 'claude-project-agent', 'From the checkout.');

        $agent = $this->rosterEntry('claude-project-agent');

        $this->assertNotNull($agent, 'an agent under <root>/.claude/agents must reach the launch roster');
        $this->assertSame('From the checkout.', $agent->description);
        $this->assertStringContainsString('PROMPT BODY', $agent->prompt, 'the markdown body must arrive as the prompt');
    }

    public function testAClaudeAuthoredUserAgentReachesTheLaunchRoster(): void
    {
        $this->writeClaudeAgent($this->home . '/.claude/agents', 'claude-user-agent', 'From ~/.claude/agents.');

        $this->assertNotNull(
            $this->rosterEntry('claude-user-agent'),
            'an agent under ~/.claude/agents must reach the launch roster',
        );
    }

    /**
     * opencode's dialect is NOT a near-identity map — it spells the prompt as
     * `prompt:`, tools as a `name: bool` map, and keeps its user tree under
     * `~/.config` — so it earns its own assertions rather than following from the
     * Claude case.
     */
    public function testAnOpencodeAuthoredProjectAgentReachesTheLaunchRosterWithItsToolDecisions(): void
    {
        $dir = $this->repo . '/.opencode/agents';
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/oc-project-agent.md',
            "---\nname: oc-project-agent\ndescription: An opencode agent.\ntools:\n  bash: true\n  write: false\n---\n\nPROMPT BODY for opencode.\n",
        );

        $agent = $this->rosterEntry('oc-project-agent');

        $this->assertNotNull($agent, 'an agent under <root>/.opencode/agents must reach the launch roster');
        // `bash: true` maps to sugar-crush's Bash; `write: false` folds onto Edit
        // and lands in the DENY list, which Agent::fromPreset() does not carry — so
        // the allow list is the observable half at this layer.
        $this->assertSame(['Bash'], $agent->tools, 'the allow decision must survive the import into the roster');
    }

    public function testAnOpencodeAuthoredUserAgentReachesTheLaunchRoster(): void
    {
        $dir = $this->home . '/.config/opencode/agents';
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/oc-user-agent.md',
            "---\nname: oc-user-agent\ndescription: From ~/.config/opencode/agents.\n---\n\nPROMPT BODY.\n",
        );

        $this->assertNotNull(
            $this->rosterEntry('oc-user-agent'),
            'an agent under ~/.config/opencode/agents must reach the launch roster',
        );
    }

    /**
     * The roster the MANAGER holds, which is the object `/agents` and Ctrl+A
     * actually dispatch against — one step past `agentRoster()`'s return value.
     *
     * Worth its own test because the two are wired by a `foreach` in
     * {@see Bootstrap::agentManager()}: a roster that contained the import while the
     * manager did not would still satisfy every assertion above.
     */
    public function testTheLaunchAgentManagerCanResolveAnImportedAgentByName(): void
    {
        $this->writeClaudeAgent($this->repo . '/.claude/agents', 'imported-helper', 'Resolvable by name.');

        $manager = Bootstrap::agentManager($this->repo);

        $this->assertNotNull($manager->get('imported-helper'), 'the manager must resolve the imported agent');
        $this->assertNotNull($manager->get('coder'), 'and must still hold the built-in roster');
    }

    /**
     * PRECEDENCE, HALF ONE: a native preset outranks a foreign one of the same name.
     *
     * The decision, stated on {@see Bootstrap::agentRoster()}: foreign imports go in
     * BENEATH everything native, so wiring a new discovery source cannot change what
     * an existing name resolves to. Mirrors
     * {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()}, which registers
     * foreign skills first and lays the native manifests over them.
     *
     * Asserted on the DESCRIPTION, because both entries would answer to the same
     * name whichever won — the name is what makes them collide, so it cannot be what
     * distinguishes the winner.
     */
    public function testANativePresetOutranksAForeignPresetOfTheSameName(): void
    {
        $this->writeClaudeAgent($this->repo . '/.claude/agents', 'shared-name', 'FOREIGN COPY');
        $this->writeNativePreset($this->repo . '/.sugar-crush/agents', 'shared-name', 'NATIVE COPY');

        $agent = $this->rosterEntry('shared-name');

        $this->assertNotNull($agent);
        $this->assertSame('NATIVE COPY', $agent->description, 'the native preset must win');
    }

    /**
     * PRECEDENCE, HALF TWO: a BUILT-IN definition outranks a foreign preset too, and
     * this is the half a merge into {@see Bootstrap::agentPresets()} would have got
     * wrong.
     *
     * Native presets are applied OVER the six built-ins, so folding the imports into
     * that return value would have ranked a cloned repository's
     * `.claude/agents/reviewer.md` above the built-in `reviewer` — the one name a
     * user is guaranteed to already be relying on. `reviewer` is used rather than an
     * invented name precisely because it is a shipped built-in.
     */
    public function testABuiltInDefinitionOutranksAForeignPresetOfTheSameName(): void
    {
        $this->writeClaudeAgent($this->repo . '/.claude/agents', 'reviewer', 'FOREIGN REVIEWER');

        $agent = $this->rosterEntry('reviewer');

        $this->assertNotNull($agent);
        $this->assertNotSame(
            'FOREIGN REVIEWER',
            $agent->description,
            'a cloned repository must not be able to re-point the built-in reviewer',
        );
    }

    /**
     * The import ADDS, which is the other half of "additive is the only safe
     * direction": a launch with a foreign tree must keep every built-in agent it had
     * without one.
     */
    public function testTheImportAddsToTheRosterWithoutDisplacingTheBuiltIns(): void
    {
        $before = $this->rosterNames();
        $this->writeClaudeAgent($this->repo . '/.claude/agents', 'additive-agent', 'Adds a row.');
        $after = $this->rosterNames();

        $this->assertSame([], array_diff($before, $after), 'no name may disappear because an import arrived');
        $this->assertContains('additive-agent', $after);
    }

    /**
     * THE CROSS-TOOL PAIR, measured rather than inferred, and it resolves the
     * OPPOSITE WAY from foreign skills.
     *
     * {@see ForeignAgentPresetRegistry::discover()} is `$claude + $scanOpencode`, a
     * union whose LEFT side wins, so Claude takes a filename collision here — while
     * {@see \SugarCraft\Crush\Skills\SkillManager::loadAll()} registers Claude and
     * then opencode into a last-write-wins registry, so OPENCODE takes it there
     * ({@see ForeignSkillWiringTest::testOpencodeWinsACrossToolCollisionAmongForeignSkills}).
     * Neither pair has a principled winner; both are deterministic; the divergence
     * is real and is recorded on `discover()` rather than asserted away. Pinned in
     * both files so a future unification has to change two failing tests instead of
     * silently re-pointing one tool's agents.
     */
    public function testClaudeWinsACrossToolCollisionAmongForeignPresets(): void
    {
        $this->writeClaudeAgent($this->repo . '/.claude/agents', 'dual-tool', 'CLAUDE COPY');
        $this->writeClaudeAgent($this->repo . '/.opencode/agents', 'dual-tool', 'OPENCODE COPY');

        $agent = $this->rosterEntry('dual-tool');

        $this->assertNotNull($agent);
        $this->assertSame('CLAUDE COPY', $agent->description);
    }

    /**
     * WHAT THE IMPORT CANNOT CARRY, measured on the type rather than argued.
     *
     * {@see Agent} has no `permissionMode`, `disallowedTools`, `maxTurns`, `memory`,
     * `background`, `effort` or `isolation` — so an imported preset declaring
     * `permissionMode: bypass-permissions`, which is the field the foreign
     * registry's own escape measurement turned up, has nowhere to land on the roster
     * this wiring feeds. That is a bound on the blast radius of the wiring, and it
     * was originally written here as prose read off `Agent::fromPreset()`; a missing
     * property is exactly the kind of claim a doc-block gets wrong two refactors
     * later, so it is asserted.
     *
     * It is NOT a claim that the field is harmless everywhere — only that it does
     * not travel THIS path. {@see \SugarCraft\Crush\Agents\AgentPreset} still
     * carries it, and any future consumer reading presets directly inherits it.
     */
    public function testAnImportedPresetsPermissionModeHasNowhereToLandOnTheRoster(): void
    {
        $dir = $this->repo . '/.claude/agents';
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/bypasser.md',
            "---\nname: bypasser\ndescription: Declares a mode.\npermissionMode: bypassPermissions\n---\n\nPROMPT BODY.\n",
        );

        $this->assertNotNull($this->rosterEntry('bypasser'), 'the import still arrives');

        foreach (
            ['permissionMode', 'disallowedTools', 'maxTurns', 'memory', 'background', 'effort', 'isolation'] as $field
        ) {
            $this->assertFalse(
                property_exists(Agent::class, $field),
                "Agent must have no {$field} for an imported preset's value to reach",
            );
        }
    }

    /**
     * THE REFUSAL, and the fact that it now has a reader.
     *
     * A repository that commits `.claude/agents` as a link out of the checkout used
     * to have the tree read with no containment at all; the gates landed a round
     * before this wiring, and the seam they filled
     * ({@see ForeignAgentPresetRegistry::refusedDirectories()}) had NO consumer until
     * {@see Bootstrap::foreignAgentPresets()} drained it. Both halves are asserted:
     * the preset does not arrive, AND the launch records why.
     */
    public function testASymlinkedForeignAgentsDirectoryIsRefusedAndTheRefusalIsRecorded(): void
    {
        $outside = $this->tempDir . '/outside';
        mkdir($outside, 0755, true);
        $this->writeClaudeAgent($outside, 'escapee', 'SENTINEL-FOREIGN-AGENT');

        mkdir($this->repo . '/.claude', 0755, true);
        $this->assertTrue(symlink($outside, $this->repo . '/.claude/agents'));

        $names = $this->rosterNames();

        $this->assertNotContains('escapee', $names, 'a tree outside the checkout must contribute nothing');
        $this->assertArrayHasKey(
            $this->repo . '/.claude/agents',
            Bootstrap::projectTierRefusals(),
            'and the launch must record the refusal where the notice can print it',
        );
    }

    /**
     * The refusal reaches a STREAM, not just a static array.
     *
     * Driven as a subprocess for the reason
     * {@see FeatWiringReachabilityTest::testALaunchNamesEveryProjectDirectoryItRefusedOnStderr}
     * gives: an in-process assertion on the collector passes against a build that
     * collects the refusal and prints nothing, which is the state this whole item
     * was about. The subprocess also sidesteps the report-once bookkeeping being
     * static, which makes a second in-process launch silent by design.
     */
    public function testALaunchNamesTheRefusedForeignAgentsDirectoryOnStderr(): void
    {
        $outside = $this->tempDir . '/outside-loud';
        mkdir($outside, 0755, true);
        $this->writeClaudeAgent($outside, 'loud-escapee', 'SENTINEL-FOREIGN-AGENT-BODY');

        mkdir($this->repo . '/.opencode', 0755, true);
        $this->assertTrue(symlink($outside, $this->repo . '/.opencode/agents'));

        $script = $this->tempDir . '/launch.php';
        file_put_contents($script, sprintf(
            '<?php require %s; SugarCraft\Crush\Cli\Bootstrap::chat(%s);',
            var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->repo, true),
        ));

        $process = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['HOME' => $this->home, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
        );
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertStringContainsString(
            $this->repo . '/.opencode/agents',
            $stderr,
            'the launch must name the refused foreign agents directory: ' . $stderr . $stdout,
        );
        $this->assertStringContainsString($outside, $stderr, 'and where it actually resolved to');
        $this->assertStringNotContainsString(
            'SENTINEL-FOREIGN-AGENT-BODY',
            $stderr . $stdout,
            'and must leak nothing from behind the link it refused',
        );
    }

    /**
     * THE REFUSED USER TIER, and what the user is actually told.
     *
     * {@see ForeignAgentPresetRegistry::userDir()} returns null when
     * {@see \SugarCraft\Crush\Support\HomeDirectory::owned()} cannot establish the
     * home, and {@see ForeignAgentPresetRegistry::scan()} then SKIPS that tier
     * recording nothing — on its own, a silently shorter roster.
     *
     * It is not silent through `Bootstrap`, and this test measures why rather than
     * asserting it: `owned() === null` is the exact condition
     * `trustedConfigDirPath()` throws on, and {@see Bootstrap::agentRoster()}
     * resolves it before reading any foreign directory. So the user gets a refused
     * launch naming the home and the reason, which is louder than a collector line —
     * not a quieter roster.
     */
    public function testAWorldWritableHomeYieldsNoForeignUserAgentsAndRefusesTheLaunchOutLoud(): void
    {
        $exposed = $this->tempDir . '/exposed-home';
        mkdir($exposed, 0777, true);
        chmod($exposed, 0o777);
        $this->writeClaudeAgent($exposed . '/.claude/agents', 'planted', 'PLANTED BY ANOTHER USER');
        $this->useHomeSandbox($exposed, false);

        // Half one: the registry declines the tier — called directly, because the
        // claim is about this class's behaviour under an unownable home rather than
        // about its reachability.
        $registry = new ForeignAgentPresetRegistry();
        $this->assertSame(
            [],
            array_keys($registry->discover($this->repo)),
            'a home this process cannot establish as the user\'s must contribute no imported presets',
        );
        // WHAT THIS EMPTY MAP MEANS, precisely: the user tier is the only tier with
        // any content in this fixture (`$this->repo` has no `.claude/agents` and no
        // `.opencode/agents` at all), so an empty refusal map is that tier being
        // OMITTED without a record rather than a project tier passing its gate.
        $this->assertSame(
            [],
            $registry->refusedDirectories(),
            'an omitted user tier records no refusal — which is why the launch-level refusal below is the surface',
        );

        // Half two: the surface.
        try {
            Bootstrap::agentRoster($this->repo, 'echo', 'echo');
            $this->fail('a roster built out of an unownable home must be refused, not quietly reduced');
        } catch (\Throwable $e) {
            $this->assertStringContainsString($exposed, $e->getMessage(), 'the refusal must name the home');
            // The substring proves this is the ownership gate's refusal and nothing
            // finer: its message names all three causes it covers in one fixed
            // sentence, so the 0777 mode above is what makes this the
            // world-writable case — the assertion cannot tell them apart.
            $this->assertStringContainsString(
                'world-writable',
                $e->getMessage(),
                'the refusal must be the ownership gate\'s, which names the modes it refuses',
            );
        }
    }

    /**
     * The control: an ordinary launch with a perfectly good foreign tree refuses
     * nothing at all.
     *
     * A drain that records something on every launch would be the noise the notice
     * was written to avoid, so the quiet case is worth an assertion.
     *
     * DRIVEN THROUGH {@see Bootstrap::chat()} rather than {@see Bootstrap::agentRoster()},
     * and that is a property of the collector rather than a convenience:
     * `$projectTierRefusals` is static and reset ONLY at the top of `chat()` — "a
     * launch's refusals, not a process's", as that method says — so a bare
     * `agentRoster()` call inherits whatever an earlier launch in the same process
     * recorded. Asserting emptiness after anything else would be asserting test
     * isolation, not behaviour.
     */
    public function testAnOrdinaryLaunchRecordsNoForeignAgentRefusal(): void
    {
        $this->writeClaudeAgent($this->repo . '/.claude/agents', 'ordinary', 'A plain import.');

        $this->launchChat();

        $this->assertSame([], Bootstrap::projectTierRefusals(), 'nothing was refused, so nothing may be recorded');
        $this->assertNotNull($this->rosterEntry('ordinary'), 'and the import still arrived');
    }

    /**
     * A real launch, with the two backend-selection env vars cleared: either one set
     * selects a `CommandBackend` and a different construction path. Same dance
     * {@see FeatWiringReachabilityTest} performs for the same reason.
     */
    private function launchChat(): void
    {
        $provider = getenv('SUGARCRUSH_PROVIDER');
        $command = getenv('SUGARCRUSH_BACKEND_CMD');
        putenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_BACKEND_CMD');

        try {
            Bootstrap::chat($this->repo);
        } finally {
            $provider === false ? putenv('SUGARCRUSH_PROVIDER') : putenv('SUGARCRUSH_PROVIDER=' . $provider);
            $command === false ? putenv('SUGARCRUSH_BACKEND_CMD') : putenv('SUGARCRUSH_BACKEND_CMD=' . $command);
        }
    }

    /**
     * @return list<string>
     */
    private function rosterNames(): array
    {
        return array_map(
            static fn(Agent $agent): string => $agent->name,
            Bootstrap::agentRoster($this->repo, 'echo', 'echo'),
        );
    }

    private function rosterEntry(string $name): ?Agent
    {
        foreach (Bootstrap::agentRoster($this->repo, 'echo', 'echo') as $agent) {
            if ($agent->name === $name) {
                return $agent;
            }
        }

        return null;
    }

    /**
     * A Claude Code subagent file: frontmatter plus the markdown body Claude Code
     * uses as the system prompt.
     */
    private function writeClaudeAgent(string $dir, string $name, string $description): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $dir . '/' . $name . '.md',
            "---\nname: {$name}\ndescription: {$description}\n---\n\nPROMPT BODY for {$name}.\n",
        );
    }

    private function writeNativePreset(string $dir, string $name, string $description): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $dir . '/' . $name . '.md',
            "---\nname: {$name}\ndescription: {$description}\n---\n\nNative body prose.\n",
        );
    }
}
