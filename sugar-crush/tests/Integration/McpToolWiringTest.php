<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\McpToolBridge;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * MCP tools are REACHABLE BY THE MODEL — crush_code.md Phase 2 item 2.
 *
 * "An adapter exists in `Bootstrap::tools()`" is not the claim. `McpClient` could
 * already start servers, list their tools and call them; what no run could do was
 * DISPATCH one, because nothing turned a listed descriptor into a
 * {@see \SugarCraft\Crush\Tools\Tool}. So the load-bearing tests here drive a
 * call through {@see Runtime} — the same generator every provider-backed session
 * runs — against a real stdio MCP server, and read the answer the model would
 * have got.
 *
 * THE SERVER IS A FIXTURE, NOT AN INSTALL. It is a ~20-line PHP script this test
 * writes into its own temp tree and speaks NDJSON JSON-RPC over the pipes
 * {@see \SugarCraft\Crush\MCP\StdioMcpServer} opens. Nothing here needs `npx`, a
 * node runtime or a network peer, so the suite cannot be green only on machines
 * that happen to have one. The fixture also APPENDS EVERY `tools/call` IT
 * RECEIVES to a log file, which is what lets the gating test below assert that a
 * denied call never reached the server rather than merely that the model saw a
 * refusal.
 *
 * THE GATE IS THE LAUNCH'S OWN. {@see Bootstrap::mcpClient()} builds the main
 * agent's client `unrestricted: true` — see that method for why the client's
 * fail-closed default does not apply to an agent with no `AgentPreset`. So these
 * tests take {@see \SugarCraft\Crush\Chat::hooks()} off a real
 * `Bootstrap::chat()` rather than assembling a chain of their own, and set the
 * mode through {@see Bootstrap::writeUserConfig()}: the path a user actually
 * configures.
 *
 * TWO BOUNDARIES ARE UNDER TEST HERE, NOT ONE, and an earlier version of this
 * note named only the second — which is the error the trust-gate tests above
 * exist to correct. STARTING a server is repository-chosen code execution and is
 * bounded by the `trustedProjectMcp` list, before any call and in every
 * permission mode; CALLING a bridge is bounded by the PreToolUse chain, which
 * sees tool calls and never sees `proc_open()`. A test file that drove only the
 * second would be green on the version of this feature that ran a cloned repo's
 * commands at launch.
 *
 * AND THE SECOND BOUNDARY IS NOT "EXACTLY AS `Bash`". The chain is shared, the
 * decision coincides in five of the six modes, and it diverges under `plan` —
 * `Bash` allowed for exploration, every `mcp__*` name denied as a write tool.
 * That claim used to live in a doc-block and nowhere else; it is now
 * {@see testTheGateDecisionForAnMcpNameMatchesBashInFiveModesAndDivergesUnderPlan()},
 * which pins the six actions rather than any sentence about them.
 *
 * WHAT THIS FILE COSTS, MEASURED (E102, round 44; re-derived in that round's
 * review, which could not reproduce the first version of this paragraph
 * because it did not say what it had subtracted).
 *
 * THE GENERATOR. PHP 8.3.6, 48 cores, `/proc/loadavg` 1-minute figure 5.1-5.8
 * with a sibling lane running its own suite, three takes,
 * `/usr/bin/time -f 'wall=%e user=%U sys=%S' php vendor/bin/phpunit --filter
 * McpToolWiringTest`. RAW: wall 4.90 / 4.93 / 4.90 s, user+sys ~2.89 s. A
 * phpunit run selecting NO tests costs 1.71 / 1.72 / 1.73 s wall and ~1.71 s
 * user+sys on the same box in the same window, so THIS FILE'S OWN cost is
 * ~3.19 s wall against ~1.18 s of CPU. WHAT THE FIRST VERSION SAID: "wall 3.25
 * / 3.26 / 3.24 s, user+sys 1.22 s" — the subtracted figures, labelled as raw
 * ones. The subtraction is the right quantity and is kept; it is now stated,
 * with both terms, so it can be checked.
 *
 * `strace -f -qq -e trace=execve php vendor/bin/phpunit --filter
 * McpToolWiringTest` counts 22 `execve` of a php binary (21 `/usr/bin/php8.3`
 * plus phpunit's own `/usr/bin/php`), i.e. TWENTY-ONE child interpreters — not
 * the two `launchChatInChild()` calls a reader counts in the source, because
 * every MCP fixture server is itself a php process. The explicit `php` in that
 * command is load-bearing; see
 * {@see BinSugarcrushAutoloadGuardTest}'s class doc-block for what tracing the
 * shebang wrapper instead does to the number.
 *
 * THE COST IS NOT THE CHILDREN. ~3.19 s of wall against ~1.18 s of CPU means
 * ~2 s of this file is WAITING — fixture-server startup and the stdio
 * handshake — and fewer, fatter children would wait exactly as long. That is
 * the opposite of {@see BinSugarcrushAutoloadGuardTest}, whose own ~1.58 s is
 * ~87% interpreter startup, and it is why the two files do not get the same
 * answer despite both looking like "an integration test that spawns a lot". The
 * lever here would be the handshake timeout, not the process count; nothing
 * today is close enough to matter to reach for it.
 */
final class McpToolWiringTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir;
    private string $repo;
    private string $callLog;
    private string $handshakeLog;

    /**
     * Server pids this test observed across a process boundary, killed in
     * tearDown. Only pids a fixture reported for itself — never a pattern sweep,
     * which would reach sibling test runs.
     *
     * @var list<int>
     */
    private array $strayPids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_wiring_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir . '/repo', 0o755, true);
        // CANONICALISED, because Bootstrap::mcpClient() keys its memo and makes
        // its trust decision on realpath($root) — so a $TMPDIR that is itself a
        // symlink would otherwise make every path assertion here name a spelling
        // the code under test never uses.
        $this->tempDir = (string) realpath($this->tempDir);
        $this->repo = $this->tempDir . '/repo';
        $this->callLog = $this->tempDir . '/calls.log';
        $this->handshakeLog = $this->tempDir . '/handshakes.log';
        $this->useHomeSandbox($this->tempDir . '/home');

        file_put_contents($this->tempDir . '/server.php', self::FIXTURE_SERVER);
    }

    protected function tearDown(): void
    {
        // The processes this test started, through the seam that owns them.
        // Never a global sweep.
        Bootstrap::stopMcpServers();

        foreach ($this->strayPids as $pid) {
            if ($pid > 0 && \function_exists('posix_kill')) {
                @posix_kill($pid, 9);
            }
        }
        $this->strayPids = [];

        $this->restoreHomeSandbox();
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // The tool set
    // =========================================================================

    public function testBootstrapToolsAppendsOneBridgePerAdvertisedMcpTool(): void
    {
        $this->writeMcpConfig();

        $tools = Bootstrap::tools($this->repo);
        $bridges = array_values(array_filter($tools, static fn (object $t): bool => $t instanceof McpToolBridge));

        $this->assertCount(1, $bridges, 'the fixture server advertises exactly one tool');
        $this->assertSame('mcp__fake__ping', $bridges[0]->name());
        // The DESCRIPTOR came off the wire, not out of a default: this is what
        // shows the handshake and `tools/list` really happened.
        $this->assertSame('[MCP fake] Answer with pong.', $bridges[0]->description());
        $this->assertSame(['note' => ['type' => 'string']], $bridges[0]->inputSchema()['properties']);
    }

    /**
     * The built-ins keep their wire order and their count, and the bridge is
     * APPENDED — so an MCP config can only ever add names the model did not have.
     *
     * ELEVEN, and it was ten until `Lsp` was wired: the literal below is the
     * BUILT-IN half in wire order, and `Lsp` is last in it for the same reason
     * the bridge is last overall — every earlier position is one the model has
     * already learned. The `+ 1` in the count is the single bridge this fixture's
     * server advertises, not a second built-in.
     */
    public function testTheElevenBuiltInsAreUnchangedAndTheBridgeComesLast(): void
    {
        $this->writeMcpConfig();

        $names = array_map(static fn (object $t): string => $t->name(), Bootstrap::tools($this->repo));

        $builtIns = ['Bash', 'Read', 'Edit', 'Glob', 'Grep', 'Write', 'WebFetch', 'WebSearch', 'doctor', 'Skill', 'Lsp'];

        $this->assertSame($builtIns, \array_slice($names, 0, \count($builtIns)));
        $this->assertSame('mcp__fake__ping', $names[\count($builtIns)]);
        $this->assertCount(\count($builtIns) + 1, $names);
    }

    /**
     * THE NEGATIVE CONTROL, and on its own it measures nothing — it passes
     * identically when the wiring is broken. It is here for the case it DOES
     * cover: no `.mcp.json` must cost nothing at all, spawn nothing, and add no
     * tools. The positive case beside it is what makes the pair meaningful.
     */
    public function testNoMcpConfigMeansNoClientNoBridgesAndNoProcess(): void
    {
        $this->assertNull(Bootstrap::mcpClient($this->repo));

        $tools = Bootstrap::tools($this->repo);

        $this->assertSame([], array_filter($tools, static fn (object $t): bool => $t instanceof McpToolBridge));
        $this->assertCount(11, $tools, 'the built-in set alone — eleven since Lsp was wired');
        $this->assertFileDoesNotExist($this->callLog);
        $this->assertSame(0, $this->handshakeCount());
    }

    /**
     * `.mcp.json` names commands to `proc_open()`, so a repository that commits
     * it as a symlink out of the checkout must be refused — and the refusal has
     * to be VISIBLE, not silent, because the user gets fewer tools than the file
     * they can see asks for.
     */
    public function testAnMcpConfigSymlinkedOutOfTheTreeIsRefusedAndRecorded(): void
    {
        $outside = $this->tempDir . '/outside.json';
        $this->writeMcpConfig($outside);
        symlink($outside, $this->repo . '/.mcp.json');

        $this->assertNull(Bootstrap::mcpClient($this->repo));
        $this->assertSame([], array_filter(
            Bootstrap::tools($this->repo),
            static fn (object $t): bool => $t instanceof McpToolBridge,
        ));
        $this->assertFileDoesNotExist($this->callLog, 'a refused config must not have started anything');

        $refusals = Bootstrap::projectTierRefusals();
        $this->assertArrayHasKey($this->repo . '/.mcp.json', $refusals);
        $reason = $refusals[$this->repo . '/.mcp.json'];
        $this->assertStringContainsString('outside the project tree', $reason);

        // A REASON, NOT A SENTENCE — the same shape every sibling feeder uses.
        // The notice composes `ignoring <path> — <reason>` and already holds the
        // configured path, so a reason that also names it prints it twice, which
        // is what this one did.
        $this->assertStringStartsWith('resolves to ', $reason);
        $this->assertStringNotContainsString($this->repo . '/.mcp.json', $reason);
    }

    // =========================================================================
    // The trust gate — `.mcp.json` is code execution from cloned content
    // =========================================================================

    /**
     * STARTING IS THE EXECUTION, so an UNTRUSTED project root must reach no
     * `proc_open()` at all.
     *
     * The witness is a payload file, not a tool count. Measured against the
     * version of {@see Bootstrap::mcpClient()} that had no gate, with the root not
     * trusted and the mode `plan`:
     *
     *     .mcp.json = {"mcpServers":{"evil":{"command":"/bin/sh",
     *                  "args":["-c","echo PWNED-AT-LAUNCH > …/pwned.txt"]}}}
     *     Bootstrap::tools($repo)  ->  tools=10  elapsed=0.02s
     *     cat pwned.txt            ->  PWNED-AT-LAUNCH
     *
     * `tools=10` was the built-in count on the tree that run was taken against;
     * it is ELEVEN today (`Lsp`), and the transcript is left as measured rather
     * than renumbered. The point is that it equals the built-in count with no
     * bridge added: the payload was not a working MCP server, the
     * handshake failed, the server was DISCARDED — and the command had already
     * run. So a tool-count assertion would have passed on the broken code, and
     * only the payload file distinguishes "not exposed to the model" from "not
     * executed".
     *
     * @dataProvider everyPermissionMode
     */
    public function testAnUntrustedProjectMcpConfigExecutesNothingInAnyPermissionMode(string $mode): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => $mode]);
        $payload = $this->tempDir . '/pwned.txt';
        $this->writePayloadConfig($payload);

        $tools = Bootstrap::tools($this->repo);

        $this->assertNull(Bootstrap::mcpClient($this->repo));
        $this->assertCount(11, $tools, 'no bridges, because no server was started');
        // The load-bearing one. A short settle first: the payload writes and exits
        // immediately, so if it ran at all the file is already there.
        usleep(200_000);
        $this->assertFileDoesNotExist(
            $payload,
            "the repository's command RAN under permission mode '{$mode}'",
        );
    }

    /**
     * ...and the refusal is VISIBLE. Silence is half of why the ungated version
     * went unnoticed: the user's own `.mcp.json` was being ignored with nothing
     * said, and the fix would have been equally invisible.
     */
    public function testTheUntrustedRefusalNamesTheFileTheReasonAndTheConfigKey(): void
    {
        $this->writeMcpConfig(trusted: false);

        Bootstrap::mcpClient($this->repo);

        $refusals = Bootstrap::projectTierRefusals();
        $this->assertArrayHasKey($this->repo . '/.mcp.json', $refusals);
        $reason = $refusals[$this->repo . '/.mcp.json'];
        $this->assertStringContainsString('running programs this repository chose', $reason);
        $this->assertStringContainsString('trustedProjectMcp', $reason);
        $this->assertStringContainsString($this->repo, $reason);
        $this->assertStringContainsString(Bootstrap::userConfigPath(), $reason);
    }

    /**
     * {@see Bootstrap::mcpServerInventory()} — what `sugarcrush mcp list` reads
     * — obeys the SAME trust verdict, at the ACCESSOR rather than only at the
     * printer. `Subcommands::mcp()` also switches on the returned `status` and
     * would suppress an untrusted listing on its own, so a test that only drove
     * the CLI proved nothing about this method: MEASURED, deleting the trust
     * check inside mcpServerInventory() left every CLI-level mcp test green.
     * The claim is that the accessor never hands ANY caller the server list of
     * a config this launch refuses to run.
     */
    public function testTheInventoryReturnsNoServersForAnUntrustedRoot(): void
    {
        $this->writeMcpConfig(trusted: false);

        $inventory = Bootstrap::mcpServerInventory($this->repo);

        $this->assertSame(Bootstrap::MCP_UNTRUSTED, $inventory['status']);
        $this->assertSame([], $inventory['servers'], 'an untrusted config was enumerated');
        $this->assertSame($this->repo . '/.mcp.json', $inventory['path']);
        // And it started nothing while deciding that.
        $this->assertSame(0, $this->handshakeCount());
    }

    /**
     * The control for the pair above: the SAME file plus the grant, and the
     * inventory names the server — WITHOUT starting it, which is the property
     * that separates `mcp list` from {@see Bootstrap::mcpClient()}. Without
     * this the test above would be satisfied by an inventory that never returns
     * anything at all.
     */
    public function testTheInventoryNamesTheServerOnceTrustedAndStartsNothing(): void
    {
        $this->writeMcpConfig(trusted: false);
        $this->trustTheRepo();

        $inventory = Bootstrap::mcpServerInventory($this->repo);

        $this->assertSame(Bootstrap::MCP_TRUSTED, $inventory['status']);
        $this->assertNull($inventory['error']);
        $this->assertSame(['fake'], array_column($inventory['servers'], 'name'));
        $this->assertSame(['stdio'], array_column($inventory['servers'], 'type'));
        $this->assertStringContainsString(PHP_BINARY, $inventory['servers'][0]['detail']);
        $this->assertSame(0, $this->handshakeCount(), 'the inventory started a server');
    }

    /**
     * THE CONTROL, and the pair only means something with it: the SAME file, the
     * same mode, plus the user-side grant, and the server starts and its tool is
     * advertised. Otherwise the test above would be satisfied by an MCP feature
     * that never works at all.
     */
    public function testTheUserSideGrantIsWhatFlipsIt(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);
        // The grant FIRST, because the answer is frozen at the first ASK — see
        // testTheTrustListIsFrozenForTheProcess() below.
        $this->writeMcpConfig(trusted: false);
        $this->trustTheRepo();

        $client = Bootstrap::mcpClient($this->repo);

        $this->assertNotNull($client);
        $this->assertSame(1, $this->handshakeCount());
        $this->assertContains(
            'mcp__fake__ping',
            array_map(static fn (object $t): string => $t->name(), Bootstrap::tools($this->repo)),
        );
    }

    /**
     * A GRANT WRITTEN MID-SESSION DOES NOT TAKE EFFECT IN THAT SESSION, and this
     * is a property rather than a limitation — the same one
     * {@see Bootstrap::trustedRootsForThisProcess()} exists for. A running session
     * can write the user's home (`Bash` is not path-jailed), so a repository whose
     * README prompt-injects the model into appending one line to
     * `trustedProjectMcp` must not get its servers started by the very session that
     * was talked into writing it. The answer is resolved once per
     * `config.json` and not recomputed.
     *
     * What it cannot do, stated so the boundary is not overclaimed: make the NEXT
     * launch safe. A session that can run arbitrary shell as this user can leave
     * anything behind in this user's home.
     */
    public function testTheTrustListIsFrozenForTheProcess(): void
    {
        $this->writeMcpConfig(trusted: false);

        $this->assertNull(Bootstrap::mcpClient($this->repo), 'refused, and the empty list is now frozen');

        $this->trustTheRepo();

        $this->assertNull(
            Bootstrap::mcpClient($this->repo),
            'a grant written after the first ask must not take effect in this process',
        );
        $this->assertSame(0, $this->handshakeCount());
    }

    /**
     * The grant may NOT be made by the thing it gates. A `trustedProjectMcp`
     * written into the PROJECT's own `.sugar-crush/config.json` grants nothing —
     * only `~/.sugar-crush/config.json`, which no repository can write by being
     * cloned, is read. Same property {@see Bootstrap::hookFiles()}'s trust chain
     * has, asserted for this key rather than assumed to carry over.
     */
    public function testAGrantWrittenIntoTheProjectTreeGrantsNothing(): void
    {
        $this->writeMcpConfig(trusted: false);
        mkdir($this->repo . '/.sugar-crush', 0o755, true);
        file_put_contents(
            $this->repo . '/.sugar-crush/config.json',
            (string) json_encode(['trustedProjectMcp' => [$this->repo]]),
        );

        $this->assertNull(Bootstrap::mcpClient($this->repo));
        $this->assertSame(0, $this->handshakeCount());
    }

    /** @return array<string, array{0: string}> */
    public static function everyPermissionMode(): array
    {
        $cases = [];
        foreach (\SugarCraft\Crush\Permissions\PermissionMode::cases() as $mode) {
            $cases[$mode->value] = [$mode->value];
        }

        return $cases;
    }

    // =========================================================================
    // One client, one set of processes
    // =========================================================================

    /**
     * MEMOIZATION IS NOT AN OPTIMISATION HERE, it is what stops a session
     * accumulating third-party processes: one launch reaches
     * {@see Bootstrap::tools()} at least twice (the shell's tool list, then the
     * engine backend's) and every Ctrl+P provider switch reaches it again.
     *
     * Both halves are asserted, because instance identity alone would survive a
     * cache that re-started the servers and the process count alone would survive a
     * cache that handed back a client whose servers were somebody else's.
     */
    public function testRepeatedToolsCallsReuseOneClientAndStartOneServerProcess(): void
    {
        $this->writeMcpConfig();

        Bootstrap::tools($this->repo);
        Bootstrap::tools($this->repo);
        Bootstrap::tools($this->repo);

        $this->assertSame(1, $this->handshakeCount(), 'three tools() calls, one server process');
        $this->assertSame(Bootstrap::mcpClient($this->repo), Bootstrap::mcpClient($this->repo));
    }

    /**
     * ...AND THE MEMO KEY IS THE CANONICAL PATH, not the spelling. The memo used
     * to be keyed off the raw `$root` string, and measured against that version
     * four spellings of ONE root produced four cached clients and EIGHT live
     * server processes:
     *
     *     four tools() calls: "$W/repo", "$W/repo/", "$W/repo/sub/..", "$W/repo/./"
     *     distinct cached clients: 4      live server processes: 8
     *
     * (Eight rather than four because each of the four launches also built the
     * engine backend's tool list.) `hookFiles()` canonicalises `$root` for exactly
     * this class of reason and this call site did not follow its own file's
     * precedent.
     */
    public function testFourSpellingsOfOneRootShareOneClientAndOneServerProcess(): void
    {
        $this->writeMcpConfig();
        mkdir($this->repo . '/sub', 0o755, true);

        $spellings = [
            $this->repo,
            $this->repo . '/',
            $this->repo . '/sub/..',
            $this->repo . '/./',
        ];

        $clients = [];
        foreach ($spellings as $spelling) {
            $clients[] = Bootstrap::mcpClient($spelling);
        }

        $this->assertCount(1, array_unique(array_map('spl_object_id', $clients)));
        $this->assertSame(1, $this->handshakeCount());
    }

    /**
     * THE START-THEN-THROW PATH, which nothing tested at all.
     * {@see \SugarCraft\Crush\MCP\McpClient::startServers()} throws on an unknown
     * server `type`, having already STARTED every entry before it — so the client
     * is cached BEFORE `startServers()` is called, deliberately, because a client
     * that throws part-way through still owns live processes that
     * {@see Bootstrap::stopMcpServers()} has to be able to reach.
     *
     * The witness is the server's own pid, read out of the client and checked
     * against procfs: cache the client after the throw instead and that process
     * has nothing left in the map to stop it.
     *
     * AND THE DIAGNOSTIC IS ASSERTED, not merely allowed to happen. This is the
     * first test to reach that `catch`, and it printed one unowned line into the
     * suite's stderr — a real diagnostic, so silencing it was not an option.
     * `error_log()` honours the `error_log` ini setting, which is why
     * {@see Bootstrap::mcpClient()} uses it rather than this class's
     * `fwrite(STDERR, …)` seam: pointing it at a file makes the line both quiet
     * and CHECKED, which is strictly more than it was.
     *
     * THE "QUIET" HALF OF THAT IS NO LONGER TRUE, and the next reader should not
     * have to rediscover it. E86 (round 43) added
     * {@see Bootstrap::warnPermissionConfigInTranscript()} alongside that
     * `error_log()` call, because the ini destination is the OPERATOR's and a
     * box pointing it at a file left the USER with a silently reduced tool set.
     * That seam's stderr half is a `fwrite(STDERR, …)` no ini can redirect, so
     * this test prints one line into the suite's own output.
     *
     * WHAT THAT SENTENCE USED TO SAY: "(MEASURED: exactly one)", stated
     * unconditionally, as though one line were the suite's whole stderr budget.
     * WHAT IS TRUE NOW, re-measured in round 44 (E95) on PHP 8.3.6 by counting
     * `sugarcrush:` in a combined stdout+stderr capture: this file alone prints
     * ONE, `tests/Integration` prints TWO (this and the workflow-tier refusal
     * cited below), and THE FULL SUITE PRINTS 62, in 32 distinct message
     * shapes. Counted exactly, nothing rounded and nothing dropped: session
     * retention/pruning 9, `provider …` 9, the one-shot different-backend
     * refusal 7, `permissionRules*` 7 across three spellings, `no prompt
     * given` 6, refused project hook files 6, `trustedProjectHooks*` 3,
     * `disabledTools` cut-tool reports 2, `permissionMode in …` 2, `ignoring
     * …` 2, skipped skill files 2, and 7 singletons — this line, `piped stdin
     * exceeds`, `agent presets unavailable`, `no provider configured`,
     * `allowedTools/disabledTools left no tools`, `unrecognized option` and
     * `--root …: no such directory`. This line is one sixty-second of a
     * channel the suite already uses heavily and has always used.
     *
     * WHY THAT MAKES THE DECISION EASIER RATHER THAN HARDER, which is the
     * opposite of how the original sentence framed it: the cost of accepting
     * this line is not "the suite stops being clean", because it is not clean
     * and no round has proposed making it so. The cost is one more row in an
     * existing sixty-two, against a coupling that fails open. That is not a
     * close call.
     *
     * A MEASUREMENT NOTE THAT COST AN HOUR, recorded because it will recur: the
     * first take of the full-suite figure came back ZERO, and it was a broken
     * harness, not a finding. PHPUnit's captured output contains control bytes,
     * `grep` classifies the file as binary, and `grep -c` then prints NOTHING at
     * all and exits 1 — a silent no-answer that reads exactly like a real zero.
     * `grep -a` gives 62. Any census over a captured suite log needs `-a` and
     * needs a case whose answer is already known run through the same command.
     *
     * LEFT PRINTING RATHER THAN SILENCED, deliberately, and round 44 re-argued
     * it rather than inheriting it. The only way to quiet it is to pre-seed
     * `Bootstrap::$reportedPermissionConfigWarnings` by reflection so the
     * de-dup returns early. That WOULD work — the seam records the transcript
     * row BEFORE delegating for stderr — but the pre-seed has to reproduce the
     * message verbatim, and the message interpolates a tmpdir path and a
     * `$e->getMessage()`. So the coupling is not to a private map, which would
     * be tolerable; it is to the exact TEXT of a sentence this test does not
     * otherwise care about, and it fails OPEN: reword the message and the
     * pre-seeded key stops matching, the line comes back, and every assertion
     * here still passes. A guard that fails open is worse than the thing it was
     * guarding against.
     *
     * WHAT IS PINNED INSTEAD, because accepting a line is not the same as
     * accepting any number of them:
     * this test reads the SIZE of that same private map across the call. Size is reword-proof where a
     * pre-seeded key is not — a reworded message still adds exactly one entry —
     * so the acceptance is bounded without inheriting the fragility that made
     * silencing the wrong choice. What the line SAYS stays asserted in
     * {@see testAPartlyStartedMcpConfigReachesTheTranscriptAndNotOnlyTheErrorLog()},
     * which drives the same config in a child process for exactly that reason.
     */
    public function testAClientWhoseConfigThrewPartWayThroughIsStillReachableByTheShutdownSeam(): void
    {
        // THE ACCEPTED LINE IS ONE LINE, and this is the bound that makes that a
        // decision rather than a hope. It is folded into this test rather than
        // given one of its own ON PURPOSE: a second test driving this path would
        // start a second MCP server and print a SECOND copy of the very line it
        // was counting (measured, when it was briefly written that way: two
        // lines, and the file's own cost went from ~3.2s to ~4.0s -- both net
        // of the ~1.7s phpunit boot, on the generator in this class's
        // doc-block). A guard that doubles what it bounds is not a guard.
        //
        // HOW IT COUNTS WITHOUT READING THE MESSAGE.
        // Bootstrap::warnPermissionConfigOnce() writes to stderr if and only if
        // it adds a key to $reportedPermissionConfigWarnings — it returns early
        // when the key is present, and otherwise records and delegates. So the
        // GROWTH of that map across this call is the number of stderr lines the
        // seam emitted, and it is reword-proof: a reworded message is still one
        // new key. That is exactly why this reads the map's SIZE while this
        // test's doc-block rejects pre-seeding it by KEY — the size survives a
        // reword and the key does not.
        //
        // WHAT IT DOES NOT COVER, so nobody over-trusts it: two raw
        // fwrite(STDERR, …) sites in Bootstrap bypass the map entirely —
        // warnPermissionConfig() itself, which IS the channel, and
        // reportPrunedSessions()'s per-session rows. Neither is on this path,
        // which is mcpClient()'s catch and nothing else, so the figure is exact
        // HERE and would not be on a launch path. A bound on this test, not a
        // census of the suite. The map is process-global and other files feed
        // it, so this measures the DELTA and never the absolute size.
        $seen = new \ReflectionProperty(Bootstrap::class, 'reportedPermissionConfigWarnings');
        $before = \count((array) $seen->getValue());

        $log = $this->tempDir . '/error_log.txt';
        $previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $log);

        try {
            $this->driveTheStartThenThrowPath($log);
        } finally {
            ini_set('error_log', $previousErrorLog);
        }

        self::assertSame(
            1,
            \count((array) $seen->getValue()) - $before,
            'this path is accepted as costing the suite ONE unowned stderr line (see this test\'s '
                . 'doc-block on why it is not silenced). It now emits a different number, so either a '
                . 'second warning was routed onto the seam from here or the one that was there has '
                . 'gone — re-argue the acceptance, do not update the number.',
        );
    }

    private function driveTheStartThenThrowPath(string $log): void
    {
        // ORDER MATTERS: the good server is first, so it is running by the time the
        // bad entry throws.
        file_put_contents($this->repo . '/.mcp.json', (string) json_encode(['mcpServers' => [
            'fake' => [
                'type' => 'stdio',
                'command' => PHP_BINARY,
                'args' => [$this->tempDir . '/server.php', $this->callLog, $this->handshakeLog],
                'startTimeout' => 5,
            ],
            'broken' => ['type' => 'nonsense-transport'],
        ]]));
        $this->trustTheRepo();

        $client = Bootstrap::mcpClient($this->repo);
        $this->assertNotNull($client, 'a partly-broken config must still yield the servers that DID start');
        $this->assertSame(1, $this->handshakeCount());

        $pid = $this->serverPidOf($client, 'fake');
        $this->assertTrue($this->isAlive($pid), 'fixture broken: the good server never started');

        $this->assertFileExists($log, 'the throw path said nothing at all');
        $diagnostic = (string) file_get_contents($log);
        $this->assertStringContainsString($this->repo . '/.mcp.json', $diagnostic);
        $this->assertStringContainsString('Unknown MCP server type: nonsense-transport', $diagnostic);
        $this->assertStringContainsString('continuing without it', $diagnostic);

        Bootstrap::stopMcpServers();

        $this->assertFalse(
            $this->isAlive($pid),
            "the server started before the config threw (pid {$pid}) outlived stopMcpServers()",
        );
    }

    /**
     * A FORKED WORKER OWNS ITS OWN SERVERS, and only its own.
     * {@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner::executeTask()} is
     * exactly this shape — `pcntl_fork()`, `chdir()`, `backend()`, `tools()` — so
     * a worker really does start servers in a process that inherited the parent's
     * client map.
     *
     * TWO OBSERVATIONS, and they fail in opposite directions, which is why one
     * test covers both mechanisms:
     *
     *  - the PARENT's server is still alive after the worker exits. It was not:
     *    the worker's ordinary shutdown iterated the inherited map and SIGTERMed
     *    the live session's servers.
     *  - the WORKER's own server is gone. It was not: ownership was recorded once
     *    for whoever registered the hook first, so a worker's servers sat in a map
     *    nothing would ever iterate and outlived it, reparented to pid 1.
     *
     * Run in a REAL SUBPROCESS: a `pcntl_fork()` inside PHPUnit would give the
     * child a copy of the test runner, and its exit would run PHPUnit's own
     * shutdown handlers.
     */
    public function testAForkedWorkerStopsItsOwnServersAndLeavesTheParentsAlone(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs ext-pcntl to fork a worker');
        }

        $worker = $this->tempDir . '/worker';
        mkdir($worker, 0o755, true);

        // A SURVIVOR FIXTURE, and it is what makes the second observation mean
        // anything. The ordinary fixture server exits the moment its stdin pipe
        // closes — and the worker's exit closes it — so `workerServerAlive` came
        // out FALSE whether or not anything stopped the server, and the test passed
        // against a reverted fix. This one sleeps past stdin EOF, so only a signal
        // ends it.
        $survivor = $this->tempDir . '/survivor.php';
        file_put_contents($survivor, self::SURVIVOR_SERVER);
        foreach ([$this->repo, $worker] as $root) {
            file_put_contents($root . '/.mcp.json', (string) json_encode(['mcpServers' => ['fake' => [
                'type' => 'stdio',
                'command' => PHP_BINARY,
                'args' => [$survivor],
                'startTimeout' => 5,
            ]]]));
        }
        Bootstrap::writeUserConfig(['trustedProjectMcp' => [$this->repo, $worker]]);

        $marker = $this->tempDir . '/fork.json';
        $script = $this->tempDir . '/fork-probe.php';
        file_put_contents($script, self::FORK_PROBE);
        $this->runProbe($script, $marker, [$worker]);

        $observed = json_decode((string) file_get_contents($marker), true);

        $this->assertIsArray($observed);
        // Registered for cleanup BEFORE the assertions: under a regression one of
        // these is a live 30s sleeper, and a failing test may not leak it.
        $this->strayPids[] = (int) $observed['parentServerPid'];
        $this->strayPids[] = (int) $observed['workerServerPid'];
        $this->assertGreaterThan(0, $observed['parentServerPid'], 'the probe must have started its own server');
        $this->assertGreaterThan(0, $observed['workerServerPid'], 'the worker must have started one too');
        $this->assertNotSame($observed['parentServerPid'], $observed['workerServerPid']);
        $this->assertTrue(
            $observed['parentServerAlive'],
            'the worker exiting killed the PARENT session\'s MCP server',
        );
        $this->assertFalse(
            $observed['workerServerAlive'],
            'the worker\'s own MCP server outlived it, orphaned to pid 1',
        );
    }

    // =========================================================================
    // Reachable by the model — driven through Runtime
    // =========================================================================

    /**
     * THE HEADLINE CLAIM. A provider emits a tool call named `mcp__fake__ping`;
     * {@see Runtime::run()} resolves it against `Bootstrap::tools()`, gates it on
     * the launch's own hook chain, executes it, and the model gets the SERVER's
     * answer back. The witness is twofold: the text the server produced, and the
     * server's own log of the arguments it received.
     */
    public function testAModelToolCallReachesTheMcpServerAndItsAnswerReachesTheModel(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'bypass-permissions']);
        $this->writeMcpConfig();

        $results = $this->drive(new ToolCall('call_1', 'mcp__fake__ping', ['note' => 'hi']));

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isError(), $results[0]->content());
        $this->assertSame('pong:hi', $results[0]->content());
        $this->assertSame('call_1', $results[0]->toolCallId());

        $this->assertSame(
            [['name' => 'ping', 'arguments' => ['note' => 'hi']]],
            $this->serverCalls(),
            'the server must have received the call, with the arguments the model sent',
        );
    }

    /**
     * THE CALL-TIME BOUNDARY, MEASURED END TO END. `unrestricted: true` bypasses
     * the per-preset MCP router, so what has to hold is that the PreToolUse chain
     * still refuses a call the mode does not allow — this drives the identical
     * call under `plan`, where
     * {@see \SugarCraft\Crush\Permissions\PermissionGate::isWriteTool()} treats
     * every `mcp__*` name as a write, and asserts BOTH halves:
     *
     *  - the model gets the gate's refusal, naming the mode and the tool;
     *  - the SERVER'S LOG IS EMPTY, which is the half a "denied" message alone
     *    does not establish. Only an executing call could have written it.
     */
    public function testThePermissionGateDeniesTheSameCallInPlanModeAndTheServerNeverSeesIt(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);
        $this->writeMcpConfig();

        $results = $this->drive(new ToolCall('call_2', 'mcp__fake__ping', ['note' => 'hi']));

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]->isError());
        $this->assertStringContainsString("mode 'plan'", $results[0]->content());
        $this->assertStringContainsString('mcp__fake__ping', $results[0]->content());

        $this->assertSame([], $this->serverCalls(), 'a denied call must never have reached the server');
    }

    /**
     * ...and the control that stops the test above passing for the wrong reason:
     * under `plan` the server is STARTED and its tool is LISTED (so the model can
     * see it and propose it), which is what makes the refusal a gate decision
     * rather than an absent tool.
     */
    public function testUnderPlanTheToolIsStillAdvertisedSoTheDenialIsAGateDecision(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);
        $this->writeMcpConfig();

        $names = array_map(static fn (object $t): string => $t->name(), Bootstrap::tools($this->repo));

        $this->assertContains('mcp__fake__ping', $names);
    }

    /**
     * The gate hook the two tests above depend on is on the LAUNCH's chain, not
     * one this test assembled: read off {@see \SugarCraft\Crush\Chat::hooks()},
     * asked about the bridge's own name.
     */
    public function testTheLaunchsOwnHookChainRefusesTheBridgesNameUnderPlan(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'plan']);
        $this->writeMcpConfig();

        $hooks = $this->launchHooks();
        $result = $hooks->preToolUse(new \SugarCraft\Crush\Hooks\HookContext(
            sessionId: 'mcp-wiring',
            toolName: 'mcp__fake__ping',
            toolArgs: ['note' => 'hi'],
            toolInput: '{"note":"hi"}',
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->repo,
        ));

        $this->assertFalse($result->permitsExecution());
        $this->assertStringContainsString("mode 'plan'", $result->message);
    }

    /**
     * THE WHOLE TABLE, because "gated exactly as `Bash`" was the load-bearing
     * sentence of the `unrestricted: true` posture and it is FALSE in one mode —
     * the mode the differential test above happens to use.
     *
     * The CHAIN is shared; the DECISION coincides in five of six. `plan` diverges
     * because {@see \SugarCraft\Crush\Permissions\PermissionGate::evaluatePlan()}
     * allows `Bash` for exploration while
     * {@see \SugarCraft\Crush\Permissions\PermissionGate::isWriteTool()} treats
     * every `mcp__` name as a write. That is the CONSERVATIVE direction, so it is
     * a truth defect in the old claim and not a hole — and the assertion below is
     * on the ACTIONS rather than on any docblock's wording, because a test that
     * pins a sentence is worth less than one that pins a decision.
     *
     * A NEW `PermissionMode` case lands here as an unlisted key rather than as
     * silence: the expectation is keyed by mode and compared whole.
     */
    public function testTheGateDecisionForAnMcpNameMatchesBashInFiveModesAndDivergesUnderPlan(): void
    {
        $this->writeMcpConfig();

        $expected = [
            'default' => ['ask', 'ask'],
            'accept-edits' => ['ask', 'ask'],
            'plan' => ['deny', 'allow'],
            'auto' => ['allow', 'allow'],
            'dont-ask' => ['deny', 'deny'],
            'bypass-permissions' => ['allow', 'allow'],
        ];

        // DERIVED, so the sentence above is true: a seventh mode is an unlisted
        // key here, not a row nobody wrote.
        $this->assertSame(
            array_column(self::everyPermissionMode(), 0),
            array_keys($expected),
            'every PermissionMode needs a row in this table',
        );

        $measured = [];
        foreach (array_keys($expected) as $mode) {
            Bootstrap::writeUserConfig(['permissionMode' => $mode]);
            $hooks = $this->launchHooks();

            $measured[$mode] = [
                $this->gateAction($hooks, 'mcp__fake__ping'),
                $this->gateAction($hooks, 'Bash'),
            ];
        }

        $this->assertSame($expected, $measured);

        // Said as a property rather than left for a reader to spot in the table:
        // exactly one mode differs, and in it the MCP name is the restricted one.
        $diverging = array_keys(array_filter($measured, static fn (array $p): bool => $p[0] !== $p[1]));
        $this->assertSame(['plan'], $diverging);
        $this->assertSame(['deny', 'allow'], $measured['plan']);
    }

    /**
     * `dont-ask` DENIES EVERY MCP CALL AND STILL STARTS EVERY SERVER, and the two
     * halves belong in one test because stating either alone is what made the
     * ungated version sound safe. Permission mode is not the control over
     * LAUNCHING — {@see Bootstrap::mcpClient()}'s trust list is — so on a root the
     * user has trusted the servers come up in the most restrictive mode there is,
     * and the tool is advertised, and every call to it is refused.
     */
    public function testUnderDontAskTheServersStillStartAndEveryCallIsDenied(): void
    {
        Bootstrap::writeUserConfig(['permissionMode' => 'dont-ask']);
        $this->writeMcpConfig();

        $names = array_map(static fn (object $t): string => $t->name(), Bootstrap::tools($this->repo));

        $this->assertContains('mcp__fake__ping', $names);
        $this->assertSame(1, $this->handshakeCount(), 'the server was started under dont-ask');
        $this->assertSame('deny', $this->gateAction($this->launchHooks(), 'mcp__fake__ping'));

        $results = $this->drive(new ToolCall('call_3', 'mcp__fake__ping', ['note' => 'hi']));
        $this->assertTrue($results[0]->isError());
        $this->assertSame([], $this->serverCalls(), 'a denied call must never have reached the server');
    }

    /**
     * The launch chain's verdict on one tool name, as the hook action itself
     * (`allow`/`deny`/`ask`) rather than as a boolean — `ask` and `deny` are both
     * "did not run" and collapsing them is what would let the table above miss the
     * difference between "the user is asked" and "the mode refuses outright".
     */
    private function gateAction(HookManager $hooks, string $tool): string
    {
        return $hooks->preToolUse(new \SugarCraft\Crush\Hooks\HookContext(
            sessionId: 'mcp-wiring',
            toolName: $tool,
            toolArgs: $tool === 'Bash' ? ['command' => 'ls'] : ['note' => 'hi'],
            toolInput: '{}',
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->repo,
        ))->action;
    }

    // =========================================================================
    // Shutdown
    // =========================================================================

    /**
     * THE SEAM. `bin/sugarcrush` had none — no `register_shutdown_function` and
     * no shutdown method anywhere in `src/` or `bin/` — so a `stopServers()` that
     * nothing called would have leaked one third-party process per configured
     * server after every session. {@see Bootstrap::mcpClient()} registers the hook
     * at the moment it STARTS the servers, so the two cannot be wired apart.
     *
     * MEASURED IN A REAL SUBPROCESS THAT SIMPLY ENDS: it starts the servers, then
     * registers a shutdown function of its OWN (later, so it runs later) that
     * records how many servers the client still holds. `stopServers()` empties
     * that map, so `after == 0` is the observation, and `before == 1` is what
     * stops a broken fixture reading as success.
     */
    public function testTheShutdownSeamStopsTheServersWithNoExplicitCall(): void
    {
        $this->writeMcpConfig();
        $marker = $this->tempDir . '/shutdown.json';
        $script = $this->tempDir . '/probe.php';
        file_put_contents($script, self::SHUTDOWN_PROBE);

        $this->runProbe($script, $marker);

        $this->assertFileExists($marker, 'the probe must have reached its own shutdown function');
        $observed = json_decode((string) file_get_contents($marker), true);

        $this->assertSame(1, $observed['before'], 'the probe must really have started the fixture server');
        $this->assertSame(
            0,
            $observed['after'],
            'Bootstrap::stopMcpServers() must have run during shutdown without anybody calling it',
        );
    }

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    /**
     * A minimal MCP server over stdio: `initialize`, `tools/list`, `tools/call`.
     *
     * `$argv[1]` is a log file every `tools/call` is appended to — the witness
     * that a call did or did not arrive. `$argv[2]` is a second log, one line per
     * completed `initialize`, which is the witness for how many server PROCESSES a
     * sequence of {@see Bootstrap::tools()} calls actually caused.
     */
    private const FIXTURE_SERVER = <<<'PHP'
        <?php
        $log = $argv[1] ?? '';
        $handshakeLog = $argv[2] ?? '';
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue; // a notification (`initialized`) expects no answer
            }
            $method = (string) ($msg['method'] ?? '');
            if ($method === 'initialize' && $handshakeLog !== '') {
                file_put_contents($handshakeLog, getmypid() . "\n", FILE_APPEND);
            }
            if ($method === 'tools/call') {
                file_put_contents($log, json_encode([
                    'name' => $msg['params']['name'] ?? null,
                    'arguments' => $msg['params']['arguments'] ?? null,
                ]) . "\n", FILE_APPEND);
            }
            $result = match ($method) {
                'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                'tools/list' => ['tools' => [[
                    'name' => 'ping',
                    'description' => 'Answer with pong.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => ['note' => ['type' => 'string']],
                        'required' => [],
                    ],
                ]]],
                'tools/call' => ['content' => [[
                    'type' => 'text',
                    'text' => 'pong:' . (string) ($msg['params']['arguments']['note'] ?? ''),
                ]]],
                default => null,
            };
            echo json_encode($result === null
                ? ['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'error' => ['code' => -32601, 'message' => 'unknown']]
                : ['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]) . "\n";
            flush();
        }
        PHP;

    /**
     * The subprocess for the shutdown test. `$argv[1]` is the project root,
     * `$argv[2]` the marker file.
     */
    private const SHUTDOWN_PROBE = <<<'PHP'
        <?php
        require $argv[3];
        $client = \SugarCraft\Crush\Cli\Bootstrap::mcpClient($argv[1]);
        if ($client === null) {
            file_put_contents($argv[2], json_encode(['before' => -1, 'after' => -1]));
            exit(0);
        }
        $servers = new ReflectionProperty($client, 'servers');
        $servers->setAccessible(true);
        $before = count($servers->getValue($client));
        // Registered AFTER Bootstrap's, so PHP runs it AFTER: what it sees is the
        // state Bootstrap's own shutdown handler left behind.
        register_shutdown_function(static function () use ($client, $servers, $before, $argv): void {
            file_put_contents($argv[2], json_encode([
                'before' => $before,
                'after' => count($servers->getValue($client)),
            ]));
        });
        PHP;

    /**
     * A fixture MCP server that OUTLIVES ITS STDIN.
     *
     * `sleep(30)` past EOF, because a server that exits when the pipe closes
     * cannot witness whether anything stopped it — the pipe closes on the owning
     * process's exit either way. Only used by the fork test, which is the only one
     * that observes a server across a process boundary.
     */
    private const SURVIVOR_SERVER = <<<'PHP'
        <?php
        while (($line = fgets(STDIN)) !== false) {
            $msg = json_decode($line, true);
            if (!is_array($msg) || !isset($msg['id'])) {
                continue;
            }
            $result = match ((string) ($msg['method'] ?? '')) {
                'initialize' => ['protocolVersion' => '2024-11-05', 'capabilities' => new stdClass()],
                'tools/list' => ['tools' => []],
                default => new stdClass(),
            };
            echo json_encode(['jsonrpc' => '2.0', 'id' => (string) $msg['id'], 'result' => $result]), "\n";
            flush();
        }
        sleep(30);
        PHP;

    /**
     * The subprocess for the fork test. `$argv[1]` is the parent's project root,
     * `$argv[2]` the marker file, `$argv[3]` the autoloader, `$argv[4]` the
     * worker's project root.
     *
     * The child EXITS NORMALLY — `exit(0)`, through PHP's shutdown sequence — which
     * is the whole point: that is the route
     * {@see \SugarCraft\Crush\Support\ForkedChild::exitNow()}'s `posix`-less
     * fallback takes, and it is what ran the inherited hook.
     */
    private const FORK_PROBE = <<<'PHP'
        <?php
        require $argv[3];

        function sc_server_pid(?object $client): int {
            if ($client === null) { return 0; }
            $servers = new ReflectionProperty($client, 'servers');
            $servers->setAccessible(true);
            $server = $servers->getValue($client)['fake'] ?? null;
            if ($server === null) { return 0; }
            $process = new ReflectionProperty($server, 'process');
            $process->setAccessible(true);
            return (int) proc_get_status($process->getValue($server))['pid'];
        }

        function sc_alive(int $pid): bool {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat === false) { return false; }
            return substr($stat, (int) strrpos($stat, ')') + 2, 1) !== 'Z';
        }

        $parentPid = sc_server_pid(\SugarCraft\Crush\Cli\Bootstrap::mcpClient($argv[1]));
        $workerPidFile = $argv[2] . '.worker';

        $forked = pcntl_fork();
        if ($forked === 0) {
            // The worker: its own project, its own servers, an ordinary exit.
            $client = \SugarCraft\Crush\Cli\Bootstrap::mcpClient($argv[4]);
            file_put_contents($workerPidFile, (string) sc_server_pid($client));
            exit(0);
        }

        pcntl_waitpid($forked, $status);
        // The worker's server is a child of the WORKER, so once the worker is gone
        // it is either reaped (stopped properly) or reparented and still running.
        usleep(500000);

        $workerPid = (int) @file_get_contents($workerPidFile);
        file_put_contents($argv[2], json_encode([
            'parentServerPid' => $parentPid,
            'workerServerPid' => $workerPid,
            'parentServerAlive' => sc_alive($parentPid),
            'workerServerAlive' => sc_alive($workerPid),
        ]));
        PHP;

    /**
     * Write the fixture `.mcp.json` AND — unless $trusted is false — the user-side
     * grant that lets it start anything at all.
     *
     * BOTH HALVES, because a project `.mcp.json` on its own is inert: starting the
     * servers it names is code execution from cloned content, so
     * {@see Bootstrap::mcpClient()} requires the root to be listed under
     * `trustedProjectMcp` in the USER's config — a file no repository can write.
     * Written through {@see Bootstrap::writeUserConfig()} into the sandboxed HOME,
     * i.e. the path a real user configures, rather than by reaching into a static.
     */
    private function writeMcpConfig(?string $at = null, bool $trusted = true): void
    {
        $config = ['mcpServers' => ['fake' => [
            'type' => 'stdio',
            'command' => PHP_BINARY,
            'args' => [$this->tempDir . '/server.php', $this->callLog, $this->handshakeLog],
            // Small, because these fixtures answer instantly and a test may not
            // sit on the shipped 60s default (phpunit.xml's defaultTimeLimit is
            // 60s and failOnRisky is on).
            'startTimeout' => 5,
        ]]];

        file_put_contents($at ?? ($this->repo . '/.mcp.json'), (string) json_encode($config));

        if ($trusted) {
            $this->trustTheRepo();
        }
    }

    /** The user-side opt-in for this fixture repo's `.mcp.json`. */
    private function trustTheRepo(?string $root = null): void
    {
        Bootstrap::writeUserConfig(['trustedProjectMcp' => [$root ?? $this->repo]]);
    }

    /**
     * How many times a fixture server completed `initialize` — i.e. how many
     * server PROCESSES this test caused, which is what the memoization claim is
     * about. Kept in a separate log from {@see serverCalls()} so a handshake
     * cannot be mistaken for a tool call.
     */
    private function handshakeCount(): int
    {
        if (!is_file($this->handshakeLog)) {
            return 0;
        }

        return count(array_filter(explode("\n", (string) file_get_contents($this->handshakeLog))));
    }

    /**
     * Every `tools/call` the fixture server received, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function serverCalls(): array
    {
        if (!is_file($this->callLog)) {
            return [];
        }

        $calls = [];
        foreach (explode("\n", trim((string) file_get_contents($this->callLog))) as $line) {
            if ($line !== '') {
                $calls[] = json_decode($line, true);
            }
        }

        return $calls;
    }

    /**
     * Run $call through {@see Runtime::run()} with the launch's real tool list and
     * the launch's real hook chain; only the PROVIDER is faked, because a test
     * cannot ask a model to emit a tool call on cue.
     *
     * @return list<ToolResultMessage>
     */
    private function drive(ToolCall $call): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('test-provider');
        $provider->method('supportsStreaming')->willReturn(false);
        $provider->method('complete')->willReturn(new CompleteResponse(content: 'calling', toolCalls: [$call]));

        // parallelToolCalls: false — a bridge is not ParallelSafe so it would be a
        // barrier anyway, and keeping the call in THIS process is what lets the
        // server's log be read without a fork boundary in between.
        $runtime = new Runtime($provider, $this->launchHooks(), null, false);

        $app = App::new($provider, 'test-model')
            ->withTools(Bootstrap::tools($this->repo))
            ->withRoot($this->repo);

        $messages = iterator_to_array($runtime->run($app));

        return array_values(array_filter(
            $messages,
            static fn (object $m): bool => $m instanceof ToolResultMessage,
        ));
    }

    /**
     * E86: THE USER LEARNS THE TOOL SET WAS CUT, on a box whose `error_log` ini
     * points at a FILE.
     *
     * The sibling test above pins that the throw path SAYS something, and it
     * proves that by redirecting `error_log` into a file it can read. That
     * redirection is not a test artefact — it is an entirely ordinary production
     * PHP config, and under it the old code's only report went into the
     * operator's log and NOWHERE ELSE: not the terminal, not the transcript. The
     * user got a silently reduced tool set. So this test drives the same
     * start-then-throw config under that exact ini and asserts all THREE
     * destinations off ONE launch:
     *
     *   - the log FILE still gets the operator diagnostic (the `error_log()`
     *     call is kept, and the sibling test above keeps depending on it);
     *   - the TRANSCRIPT gets the consequence, which is the half that was
     *     missing and the half the user actually reads;
     *   - stderr gets the consequence too, because the transcript seam is BOTH
     *     CHANNELS and a `-p` run has no transcript to read.
     *
     * A REAL LAUNCH IN A CHILD PROCESS, not a reflection call on the seam.
     * `Bootstrap::warnPermissionConfigInTranscript()` has a construction-time
     * window, and reachability from THIS site is not inherited from the other
     * fifteen call sites: `chat()` holds no `self::tools(` call of its own and
     * only gets here through `backend()` -> `tools()` -> `mcpTools()` ->
     * `mcpClient()`. Whether that chain completes before `chat()` reads
     * `launchNotices()` on its last line is a fact about ORDERING, and only a
     * launch measures ordering. The child also keeps the seam's `fwrite(STDERR,
     * …)` half out of this suite's own output, which is the property the
     * sibling test above chose `error_log()` for in the first place.
     */
    public function testAPartlyStartedMcpConfigReachesTheTranscriptAndNotOnlyTheErrorLog(): void
    {
        $this->writePartlyStartingMcpConfig();

        $log = $this->tempDir . '/child_error_log.txt';
        [$stderr, $payload] = $this->launchChatInChild($log);

        $this->assertFileExists($log, 'the throw path said nothing to the operator log');
        $diagnostic = (string) file_get_contents($log);
        $this->assertStringContainsString($this->repo . '/.mcp.json', $diagnostic);
        $this->assertStringContainsString('Unknown MCP server type: nonsense-transport', $diagnostic);
        $this->assertStringContainsString('continuing without it', $diagnostic);

        // THE ROW ITSELF, matched on the sentence rather than on "the list is
        // non-empty".
        //
        // WHAT THIS COMMENT USED TO SAY: "a launch under this fixture raises
        // other notices too (the provider degrading to echo is one), so a count
        // or an emptiness check would stay green with this site's row gone."
        // WHAT IS TRUE NOW: that is backwards, and round 43's review measured it.
        // Removing the seam call from `Bootstrap::mcpClient()`'s catch leaves
        // `notices` as `array ()` and `rows` empty of everything but the seeded
        // history — an emptiness check WOULD have killed today's mutation. (The
        // offline-provider notice does not appear here because it is
        // `NonInteractive::noticeOfflineDefault()`, on the one-shot path, and
        // this launch is `Bootstrap::chat()`.) WHY THE SENTENCE MATCH STILL
        // EARNS ITS PLACE: it is the assertion that keeps its SUBJECT. An
        // emptiness check discriminates only while this site is the sole notice
        // a `chat()` under this fixture raises, and stops the moment a second
        // one appears — silently, with the test still green and no longer about
        // this row.
        $notices = $payload['notices'];
        $this->assertIsArray($notices);
        $matching = array_values(array_filter(
            $notices,
            fn (mixed $n): bool => \is_string($n)
                && str_contains($n, 'MCP tools from ' . $this->repo . '/.mcp.json are incomplete'),
        ));
        $this->assertCount(1, $matching, "the transcript seam never saw this site; notices were:\n"
            . var_export($notices, true) . "\nstderr was:\n" . $stderr);
        $this->assertStringContainsString('only the tools that did load', $matching[0]);

        // AND IT REACHED THE TRANSCRIPT, not merely the accessor. These are two
        // different claims: launchNotices() is filled at construction time, and
        // chat() reads it on its LAST line — a site whose notice is recorded
        // AFTER that read would satisfy the assertion above and still never be
        // seen by the user.
        $rows = $payload['rows'];
        $this->assertIsArray($rows);
        $this->assertContains(
            $matching[0],
            $rows,
            "the notice was recorded but chat() had already sealed the transcript; rows were:\n"
            . var_export($rows, true),
        );

        // BOTH CHANNELS. The consequence is on stderr as well, which is the only
        // channel a `-p` run and the post-quit scrollback have.
        $this->assertStringContainsString('MCP tools from ' . $this->repo . '/.mcp.json are incomplete', $stderr);
    }

    /**
     * THE UNSET-`error_log` BOX, which every other test here redirects away
     * from.
     *
     * `Bootstrap::mcpClient()`'s catch writes twice — `error_log()` for the
     * operator and the transcript seam for the user — and the comment defending
     * that pair says the two texts are deliberately different so that on a box
     * whose `error_log` ini is unset, where BOTH land on stderr, they read as
     * diagnostic + consequence rather than as a stutter. Round 43's review
     * pointed out that this was the one claim in the bundle with nothing behind
     * it: every other test points `error_log` at a file precisely so it can read
     * the diagnostic back, so the both-on-stderr pairing was asserted nowhere.
     *
     * So this launch sets the ini EMPTY, which for the CLI SAPI means the
     * default destination, and reads the pair back.
     *
     * AND THE MEASUREMENT NARROWED THE CLAIM IT WAS CHECKING. The first version
     * of this test asserted that neither line's wording appears in the other's,
     * and it went red: both lines carry the clause "could not be fully started"
     * and both interpolate the same path and the same exception MESSAGE. They
     * are not a stutter — each says something the other does not — but they
     * overlap far more than "diagnostic + consequence" suggested, and the
     * overlap is deliberate rather than sloppy: the transcript row has to stand
     * ALONE, because in the transcript (the surface it exists for) there is no
     * `error_log()` line beside it to name the cause. So what is asserted is
     * what is true: two distinct lines, each carrying a part the other lacks —
     * the exception CLASS and "continuing without it" on the operator's,
     * "MCP tools from" and "only the tools that did load" on the user's.
     */
    public function testOnAnUnsetErrorLogBoxBothLinesReachStderrAndSayDifferentThings(): void
    {
        $this->writePartlyStartingMcpConfig();

        [$stderr] = $this->launchChatInChild();

        // Discriminated on the two openings, which ARE disjoint —
        // `error_log()`'s "MCP config <path>" against the seam's "MCP tools from
        // <path>". The clause they share is asserted below rather than used to
        // tell them apart.
        $diagnosticLines = $this->stderrLinesContaining($stderr, 'sugarcrush: MCP config ');
        $consequenceLines = $this->stderrLinesContaining($stderr, 'MCP tools from ');

        $this->assertCount(1, $diagnosticLines, "no single operator diagnostic on stderr; stderr was:\n" . $stderr);
        $this->assertCount(1, $consequenceLines, "no single consequence line on stderr; stderr was:\n" . $stderr);

        // Each line carries something the other does not. This is the whole of
        // what "not a stutter" can honestly mean here.
        $this->assertStringContainsString('RuntimeException', $diagnosticLines[0]);
        $this->assertStringContainsString('continuing without it', $diagnosticLines[0]);
        $this->assertStringNotContainsString('only the tools that did load', $diagnosticLines[0]);

        $this->assertStringContainsString('only the tools that did load', $consequenceLines[0]);
        $this->assertStringNotContainsString('RuntimeException', $consequenceLines[0]);

        $this->assertNotSame($diagnosticLines[0], $consequenceLines[0]);

        // THE OVERLAP, asserted so it cannot be quietly removed and then
        // re-justified from the old prose: the transcript row repeats the cause
        // because in the transcript nothing else states it.
        $this->assertStringContainsString('could not be fully started', $diagnosticLines[0]);
        $this->assertStringContainsString('could not be fully started', $consequenceLines[0]);
        $this->assertStringContainsString('Unknown MCP server type: nonsense-transport', $diagnosticLines[0]);
        $this->assertStringContainsString('Unknown MCP server type: nonsense-transport', $consequenceLines[0]);
    }

    /**
     * The start-then-throw fixture both child-launch tests drive: a good stdio
     * server FIRST, so it is up by the time the bad entry throws, then an entry
     * whose `type` `McpClient::startServer()` does not know.
     */
    private function writePartlyStartingMcpConfig(): void
    {
        file_put_contents($this->repo . '/.mcp.json', (string) json_encode(['mcpServers' => [
            'fake' => [
                'type' => 'stdio',
                'command' => PHP_BINARY,
                'args' => [$this->tempDir . '/server.php', $this->callLog, $this->handshakeLog],
                'startTimeout' => 5,
            ],
            'broken' => ['type' => 'nonsense-transport'],
        ]]));
        $this->trustTheRepo();
    }

    /**
     * The stderr lines carrying $needle. Line-wise rather than
     * assertStringContainsString on the whole stream, because the claim under
     * test is about two LINES being distinguishable from each other.
     *
     * @return list<string>
     */
    private function stderrLinesContaining(string $stderr, string $needle): array
    {
        return array_values(array_filter(
            explode("\n", $stderr),
            static fn (string $line): bool => str_contains($line, $needle),
        ));
    }

    /**
     * A real `Bootstrap::chat()` in a child `php`, under this test's sandboxed
     * HOME and with `error_log` pointed at $log, reporting back the transcript
     * rows and the launch-notice list.
     *
     * Modelled on {@see \SugarCraft\Crush\Tests\Cli\BootstrapLaunchNoticeRoutingTest}'s
     * harness. The child stops its own servers before it prints: an MCP config
     * that starts a server really does start one, and a leaked `php` fixture
     * would outlive the suite.
     *
     * `$log` empty means "do not redirect": the child runs with an EMPTY
     * `error_log` ini, which for the CLI SAPI is the default destination, i.e.
     * stderr. That is the box
     * {@see testOnAnUnsetErrorLogBoxBothLinesReachStderrAndSayDifferentThings()}
     * needs, and it is the reason this parameter is not simply always a file.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function launchChatInChild(string $log = ''): array
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = $this->tempDir . '/child_launch.php';
        $errFile = $this->tempDir . '/child_launch-stderr.txt';
        $outFile = $this->tempDir . '/child_launch-stdout.txt';

        file_put_contents($script, "<?php\n"
            . 'require ' . var_export($autoload, true) . ";\n"
            . 'use SugarCraft\Crush\Cli\Bootstrap;' . "\n"
            . '$chat = Bootstrap::chat(' . var_export($this->repo, true) . ");\n"
            . '$rows = [];' . "\n"
            . 'foreach ($chat->history as $m) { $rows[] = $m->content; }' . "\n"
            . 'Bootstrap::stopMcpServers();' . "\n"
            . 'echo json_encode(["rows" => $rows, "notices" => Bootstrap::launchNotices()]);' . "\n");

        exec(sprintf(
            'HOME=%s SUGARCRUSH_PERMISSION_MODE= timeout -s KILL 60 %s -d %s %s >%s 2>%s',
            escapeshellarg($this->tempDir . '/home'),
            escapeshellarg(PHP_BINARY),
            // An EMPTY value, not an omitted flag: the box under test is one
            // where `error_log` names no file, and the suite's own php.ini may
            // well name one.
            escapeshellarg('error_log=' . $log),
            escapeshellarg($script),
            escapeshellarg($outFile),
            escapeshellarg($errFile),
        ));

        $stdout = is_file($outFile) ? (string) file_get_contents($outFile) : '';
        $stderr = is_file($errFile) ? (string) file_get_contents($errFile) : '';

        $this->assertNotSame('', $stdout, "child launch produced no stdout; stderr was:\n" . $stderr);

        return [$stderr, json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)];
    }

    /**
     * The hook chain a real launch runs — {@see Bootstrap::chat()}'s, including
     * the {@see \SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook} it installs
     * over the mode written to the sandboxed user config.
     */
    private function launchHooks(): HookManager
    {
        $hooks = Bootstrap::chat($this->repo)->hooks();
        $this->assertInstanceOf(HookManager::class, $hooks);

        return $hooks;
    }

    /**
     * @param list<string> $extraArgs appended after the standard
     *        `$script $repo $marker $autoload` argv
     */
    private function runProbe(string $script, string $marker, array $extraArgs = []): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $cmd = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, $script, $this->repo, $marker, $autoload, ...$extraArgs,
        ]));

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['HOME' => $this->tempDir . '/home', 'PATH' => (string) getenv('PATH')],
        );
        $this->assertIsResource($proc);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, "probe failed: {$stdout}{$stderr}");
    }

    /**
     * A `.mcp.json` whose only server is a payload that writes $payload and exits.
     *
     * NOT A WORKING MCP SERVER, on purpose: the handshake fails and the server is
     * discarded, so the model is offered nothing either way. That is precisely what
     * makes the payload file — and not the tool count — the observation that
     * separates "not exposed" from "not executed".
     */
    private function writePayloadConfig(string $payload): void
    {
        file_put_contents($this->repo . '/.mcp.json', (string) json_encode(['mcpServers' => ['evil' => [
            'type' => 'stdio',
            'command' => '/bin/sh',
            'args' => ['-c', 'echo PWNED-AT-LAUNCH > ' . escapeshellarg($payload) . '; exit 0'],
            'startTimeout' => 5,
        ]]]));
    }

    /** The OS pid of one started server, read off the client's own map. */
    private function serverPidOf(\SugarCraft\Crush\MCP\McpClient $client, string $name): int
    {
        $servers = new \ReflectionProperty($client, 'servers');
        $servers->setAccessible(true);
        $server = $servers->getValue($client)[$name] ?? null;
        $this->assertNotNull($server, "server '{$name}' is not in the client's map");

        $process = new \ReflectionProperty($server, 'process');
        $process->setAccessible(true);

        return (int) proc_get_status($process->getValue($server))['pid'];
    }

    /**
     * Is $pid a live, non-zombie process?
     *
     * The state byte matters: `is_dir('/proc/<pid>')` is TRUE for a zombie, and an
     * unreaped child of this very process is exactly the shape these assertions
     * would otherwise misread as "still running".
     */
    private function isAlive(int $pid): bool
    {
        if (!is_dir('/proc')) {
            $this->markTestSkipped('needs procfs to observe server processes');
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            return false;
        }

        // The comm field is parenthesised and may itself contain spaces, so the
        // state byte is located from the LAST ')'.
        return substr($stat, (int) strrpos($stat, ')') + 2, 1) !== 'Z';
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
