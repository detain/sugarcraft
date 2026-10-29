<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

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
 * of which files actually hold an enforcing {@see \SugarCraft\Code\Support\ContainedPath}
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
 *    binds ({@see \SugarCraft\Code\Tests\Workflows\WorkflowUserTierContainmentTest}
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
        // Audit 15b-15. Not a boundary and not pretending to be one: an `@`
        // mention is the person at the terminal choosing what to show the
        // model, exactly as pasting the file's text would be. What makes it
        // USER typed is enforced where the text comes from - Chat::submit()
        // never reads a file-based command's (repository-authored) expansion.
        'USER_TYPED' => 'a path the person at the terminal typed, dropped or pasted into their own prompt',
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
        'Agents/AgentManager.php|scandir' => [
            'NAMES_ONLY — pruneSessionArtifacts() (roadmap P-D1) lists the session directories '
                . 'under `subagents/` and `mailboxes/`, which this package\'s own writers create; '
                . 'links are skipped and nothing is read',
            'NAMES_ONLY — newestMtime() lists a session directory two levels down to stat ages, '
                . 'never following a link; nothing is read',
            'NAMES_ONLY — removeTree() lists the same directory to unlink it; a link is unlinked, '
                . 'never followed',
        ],
        'Agents/Board/Board.php|fopen' => [
            'SELF_LOCATED — create() makes the batch\'s board file `x` (exclusive) under a 0077 umask at the '
                . 'path Support\\ToolIpcFiles::reserve() named in the per-uid runtime directory',
            'SELF_LOCATED — post() reopens that same board file `r+` to append under flock()',
            'SELF_LOCATED — entries() reopens that same board file `r` under a shared flock(); every line is '
                . 'shape-checked as untrusted peer text',
        ],
        'Agents/DelegationSlots.php|fopen' => [
            'SELF_LOCATED — a `slot-<n>.lock` seat file in the per-scope directory this class made '
                . 'inside the PrivateDir-verified per-uid base; opened only to flock() it, nothing is read',
            'SELF_LOCATED — the stale-scope sweep opens the same seat files to test their lock, '
                . 'nothing is read',
        ],
        'Agents/DelegationSlots.php|glob' => [
            'NAMES_ONLY — the stale-scope sweep lists the scope directories in the PrivateDir-verified '
                . 'per-uid base this class owns; links are skipped',
            'NAMES_ONLY — the same sweep lists one scope directory\'s `slot-*.lock` seat files to '
                . 'test their locks',
        ],
        'Agents/AgentPresetRegistry.php|glob' => [
            'CONTAINED — the preset directory is anchored and each `*.md` confined to it',
        ],
        'Agents/AgentPresetRegistry.php|file_get_contents' => [
            'CONTAINED — the preset body, read only for a path that passed both compares',
        ],
        'Agents/AgentWorkerPool.php|glob' => [
            'SELF_LOCATED — the pool sweeps its own result directory (makeResultDirPath())',
            'SELF_LOCATED — the same sweep for the `.progress` siblings a killed worker left',
        ],
        'Agents/AgentWorkerPool.php|file_get_contents' => [
            'SELF_LOCATED — a result file this pool named and a forked child of it wrote',
            'SELF_LOCATED — the same directory, read during the drain loop',
            'SELF_LOCATED — a running agent\'s progress file, named by progressFile() '
                . 'from the pool\'s own 0700 random dir and written only by its own child',
        ],
        'Agents/ForeignAgentPresetRegistry.php|glob' => [
            'CONTAINED — `.claude/agents` / `.opencode/agents`, anchored per tier',
        ],
        'Agents/ForeignAgentPresetRegistry.php|file_get_contents' => [
            'CONTAINED — the foreign preset body, behind the same pair',
        ],
        'Agents/Live/AgentRunCards.php|file_get_contents' => [
            'SELF_LOCATED — a run card under ~/.candy-code/mailboxes/<session>/<run>/, found by the glob over '
                . 'the session directory this class names; every field is shape-checked as untrusted',
            'SELF_LOCATED — the same card, re-read under its lock to rewrite it',
        ],
        'Agents/Live/AgentRunCards.php|fopen' => [
            'SELF_LOCATED — the card\'s sibling `.lock`, opened only to flock() it, nothing is read',
        ],
        'Agents/Live/AgentRunCards.php|glob' => [
            'NAMES_ONLY — lists one session\'s run directories under ~/.candy-code/mailboxes for their card.json',
        ],
        'Agents/Live/AgentTranscriptTail.php|fopen' => [
            'CALLER_SUPPLIED — a sub-agent transcript log; AgentManager::recordChildSession() opens one only after SubAgentTranscriptLog::isLogPath() held it under the transcript root',
        ],
        'Agents/Live/SubAgentTranscriptLog.php|fopen' => [
            'SELF_LOCATED — the run\'s own log under ~/.candy-code/subagents, opened to append',
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
        'Agents/ProcessExecutor.php|require' => [
            'PROCESS_DERIVED — inside the GENERATED live worker script: the composer '
                . 'autoload of the installation already executing this code. The path is '
                . 'computed in the PARENT and reaches the child only down the stdin pipe '
                . 'proc_open() just created for it, so no party outside this process can '
                . 'name it; the child refuses a value that is not is_file(). Same verdict '
                . 'AND, since round 60, the same MECHANISM as '
                . 'Background/BackgroundSupervisor.php|require: '
                . 'ProcessExecutor::autoloadPath() delegates to '
                . 'BackgroundSupervisor::autoloadPath(), which asks the live Composer '
                . 'ClassLoader first. It used to do its own two-climb arithmetic, which '
                . 'resolved only in a root-package checkout',
        ],
        'Agents/SuspendedDelegations.php|glob' => [
            'SELF_LOCATED — sweeps its own `*.run` files in the owner-only directory HookContextFiles::verifiedDirectory() accepted',
        ],
        'Agents/SuspendedDelegations.php|file_get_contents' => [
            'SELF_LOCATED — a suspension this store wrote, named by a hex-only id in that same verified directory',
        ],
        'Agents/TaskList.php|fopen' => [
            'SELF_LOCATED — openForWrite()\'s `a`-mode flock handle on the task database this store owns; '
                . 'the handle is locked, never read',
            'SELF_LOCATED — acquireTaskLock()\'s `a`-mode flock handle on a `task_locks/<hash>.lock` beside '
                . 'that database, named from a hash; never read',
        ],
        'Agents/TeamManager.php|file_get_contents' => [
            'SELF_LOCATED — the team registry this manager writes under `~/.candy-code`',
        ],
        'Agents/WorktreeConfig.php|file_get_contents' => [
            'CONTAINED — `.candy-code/config.json`, behind the directory + file pair',
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
            'SELF_LOCATED — the same path on the write side (E137): opened `c` for the timed '
                . 'LOCK_EX, created by this manager itself and truncated only under the lock',
        ],
        'Agents/WorktreeManager.php|new RecursiveDirectoryIterator' => [
            'NAMES_ONLY — recursive pattern expansion, gated at the copy like the glob above',
        ],
        'App/App.php|file_get_contents' => [
            'SELF_LOCATED — the W4.2 Ctrl+V paste landing: the volatile temp file was written by '
                . 'Support\\ClipboardImage::save() into a 0700 directory it created itself, and the '
                . 'bytes enter the app only through ImageIngress\'s validation door',
        ],
        'Attachments/FileMentions.php|fopen' => [
            'USER_TYPED — resolve() snapshots an `@path` mention from the prompt the user submitted '
                . '(bounded: 20 files, 256 KiB of text, 5 MiB per image)',
        ],
        'Attachments/FileMentions.php|opendir' => [
            'NAMES_ONLY — complete() lists one directory\'s names for Tab completion of a mention; '
                . 'the read is resolve()\'s, on submit',
        ],
        'Attachments/WorkspaceWalk.php|opendir' => [
            'CONTAINED — collect() re-checks every frame with ContainedPath::within() before '
                . 'listing it, and every frame is built by concatenating non-link components '
                . 'onto the resolved walk root; the sink moved out of FsMethods at C-WEB-7.c and '
                . 'the memo keys on the resolved root, so caching never widens containment',
        ],
        'Bundles/Bundle.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Bundles/BundleDiscovery.php — a bundle\'s manifest.json, read by '
                . 'fromDir() and readManifest() only at paths that ride the directory discovery '
                . 'accepted through guard()\'s containment',
            'CONTAINED_UPSTREAM:Bundles/BundleDiscovery.php — the second of the two manifest '
                . 'reads above; a bundle dir is never user-supplied content, only a name under '
                . 'a container the tier\'s boundary admitted',
        ],
        'Bundles/BundleDiscovery.php|glob' => [
            'NAMES_ONLY — lists one container\'s children; the jail decision rides guard()\'s '
                . 'per-directory containment in this file, and manifest bytes are read in Bundle',
        ],
        'Bundles/BundleRecommender.php|scandir' => [
            'NAMES_ONLY — recommend() lists the caller-supplied project root one level to see '
                . 'which manifest signals exist; no content is read, nothing is installed, and '
                . 'a later content read of any of these names would carry its own gate',
        ],
        'Bundles/BundleSource.php|file_get_contents' => [
            'CONTAINED — the provenance record inside a bundle directory, gated by a '
                . 'ContainedPath::below() compare in this file against the boundary the caller '
                . 'names; a planted link out of the tree fails the compare before any bytes are read',
        ],
        'Bundles/Marketplace.php|file_get_contents' => [
            'CONTAINED — a checkout\'s catalog file, gated by a ContainedPath::below() compare '
                . 'in this file against that checkout; a .claude-plugin symlinked elsewhere '
                . 'resolves outside the boundary and answers null, as does every relative '
                . 'source through resolveRelative()\'s second compare in this same file',
        ],
        'Chat.php|file_get_contents' => [
            'SELF_LOCATED — a forked child\'s result file, named by Support\ToolIpcFiles',
            'SELF_LOCATED — takeIpcPayload(): the same ToolIpcFiles-named payload shape, read once and '
                . 'discarded by the tool fan-out and the turn-hook fork alike',
            'USER_TYPED — pastedImagePath() sniffs 16 bytes of a path the user pasted or dropped, to '
                . 'tell an image from text',
        ],
        'Cli/Bootstrap.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Providers/ProviderFactory.php — the dev provider config, whose '
                . 'two boundaries live in readableDefaultConfigPath()',
            'OWNED_HOME — `~/.candy-code/config.json`, resolved through trustedConfigDirPath()',
            'OWNED_HOME — userConfigForMerge(): the same `config.json` re-read strictly on the WRITE '
                . 'side (15d-15), under writeUserConfig()\'s lock; a symlinked config is followed only '
                . 'to a regular file requirePrivatePolicyFile() accepts (userConfigWriteTarget())',
            'OWNED_HOME — the permission policy file, additionally ownership-checked before it is read',
            'OWNED_HOME — keybindingsReloadParseFailure() (TUI-5) reading the user `config.json` and the '
                . 'user keybindings file (LayeredSettings::USER_FILE under userSettingsDirOrNull()) to '
                . 'refuse a /keys reload whose source no longer parses; both paths resolve under a home '
                . 'HomeDirectory::owned() established as this user\'s, and the bytes are only ever '
                . 'json_decode\'d into a validation verdict',
            'CONTAINED_UPSTREAM:Cli/McpDecision.php — pendingTrustMcpDeclared() (C-SEC-5.a) reading the '
                . 'project `.mcp.json` the mcpConfigDecision() path names, for the census probe: the path '
                . 'arrives already bounded by McpDecision\'s own ContainedPath::within compare (the same '
                . 'route trustProjectMcp() reads above), reached only on the UNTRUSTED or TRUSTED verdict, '
                . 'and the bytes decode to a server-count and nothing else — never executed, never launched',
        ],
        'Cli/Bootstrap.php|fopen' => [
            'SELF_LOCATED — acquireUserConfigLock(): the `.config.json.lock` sidecar beside the '
                . 'config, created by this process under umask 077 and opened `c` only for the '
                . 'timed LOCK_EX; no byte of it is read',
        ],
        'Cli/Bootstrap.php|scandir' => [
            'NAMES_ONLY — installedBundleNames() (C-EXT-4.d) lists the names under the home '
                . 'install root `~/.candy-code/bundles` to exclude already-installed entries from '
                . 'the launch recommendation row; only directory names survive the filter, no '
                . 'content is read here, and the root itself is the same owned-home location '
                . 'the `bundles` verb reads for its own listings',
        ],
        'Cli/LegacyMigration.php|fopen' => [
            'OWNED_HOME — serverRunning(): the pre-rename `server.lock` under a home '
                . 'HomeDirectory::owned() established as this user\'s, opened `c` only to attempt '
                . 'the non-blocking flock probe; absent file short-circuits on lstat and no byte is read',
        ],
        'Cli/McpDecision.php|file_get_contents' => [
            'CONTAINED — mcpServerInventory() reading the project `.mcp.json` for `candy-code mcp list`. '
                . 'Same ContainedPath::within() compare and same trust gate mcpClient() applies, because both '
                . 'come through mcpConfigDecision(); this arm is reached only on the TRUSTED verdict',
            'CONTAINED — mcpConfigDigest() hashing the two tier files the decision path names: the project '
                . '`.mcp.json` and, from MCP-3 on, the user tier `~/.candy-code/mcp.json` — at the '
                . 'memo-store point inside mcpClient(), which notes one digest per tier this launch may '
                . 'read (behind the identical mcpConfigDecision() gate), and from '
                . 'mcpConfigChangedSinceLaunch(), which re-runs that decision for the same root and '
                . 're-reads each tier it stored before '
                . 'reading. file_get_contents rather than hash_file on purpose — the latter spelling is '
                . 'outside this census\'s sink vocabulary and would be an ungazed read on a '
                . 'repository-chosen path',
            'CONTAINED — trustProjectMcp() reading the decision-path `.mcp.json` for `candy-code mcp trust` '
                . '(audit MCP-5): the same mcpConfigDecision() containment, reached only on the UNTRUSTED or '
                . 'TRUSTED verdict; it is fingerprinted and printed, never executed',
            'OWNED_HOME — mcpUserTierRows() reading the user tier `~/.candy-code/mcp.json` for the listing '
                . '(MCP-3): reached only when HomeDirectory::owned() established the home as this user\'s and '
                . 'the file exists; the same three broken-shape reports as the project read, and the bytes '
                . 'are listed and shadowed, never executed',
        ],
        'Cli/Attach.php|file_get_contents' => [
            'SELF_LOCATED — token(): the owner-token file in the `serve` state dir '
                . 'StateDir::existing() verified 0700 and ours; lstat refuses anything but a '
                . 'regular file, and it is read, never minted (roadmap O-8a)',
        ],
        'Cli/NonInteractive.php|file_get_contents' => [
            'CALLER_SUPPLIED — `--json-schema` operand (AUT-4): the operator\'s own argv names the '
                . 'file; an inline `{` document never reaches this sink, a miss is refused before '
                . 'reading (is_file + is_readable), and the bytes are only ever json_decode\'d into '
                . 'a responseSchema shape — never executed, never written back',
            'CALLER_SUPPLIED — `--append-system-prompt` @operand (C-CTX-4.g): the operator\'s own '
                . 'argv names the file, a miss is refused before reading (is_file + is_readable), '
                . 'the bytes are bounded at the instruction-document ceiling before use, and they '
                . 'ride only as PromptFence-escaped rules text — never executed, never written back',
        ],
        'Cli/SelfUpdate.php|file_get_contents' => [
            'SELF_LOCATED — verifySignature() (DST-3) reads back the temp phar this very process '
                . 'downloaded to its own `phar.tmp-<pid>-<random>` name, for the detached-signature leg '
                . 'against the pinned release key; the path is never externally chosen',
        ],
        'Cli/Serve.php|file_get_contents' => [
            'SELF_LOCATED — storedToken(): the owner-token file in the `serve` state dir '
                . 'StateDir::existing() verified 0700 and ours; lstat refuses anything but a '
                . 'regular file, and it is read, never minted',
        ],
        'Cli/Serve.php|fopen' => [
            'SELF_LOCATED — tail(): server.log in the verified state dir, which a detached '
                . 'server writes; lstat-checked as a regular file before it is opened',
            'SELF_LOCATED — follow(): the same log, re-opened at the offset already printed',
        ],
        'Host/Commands/AgentsHostCommand.php|glob' => [
            'NAMES_ONLY — proposedListing() lists the `*.md` names in the project\'s own '
                . '`.candy-code/agents-proposed/` draft directory for the /agents proposed table',
        ],
        'Host/Commands/AgentsHostCommand.php|file_get_contents' => [
            'CONTAINED — the draft\'s frontmatter, read only for a name that passed DRAFT_NAME and whose '
                . 'real path ContainedPath::below holds under the project root, so a grafted directory '
                . 'link reads nothing outside it',
            'CONTAINED — the accept leg\'s draft body, behind the same name/path doors, validated against '
                . 'FrontmatterKeyAudit::KNOWN before the consent print and the rename into `.candy-code/agents/`',
        ],
        'Host/Commands/NewAgentPrompt.php|glob' => [
            'NAMES_ONLY — existingPresets() lists the `*.md` names in the live and draft directories '
                . 'so the draft prompt can name the taken ones without telling the agent to overwrite; '
                . 'only the basename is kept, no bytes are read',
        ],
        'Host/Commands/NewRulePrompt.php|glob' => [
            'NAMES_ONLY — existingRules() lists the `*.md` names in the project\'s own `.candy-code/rules/` '
                . 'so the /newrule prompt can name the taken ones; nothing is read',
        ],
        'Host/TurnController.php|file_get_contents' => [
            'SELF_LOCATED — a forked child\'s result file (roadmap O-2g takePayload()), named by '
                . 'Support\\ToolIpcFiles::reserve() in this process before the fork, read once and discarded',
        ],
        'Commands/CommandLoader.php|new RecursiveDirectoryIterator' => [
            'CONTAINED — the commands directory is anchored to its tree and each `*.md` confined to it',
        ],
        'Cli/Subcommands.php|scandir' => [
            'USER_TYPED — copyBundleTree() lists the install source the person at the terminal '
                . 'named on `bundles install`; the destination is this command\'s own container',
            'SELF_LOCATED — removeTree() sweeps only the scratch paths this verb minted inside '
                . 'its own root',
            // C-EXT-4.b: bundlesBundleRootNames() lists the command's OWN bundle
            // root (under HomeDirectory::owned()) names-only, to pick which
            // installed bundles `bundles update` walks and `recommend` excludes.
            'SELF_LOCATED — bundlesBundleRootNames() reads only the names directly under '
                . 'the operator-owned `~/.candy-code/bundles` root this command minted',
        ],
        'Commands/BangShell.php|file_get_contents' => [
            'SELF_LOCATED — the `!cmd` result payload (roadmap 5.14g): the name ToolIpcFiles::reserve() '
                . 'chose in this process before the fork, written 0600 by its own forked child, '
                . 'read once and discarded',
        ],
        'Commands/CommandSpec.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Commands/CommandLoader.php — parses a path the loader already bounded',
            'CONTAINED — includeFile() reads an `@path` written inside a command file, behind this '
                . 'file\'s own ContainedPath::within() compare against the checkout',
        ],
        'Commands/Specs/BuiltInCommands.php|require' => [
            'PROCESS_DERIVED — the `NNNN-<name>.php` spec files the scandir below listed, in the '
                . 'installation\'s own builtin-commands/ located from `__DIR__`; each must return a '
                . 'BuiltInCommand. Nothing outside the shipped source can name one',
        ],
        'Commands/Specs/BuiltInCommands.php|scandir' => [
            'NAMES_ONLY — lists `builtin-commands/*.php`, located from `__DIR__`: the installation\'s '
                . 'own shipped spec files (DH-CMDS); scandir not glob since DST-1 so the listing '
                . 'reaches inside a phar:// archive, the .php filter reproducing the old glob set; '
                . 'no config, project or $HOME path reaches it',
        ],
        'Commands/EditorCommand.php|file_get_contents' => [
            'SELF_LOCATED — the `/editor` temp file: tempnam() created it owner-only in the system temp '
                . 'directory, it is read back once after the user\'s editor exits, and unlinked',
        ],
        'Commands/ShareCommand.php|scandir' => [
            'NAMES_ONLY — sweepExpiredExports() (rv-devops-4) lists the share-exports root under '
                . 'HomeDirectory::owned() for file mtimes only; links and non-files are skipped, no '
                . 'export content is ever read here, and only a regular file past the retention '
                . 'cutoff is unlinked',
        ],
        'Config/LayeredSettings.php|file_get_contents' => [
            // NOT `CONTAINED`, which is what this row said first and is true of only ONE of the two '
            // callers — the recurring defect in a single word. readFile() is private and both of its
            // callers hold their own boundary, of two DIFFERENT kinds, so the verdict has to be the
            // one that is true of both.
            'CALLER_SUPPLIED — readFile() is the ONE read behind the whole settings layering, and '
                . 'each caller bounds the path before reaching it, differently: projectLayer() only '
                . 'past ContainedPath::below() on `<root>/.candy-code` and ContainedPath::within() '
                . 'on the file, plus a trust grant already established for that root '
                . '(LayeredSettings::PROJECT_SETTINGS_TRUST_KEY); userLayer() takes its DIRECTORY '
                . 'from its caller and in production is handed '
                . 'Bootstrap::userSettingsDirOrNull() — trustedConfigDirPath(), the home-OWNED '
                . '~/.candy-code, deliberately NOT dirname(userConfigPath()) and so not '
                . 'relocatable by --config',
            // w1e (v1 F-4, spine commit 9c157d64e): the second sink is the
            // corrupt-file diagnosis re-read, same caller-holds-the-boundary
            // shape — keyAuditRows() hands it only the paths projectFiles()
            // and the audit's own $configDir built, and the doc-block there
            // argues the trust point: reading for a report is not applying.
            'CALLER_SUPPLIED — unusableSettingsFileReason() re-reads the exact path the audit '
                . 'walk just assembled (the caller passes no external name), purely to tell a '
                . 'valid-but-empty `{}` from a corrupt one; the bytes are decoded and '
                . 'summarised into a launch/doctor row, never merged into settings — whether '
                . 'the file CONtributes stays with the trust grant above',
        ],
        'Config/PolicySnapshot.php|file_get_contents' => [
            'CALLER_SUPPLIED — read() resolves the snapshot path through the caller\'s '
                . '$configDir callable; production hands it Bootstrap\'s trusted config dir (the '
                . 'home-OWNED ~/.candy-code), and a missing or malformed record degrades to '
                . 'null (baseline reset / entry-level-only quarantine), never a crash path.',
            'CALLER_SUPPLIED — fingerprint() hashes the exact bytes of a policy file whose '
                . 'path the caller names (user config / hooks files in Bootstrap); a file that '
                . 'cannot be read counts as ABSENT (null), the same verdict the loader itself '
                . 'would reach, so the snapshot never admits a path the caller did not already '
                . 'trust it to hash',
        ],
        'Config/ProviderKeyStore.php|file_get_contents' => [
            'CALLER_SUPPLIED — `store()`\'s providers-map merge-read: Bootstrap::userConfigPath(), '
                . 'the operator\'s own config file — the same read `login` performs (credited on the '
                . 'Cli/Subcommands.php row) — decoded only to keep the other providers\' entries when '
                . 'the map is rewritten through Bootstrap::writeUserConfig(); the corrupt-config '
                . 'branch refuses, never repairs (C-TUI-5.d extraction)',
            'CALLER_SUPPLIED — `store()`\'s write-back re-read: the SAME path, re-read only to confirm '
                . 'the slot settled before any success is promised (writeUserConfig() can bail '
                . 'unwritten); the never-fabricate law lifted from Subcommands::loginStoreKey',
        ],
        'Config/StatusLineCommand.php|file_get_contents' => [
            'SELF_LOCATED — collect(): the status-line child\'s result payload, the name '
                . 'Support\\ToolIpcFiles::reserve() created for this run, read once after the '
                . 'child exits and immediately discarded; decoded as JSON, never executed',
        ],
        'Context/Compaction/ReinjectionPlan.php|file_get_contents' => [
            'CONTAINED — renderFiles() (roadmap 2.6): a file the conversation\'s Read/Edit/Write calls '
                . 'touched, re-read after a compaction only when ContainedPath::below() holds it under the '
                . 'project root; size-capped, never binary',
        ],
        'Context/ImportResolver.php|file_get_contents' => [
            'CALLER_SUPPLIED — an `@file` import, judged by the $boundaryCheck callback the caller '
                . 'supplies (InstructionFileLoader passes one built on ContainedPath)',
        ],
        // plan_comparison CTX-3: the instruction-diff tracker. BOTH reads take
        // their paths from InstructionFileLoader documents — every emitted path
        // passed one of the loader's eight ContainedPath::within() gates on the way
        // out (loadPersonal()'s entries are gated against their own home-dir anchor,
        // see InstructionFileLoader's class doc-block) — and the tracker adds a
        // staleness gate of its own: a realpath that moved since capture is
        // REFUSED, never followed, so a re-read can never escape on the old verdict.
        'Context/InstructionDiffTracker.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Context/InstructionFileLoader.php — capture(): re-reads the bytes of a '
                . 'loader document path, one admitted only through the loader\'s own ContainedPath gate; '
                . 'an over-cap document (body null) is stat-tracked and never read here',
            'CONTAINED_UPSTREAM:Context/InstructionFileLoader.php — recheck(): reads only after the stat '
                . 'moved AND realpath still equals the path captured under the loader\'s gate — a moved '
                . 'inode is degraded to a pointer line, not followed',
        ],
        // One sink since audit 15d-09 / C3 moved the four document reads into
        // readBounded(), the size-bounded read; the four read DECISIONS are
        // unchanged and each still compares its path before calling it.
        'Context/InstructionFileLoader.php|file_get_contents' => [
            'CONTAINED — readBounded(), the one size-bounded read behind all four instruction-document '
                . 'read decisions, each of which compares its path with ContainedPath BEFORE calling it: '
                . 'the root instruction file and a walked-to one against $repoRoot, a configured '
                . '`instructions:` glob match against $repoRoot, and an ancestor (monorepo-parent) file '
                . 'against InstructionFileLoader::ancestorRoot() rather than $repoRoot — by construction '
                . 'outside $repoRoot, so that boundary would refuse every one of them; the boundary it '
                . 'uses is established by a positive `.git` marker found above $repoRoot, never by a '
                . 'walk to `/`',
        ],
        // Three sinks and THREE gates, which is the correction. This row set
        // shipped saying only one of the three followed a path the repository
        // chose, on the reasoning that a scandir() entry holds no separator and
        // that the composer.json path was therefore assembled entirely by the
        // caller. Both sentences were about the path STRING; the escape was in
        // the file it RESOLVED to. A committed directory symlink (git mode
        // 120000, materialised by clone) put an out-of-root manifest's psr-4
        // prefix and description into the system prompt. See
        // Context/RepoMapBlock's ON PATHS THAT COME FROM CONTENT.
        'Context/RepoMapBlock.php|scandir' => [
            'NAMES_ONLY — the immediate children of the root, enumerated as CANDIDATE sub-package '
                . 'directories. A scandir() entry cannot contain a separator, but the directory it '
                . 'names can be a symlink out of the tree, so the name is not the gate: each '
                . 'candidate is refused unless ContainedPath::below() puts it strictly inside the '
                . 'root, and the manifest read below is gated again at its own sink',
            // The source walk was a RecursiveDirectoryIterator until audit 15d-17
            // made it a sorted scandir() walk; the sink moved, the gate did not.
            'CONTAINED — the one path in this file that CONTENT steers: a manifest\'s '
                . '`autoload.psr-4` values are written by whoever wrote the repository, and each is '
                . 'refused by ContainedPath::within() against the root before the sorted walk '
                . 'lists it; the walk never enters a symlinked directory',
        ],
        'Context/RepoMapBlock.php|glob' => [
            'NAMES_ONLY — expansion of a `repositories: {type: path}` url from the ROOT manifest, '
                . 'which is content; a match outside the root is dropped here for want of a '
                . 'relative name and refused again by the ContainedPath::below() gate on each '
                . 'candidate',
        ],
        'Context/RepoMapBlock.php|file_get_contents' => [
            'CONTAINED — a composer.json at `$root` or under a candidate directory. The path is '
                . 'assembled from App::$root (`--root`) and a scandir()/glob() entry, and NONE OF '
                . 'THAT MAKES THE READ SAFE: is_file(), is_readable() and file_get_contents() all '
                . 'follow symlinks, so a committed link is what chooses the file. '
                . 'ContainedPath::within() sits inside readManifest() itself, at the sink rather '
                . 'than at its callers, so a third caller cannot ship without it',
        ],
        'Context/InstructionFileLoader.php|glob' => [
            'NAMES_ONLY — expansion of the configured glob; each match is compared before it is read',
        ],
        // A RecursiveDirectoryIterator until audit 15d-17 made the tier walk a
        // sorted scandir() walk; the anchor and per-entry gate are unchanged.
        'Context/RuleLoader.php|scandir' => [
            'CONTAINED — the tier directory is anchored to its tree (project/root) or to the owned '
                . 'HOME/.candy-code (user), and each `*.md` is confined to the resolved directory '
                . 'before its bytes are read',
        ],
        'Context/RuleLoader.php|file_get_contents' => [
            'CONTAINED — a rule body, read only for a path that already passed the directory anchor '
                . 'and the per-entry within() compare',
        ],
        'Diagnostics/TuiErrorLog.php|fopen' => [
            'SELF_LOCATED — the TUI error log, `~/.candy-code/logs/candy-code.log` under the '
                . 'owned home bin/candy-code passes in; opened `ab` only to create it under a 0077 '
                . 'umask and write one launch header — nothing is read back. A symlinked file or a '
                . 'world-writable directory is refused before the open (audit C2a)',
        ],
        // plan_comparison EDT: the post-edit formatter's before/after pair. The
        // path reaching both reads is the one PathJail::resolve() produced for the
        // file the Edit call just made — the same jail LintRunner's step 3.E row
        // claims, and stricter: the formatter is handed the file for WRITING, so a
        // path Edit could not reach is never formatted here at all.
        'Format/FormatRunner.php|file_get_contents' => [
            'PATH_JAIL — format(): the restore baseline. `$absolute` is `PathJail::resolve($root, '
                . '$path)` re-checked by `is_file()` and skipped when it is a tool-output spill; the '
                . 'bytes go to a hash and to `guard()`, never to the model',
            'PATH_JAIL — guard(): the read-back that decides whether the formatter run earned its '
                . 'write — same jail-resolved path, compared to the baseline hash',
        ],
        // plan_comparison C-TOOL-1: the pre-format evidence for the ledger-
        // agreement check that keeps a formatter from blessing an already-
        // stale file. Same jail the FormatRunner row above claims — the path
        // is the model-spelled tool argument resolved against the hook's
        // project root, and the bytes are only ever compared to the ledger's
        // stored hash, never rendered and never kept.
        'Hooks/BuiltIn/PostEditFormatHook.php|file_get_contents' => [
            'PATH_JAIL — preFormatBytes(): `$absolute` is `PathJail::resolve($context->projectRoot, '
                . '$path)`, size-capped at `FormatRunner::MAX_SOURCE_BYTES` like the runner\'s own '
                . 'baseline read; the bytes feed only `ReadLedger::staleness()` evidence',
        ],
        'Hooks/BuiltIn/AuditLogReader.php|fopen' => [
            'SELF_LOCATED — window(): the `audit.log` (and its rotated `audit.log.1`) inside the per-uid '
                . 'audit directory `AuditHook::defaultLogDirectory()` names, which AuditHook creates and '
                . 'appends; lstat refuses anything but a regular file before the open, and every read is '
                . 'a bounded tail window. A serve under a different HOME/TMPDIR simply finds no file — '
                . '`filesize()` false answers as the empty trail the class note promises',
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
            'CALLER_SUPPLIED — `hooks.yaml`; this class holds no boundary. Cli\\Bootstrap resolves '
                . 'the user copy through trustedConfigDirPath() (owned home) and puts the project '
                . 'copy behind projectHooksAreTrusted(), which refuses the LAUNCH rather than the read',
        ],
        'Hooks/ClaudeSettingsHooks.php|file_get_contents' => [
            'CALLER_SUPPLIED — `hooks` block of a `.claude/settings*.json`; same resolution as the '
                . 'HookConfig row above and for the same reasons: user copy from the owned home '
                . '(requirePrivatePolicyFile also screens it), project copies behind '
                . 'projectHooksAreTrusted(), which refuses the LAUNCH rather than the read',
        ],
        'LSP/LspClient.php|file' => [
            'CALLER_SUPPLIED — the URI came from the editor request this client is answering',
            'CALLER_SUPPLIED — declarationsIn(): the regex outline of a path the Read tool already '
                . 'resolved through its workspace jail (step 3.F)',
        ],
        // Step 3.F: the bytes a touch sends the language server in `didOpen` —
        // freshDiagnostics() and outline(). Every caller resolved the path
        // through a jail first: LspTool and Read their own, the post-edit
        // diagnostics hook PathJail::resolve() against the project root.
        'LSP/LspClient.php|file_get_contents' => [
            'CALLER_SUPPLIED — freshDiagnostics(): a path LspTool or PostEditDiagnosticsHook already '
                . 'resolved through the workspace jail',
            'CALLER_SUPPLIED — outline(): a path the Read tool already resolved through its workspace jail',
        ],
        // Audit B7: the exchange lock's sidecar files. Every path is `<lock>.<suffix>`
        // where <lock> is the file the connecting process created; forks read the
        // same files because they inherited that path, not because content named it.
        // Audit B8: the lock is no longer a tempnam() — create() names it
        // `candy-code-lsp-lock-<pidns>-<pid>-<rand>` in the temp dir, and
        // sweepStale() reads that same directory for this class' own prefix.
        'LSP/LspExchangeLock.php|file_get_contents' => [
            'SELF_LOCATED — load(): the `.state` file beside the lock create() made',
            'SELF_LOCATED — loadFrame(): the `.frame` file beside the same lock',
            'SELF_LOCATED — journal(): the `.notes` file beside the same lock',
        ],
        'LSP/LspExchangeLock.php|fopen' => [
            'SELF_LOCATED — create(): the exclusive (`x`) create of a fresh owner-named lock in the temp dir',
            'SELF_LOCATED — sweepStale(): a dead owner\'s lock, matched by this class\' own FILE_PREFIX name '
                . 'shape in the temp dir, opened only to take its flock before the unlink',
            'SELF_LOCATED — handle(): the lock file itself, opened for flock()',
        ],
        'LSP/LspExchangeLock.php|glob' => [
            'SELF_LOCATED — sweepStale(): the temp dir listed for this class\' own FILE_PREFIX',
            'SELF_LOCATED — destroy(): the owner sweeping its own `<lock>.*` sidecars and temps',
        ],
        // C-TOOL-5.c: the pinned release download for `lsp install rust`. The
        // tree is the private `.candy-code/lsp` dir (HomeDirectory::owned()
        // by default, and PrivateDir::ensure screens whatever root a caller
        // names BEFORE any fetch), the temp carries this class' own
        // `.dl-<pid>-<rand>.gz` spelling, and nothing opens until the bytes
        // already match the manifest's pinned sha256.
        'LSP/LspInstaller.php|fopen' => [
            'OWNED_HOME — the gzip read of this class\'s own `.dl-` download temp inside the private install tree',
            'OWNED_HOME — the staged decompression write beside that temp; only the checksummed stream lands there, and it renames into bin/ when closed',
        ],
        // WAS `CALLER_SUPPLIED — nothing in src/ builds one yet, so the first caller
        // owns the boundary`, and the first caller has now arrived:
        // Cli\Bootstrap::mcpClient() resolves `$root/.mcp.json` and refuses it
        // through ContainedPath::within() before constructing the client. A row
        // whose rationale is "nobody calls this" expires the moment somebody does,
        // which is the transition this ledger exists to make visible.
        'Cli/Subcommands.php|file_get_contents' => [
            'CALLER_SUPPLIED — `doctor`\'s config-file check: Bootstrap::userConfigPath(), the operator\'s '
                . '`--config` path or `~/.candy-code/config.json`, read only to be JSON-validated and reported',
            'CALLER_SUPPLIED — `mcp import`\'s operand: the operator\'s own argv names the file, '
                . 'the verb reads one document, translates it through the shared McpForeignTranslate '
                . 'table, and PRINTS (the never-write law); nothing read here is ever executed',
            // W4 (PRV-4): `candy-code login` merges its new key onto the file's EXISTING
            // providers map — same operator-named path as the `doctor` row above.
            'CALLER_SUPPLIED — `login`\'s merge-read (Subcommands::loginStoreKey): Bootstrap::userConfigPath(), '
                . 'decoded to keep the other providers\' entries when the map is rewritten through '
                . 'Bootstrap::writeUserConfig(); the corrupt-config branch refuses, never repairs',
            'CALLER_SUPPLIED — `login`\'s write-back re-read: the SAME path, re-read only to confirm the '
                . 'slot settled before promising it on stdout (writeUserConfig() can bail unwritten)',
            // C-EXT-4.b: the three bundlesGitSha() reads of a checkout's OWN git
            // metadata — HEAD, the loose ref HEAD names, and packed-refs as the
            // fallback. The directory is either a scratch the clone seam just
            // minted inside the command's own root or the operator-named source
            // directory; only a 40-hex line ever leaves the function.
            'SELF_LOCATED — `bundlesGitSha()`\'s read of `<dir>/.git/HEAD` in a checkout this '
                . 'verb minted (or the operator-named marketplace source dir); only a ref name '
                . 'or a bare 40-hex commit is ever parsed out',
            'SELF_LOCATED — `bundlesGitSha()`\'s follow-on read of the loose ref file HEAD '
                . 'names, inside the same checkout; a `..` traversal in the ref line is refused first',
            'SELF_LOCATED — `bundlesGitSha()`\'s packed-refs scan when the loose ref file is '
                . 'unreadable, the same checkout\'s own `.git/packed-refs` index',
        ],
        'Lint/LintRunner.php|file_get_contents' => [
            'PATH_JAIL — the file a Write/Edit call named, resolved through PathJail against the hook\'s '
                . 'project root exactly as Edit resolves it, so a refused edit cannot lint (and quote) a file '
                . 'outside the workspace (step 3.E)',
        ],
        'MCP/McpClient.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Cli/McpDecision.php — `$root/.mcp.json`, bounded against the root '
                . 'that named it before this class is constructed. Still a constructor argument, so an '
                . 'EMBEDDER building one directly owns its own boundary; the launch path has one',
        ],
        'MCP/McpTrustPins.php|file_get_contents' => [
            'CALLER_SUPPLIED — the trust record path (audit MCP-5); both call sites — '
                . 'Bootstrap::claudeMcpGrant() and McpDecision::trustProjectMcp() — name '
                . '`~/.candy-code/mcp-trust.json` through trustedConfigDirPath(). Hashes and summaries only, '
                . 'never executed',
        ],
        'MCP/OAuthClientRegistration.php|file_get_contents' => [
            'SELF_LOCATED — `~/.local/share/candy-code/mcp-auth.json`, written by this class',
        ],
        'MCP/OAuthClientRegistration.php|fopen' => [
            'SELF_LOCATED — acquireAuthLock(): the `mcp-auth.json.lock` sidecar beside the auth '
                . 'file, created by this process under umask 077 and opened `c` only for the timed '
                . 'LOCK_EX that serialises the read-merge-write; no byte of it is read',
        ],
        'Memory/AutoMemoryConsolidator.php|file_get_contents' => [
            'SELF_LOCATED — readState(): the `.auto-memory-<key>.json` throttle this class writes beside '
                . 'the home store\'s notes (roadmap 5.2); decoded as JSON, never executed',
        ],
        'Memory/CompactionJournal.php|file' => [
            'SELF_LOCATED — entries(): the `.compaction-journal-<key>.jsonl` this class appends beside '
                . 'the home store\'s notes (roadmap 5.4-1); each line decoded as JSON, never executed',
        ],
        'Memory/CompactionJournal.php|file_get_contents' => [
            'SELF_LOCATED — scanFrom(): the one byte BEFORE the stored cursor offset, probed to '
                . 'prove the resume point sits on a line boundary before anything is read from it',
        ],
        'Memory/CompactionJournal.php|fopen' => [
            'SELF_LOCATED — scanFrom(): the same journal opened `rb`, read forward from the '
                . 'validated byte cursor; each line decoded as JSON, never executed',
            'SELF_LOCATED — trimBefore(): opened `r+b` under LOCK_EX to drop the compacted prefix; '
                . 'only this class\'s own journal is ever rewritten',
            'SELF_LOCATED — write(): the same journal opened `ab` for one locked append; no byte of it is read',
        ],
        'Memory/DreamPass.php|file_get_contents' => [
            'SELF_LOCATED — readState(): the `.dream-<key>.json` throttle and journal cursor this class writes '
                . 'beside the home store\'s notes (roadmap 5.4-3); decoded as JSON, never executed',
        ],
        'Memory/ForeignMemoryImporter.php|file_get_contents' => [
            'CONTAINED — a `.opencode/memory` file behind the project tier\'s anchor',
            'CONTAINED — the user tier\'s, behind HomeDirectory::owned()',
        ],
        // glob() until audit 15d-06: the memory directory went in as part of
        // the pattern, so a `[`/`*`/`?` in a checkout or home path listed
        // nothing of its own (or a sibling tree's notes). Now scandir().
        'Memory/ForeignMemoryImporter.php|scandir' => [
            'NAMES_ONLY — enumeration inside a directory both compares already accepted',
        ],
        // Seven glob() sites until audit 15d-22: the store's own path went in
        // as part of the pattern, so a `[`/`*`/`?` in a checkout path listed
        // nothing (or a sibling tree). Every listing and id lookup now goes
        // through the one scandir() in directoryNames(); lookups build the path.
        'Memory/MemoryStore.php|scandir' => [
            'SELF_LOCATED — the names inside the store\'s own directory or one of its scope '
                . 'directories, for every listing and id lookup',
        ],
        'Memory/MemoryStore.php|file_get_contents' => [
            'SELF_LOCATED — the home store\'s legacy shared project/MEMORY.md, read only to tell a generated '
                . 'index (retired when its notes bind to a project, audit 15d-05) from a hand-written one',
            'SELF_LOCATED — the .bound-legacy record a keyed project directory of this store wrote (audit 15d-05)',
            'SELF_LOCATED — a repo store\'s scope MEMORY.md, read only to tell a generated index (retired, audit N2) '
                . 'from a hand-written one; never returned or rendered',
            'SELF_LOCATED — a scope index this store wrote',
            'SELF_LOCATED — an entry this store wrote',
        ],
        'Protocol/Methods/AgentsMethods.php|fopen' => [
            'CALLER_SUPPLIED — `agents.transcript` (roadmap O-6c): a sub-agent transcript log, opened only '
                . 'after SubAgentTranscriptLog::isLogPath() held the announced path under the transcript root, '
                . 'or the path forRun() builds there; read in bounded pages',
        ],
        'Protocol/Methods/FilesMethods.php|file_get_contents' => [
            'PATH_JAIL — `files.read` (roadmap O-3b): a path a server client named, resolved through '
                . 'PathJail::resolve() against the served project root before it is read, size-capped',
        ],
        'Protocol/Methods/MediaMethods.php|fopen' => [
            'CALLER_SUPPLIED — media.fetch slices a client path only after a literal ".." refusal and a '
                . 'realpath containment to the session media root (W6.2 jail); a symlink escaping the root '
                . 'resolves outside it and is refused, and the fd is read in bounded slices, never whole',
            'SELF_LOCATED — the ledger atomic-publish temp (.partial beside jobs/<jobId>.json the method '
                . 'itself mints from the MediaJob id, xb + 0600 + rename), never caller-supplied',
        ],
        'Protocol/Methods/MediaMethods.php|file_get_contents' => [
            'SELF_LOCATED — generate.status reads back only the jobs/<jobId>.json ledger this class wrote '
                . 'under the session media root; the path is derived from the store layout, not from the request',
        ],
        'Providers/ModelMetadata.php|file_get_contents' => [
            'OWNED_HOME — readCache(): `~/.candy-code/cache/model_prices_and_context_window.json` under '
                . 'HomeDirectory::owned() on the production path, new(); cachedAt() is the test and embedder '
                . 'seam whose caller names the file. Read as numbers only, never executed (roadmap 5.13a)',
        ],
        'Providers/ModelMetadata.php|fopen' => [
            'OWNED_HOME — acquireLock(): the `.lock` sidecar beside that cache, opened `c` only for a '
                . 'non-blocking LOCK_EX that keeps two processes from refreshing at once; no byte of it is read',
        ],
        'Providers/ProviderFactory.php|file_get_contents' => [
            'CONTAINED — fromProjectConfig(), behind readableDefaultConfigPath()',
            'CONTAINED — projectProviderConfig(), behind the same pair',
        ],
        'RepoMap/CtagsSymbolExtractor.php|file_get_contents' => [
            'CALLER_SUPPLIED — the reference scan of a file the RepoMap tool listed with `git ls-files` under '
                . 'its root, skipped when a symlink and re-resolved through PathJail before it is handed over; '
                . 'size-capped at MAX_FILE_BYTES before the read',
        ],
        'RepoMap/RepoMapBuilder.php|file_get_contents' => [
            'PATH_JAIL — the renderer\'s line source (W2-j carry-over), shared by the RepoMap tool and the '
                . 'prompt\'s SymbolMapBlock since 5.5-5: a path from the builder\'s own `git ls-files` listing, '
                . 're-resolved through PathJail against the root, a symlink refused, and nothing read past '
                . 'PhpSymbolExtractor::MAX_FILE_BYTES',
        ],
        'RepoMap/PhpSymbolExtractor.php|file_get_contents' => [
            'CALLER_SUPPLIED — extractFile()\'s source read, for a path TagCache::tagsFor() received from the '
                . 'RepoMap tool\'s `git ls-files` listing, PathJail-resolved and symlinks skipped; size-capped at '
                . 'MAX_FILE_BYTES before the read '
                . '(W2-j carry-over, invisible until the census learned the `\\file_get_contents` spelling)',
        ],
        'Runtime.php|file_get_contents' => [
            'SELF_LOCATED — a forked child\'s result file, named by Support\ToolIpcFiles',
        ],
        'Server/Auth/SessionFileStore.php|file_get_contents' => [
            'SELF_LOCATED — sessions.json, the hash-only cookie rows this store itself wrote 0600 into the '
                . '`serve` state dir PrivateRetainedDir::verified() created 0700 and checked is ours; lstat '
                . 'refuses a link, it is decoded as JSON at depth 8 and only ever yields sha256 hashes',
        ],
        'Server/Auth/TokenStore.php|file_get_contents' => [
            'SELF_LOCATED — the owner-token file this store minted, in the `serve` state dir '
                . 'PrivateRetainedDir::verified() created 0700 and checked is ours; lstat refuses a link',
        ],
        'Server/DiscoveryFile.php|file_get_contents' => [
            'SELF_LOCATED — server.json, the record a server wrote 0600 into the state dir '
                . 'PrivateDir verified 0700 and ours; lstat refuses a link, and it is decoded as '
                . 'JSON at depth 8, never executed',
        ],
        'Server/Http/StaticFiles.php|file_get_contents' => [
            'CONTAINED — a static asset, read only for a path ContainedPath::within() keeps under the web root',
        ],
        'Server/StateDir.php|fopen' => [
            'SELF_LOCATED — server.lock in the verified state dir, opened `c` under umask 077 '
                . 'only to hold flock(); lstat refuses anything but a regular file and no byte '
                . 'of it is read',
        ],
        'Session/Import/SessionImporter.php|file_get_contents' => [
            'USER_TYPED — importFile() reads the transcript file the person at the terminal named for '
                . 'import (C-SES-7.a); is_file() gates it, the byte bound is checked before the read, and '
                . 'the content only ever becomes session transcript rows after redaction',
        ],
        'Session/PromptHistory.php|file' => [
            'SELF_LOCATED — ~/.candy-code/prompt_history.jsonl, the path Bootstrap::promptHistory() names',
        ],
        'Session/PromptHistory.php|fopen' => [
            'SELF_LOCATED — the same file, opened c+ under an exclusive lock to append',
        ],
        'Session/SessionLock.php|file_get_contents' => [
            'SELF_LOCATED — <configDir>/sessions/<id>.lock, read for the holder pid the read-only '
                . 'notice names (audit SES-3(b)); the id is hashed unless it is in the minted alphabet',
        ],
        'Session/SessionLock.php|fopen' => [
            'SELF_LOCATED — the same lock file, opened c+e and flock()ed to hold the session (audit SES-3(b))',
        ],
        'Session/SessionStore.php|file_get_contents' => [
            'CALLER_SUPPLIED — gitBranchAt(): a linked worktree\'s `.git` pointer file in or above the '
                . 'directory Bootstrap::openSession()/seedSession() passes (the process cwd); 4 KB, only a '
                . '`gitdir:` line is taken (audit B1)',
            'CALLER_SUPPLIED — gitBranchAt(): that gitdir\'s HEAD, the same file `git branch --show-current` '
                . 'reads; 4 KB, and only a `ref: refs/heads/<name>` line is kept, as the session\'s branch',
        ],
        'Share/JsonlTranscriptWriter.php|fopen' => [
            'SELF_LOCATED — the journal append: `ab` on a path this class assembled from its '
                . 'defaultRoot()/baseDir seam, a slugFor() project segment and a segment()-contained '
                . 'session id, under a 0077 umask with the 0700 dir and symlink doors checked first; '
                . 'nothing is read back through this handle',
        ],
        'Share/JsonlTranscriptWriter.php|file_get_contents' => [
            'OWNED_HOME — the high-water replay read of the journal file at exactly the path '
                . 'save() just assembled, under the ~/.candy-code/transcripts root '
                . 'HomeDirectory::owned() establishes (tests hand a temp root to the baseDir seam)',
        ],
        'Background/BackgroundSessionRunner.php|file_get_contents' => [
            'SELF_LOCATED — the per-spawn token file the supervisor minted in its 0700 '
                . 'IPC dir, read to authenticate the daemon\'s handshake (audit M5)',
            'SELF_LOCATED — the same token file, re-read per connection to gate '
                . 'HEARTBEAT/RESUME/STOP (audit M5)',
        ],
        'Background/BackgroundSessionRunner.php|fopen' => [
            // w1e (v1-m-2, spine commit eb07cb95b): both opens are the same
            // sidecar rotation on the path `$this->bufferPath . '.log'` — a
            // file this daemon process creates beside the buffer file it
            // minted for its own session.
            'SELF_LOCATED — trimSidecarLog()\'s read of its own sidecar log, '
                . 'fseek-ed to the tail window and copied out to the `.1` '
                . 'generation before the truncate',
            'SELF_LOCATED — trimSidecarLog()\'s `r+b` re-open of the same '
                . 'path, truncated in place so the O_APPEND writer\'s open '
                . 'file description keeps feeding the fresh log',
        ],
        'Background/BackgroundShellRegistry.php|file_get_contents' => [
            'PROCESS_DERIVED — groupMemberAlive() (SHL-1) reads /proc/<pid>/stat for the numeric '
                . 'entries the scandir below enumerated, to reject zombies from the liveness answer; '
                . 'no config, project or $HOME path reaches the name',
            'SELF_LOCATED — readExitFile() reads back the exit-code file the wrapper child wrote '
                . 'into this registry\'s own 0700 directory; a non-numeric body answers null',
        ],
        'Background/BackgroundShellRegistry.php|fopen' => [
            'SELF_LOCATED — sinkFiles() creates the stdout/stderr/exit sinks with `xb` in the '
                . 'registry\'s own directory before the fork, settled to 0600',
            'SELF_LOCATED — sliceFrom() opens a capture file this registry created to tail from '
                . 'the caller\'s cursor; the size guard answers before the open ever happens',
        ],
        'Background/BackgroundShellRegistry.php|scandir' => [
            'NAMES_ONLY — groupMemberAlive() enumerates /proc; the numeric names feed only the '
                . 'stat reads above and nothing is admitted or refused on the listing itself',
            'NAMES_ONLY — sweepCorpseFiles() (SHL-1) lists this registry\'s own 0700 directory '
                . 'for `sh-<hex>.(out|err|exit)` names no row still owns; the names feed only an '
                . 'mtime check and an unlink, and no content read follows the listing',
            // cure 2026-10-27 (C-TOOL-7.a finding): the launch sweep joined the key. The
            // containment leg for both new rows: the sweep only ever descends directories
            // matching `launch-<digits>` under its own 0700 shells root — a name that fails
            // the pattern, the pid-liveness gate, or the retention ceiling is never opened.
            'NAMES_ONLY — sweepDeadLaunchDirs() lists the registry root\'s sibling '
                . '`launch-<pid>` directory names; the name feeds only the signal-0 liveness '
                . 'gate and the retention check before the descend below, and nothing is '
                . 'admitted as content from the listing',
            'NAMES_ONLY — sweepDeadLaunchDirs()\'s descend into one already-matched '
                . '`launch-<digits>` directory under its own 0700 root lists the `sh-*` '
                . 'capture names (out|err|exit|outd|errd) for an mtime check and an '
                . 'unlink; no content read follows the listing',
        ],
        'Background/BackgroundSupervisor.php|file_get_contents' => [
            'SELF_LOCATED — the IPC buffer this supervisor named for its own child (or one '
                . 'reconnect() adopted from the index after vetting its directory)',
            'SELF_LOCATED — the per-spawn token file this supervisor minted, read to '
                . 'authenticate a reconnect (audit M5)',
            'SELF_LOCATED — ownedActiveSummaries() (roadmap 4.3-2) reads one `sess_*.json` index '
                . 'record a supervisor of this uid wrote into the 0700 index directory indexDir() '
                . 'verified; only its name, agent tag and task are used, and only when it names '
                . 'this process as owner',
            'SELF_LOCATED — askRows() (ISS-004, F2/F4) reads one session\'s `.ask` relay file '
                . 'inside the 0700 IPC directory this supervisor minted or vetted; a torn or '
                . 'hostile line fails its shape check and is skipped, never trusted',
            'SELF_LOCATED — permissionAnswers() (ISS-004, F3) reads the session\'s answer file — '
                . 'the server-minted nonce name from the vetted record, or the derived legacy '
                . 'pair — inside the same 0700 IPC directory; only rows whose askId is the '
                . 'content-hash shape and whose reply is a known word are read at all',
        ],
        'Background/BackgroundPermissionRelay.php|file_get_contents' => [
            'SELF_LOCATED — the SERVER-MINTED answer file this relay was handed at spawn (F1): '
                . 'a path this daemon could not derive, inside the supervisor-minted 0700 IPC '
                . 'directory; only lines whose askId AND askToken both match the question being '
                . 'awaited and whose reply is a valid word settle it (ISS-004, F2)',
        ],
        'Background/BackgroundSupervisor.php|glob' => [
            'NAMES_ONLY — the startup sweep (audit BG-2) lists `candy_code_bg_<uid>_*` names in the '
                . 'temp dir; each candidate is then lstat-checked as a real directory of this uid '
                . 'in the exact minted shape, and no content is read',
            'NAMES_ONLY — reconnect() (roadmap 4.3-3) lists `sess_*.json` records in the per-uid '
                . 'index directory, which indexDir() lstat-verified as a 0700 directory of this uid; '
                . 'the content is read by the fopen below',
            'NAMES_ONLY — adoptHandedOff() (roadmap 4.3-2) lists the same index directory for '
                . 'records a forked turn handed to this process; each is claimed through the '
                . 'same fopen and vetting as reconnect()',
            'NAMES_ONLY — ownedActiveSummaries() (roadmap 4.3-2) lists the same index directory; '
                . 'the record is read by the file_get_contents above',
        ],
        'Background/BackgroundSupervisor.php|fopen' => [
            'SELF_LOCATED — one index record a supervisor of this uid wrote 0600 into its own 0700 '
                . 'index directory, lstat-checked as a regular file of this uid, opened r+ and '
                . 'flock()ed to claim it; every path it names is re-vetted before use (recordIpc())',
        ],
        'Background/BackgroundSupervisor.php|require' => [
            'PROCESS_DERIVED — inside the GENERATED child script: the composer autoload of the '
                . 'installation already executing this code, found via the live ClassLoader\'s own '
                . 'file. A hostile autoloader there is one this process has already loaded',
        ],
        'Background/BackgroundSupervisor.php|scandir' => [
            'NAMES_ONLY — the same sweep lists one vetted IPC directory\'s entries to stat their '
                . 'type and age; nothing is read, and a directory holding anything but regular '
                . 'files and sockets is left whole',
        ],
        // AUT-1 (plan_comparison §3): per-row fire-claim locks.
        'Background/ScheduleRegistry.php|fopen' => [
            'SELF_LOCATED — claimLock() creates one schedule\'s fire-claim lock file with `c` '
                . 'inside the 0700 `schedules/` directory beside this registry\'s own config path, '
                . 'settles it to 0600 before any payload, and flock()s it non-blocking (the '
                . 'SessionLock idiom); the only payload written is this process\'s pid, and the '
                . 'name in the path is regex-vetted by Schedule::NAME_PATTERN before the open',
        ],
        // C-EXT-3.e (R-EXT-2): the learned-skill store beside the drafts it
        // versions — one window listing and one size-capped revision read.
        'Skills/LearnedSkills.php|scandir' => [
            'NAMES_ONLY — /skills history lists one learned skill\'s revision window and counts '
                . 'its archive: the prefix is the owned skills tree and a name the draft store\'s '
                . 'sanitiser accepted, entries that are not real NON-LINK `<n>.md` files are never '
                . 'read on, and a linked directory anywhere in the tree lists nothing at all',
        ],
        'Skills/LearnedSkills.php|file_get_contents' => [
            'OWNED_HOME — one saved revision of the user tier, by a path this store built from a '
                . 'sanitised name (a slash, dot or NUL cannot survive the sanitiser), behind a '
                . 'link check on the file and a filesize gate, read no longer than '
                . 'LearnedSkills::MAX_REVISION_BYTES',
        ],
        // Roadmap 5.4-3's propose mode: the dream pass's skill drafts.
        'Skills/ProposedSkills.php|scandir' => [
            'NAMES_ONLY — `/skills proposed` lists the owned home\'s drafts tree; only names that '
                . 'are already sanitised draft names, as real (non-link) directories holding a '
                . 'non-link SKILL.md, are read on, through Skill::fromFile()\'s bounded reader',
            'NAMES_ONLY — removing one draft directory: entries are unlinked, a link is never '
                . 'followed, and only a real subdirectory is descended',
        ],
        // Audit 15d-27 routed the four skill reads (Skill::fromFile(), the
        // manifest head, the body, the asset) through one size-bounded reader;
        // the PATHS are still bounded where they always were, in the loader.
        'Skills/SkillFileReader.php|file_get_contents' => [
            'CONTAINED_UPSTREAM:Skills/SkillLoader.php — a whole SKILL.md or skill ASSET the loader '
                . 'bounded (entry + directory pair; an asset against its own skill directory)',
            'CONTAINED_UPSTREAM:Skills/SkillLoader.php — the frontmatter head of a SKILL.md the loader bounded',
        ],
        'Skills/SkillLoader.php|new DirectoryIterator' => [
            'CONTAINED — the bounded walk over a skills tree, itself capped against a grafted tree',
            'NAMES_ONLY — the C-EXT-2 resource listing under one skill\'s scripts|references|assets: '
                . 'it announces relative names and reads no content, links are neither listed nor '
                . 'followed, directory descent re-asks ContainedPath::within() and the skill walk\'s '
                . 'own MAX_DEPTH, and the later read through loadSkillAsset() re-decides containment '
                . 'per path',
        ],
        'StreamingDirectoryLister.php|opendir' => [
            'NAMES_ONLY — yields entry names; no content is read here and this class holds no '
                . 'boundary. Its first production consumer (SHL-2) is Tools/BuiltIn/Ls.php, which '
                . 'feeds it a PathJail-resolved directory: the containment the gap-note asked for '
                . 'lives in the caller, and any second consumer inherits that obligation',
            'NAMES_ONLY — the same, in the counting variant (the former "chunked" naming described '
                . 'a $chunkSize ctor knob that was declared and read nowhere; removed by SHL-2)',
        ],
        'Support/AiCommentWatcher.php|file_get_contents' => [
            'PATH_JAIL — the watch-files poll (roadmap 5.14i) reads a project file its own walk found: '
                . 'a symlink is refused, the path re-resolved through PathJail against the root, and '
                . 'nothing over MAX_FILE_BYTES is read; only user-tier `watchFiles` arms it',
        ],
        'Support/AiCommentWatcher.php|scandir' => [
            'NAMES_ONLY — the watch-files walk of the project root (roadmap 5.14i), capped at MAX_FILES; '
                . 'no symlink is followed, and the gate is on the later read',
        ],
        'Support/AtomicFileWriter.php|fopen' => [
            'SELF_LOCATED — the uniquely-named temp this writer creates beside the '
                . 'target and renames onto it; the path is dirname(target) plus a '
                . 'random suffix, never caller-supplied beyond the target itself',
            'CALLER_SUPPLIED — replace()\'s in-place fallback opens the target itself (a '
                . 'hard link, a dangling link, a directory no temp can be made in); Edit and '
                . 'Write hand it the path they already resolved through PathJail',
        ],
        'Support/AtomicFileWriter.php|glob' => [
            'NAMES_ONLY — sweepOrphanTemps() lists `.<target>.tmp.<16 hex>` beside a target '
                . 'it has just published (audit R8); no content is read, the pattern is the '
                . 'target\'s own escaped name, and only an old regular file this uid owns is unlinked',
        ],
        'Support/ClipboardImage.php|file_get_contents' => [
            'SELF_LOCATED — save() sniffs the paste file it just had the clipboard tool write, under '
                . 'its own 0700 directory with a random name',
        ],
        'Support/ClipboardImage.php|scandir' => [
            'NAMES_ONLY — sweepStale() lists its own per-uid paste directory (audit 15b-15 residual); '
                . 'no content is read, and only an old regular file this uid owns whose name has '
                . 'save()\'s exact shape is unlinked',
        ],
        'Support/MediaStore.php|fopen' => [
            'SELF_LOCATED — the `.partial` this call publishes, opened `xb` (exclusive) under a '
                . '0077 umask inside the PrivateDir-verified 0700 session directory; the name is '
                . 'built from savePattern tokens, never taken from disk, and nothing is read back '
                . 'through this handle',
        ],
        'Support/MediaStore.php|file_get_contents' => [
            'OWNED_HOME — read() of one published artifact whose name came from this store\'s own '
                . 'scandir, resolved through the pathFor() separator/containment door, under the '
                . '~/.candy-code/media root HomeDirectory::owned() establishes (tests hand a '
                . 'temp root to the forSession() seam)',
        ],
        'Support/MacAltNotice.php|file_get_contents' => [
            'OWNED_HOME — readStamp(): the one-line mac-alt-notice version stamp this class '
                . 'itself wrote under the ~/.candy-code/cache root HomeDirectory::owned() '
                . 'establishes (tests hand a temp path to the showsOnThisLaunch() seam); an '
                . 'unread or malformed body reads as "no stamp" and fails soft toward showing '
                . 'the notice again, never as data consumed',
        ],
        'Support/MediaStore.php|scandir' => [
            'NAMES_ONLY — list() enumerates this store\'s own session directory; no content is '
                . 'read, `.partial` residue and non-regular entries are skipped, and the gate is '
                . 'on the later read through pathFor()',
            'NAMES_ONLY — sweepExpiredDirs() (rv-devops-4) lists the media root\'s session '
                . 'directories for their mtimes only; links and non-directories are skipped, no '
                . 'content is read, and only a directory past the retention cutoff is unlinked',
            'NAMES_ONLY — removeSweptTree() lists one already-condemned expired session directory '
                . 'to unlink its regular files and rmdir it when empty; links are unlinked, never '
                . 'followed, and no file content is ever read',
        ],
        'Support/PrivateRetainedDir.php|scandir' => [
            'NAMES_ONLY — sweep() lists a retained store\'s own owner-only directory (and its one level of '
                . 'session sub-directories) after re-verifying it; no content is read, and only a regular '
                . 'file older than the retention window is unlinked, typed by lstat() so no link is followed',
        ],
        'Support/Directories/DirectoryBrowser.php|opendir' => [
            'NAMES_ONLY — list() reads the child DIRECTORY names of a directory resolve() confined to the browse root '
                . '(ContainedPath::within on the real path; each linked child re-checked); no file content is ever read',
        ],
        'Support/SessionRelaunch.php|scandir' => [
            'PROCESS_DERIVED — closeInheritedOnExec() lists /proc/self/fd, this process\'s own descriptor table, to mark each close-on-exec before the restart',
        ],
        'Support/ForkedChild.php|scandir' => [
            'PROCESS_DERIVED — closeInheritedServerFds() lists /proc/self/fd, this process\'s own descriptor table',
        ],
        'Support/ProcessContainment.php|scandir' => [
            'PROCESS_DERIVED — closeOnExec() lists /proc/self/fd, this process\'s own descriptor table',
        ],
        'Support/ProcessTree.php|file_get_contents' => [
            'PROCESS_DERIVED — /proc/<pid>/stat for an integer pid: the kernel\'s process table',
        ],
        'Support/ProcessTree.php|scandir' => [
            'PROCESS_DERIVED — snapshot() lists /proc, the kernel\'s process table',
        ],
        'Support/SiblingSpendLedger.php|fopen' => [
            'SELF_LOCATED — create()\'s exclusive (`x`, 0600) open of the name ToolIpcFiles::reserve() '
                . 'just chose in the temp dir; never caller-supplied',
            'SELF_LOCATED — record()\'s `r+` append to that same group ledger, handed to the member by '
                . 'the parent that created it',
            'SELF_LOCATED — entries()\' read of that same group ledger',
        ],
        'Support/ToolIpcFiles.php|file_get_contents' => [
            // w1e (v1-M-1, spine commit b71cc5384): the only content read in
            // the stranded-result-dir sweep is the creator's own sentinel.
            'SELF_LOCATED — sweepStalePoolDirs() reads the SafeDelete sentinel '
                . 'marker THIS tool\'s creator stamped into its own result dir; '
                . 'the bytes are a pid+start-time, consumed only as a liveness '
                . 'verdict, never parsed as data',
        ],
        'Support/ToolIpcFiles.php|glob' => [
            'SELF_LOCATED — sweeps this package\'s own IPC prefixes, uid-checked per entry',
            // w1e (v1-M-1, same commit): second glob, the stranded sc_pool_*
            // result directories; every entry must pass the name shape, the
            // uid, the directory type, AND the creator sentinel before the
            // later scandir below is ever pointed at it.
            'SELF_LOCATED — sweepStalePoolDirs() lists only this package\'s '
                . 'own AgentWorkerPool::RESULT_DIR_PREFIX names under the base '
                . 'SafeDelete::baseIsSane() vouched for',
        ],
        'Support/ToolIpcFiles.php|scandir' => [
            // w1e (v1-M-1, same commit): names only — each feeds an lstat
            // (regular-file + mtime) and an unlink; the gate on any content
            // is the later marker read above, and a non-regular entry voids
            // the whole directory rather than touching it.
            'NAMES_ONLY — enumerates the vetted result directory for '
                . 'regular-file mtimes and unlink targets; nothing is read '
                . 'through this listing',
        ],
        'Tools/BuiltIn/ApplyPatch.php|file_get_contents' => [
            'PATH_JAIL — every Update/Delete path is resolved through PathJail (or the worktree jail) before the read',
            'PATH_JAIL — the bounded pre-application read (ISS-017): cap+1 bytes of the same already-jailed path, '
                . 'the size guard above it and the jailed $path are shared with the arm that applies the hunk',
        ],
        'Tools/BuiltIn/Edit.php|file_get_contents' => [
            'PATH_JAIL — the model\'s path, resolved through PathJail before the read',
            'PATH_JAIL — the bounded cap+1 head read (audit F-T2 re-measure), one statement above the byte-count '
                . 'refusal, on the same jailed path the edit will write back',
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
        'Tools/BuiltIn/PlanExitTool.php|file_get_contents' => [
            'PATH_JAIL — the model\'s plan file, resolved through PathJail and read only when it is a `.md` '
                . 'directly in `.candy-code/plans` (roadmap 5.7-2)',
        ],
        'Tools/BuiltIn/Read.php|fopen' => [
            'PATH_JAIL — the read tool\'s page arm, streaming every read a page at a time (0.11)',
            'PATH_JAIL — the binary sniff\'s bounded head read (ISS-060), on the same already-jailed '
                . '$path: the jail resolve and the regular-file guard both sit above it',
        ],
        'Tools/BuiltIn/WebFetch.php|fopen' => [
            'NOT_A_FILESYSTEM_PATH — a pinned HTTP(S) URL through a stream context',
        ],
        'Tools/Web/Search/SearchBackends.php|fopen' => [
            'NOT_A_FILESYSTEM_PATH — the search endpoint (sink moved out of WebSearch.php when the '
                . 'backends were extracted), same shape: a pinned HTTP(S) URL through '
                . 'a stream context, redirects refused and the body read bounded (F-W3)',
        ],
        'Tools/BuiltIn/Write.php|file_get_contents' => [
            'PATH_JAIL — reads the existing file to diff before writing, same jailed path',
            'PATH_JAIL — the preview\'s bounded cap+1 head read (ISS-010): the PRE-WRITE diff the permission modal '
                . 'shows, on the same already-jailed path and behind the same size guard as the write arm',
        ],
        'Tools/Catalog/ToolCatalog.php|scandir' => [
            'NAMES_ONLY — lists the installation\'s own `src/Tools/BuiltIn/*.php` located from '
                . '`__DIR__` (DH-TOOLS); scandir not glob since DST-1 so the listing reaches inside a '
                . 'phar:// archive, the sorted .php filter reproducing the old glob set; it reads '
                . 'names only and resolves each through the autoloader. '
                . 'The one other caller is the catalog\'s own test, over a fixture tree it wrote',
        ],
        'Tools/IgnoreRules.php|file_get_contents' => [
            'CALLER_SUPPLIED — a `.gitignore`-shaped file inside the walk the calling tool jailed',
        ],
        'Tools/Sandbox/Bubblewrap.php|file_get_contents' => [
            'CALLER_SUPPLIED — the `.git` FILE at the root the Bash tool runs in (5.12), read bounded '
                . 'only for its `gitdir:` line; the path it names is believed only after the next read',
            'CALLER_SUPPLIED — git\'s back-pointer `<gitdir>/gitdir`, read bounded and compared to the '
                . 'root\'s own `.git`; nothing reaches the model, a mismatch binds nothing',
            'PROCESS_DERIVED — the AppArmor userns sysctl (C-TOOL-4.a): the kernel interface named by '
                . 'appArmorRestrictsUserns()\'s parameter default, read bounded at 16 bytes; the content '
                . 'is the kernel\'s own, the path is a test seam, and nothing model-supplied reaches either',
        ],
        'Tui/FirstRunWizard/FirstRunWizard.php|file_get_contents' => [
            'OWNED_HOME — readStamp(): the one-line dismissal stamp this class itself wrote under the '
                . '~/.candy-code/cache root HomeDirectory::owned() establishes (tests hand a temp path '
                . 'to the shouldOffer()/markDismissed() seam); an unreadable or empty body reads as '
                . '"no stamp" and fails soft toward OFFERING the wizard again, never as data consumed',
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
        // Step 3.G: the session's auto-commit record, `<git dir>/candy-code/auto-commits.jsonl`,
        // located by `git rev-parse --git-path` and written by this class alone.
        'Workspace/AutoCommitter.php|file' => [
            'SELF_LOCATED — record(): the record file this class appends to, re-read to trim it',
            'SELF_LOCATED — records(): the same record file, read for this session\'s commits',
            'SELF_LOCATED — forget(): the same record file, rewritten without an undone commit',
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
     * actually holds an enforcing {@see \SugarCraft\Code\Support\ContainedPath}
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
     * {@see \SugarCraft\Code\Tools\PathJail}, and an `OWNED_HOME` row in one that
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
            [
                'Agents/ProcessExecutor.php|require',
                'Commands/Specs/BuiltInCommands.php|require',
                'Background/BackgroundSupervisor.php|require',
                'Workflows/WorkflowRegistry.php|require',
            ],
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
            // Missed until the RepoMap extractors (W2-j carry-over): the
            // fully-qualified spelling is one token, not a T_STRING.
            'a fully-qualified read' => ['<?php @\\file_get_contents($p);', 1],
            'a fully-qualified call to something that is not a sink' => ['<?php \\strlen($p);', 0],
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
            // GENERATED CODE. src/Background/BackgroundSupervisor.php builds its
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
            (string) file_get_contents($this->srcDir . '/Background/BackgroundSupervisor.php'),
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
     * STRING LITERALS ARE RE-TOKENISED, not skipped: `src/Background/BackgroundSupervisor.php`
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

        // A fully-qualified call (`\\file_get_contents(…)`) is the same function:
        // PHP 8 lexes it as one T_NAME_FULLY_QUALIFIED token, which a T_STRING
        // scan alone never saw — so `@\\file_get_contents()` in RepoMap and five
        // other files went uncounted. It cannot be a method, a static call or a
        // declaration, so only the function-sink check applies to it.
        if ($token[0] === \T_NAME_FULLY_QUALIFIED && ($tokens[$i + 1] ?? null) === '(') {
            $name = strtolower(ltrim((string) $token[1], '\\'));

            return \in_array($name, self::FUNCTION_SINKS, true) ? $name : null;
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
