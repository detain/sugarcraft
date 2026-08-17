<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * EVERY READ AND EXECUTE SINK IN `src/`, ENUMERATED FROM THE TREE, each one
 * required to name the gate that bounds it.
 *
 * WHY THIS EXISTS, and why it is not another list.
 * {@see ContainedPathInventoryTest} counts the containment compares that are
 * WRITTEN. That catches a gate being deleted and it is structurally blind to a
 * read path that never had one — which is not a hypothetical limitation but the
 * mechanism behind the last four findings in this lane, and the tenth of them was
 * ARBITRARY CODE EXECUTION sitting on a row that inventory reported as GREEN:
 * `Workflows/WorkflowRegistry.php => 2`, correct about the project tier's two
 * compares, silent about the user tier's `require` with no compare at all.
 *
 * The inversion is the whole point. That instrument starts from the GATES and asks
 * how many there are; this one starts from the SINKS — the calls that actually read
 * bytes or execute code — and asks, for each, what bounds it. A new sink cannot
 * arrive quietly: it is derived from `src/`, so it fails
 * {@see testTheCensusIsDerivedFromSrcAndFullyClassified()} by EXISTING, and the only
 * way to make that test pass is to write down a verdict.
 *
 * BE PRECISE ABOUT WHAT THAT WOULD HAVE DONE TO THE TENTH FINDING, because "this
 * test would have caught it" is the kind of sentence this lane keeps having to
 * retract. It would NOT have failed automatically: `Workflows/WorkflowRegistry.php`
 * held two enforcing compares for its project tier, so a row claiming `CONTAINED`
 * for the `require` would have passed the measured check
 * ({@see testEveryContainedClaimIsBackedByTheRoutedCallInventory()} asks about the
 * FILE, not about this path). What it would have done is force somebody to WRITE a
 * sentence next to a `require` saying which boundary bounds it — and the only true
 * sentence available then was "none". A reviewer can challenge a false sentence; a
 * green `=> 2` on the gate inventory asked no question at all. The one rule here that
 * is automatic for this shape is
 * {@see testEveryExecutePathIsContainedOrDerivedFromTheInstallation()}: the verdict
 * the old doc-block's reasoning amounted to — "the user's own directory" — is
 * `SELF_LOCATED`, which an execute path may not take.
 *
 * WHAT A VERDICT IS. A word from {@see VERDICTS} plus a sentence saying which
 * boundary applies. Four of the words are MEASURED rather than trusted:
 * `CONTAINED` and `CONTAINED_UPSTREAM` are checked against
 * {@see ContainedPathInventoryTest::ROUTED_CALL_SITES} — the derived, asserted map
 * of which files actually hold an enforcing {@see \SugarCraft\Crush\Support\ContainedPath}
 * call — and `PATH_JAIL` and `OWNED_HOME` are checked against the file naming the
 * mechanism it claims. The rest are JUDGEMENTS about where a path comes from, which
 * no scanner can make; they are written down so a reviewer can disagree with a
 * sentence rather than reverse-engineer an omission.
 *
 * WHAT IT DOES NOT DO, stated because the instrument it replaces was over-trusted
 * for exactly this shape of gap:
 *
 *  - it does not prove a verdict is CORRECT. `CONTAINED` means the file holds an
 *    enforcing compare, not that THIS sink's path went through it — a file can hold
 *    one gate and five ungated reads, which is why the rows are per-sink and each
 *    carries its own sentence. The per-tier containment tests are what prove a gate
 *    binds ({@see \SugarCraft\Crush\Tests\Workflows\WorkflowUserTierContainmentTest}
 *    and its siblings);
 *  - it sees a fixed set of sink spellings ({@see FUNCTION_SINKS},
 *    {@see STATIC_SINKS}, {@see CONSTRUCTOR_SINKS} and the `require`/`include`
 *    family). A read through a variable function name, `eval()`, a stream wrapper
 *    registered at runtime, or an extension function nobody listed here is invisible
 *    — {@see testTheCensusScannerRecognisesTheShapesItClaimsTo()} pins what it does
 *    and does not see;
 *  - it covers READS and EXECUTES, not WRITES. The worktree escape wrote outside the
 *    worktree as well as reading outside the checkout, and a write census is a
 *    second instrument with a different verdict vocabulary. Not this one.
 */
final class ReadPathCensusTest extends TestCase
{
    /** Plain function calls that read bytes off a path. */
    private const FUNCTION_SINKS = [
        'file_get_contents', 'file', 'fopen', 'readfile', 'scandir', 'glob', 'opendir',
        'parse_ini_file', 'simplexml_load_file', 'yaml_parse_file',
    ];

    /** `Class::method()` reads — the spelling a plain function-name scan misses. */
    private const STATIC_SINKS = ['parsefile'];

    /** `new X($path)` reads — an iterator that opens a directory is a read. */
    private const CONSTRUCTOR_SINKS = [
        'RecursiveDirectoryIterator', 'DirectoryIterator', 'GlobIterator', 'SplFileObject',
    ];

    /**
     * The verdict vocabulary. Each key is a word a row may use; each value says what
     * claiming it MEANS, and the four marked MEASURED are checked rather than
     * believed.
     *
     * @var array<string, string>
     */
    private const VERDICTS = [
        // MEASURED against ContainedPathInventoryTest::ROUTED_CALL_SITES.
        'CONTAINED' => 'this file holds an enforcing ContainedPath compare that bounds this read',
        // MEASURED: the named upstream file must hold one.
        'CONTAINED_UPSTREAM' => 'the path arrives already bounded from the file named after the colon',
        // MEASURED: the file must reference PathJail.
        'PATH_JAIL' => 'a model-supplied path resolved through Tools\PathJail',
        // MEASURED: the file must reference the owned-home resolution.
        'OWNED_HOME' => 'under a home HomeDirectory::owned() established as this user\'s',
        'SELF_LOCATED' => 'a file this process itself created, in a directory it owns',
        'PROCESS_DERIVED' => 'derived from the running installation or the kernel, not from content',
        'NOT_A_FILESYSTEM_PATH' => 'a URL; the sink function is shared, the domain is not',
        'NAMES_ONLY' => 'enumerates names and reads no content; the gate is on the later read',
        'CALLER_SUPPLIED' => 'an in-process caller chose the path and holds the boundary',
    ];

    /**
     * THE LEDGER. `src/`-relative file + sink spelling => one entry per occurrence.
     *
     * Each entry is `VERDICT[:upstream/File.php] — why`. The list LENGTH is the
     * occurrence count, so a new sink of an already-listed kind in an already-listed
     * file still reds this test: there is no row shape that absorbs an addition
     * silently.
     *
     * @var array<string, list<string>>
     */
    private const READ_PATHS = [
        'Agents/AgentPresetRegistry.php|glob' => [
            'CONTAINED — the preset directory is anchored and each `*.md` confined to it',
        ],
        'Agents/AgentPresetRegistry.php|file_get_contents' => [
            'CONTAINED — the preset body, read only for a path that passed both compares',
        ],
        'Agents/AgentWorkerPool.php|glob' => [
            'SELF_LOCATED — the pool sweeps its own result directory (makeResultDirPath())',
        ],
        'Agents/AgentWorkerPool.php|file_get_contents' => [
            'SELF_LOCATED — a result file this pool named and a forked child of it wrote',
            'SELF_LOCATED — the same directory, read during the drain loop',
        ],
        'Agents/ForeignAgentPresetRegistry.php|glob' => [
            'CONTAINED — `.claude/agents` / `.opencode/agents`, anchored per tier',
        ],
        'Agents/ForeignAgentPresetRegistry.php|file_get_contents' => [
            'CONTAINED — the foreign preset body, behind the same pair',
        ],
        'Agents/Mailbox.php|fopen' => [
            'SELF_LOCATED — an inbox file under the team store this process writes',
            'SELF_LOCATED — the same inbox, re-opened to compact it',
        ],
        'Agents/Mailbox.php|file_get_contents' => [
            'SELF_LOCATED — a wake marker this mailbox wrote',
        ],
        'Agents/ProcessExecutor.php|file_get_contents' => [
            'PROCESS_DERIVED — `/proc/meminfo`, a kernel interface named by a literal',
        ],
        'Agents/TeamManager.php|file_get_contents' => [
            'SELF_LOCATED — the team registry this manager writes under `~/.sugar-crush`',
        ],
        'Agents/WorktreeConfig.php|file_get_contents' => [
            'CONTAINED — `.sugar-crush/config.json`, behind the directory + file pair',
        ],
        'Agents/WorktreeManager.php|file' => [
            'CONTAINED — the `.worktreeinclude` list, bounded by the include-file gate',
        ],
        'Agents/WorktreeManager.php|glob' => [
            'NAMES_ONLY — pattern expansion; the COPY of each match is what ContainedPath bounds, '
                . 'so a `../` pattern is enumerated here and refused there',
        ],
        'Agents/WorktreeManager.php|scandir' => [
            'CONTAINED — recursive copy of a source directory that passed the pattern gate',
            'SELF_LOCATED — the stale-worktree sweep, over the base path this manager created',
        ],
        'Agents/WorktreeManager.php|file_get_contents' => [
            'SELF_LOCATED — the sweep marker this manager writes',
        ],
        'Agents/WorktreeManager.php|fopen' => [
            'SELF_LOCATED — its own `.registry.json`, opened for a lock',
        ],
        'Agents/WorktreeManager.php|new RecursiveDirectoryIterator' => [
            'NAMES_ONLY — recursive pattern expansion, gated at the copy like the glob above',
        ],
        'Chat.php|file_get_contents' => [
            'SELF_LOCATED — a forked child\'s result file, named by Support\ToolIpcFiles',
        ],
        'Cli/Bootstrap.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Providers/ProviderFactory.php — the dev provider config, whose '
                . 'two boundaries live in readableDefaultConfigPath()',
            'OWNED_HOME — `~/.sugar-crush/config.json`, resolved through trustedConfigDirPath()',
            'OWNED_HOME — the permission policy file, additionally ownership-checked before it is read',
        ],
        'Commands/CommandLoader.php|new RecursiveDirectoryIterator' => [
            'CONTAINED — the commands directory is anchored to its tree and each `*.md` confined to it',
        ],
        'Commands/CommandSpec.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Commands/CommandLoader.php — parses a path the loader already bounded',
        ],
        'Context/ImportResolver.php|file_get_contents' => [
            'CALLER_SUPPLIED — an `@file` import, judged by the $boundaryCheck callback the caller '
                . 'supplies (InstructionFileLoader passes one built on ContainedPath)',
        ],
        'Context/InstructionFileLoader.php|file_get_contents' => [
            'CONTAINED — the root instruction file, one compare per read decision',
            'CONTAINED — a walked-to instruction file',
            'CONTAINED — a configured `instructions:` glob match',
        ],
        'Context/InstructionFileLoader.php|glob' => [
            'NAMES_ONLY — expansion of the configured glob; each match is compared before it is read',
        ],
        // CALLER_SUPPLIED, not CONTAINED_UPSTREAM, and the correction was made BY
        // this test: the first draft claimed the upstream gate was in
        // `Cli/Bootstrap.php`, and the measured check refused it, because Bootstrap's
        // two gates for this file are HomeDirectory::owned() (the user copy) and the
        // hook-trust prompt (the project copy) — neither of which is a ContainedPath
        // compare. A verdict word that names the wrong mechanism is precisely what
        // the measured half of this instrument exists to catch, and it caught one on
        // its first run.
        'Hooks/HookConfig.php|file_get_contents' => [
            'CALLER_SUPPLIED — `hooks.yaml`; this class holds no boundary. Cli\Bootstrap resolves '
                . 'the user copy through trustedConfigDirPath() (owned home) and puts the project '
                . 'copy behind projectHooksAreTrusted(), which refuses the LAUNCH rather than the read',
        ],
        'LSP/LspClient.php|file' => [
            'CALLER_SUPPLIED — the URI came from the editor request this client is answering',
        ],
        'MCP/McpClient.php|file_get_contents' => [
            'CALLER_SUPPLIED — the MCP config path is a constructor argument; nothing in `src/` '
                . 'builds one yet, so the first caller owns the boundary',
        ],
        'MCP/OAuthClientRegistration.php|file_get_contents' => [
            'SELF_LOCATED — `~/.local/share/sugar-crush/mcp-auth.json`, written by this class',
        ],
        'Memory/ForeignMemoryImporter.php|file_get_contents' => [
            'CONTAINED — a `.opencode/memory` file behind the project tier\'s anchor',
            'CONTAINED — the user tier\'s, behind HomeDirectory::owned()',
        ],
        'Memory/ForeignMemoryImporter.php|glob' => [
            'NAMES_ONLY — enumeration inside a directory both compares already accepted',
        ],
        'Memory/MemoryStore.php|glob' => [
            'SELF_LOCATED — the store\'s own scope directories, listed',
            'SELF_LOCATED — one scope\'s entries',
            'SELF_LOCATED — a lookup by id',
            'SELF_LOCATED — the same lookup before an update',
            'SELF_LOCATED — the same lookup before a delete',
            'SELF_LOCATED — a scope re-index',
        ],
        'Memory/MemoryStore.php|file_get_contents' => [
            'SELF_LOCATED — a scope index this store wrote',
            'SELF_LOCATED — an entry this store wrote',
        ],
        'Providers/ProviderFactory.php|file_get_contents' => [
            'CONTAINED — fromProjectConfig(), behind readableDefaultConfigPath()',
            'CONTAINED — projectProviderConfig(), behind the same pair',
        ],
        'Runtime.php|file_get_contents' => [
            'SELF_LOCATED — a forked child\'s result file, named by Support\ToolIpcFiles',
        ],
        'Sessions/BackgroundSupervisor.php|file_get_contents' => [
            'SELF_LOCATED — the IPC buffer this supervisor named for its own child',
            'SELF_LOCATED — the same buffer, re-read while streaming',
        ],
        'Sessions/BackgroundSupervisor.php|require' => [
            'PROCESS_DERIVED — inside the GENERATED child script: the composer autoload of the '
                . 'installation already executing this code, found via the live ClassLoader\'s own '
                . 'file. A hostile autoloader there is one this process has already loaded',
        ],
        'Skills/Skill.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Skills/SkillLoader.php — parses a SKILL.md the loader bounded',
        ],
        'Skills/SkillLoader.php|file_get_contents' => [
            'CONTAINED — a SKILL.md behind the entry + directory pair',
            'CONTAINED — the single-file arm of the same read',
            'CONTAINED — a skill ASSET, compared against its own skill directory',
        ],
        'Skills/SkillLoader.php|new DirectoryIterator' => [
            'CONTAINED — the bounded walk over a skills tree, itself capped against a grafted tree',
        ],
        'StreamingDirectoryLister.php|opendir' => [
            'NAMES_ONLY — yields entry names; no content is read here and this class holds no '
                . 'boundary. DORMANT: nothing in `src/` constructs it, so the first consumer must '
                . 'pass a jailed root — recorded as a gap rather than given a boundary with no anchor',
            'NAMES_ONLY — the same, in the chunked variant',
        ],
        'Support/ToolIpcFiles.php|glob' => [
            'SELF_LOCATED — sweeps this package\'s own IPC prefixes, uid-checked per entry',
        ],
        'Tools/BuiltIn/Edit.php|file_get_contents' => [
            'PATH_JAIL — the model\'s path, resolved through PathJail before the read',
        ],
        'Tools/BuiltIn/Glob.php|glob' => [
            'PATH_JAIL — the search root is jailed; the pattern is additionally prefix-checked here',
        ],
        'Tools/BuiltIn/Glob.php|new RecursiveDirectoryIterator' => [
            'PATH_JAIL — the recursive arm of the same jailed root',
        ],
        'Tools/BuiltIn/Grep.php|glob' => [
            'PATH_JAIL — probes for excluded directories under the jailed search root',
        ],
        'Tools/BuiltIn/Read.php|fopen' => [
            'PATH_JAIL — the streaming arm of the read tool',
        ],
        'Tools/BuiltIn/Read.php|file_get_contents' => [
            'PATH_JAIL — the whole-file arm',
        ],
        'Tools/BuiltIn/WebFetch.php|file_get_contents' => [
            'NOT_A_FILESYSTEM_PATH — an HTTP(S) URL through a stream context',
        ],
        'Tools/BuiltIn/WebSearch.php|file_get_contents' => [
            'NOT_A_FILESYSTEM_PATH — the search endpoint, same shape',
        ],
        'Tools/BuiltIn/Write.php|file_get_contents' => [
            'PATH_JAIL — reads the existing file to diff before writing, same jailed path',
        ],
        'Tools/IgnoreRules.php|file_get_contents' => [
            'CALLER_SUPPLIED — a `.gitignore`-shaped file inside the walk the calling tool jailed',
        ],
        'Workflows/WorkflowEngine.php|file_get_contents' => [
            'SELF_LOCATED — a pause file this engine wrote',
            'SELF_LOCATED — the same, on resume',
            'SELF_LOCATED — the same, while listing paused runs',
        ],
        'Workflows/WorkflowEngine.php|glob' => [
            'SELF_LOCATED — its own `.running/*.json`. RESIDUAL, stated: the pause directory is '
                . 'built from the registry\'s CONFIGURED workflowsPath(), not from the anchored '
                . 'readableUserDir(), so a link on the workflows directory relocates the pause '
                . 'files with it. What that yields is this engine\'s own JSON, not code',
        ],
        'Workflows/WorkflowRegistry.php|require' => [
            'CONTAINED — THE TENTH READ PATH: the user tier\'s directory is anchored to $HOME and '
                . 'the resolved `.php` is confined to it. This row is why this file exists',
        ],
        'Workflows/WorkflowRegistry.php|scandir' => [
            'CONTAINED — the listing, whose entries are confined in both tiers',
        ],
        'Workflows/WorkflowRegistry.php|Yaml::parseFile' => [
            'CONTAINED — a `.yaml` workflow, confined to the tier directory it was found in',
        ],
    ];

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = \dirname(__DIR__, 2) . '/src';
    }

    /**
     * THE ASSERTION THAT MAKES THIS AN INSTRUMENT RATHER THAN A LIST: the ledger and
     * the tree must agree exactly, in both directions and per occurrence.
     *
     * A new sink reds this with its own `file|sink` key in the diff. A deleted one
     * reds it too, so a row cannot outlive the read it describes.
     */
    public function testTheCensusIsDerivedFromSrcAndFullyClassified(): void
    {
        $derived = $this->sinksPerFile();
        $ledger = [];
        foreach (self::READ_PATHS as $key => $verdicts) {
            $ledger[$key] = \count($verdicts);
        }

        ksort($derived);
        ksort($ledger);

        $this->assertSame(
            $ledger,
            $derived,
            'every read/execute sink in src/ must carry a verdict in READ_PATHS, and every verdict a sink',
        );
    }

    /**
     * Every verdict word is one of {@see VERDICTS}, and every word in
     * {@see VERDICTS} is used by at least one row.
     *
     * Both directions, because a typo'd verdict would otherwise be silently
     * unchecked by the measured assertions below, and a category nothing uses is a
     * category whose meaning has stopped being reviewed.
     */
    public function testEveryVerdictIsFromTheVocabularyAndEveryWordIsUsed(): void
    {
        $used = [];
        foreach (self::READ_PATHS as $key => $verdicts) {
            foreach ($verdicts as $entry) {
                [$word] = $this->parse($entry);
                $this->assertArrayHasKey($word, self::VERDICTS, "unknown verdict on {$key}: {$word}");
                $used[$word] = true;
                $this->assertStringContainsString('—', $entry, "{$key} must say WHY, not just which word");
            }
        }

        $this->assertSame(
            array_keys(self::VERDICTS),
            array_keys(array_intersect_key(self::VERDICTS, $used)),
            'a verdict word nothing uses is one whose meaning nobody is reviewing',
        );
    }

    /**
     * MEASURED, not trusted: a row claiming `CONTAINED` must sit in a file that
     * actually holds an enforcing {@see \SugarCraft\Crush\Support\ContainedPath}
     * call, and a row claiming `CONTAINED_UPSTREAM:<file>` must name a file that
     * does.
     *
     * The source of truth is {@see ContainedPathInventoryTest::ROUTED_CALL_SITES},
     * which that test asserts against a derivation over `src/` — so the two
     * instruments cannot disagree about which files hold a gate, and neither holds a
     * second copy of the scanner.
     */
    public function testEveryContainedClaimIsBackedByTheRoutedCallInventory(): void
    {
        $gated = ContainedPathInventoryTest::ROUTED_CALL_SITES;

        foreach (self::READ_PATHS as $key => $verdicts) {
            $file = explode('|', $key)[0];

            foreach ($verdicts as $entry) {
                [$word, $upstream] = $this->parse($entry);

                if ($word === 'CONTAINED') {
                    $this->assertArrayHasKey(
                        $file,
                        $gated,
                        "{$key} claims CONTAINED but {$file} holds no enforcing ContainedPath call",
                    );
                }

                if ($word === 'CONTAINED_UPSTREAM') {
                    $this->assertNotNull($upstream, "{$key} must name the file it is bounded by");
                    $this->assertFileExists($this->srcDir . '/' . $upstream, "{$key} names {$upstream}");
                    $this->assertArrayHasKey(
                        $upstream,
                        $gated,
                        "{$key} is bounded upstream by {$upstream}, which holds no enforcing call",
                    );
                }
            }
        }
    }

    /**
     * The other two measured words: a `PATH_JAIL` row must be in a file that names
     * {@see \SugarCraft\Crush\Tools\PathJail}, and an `OWNED_HOME` row in one that
     * reaches the owned-home resolution.
     *
     * Weaker than the containment check — presence of a mechanism, not proof it
     * bounds this read — and asserted anyway, because the failure mode being removed
     * is a verdict word copied onto a row whose file has no such mechanism at all.
     */
    public function testThePathJailAndOwnedHomeClaimsNameAMechanismTheirFileHas(): void
    {
        foreach (self::READ_PATHS as $key => $verdicts) {
            $file = explode('|', $key)[0];
            $source = (string) file_get_contents($this->srcDir . '/' . $file);

            foreach ($verdicts as $entry) {
                [$word] = $this->parse($entry);

                if ($word === 'PATH_JAIL') {
                    $this->assertStringContainsString('PathJail', $source, "{$key} claims PATH_JAIL");
                }

                if ($word === 'OWNED_HOME') {
                    $this->assertTrue(
                        str_contains($source, 'HomeDirectory::owned()')
                        || str_contains($source, 'trustedConfigDirPath'),
                        "{$key} claims OWNED_HOME but {$file} reaches no owned-home resolution",
                    );
                }
            }
        }
    }

    /**
     * AN EXECUTE PATH MAY NOT BE CLASSIFIED AS A CONVENIENCE. `require`/`include`
     * run code, so the only verdicts open to them are `CONTAINED`,
     * `CONTAINED_UPSTREAM` and `PROCESS_DERIVED` — nothing that rests on "this
     * process wrote the file" or "the caller chose it".
     *
     * This is finding F1 written as a rule rather than as a memory: the `require` in
     * `WorkflowRegistry::load()` sat for months under a doc-block arguing the
     * directory was the user's own, which is `SELF_LOCATED` reasoning applied to an
     * execute path.
     */
    public function testEveryExecutePathIsContainedOrDerivedFromTheInstallation(): void
    {
        $execute = [];

        foreach (self::READ_PATHS as $key => $verdicts) {
            if (!preg_match('/\|(require|require_once|include|include_once)$/', $key)) {
                continue;
            }

            $execute[$key] = true;

            foreach ($verdicts as $entry) {
                [$word] = $this->parse($entry);
                $this->assertContains(
                    $word,
                    ['CONTAINED', 'CONTAINED_UPSTREAM', 'PROCESS_DERIVED'],
                    "{$key} EXECUTES code; {$word} is not a verdict an execute path may take",
                );
            }
        }

        $this->assertSame(
            ['Sessions/BackgroundSupervisor.php|require', 'Workflows/WorkflowRegistry.php|require'],
            array_keys($execute),
            'the execute paths in src/, named — a new one is a change worth reading twice',
        );
    }

    /**
     * The scanner's own shapes, driven — including the three it must NOT count, each
     * of which was a false positive or a miss in this instrument's first draft.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function scannerShapes(): array
    {
        return [
            'a plain read' => ['<?php file_get_contents($p);', 1],
            'a language construct' => ['<?php require $p;', 1],
            'a static method read' => ['<?php Yaml::parseFile($p);', 1],
            'an iterator that opens a directory' => ['<?php new \\RecursiveDirectoryIterator($p);', 1],
            // Found by running the draft over `src/`: `new Glob(...)` is a TOOL
            // CLASS, and matching the bare name counted it as a `glob()` call.
            'a constructor whose class merely shares a sink NAME' => ['<?php new Glob($root);', 0],
            // The same shape one level subtler: a method call, not a function.
            'a method that shares a sink name' => ['<?php $this->file($p);', 0],
            'a static call to something else named glob' => ['<?php Helper::glob($p);', 0],
            // A doc-comment mention is a cross-reference, which is how the
            // ContainedPath inventory came to list a file that never called it.
            'a mention in a doc-comment' => ["<?php /** calls glob() over src/ */\n\$x = 1;", 0],
            'a declaration of a same-named method' => ['<?php class C { public function file($p) {} }', 0],
            // GENERATED CODE. src/Sessions/BackgroundSupervisor.php builds its
            // forked child's source as a string and `require`s the autoloader
            // inside it; a token scan of the outer file cannot see that, so string
            // literals are re-tokenised. This is a real execute path, not a
            // curiosity.
            'a sink inside a generated-code string' => ['<?php $src = \'<?php require $autoload;\';', 1],
            'a sink name inside an ordinary string' => ['<?php $msg = "could not glob the directory";', 0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scannerShapes')]
    public function testTheCensusScannerRecognisesTheShapesItClaimsTo(string $code, int $expected): void
    {
        $this->assertCount($expected, $this->sinksIn($code));
    }

    /**
     * The generated-child execute path, asserted where it lives rather than only as
     * a synthetic row: it is the one sink in `src/` that a token scan of executable
     * tokens alone cannot see, and the reason string literals are re-tokenised.
     */
    public function testTheGeneratedChildScriptsRequireIsSeenInTheRealFile(): void
    {
        $sinks = $this->sinksIn(
            (string) file_get_contents($this->srcDir . '/Sessions/BackgroundSupervisor.php'),
        );

        $this->assertContains('require', $sinks, 'the forked child\'s autoload require');
    }

    // ─── the instrument ─────────────────────────────────────────────

    /**
     * @return array{0: string, 1: string|null} the verdict word and its upstream file
     */
    private function parse(string $entry): array
    {
        $word = strtok($entry, ' ');
        $word = $word === false ? '' : $word;

        if (!str_contains($word, ':')) {
            return [$word, null];
        }

        [$word, $upstream] = explode(':', $word, 2);

        return [$word, $upstream];
    }

    /** @return array<string, int> `src/`-relative file + `|` + sink spelling => occurrences */
    private function sinksPerFile(): array
    {
        $counts = [];

        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen($this->srcDir) + 1);

            foreach ($this->sinksIn((string) file_get_contents($file->getPathname())) as $sink) {
                $key = $relative . '|' . $sink;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Every sink spelling in $code, as it should be named in the ledger.
     *
     * STRING LITERALS ARE RE-TOKENISED, not skipped: `src/Sessions/BackgroundSupervisor.php`
     * builds its forked child's whole source as a string and `require`s inside it,
     * which is an execute path an executable-token scan cannot see. Only the
     * `require`/`include` family is looked for in there — a sink NAME inside an
     * ordinary message string is not a call, and only the constructs that need no
     * parentheses can be recognised without parsing the string as a program.
     *
     * @return list<string>
     */
    private function sinksIn(string $code): array
    {
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token;
        }

        $found = [];
        foreach ($tokens as $i => $token) {
            $sink = $this->sinkAt($tokens, $i, $token);
            if ($sink !== null) {
                $found[] = $sink;
            }
        }

        return $found;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param  array{0: int, 1: string, 2: int}|string       $token
     */
    private function sinkAt(array $tokens, int $i, mixed $token): ?string
    {
        if (!\is_array($token)) {
            return null;
        }

        if (\in_array($token[0], [\T_REQUIRE, \T_REQUIRE_ONCE, \T_INCLUDE, \T_INCLUDE_ONCE], true)) {
            return strtolower($token[1]);
        }

        if ($token[0] === \T_CONSTANT_ENCAPSED_STRING || $token[0] === \T_ENCAPSED_AND_WHITESPACE) {
            return $this->requireInsideGeneratedCode((string) $token[1]);
        }

        if ($token[0] === \T_NEW) {
            $class = $tokens[$i + 1] ?? null;
            if (!\is_array($class)
                || !\in_array($class[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)
            ) {
                return null;
            }

            $short = ltrim((string) $class[1], '\\');

            return \in_array($short, self::CONSTRUCTOR_SINKS, true) ? 'new ' . $short : null;
        }

        if ($token[0] !== \T_STRING || ($tokens[$i + 1] ?? null) !== '(') {
            return null;
        }

        $name = strtolower((string) $token[1]);
        $before = $tokens[$i - 1] ?? null;

        // `Yaml::parseFile()` is a read; `Helper::glob()` is somebody else's method
        // that happens to share a name, and `new Glob(` is a tool class. The
        // preceding token is what tells the three apart.
        if (\is_array($before) && $before[0] === \T_DOUBLE_COLON) {
            if (!\in_array($name, self::STATIC_SINKS, true)) {
                return null;
            }

            $subject = $tokens[$i - 2] ?? null;
            $class = \is_array($subject) ? ltrim((string) $subject[1], '\\') : '';

            return $class . '::' . (string) $token[1];
        }

        if (\is_array($before)
            && \in_array($before[0], [\T_FUNCTION, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_NEW], true)
        ) {
            return null;
        }

        return \in_array($name, self::FUNCTION_SINKS, true) ? $name : null;
    }

    /**
     * `require`/`include` inside a string that is really PHP SOURCE, or null.
     *
     * DELIBERATELY NARROW, and the narrowing is measured rather than cautious. The
     * first draft re-tokenised any literal mentioning the words and counted three
     * sinks in `Tools/BuiltIn/Grep.php` plus one in `Tools/BuiltIn/Edit.php` — all
     * of them ENGLISH: `require`/`include` are PHP keywords, so a help string
     * reading "include the pattern" tokenises as `T_INCLUDE T_STRING T_STRING`. The
     * refusal message in `WorkflowRegistry` ("a request to require whatever appears
     * there later") was a fifth.
     *
     * So a match needs the shape of a STATEMENT, not the presence of a word: the
     * keyword must be followed by a variable or a quoted path, and the literal must
     * contain a `;`. That is what `'…require $autoload;…'` — the forked child's
     * generated source in `Sessions/BackgroundSupervisor.php`, which has no `<?php`
     * opener to test for — has and what a sentence does not.
     */
    private function requireInsideGeneratedCode(string $literal): ?string
    {
        $body = trim($literal, "'\"");
        if (!str_contains($body, ';')
            || (!str_contains($body, 'require') && !str_contains($body, 'include'))
        ) {
            return null;
        }

        $tokens = [];
        foreach (@token_get_all('<?php ' . $body) as $token) {
            if (\is_array($token) && $token[0] === \T_WHITESPACE) {
                continue;
            }

            $tokens[] = $token;
        }

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)
                || !\in_array($token[0], [\T_REQUIRE, \T_REQUIRE_ONCE, \T_INCLUDE, \T_INCLUDE_ONCE], true)
            ) {
                continue;
            }

            $operand = $tokens[$i + 1] ?? null;
            $isPath = \is_array($operand)
                && \in_array($operand[0], [\T_VARIABLE, \T_CONSTANT_ENCAPSED_STRING], true);

            if ($isPath) {
                return strtolower($token[1]);
            }
        }

        return null;
    }
}
