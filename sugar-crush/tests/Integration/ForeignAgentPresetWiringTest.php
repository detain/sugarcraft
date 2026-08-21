<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentPreset;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Permissions\PermissionMode;
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
 * {@see Agent::fromPreset()} reads — which is now ALL SIXTEEN of
 * {@see \SugarCraft\Crush\Agents\AgentPreset}'s, including `maxTurns:`,
 * `memory:`, `effort:` and `source:`. An earlier revision of this paragraph
 * said the opposite ("they do NOT prove that … travel", "`Agent` has no source
 * field"), which was true of the six-field mapper it was written against and
 * false from the moment that widened; it is called out rather than quietly
 * swapped because a stale DOMAIN paragraph is worse than none — it tells a
 * reader not to look.
 *
 * The ONE field that does not travel unconditionally is `permissionMode:`,
 * which {@see Agent::fromPreset()} gates on provenance: a foreign preset's is
 * forced to {@see PermissionMode::Default} and a native preset's is honoured.
 * Both halves are proven here.
 *
 * What these still do NOT prove is that a palette BADGE exists. `Agent` has a
 * `$source` field now and `fromPreset()` copies it, so the tag reaches the
 * roster — but nothing renders it, which makes the remaining half of Phase 1
 * item 3 a call site rather than a field.
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
        // and lands in the DENY list, which Agent::fromPreset() DOES carry now
        // (`disallowedTools`) — the allow list is asserted here because it is
        // the half this test is about; the deny half is covered by
        // AgentPresetFieldCarryTest.
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
     * WHAT THE IMPORT CARRIES, AND WHAT STILL STOPS IT — and the bound here
     * CHANGED SHAPE TWICE, which is the part worth reading before trusting it.
     *
     * ROUND ONE, the original: {@see Agent} had no `permissionMode`,
     * `disallowedTools`, `maxTurns`, `memory`, `background`, `effort` or
     * `isolation` at all, so an imported preset declaring
     * `permissionMode: bypassPermissions` had nowhere to land on the roster.
     * That was the cheapest possible guarantee — a field that does not exist
     * cannot be read by anything, ever — and it was an ACCIDENT of
     * `fromPreset()` reading six of sixteen fields.
     *
     * ROUND TWO, and it was a REGRESSION: `fromPreset()` was widened to carry
     * all sixteen, because a bridge that silently keeps six makes the other
     * ten look broken rather than unwired. That deleted the bound and replaced
     * it with "nothing reads the field yet" — and this very test asserted the
     * escape as if it were a feature, with
     * `assertSame(PermissionMode::BypassPermissions, $agent->permissionMode)`.
     * Reproduced end-to-end through {@see Bootstrap::agentRoster()}: a
     * `.claude/agents/*.md` committed to a repository, read with no
     * `trustedProject*` opt-in of any kind, produced a rostered `Agent`
     * carrying `BypassPermissions`.
     *
     * ROUND THREE, what this asserts now: {@see Agent::fromPreset()} gates
     * THAT ONE FIELD on the provenance the preset already carries. A native
     * preset out of `.sugar-crush/agents` keeps the mode its author wrote; a
     * foreign one is forced to {@see PermissionMode::Default}. The other
     * fifteen fields still travel unconditionally — they describe an agent,
     * they do not decide what it may do — so the widening's actual purpose
     * survives. The bound is back to UNREPRESENTABLE for the path that
     * matters, at the cost of one conditional, and it no longer depends on
     * nobody ever adding a reader.
     *
     * Both halves are asserted, because a gate that also broke the native tier
     * would look identical from the foreign side.
     */
    public function testAnImportedPresetsPermissionModeIsForcedToDefaultOnTheRoster(): void
    {
        $dir = $this->repo . '/.claude/agents';
        mkdir($dir, 0755, true);
        file_put_contents(
            $dir . '/bypasser.md',
            "---\nname: bypasser\ndescription: Declares a mode.\npermissionMode: bypassPermissions\n---\n\nPROMPT BODY.\n",
        );

        $agent = $this->rosterEntry('bypasser');
        $this->assertNotNull($agent, 'the import still arrives');

        // The mapper really does understand the value — asserted so a green
        // result here can never mean "the frontmatter was ignored", which
        // would make the gate look effective while proving nothing.
        $preset = (new ForeignAgentPresetRegistry())->discover($this->repo)['bypasser'] ?? null;
        $this->assertNotNull($preset, 'fixture assumption: the foreign mapper parses this file');
        $this->assertSame(
            PermissionMode::BypassPermissions,
            $preset->permissionMode,
            'fixture assumption: the mapper kebab-cases bypassPermissions and resolves it',
        );

        $this->assertSame(
            PermissionMode::Default,
            $agent->permissionMode,
            'a repository-supplied preset must not raise its own permission mode on the roster',
        );

        // Every route OFF the Agent, not just the property. toArray() emits
        // permission_mode and json_encode() sees the public property, and a
        // gate that only held for one spelling would be no gate at all.
        $this->assertSame(PermissionMode::Default->value, $agent->toArray()['permission_mode']);
        $encoded = json_decode((string) json_encode($agent), true);
        $this->assertIsArray($encoded);
        $this->assertSame(PermissionMode::Default->value, $encoded['permissionMode']);

        foreach (
            ['permissionMode', 'disallowedTools', 'maxTurns', 'memory', 'background', 'effort', 'isolation'] as $field
        ) {
            $this->assertTrue(
                property_exists(Agent::class, $field),
                "Agent should carry {$field} now that fromPreset() reads all sixteen preset fields",
            );
        }
    }

    /**
     * THE OTHER HALF OF THE GATE: a NATIVE preset keeps its declared mode.
     *
     * Without this, {@see Agent::fromPreset()} forcing every preset to
     * {@see PermissionMode::Default} would pass the test above while quietly
     * breaking `.sugar-crush/agents` — the tier that is sugar-crush's own
     * configuration and the one a user writes deliberately. Asserted on the
     * value object rather than through a roster, because the native discovery
     * path is {@see \SugarCraft\Crush\Agents\AgentPresetRegistry}'s and is
     * covered by its own suite; what is under test here is the gate's
     * condition, not the tier's discovery.
     */
    public function testANativePresetsPermissionModeIsCarriedUngated(): void
    {
        $native = new AgentPreset(
            name: 'native-bypasser',
            description: 'Declares a mode.',
            initialPrompt: 'PROMPT BODY.',
            permissionMode: PermissionMode::BypassPermissions,
            source: SkillSource::Native,
        );

        $agent = Agent::fromPreset($native, 'anthropic', 'claude-sonnet-4-6');

        $this->assertSame(
            PermissionMode::BypassPermissions,
            $agent->permissionMode,
            'the gate is on provenance, not on the field — native config must still be honoured',
        );

        foreach ([SkillSource::Claude, SkillSource::Opencode, SkillSource::AgentSkillsSpec] as $foreign) {
            $this->assertSame(
                PermissionMode::Default,
                Agent::fromPreset(
                    new AgentPreset(
                        name: 'x',
                        description: 'x',
                        permissionMode: PermissionMode::BypassPermissions,
                        source: $foreign,
                    ),
                    'anthropic',
                    'claude-sonnet-4-6',
                )->permissionMode,
                "{$foreign->value} is repository-supplied content and must not raise its own mode",
            );
        }
    }

    /**
     * The census, now DEFENCE IN DEPTH rather than the only bound.
     *
     * {@see Agent::fromPreset()} gates a foreign preset's `permissionMode` to
     * {@see PermissionMode::Default} at construction, so a new reader can no
     * longer execute under a repository-supplied mode by accident. This census
     * still earns its place: it catches the reader arriving, which is the
     * moment to decide whether the NATIVE tier's mode should drive that code
     * path either, and it is the thing that would notice the gate being
     * removed and a reader added in the same change.
     *
     * THE NEEDLE COVERS THREE ROUTES, not one, and the first cut covered only
     * the first. `->permissionMode` is the direct property read. But
     * {@see Agent::toArray()} re-emits the same value under the snake_case key
     * `permission_mode`, and `Agent`'s properties are all public so
     * `json_encode($agent)` emits `"permissionMode"` with no method call at
     * all. MEASURED on the one-needle version: a reader planted in
     * `AgentManager::createSubAgent()` — the exact downstream the doc-blocks
     * name — reading `$agent->toArray()['permission_mode']` left this suite
     * fully GREEN. Neither `toArray()` nor `fromArray()` has an `Agent`-typed
     * caller today; both are documented as the persistence seam to be wired
     * later, which is precisely why the hole had to be closed before it was
     * used rather than after.
     *
     * The `json_encode()` route is NOT scanned, and the tail of this method
     * says why: a static needle for it MEASURED at six false positives, and
     * what actually closes it is `fromPreset()`'s provenance gate rather than
     * a census. The needles here scan the two routes a census can scan
     * honestly.
     *
     * If you are the one adding a reader: this test is not asking you not to.
     * It is asking that the gate be decided deliberately at that moment,
     * because the roster is fed from two repository-chosen directories that no
     * `trustedProject*` key gates.
     */
    public function testNoSourceFileOutsideAgentReadsAnAgentsPermissionMode(): void
    {
        $src = realpath(\dirname(__DIR__, 2) . '/src');
        self::assertIsString($src);

        $readers = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getPathname();
            $code = self::codeWithoutComments((string) file_get_contents($path));
            foreach (['->permissionMode', 'permission_mode'] as $needle) {
                if (str_contains($code, $needle)) {
                    $readers[] = str_replace('\\', '/', substr($path, \strlen($src) + 1));
                    break;
                }
            }
        }
        $readers = array_values(array_unique($readers));
        sort($readers);

        $this->assertSame(
            ['Agents/Agent.php'],
            $readers,
            'a new reader of Agent::$permissionMode appeared (directly or via the permission_mode '
                . 'array key); an imported .claude/agents preset can set it on the preset, '
                . 'so decide the gate before wiring the read',
        );

        // The comment-stripping is load-bearing rather than tidy, so it is
        // pinned by the file that proves it: ForeignAgentPresetRegistry's
        // doc-block quotes the needle while its code never reads it. Raw-byte
        // scanning reported it as a second reader, which is a false alarm that
        // invites weakening the needle.
        $registry = (string) file_get_contents($src . '/Agents/ForeignAgentPresetRegistry.php');
        $this->assertStringContainsString('->permissionMode', $registry, 'fixture assumption: the prose quotes it');
        $this->assertStringNotContainsString('->permissionMode', self::codeWithoutComments($registry));

        // THE THIRD ROUTE, asserted on BEHAVIOUR rather than on source text.
        // Agent's properties are all public, so json_encode() on one emits
        // "permissionMode" with no property read and no toArray() call for
        // either needle above to catch. A text census cannot close that: the
        // only honest static needle is "this file calls json_encode() and
        // mentions Agent", which MEASURED at six false positives
        // (AgentWorkerPool, ProcessExecutor, WorktreeManager, Chat,
        // BackgroundSupervisor, WorkflowEngine) — an allow-list that large is
        // one that gets appended to rather than read.
        //
        // What closes the route instead is Agent::fromPreset()'s provenance
        // gate: a foreign preset's mode is Default BEFORE any encoder sees it,
        // so every serialization of it carries Default no matter which route
        // reaches the field. That is asserted on the value, through the real
        // roster, by
        // testAnImportedPresetsPermissionModeIsForcedToDefaultOnTheRoster().
        // Pinned here is the premise that makes the route exist at all, so
        // that making the property non-public — which would silently retire
        // half of that test's coverage — reds this instead.
        $this->assertTrue(
            (new \ReflectionProperty(Agent::class, 'permissionMode'))->isPublic(),
            'if this is no longer public, json_encode() no longer exposes it and the gate test above '
                . 'is asserting a route that has stopped existing',
        );
    }

    /**
     * The source with every comment and doc-block removed, so the scrape above
     * matches real CODE rather than prose that quotes it.
     *
     * MEASURED, not defensive: the first cut of the census read raw file bytes
     * and immediately reported `Agents/ForeignAgentPresetRegistry.php` as a
     * second reader — because that class's doc-block *explains* the census and
     * quotes the very string it searches for. The natural "fix" for that is to
     * weaken the needle, which is the outcome worth avoiding; stripping
     * comments is what {@see \SugarCraft\Crush\Tests\Tools\BuiltInToolCorpusTest}'s
     * own source-text scrape already does for the same reason.
     */
    private static function codeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (\is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
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
     * A real launch, with all THREE backend-selection env vars cleared —
     * `$SUGARCRUSH_PROVIDER` and both shell-out variables, which is what the code
     * below does and what this sentence used to under-count as "two". Either
     * shell-out variable selects a `CommandBackend` and a different construction
     * path. Same dance {@see FeatWiringReachabilityTest} performs for the same
     * reason.
     */
    private function launchChat(): void
    {
        $provider = getenv('SUGARCRUSH_PROVIDER');
        $command = getenv('SUGARCRUSH_BACKEND_CMD');
        $streamCommand = getenv('SUGARCRUSH_BACKEND_CMD_STREAM');
        putenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_BACKEND_CMD');
        putenv('SUGARCRUSH_BACKEND_CMD_STREAM');

        try {
            Bootstrap::chat($this->repo);
        } finally {
            $provider === false ? putenv('SUGARCRUSH_PROVIDER') : putenv('SUGARCRUSH_PROVIDER=' . $provider);
            $command === false ? putenv('SUGARCRUSH_BACKEND_CMD') : putenv('SUGARCRUSH_BACKEND_CMD=' . $command);
            $streamCommand === false ? putenv('SUGARCRUSH_BACKEND_CMD_STREAM') : putenv('SUGARCRUSH_BACKEND_CMD_STREAM=' . $streamCommand);
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
