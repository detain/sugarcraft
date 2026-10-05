<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host\Commands;

use SugarCraft\Crush\Commands\MemoryHistoryCommand;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Context\ProjectMemoryWriter;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Memory\ForeignMemoryImporter;
use SugarCraft\Crush\Memory\MemoryEntry;
use SugarCraft\Crush\Memory\MemoryHistory;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Memory\MemoryWriter;
use SugarCraft\Crush\Memory\UnreadableNotes;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Support\ContainedPath;
use SugarCraft\Crush\Support\ProjectRoot;

/**
 * `/memory list|add|search|delete|edit|clear|import|log|restore` (moved out of
 * `Chat::handleMemoryCommand()` in roadmap O-2h, which now delegates here).
 *
 * Every sub-command reads the session's HOME store and its project root, and
 * routes through {@see MemoryWriter} — the router the `Memory` tool and
 * auto-memory use — so a note typed here, a note the model saves and the note
 * the prompt shows are the same note. Answers are UI-only exchanges; a change
 * is its own commit in the home memory history (roadmap 5.4-2), and a
 * history warning rides after the answer as one more row.
 */
final class MemoryCommand implements HostCommand
{
    public function run(CommandContext $context, string $inputText): CommandResult
    {
        $home = $context->memoryStore;
        if ($home === null) {
            return self::respond($inputText, Lang::t('host.memory.not_configured'));
        }
        $root = $context->projectRoot();

        $afterMemory = CommandText::argument($inputText);
        if ($afterMemory === '') {
            return self::help($inputText);
        }

        $parts = preg_split('/\s+/', $afterMemory, 2) ?: [$afterMemory];
        $command = $parts[0];
        $args = $parts[1] ?? '';

        // Roadmap 5.4-2: the HOME memory directory is a git repository (null
        // for a repository store - see MemoryHistory). A change a `/memory`
        // command makes is its own commit, and whatever changed since the
        // last one (the Memory tool, auto-memory, a hand edit) is committed
        // first under its own subject, so `/memory log` never credits a
        // command with a change it did not make.
        $history = MemoryHistory::forStore($home);
        $mutates = \in_array($command, ['add', 'delete', 'clear', 'edit', 'import'], true);
        $warnings = [];
        if ($mutates) {
            $warnings[] = MemoryHistoryCommand::record($history, MemoryHistoryCommand::OUTSIDE_SUBJECT);
        }

        $result = match ($command) {
            'list' => self::list($home, $root, $inputText, $args),
            'add' => self::add($home, $root, $inputText, $args),
            'search' => self::search($home, $root, $inputText, $args),
            'delete' => self::delete($home, $root, $inputText, $args),
            'clear' => self::clear($home, $root, $inputText, $args),
            'edit' => self::edit($home, $root, $inputText, $args),
            'import' => self::import($home, $root, $inputText, $args),
            'log' => self::respond($inputText, MemoryHistoryCommand::log($history, $args)),
            'restore' => self::respond($inputText, MemoryHistoryCommand::restore($history, $args)),
            default => self::help($inputText, Lang::t('host.memory.unknown_command', ['command' => $command])),
        };

        if ($mutates) {
            $warnings[] = MemoryHistoryCommand::record($history, "memory: /memory {$command}");
        }

        foreach ($warnings as $warning) {
            if ($warning !== null) {
                $result = $result->withRows(Message::assistant($warning)->withUiOnly());
            }
        }

        return $result;
    }

    /** A `/memory` answer: the echo and the reply, UI-only. */
    private static function respond(string $inputText, string $response): CommandResult
    {
        return CommandResult::reply($inputText, $response);
    }

    /**
     * Show help text for /memory command.
     */
    private static function help(string $inputText, ?string $error = null): CommandResult
    {
        $lines = [];
        if ($error !== null) {
            $lines[] = Lang::t('host.memory.error', ['error' => $error]);
            $lines[] = '';
        }
        $lines[] = Lang::t('host.memory.help.heading');
        $lines[] = '';
        $lines[] = Lang::t('host.memory.help.list');
        $lines[] = Lang::t('host.memory.help.add');
        $lines[] = Lang::t('host.memory.help.search');
        $lines[] = Lang::t('host.memory.help.delete');
        $lines[] = Lang::t('host.memory.help.edit');
        $lines[] = Lang::t('host.memory.help.clear');
        $lines[] = Lang::t('host.memory.help.import');
        $lines[] = Lang::t('host.memory.help.log', ['count' => MemoryHistory::DEFAULT_LOG_ENTRIES]);
        $lines[] = Lang::t('host.memory.help.restore');
        $lines[] = Lang::t('host.memory.help.self');
        $lines[] = '';
        $lines[] = Lang::t('host.memory.help.scopes', [
            'max' => MemoryBlock::empty()->withSettings()->limits()['userMaxEntries'],
        ]);
        $lines[] = Lang::t('host.memory.help.history');

        return self::respond($inputText, implode("\n", $lines));
    }

    /**
     * Handle /memory add <content> [--scope <scope>].
     */
    private static function add(MemoryStore $home, string $root, string $inputText, string $args): CommandResult
    {
        if ($args === '') {
            return self::help($inputText, Lang::t('host.memory.usage.add'));
        }

        // Parse --scope flag if present (can be before or after content).
        // Defaults to `project` (roadmap 0.6): a note typed without a scope
        // is almost always about the repository in front of the user, and
        // the old `user` default sent it to the one scope the prompt did not
        // read at the time.
        $scope = 'project';
        $content = $args;

        if (preg_match('/^--scope\s+(user|project|agent)\s+(.*)$/s', $args, $m)) {
            $scope = $m[1];
            $content = trim($m[2]);
        } elseif (preg_match('/^(.*?)\s+--scope\s+(user|project|agent)\s*$/s', $args, $m)) {
            $content = trim($m[1]);
            $scope = $m[2];
        }

        if ($content === '') {
            return self::help($inputText, Lang::t('host.memory.usage.add'));
        }

        try {
            // Routed through MemoryWriter (roadmap 5.1-2), the same router the
            // `Memory` tool uses, so a note typed here and a note the model
            // saves land in the same place. E25 piece 2: a project note goes to
            // the repo-local `.sugar-crush/memory/` whenever the tree can host
            // one, and degrades to the home store otherwise — a headless
            // `--root ''`, a read-only checkout, or a `.sugar-crush` planted as a
            // symlink out of the tree should cost the user their note, not their
            // command. The reply SAYS when the note fell back (15d-05 residual).
            $saved = MemoryWriter::new($home, $root)
                ->save($content, $scope);
            $response = Lang::t('host.memory.created', ['id' => $saved->id, 'scope' => $scope]);
            if ($saved->fellBackToHome) {
                $response .= "\n\n" . Lang::t('host.memory.fell_back_home');
            }
            // 0.6: say so when a note lands where the model will never read it.
            if ($scope === 'agent') {
                $response .= "\n\n" . Lang::t('host.memory.agent_scope_note');
            }
        } catch (\Throwable $e) {
            $response = Lang::t('host.memory.error', ['error' => $e->getMessage()]);
        }

        return self::respond($inputText, $response);
    }

    /**
     * Handle `/memory import claude|opencode` — the runtime trigger point of
     * {@see ForeignMemoryImporter}, wired P7.S6 per the importer's own docblock
     * contract (the sentinel lives HERE, at the caller, because only the caller
     * knows whether a re-import was intentional).
     *
     * Two guards run before a single foreign byte reaches the store: a
     * determinable project root (the sentinel must live inside the project),
     * and an absent `.imported-{target}` sentinel (imports are not idempotent
     * — `MemoryStore::add()` mints a fresh UUID per entry, so the one-shot
     * guard IS the de-duplication).
     *
     * There is deliberately NO entry cap here. Imports land in the `agent`
     * scope (`MemoryScope::Local`, which the store persists under the string
     * 'agent'), and `MemoryBlock` folds only the project and user scopes into
     * the prompt — its own docblock lists the agent scope under "WHAT IS
     * DELIBERATELY NOT HERE" and `capture()` reads exactly
     * `list(MemoryScope::Project)` and `list(MemoryScope::User)`. No
     * number of imported entries can therefore crowd the prompt's memory
     * index, and agent scope is the point, not an oversight: the provenance-
     * badge attack story in {@see ForeignMemoryImporter}'s class docblock
     * (:106-124) is why another tool's memory bodies do not get direct
     * prompt access — they stay listable and searchable until the user
     * promotes what they actually want.
     *
     * Refusals the importer records ({@see ForeignMemoryImporter::refusedDirectories()})
     * surface in the response text — the command answers, it does not warn
     * through the transcript seams.
     */
    public static function import(MemoryStore $home, string $root, string $inputText, string $args): CommandResult
    {
        $target = strtolower(trim($args));
        if ($target === '') {
            return self::help($inputText, Lang::t('host.memory.usage.import'));
        }
        if ($target !== 'claude' && $target !== 'opencode') {
            return self::help($inputText, Lang::t('host.memory.import.unknown_target', ['target' => $target]));
        }

        // Defense-in-depth, not a behaviorally reachable branch: projectRoot()
        // falls back to getcwd(), so '' is observable only when neither an
        // explicit root nor a working directory exists. The guard is still
        // answered rather than letting the sentinel path dangle at a bare
        // '/.sugar-crush/...'.
        $projectRoot = $root;
        if ($projectRoot === '') {
            return self::respond(
                $inputText,
                Lang::t('host.memory.import.no_root', ['target' => $target])
            );
        }

        // Under the REPOSITORY root, not the launch directory (15d-05/15d-13
        // residual): every other `.sugar-crush/*` lookup walks up to the repo
        // root, so a sentinel written beside a subdirectory launch was a second
        // `.sugar-crush/` the next launch from the top never saw, and the same
        // project re-imported from there.
        $sentinel = ProjectRoot::resolve($projectRoot) . '/.sugar-crush/memory/.imported-' . $target;
        if (file_exists($sentinel)) {
            return self::respond(
                $inputText,
                Lang::t('host.memory.import.already', ['sentinel' => $sentinel])
            );
        }

        try {
            $importer = new ForeignMemoryImporter($home);
            $imported = $target === 'claude'
                ? $importer->importClaudeCode($projectRoot)
                : $importer->importOpencode($projectRoot);
            $refused = $importer->refusedDirectories();

            $lines = [];
            if ($imported > 0) {
                $sentinelNote = self::writeImportSentinel($root, $sentinel, $target, $imported);
                $lines[] = Lang::t('host.memory.import.done', ['count' => $imported, 'target' => $target])
                    . $sentinelNote;
            } else {
                $lines[] = Lang::t($refused === [] ? 'host.memory.import.none' : 'host.memory.import.none_refused', [
                    'target' => $target,
                ]);
            }
            if ($refused !== []) {
                $lines[] = '';
                $lines[] = Lang::t('host.memory.import.refused_heading');
                foreach ($refused as $path => $why) {
                    $lines[] = "- `{$path}`: {$why}";
                }
            }

            return self::respond($inputText, implode("\n", $lines));
        } catch (\Throwable $e) {
            return self::respond(
                $inputText,
                Lang::t('host.memory.import.failed', ['error' => $e->getMessage()])
            );
        }
    }

    /**
     * Write the re-import sentinel, creating its directories defensively; a
     * foreign tree that imported but could not record its sentinel would be
     * silently re-importable, so the response says so when that happens
     * rather than pretending the guard exists.
     *
     * The sentinel's DIRECTORY is under project control like everything else
     * this command reads, so both write hazards of a repository-chosen path
     * are closed HERE rather than named as a gap to fix later. Containment is
     * judged BEFORE the recursive create: a committed `.sugar-crush ->
     * <outside>` symlink would otherwise have its outside target mkdir'd by
     * this call and only refused afterwards. Then again after the create —
     * the only way a symlink appears at the checked path between check and
     * write is a race, and the re-check plus temp-create-and-rename turns
     * even that into a refusal or a replaced directory entry rather than a
     * write THROUGH a planted symlink — the same atomic-rename answer this
     * repo's GIF writer ships for the same CWE-59 shape, and what makes a
     * pre-planted `.imported-<target>` symlink a refusal the user can see
     * (via the exists-check in the caller) rather than an arbitrary-file
     * truncation performed by this launch.
     */
    private static function writeImportSentinel(string $root, string $sentinel, string $target, int $imported): string
    {
        $dir = dirname($sentinel);
        // First of the two containment gates the docblock above describes
        // (pre-check here, post-mkdir re-check below): this one is reachable
        // through /memory import whenever a planted out-of-tree `.sugar-crush`
        // symlink points the sentinel directory outside the project, while it
        // also re-derives the caller's non-empty-root precondition as defense
        // in depth; only the re-check below has no reachable path absent a
        // race.
        if (!self::importSentinelDirIsContained($root, $dir)) {
            return Lang::t('host.memory.sentinel.outside');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return Lang::t('host.memory.sentinel.no_dir');
        }
        // The post-create re-check: defensive against a symlink appearing at
        // the checked path between the gates above and this moment.
        if (!self::importSentinelDirIsContained($root, $dir)) {
            return Lang::t('host.memory.sentinel.escaped');
        }

        $tmp = $dir . '/sentinel-tmp-' . bin2hex(random_bytes(6));
        $written = @file_put_contents(
            $tmp,
            "{$imported} {$target} memories imported by /memory import at " . date('c') . "\n"
        );
        if ($written === false || !@rename($tmp, $sentinel)) {
            @unlink($tmp);

            return Lang::t('host.memory.sentinel.unwritten');
        }

        return Lang::t('host.memory.sentinel.written', ['sentinel' => $sentinel]);
    }

    /**
     * Whether the sentinel directory resolves inside this project, judging the
     * deepest EXISTING ancestor when the directory itself does not exist yet.
     *
     * WHY NOT ASK {@see ContainedPath::below()} ABOUT THE LEAF DIRECTLY: its
     * containment verdict is `realpath()`-based, so a not-yet-existing path —
     * the normal state of a fresh project's `.sugar-crush/memory` — answers
     * false, which would refuse every legitimate first sentinel. The climb is
     * sound because a component that does not exist cannot be a symlink at
     * check time (a broken one still stops the climb via `is_link()`, and
     * `below()` then refuses it on the unresolvable realpath), and a probe
     * that lands exactly on the project root is containment's floor, not a
     * violation — `below()` is strict-below by design, so that case is
     * answered by equality rather than handed to a predicate built to say no
     * to it. The post-write moment is covered by re-calling this method after
     * the mkdir, not by anything in here.
     */
    private static function importSentinelDirIsContained(string $root, string $dir): bool
    {
        // The same root the sentinel path was built under (import()).
        $root = ProjectRoot::resolve($root);
        $probe = $dir;
        while ($probe !== '/' && !file_exists($probe) && !is_link($probe)) {
            $probe = dirname($probe);
        }

        return $probe === $root || ContainedPath::below($probe, $root);
    }

    /**
     * Handle /memory list [scope].
     */
    private static function list(MemoryStore $home, string $root, string $inputText, string $args): CommandResult
    {
        // `project`, the same default `/memory add` takes (0.6), so a bare
        // `/memory add` followed by a bare `/memory list` shows the note.
        $scope = 'project';
        if ($args !== '') {
            $trimmed = trim($args);
            // Handle --scope <scope> syntax
            if (str_starts_with($trimmed, '--scope ')) {
                $scopeCandidate = trim(substr($trimmed, 8));
                if (in_array($scopeCandidate, ['user', 'project', 'agent'], true)) {
                    $scope = $scopeCandidate;
                }
            } elseif (in_array($trimmed, ['user', 'project', 'agent'], true)) {
                $scope = $trimmed;
            }
        }

        try {
            // E694 slice-A: project notes can live in EITHER store since E25p2,
            // so the listing reads both, grouped and store-named — an id does
            // not say which tree holds the file. The repo store is consulted
            // only for the scope that can host repo notes, and when it has
            // nothing to show the pre-grouping home-only bytes come back
            // verbatim (the same degradation locate() promises per id).
            $repoStore = $scope === 'project' ? self::writer($home, $root)->repository() : null;
            $repoEntries = $repoStore?->list($scope) ?? [];
            $entries = $home->list($scope);
            if ($repoEntries === []) {
                if ($entries === []) {
                    $response = Lang::t('host.memory.list.none', ['scope' => $scope]);
                } else {
                    $lines = [Lang::t('host.memory.list.heading', ['scope' => $scope]), ''];
                    foreach ($entries as $entry) {
                        $lines = [...$lines, ...self::entryRows($entry, withScope: false)];
                    }
                    $response = implode("\n", $lines);
                }
            } else {
                $lines = [Lang::t('host.memory.list.heading', ['scope' => $scope]), '', self::storeBanner(isRepo: true)];
                foreach ($repoEntries as $entry) {
                    $lines = [...$lines, ...self::entryRows($entry, withScope: false)];
                }
                if ($entries !== []) {
                    $lines = [...$lines, '', self::storeBanner(isRepo: false)];
                    foreach ($entries as $entry) {
                        $lines = [...$lines, ...self::entryRows($entry, withScope: false)];
                    }
                }
                $response = implode("\n", $lines);
            }
            // The notes of this scope the stores could not read — the user's
            // only view of them outside the prompt (audit 15d-04 follow-up).
            $response .= self::unreadableSection([
                ...($repoStore?->skipped($scope) ?? []),
                ...$home->skipped($scope),
            ]);
        } catch (\Throwable $e) {
            $response = Lang::t('host.memory.error', ['error' => $e->getMessage()]);
        }

        return self::respond($inputText, $response);
    }

    /**
     * The unreadable-notes section a `/memory` answer ends with, or '' when
     * every note read — so a clean store's answer is byte-identical to before.
     *
     * @param array<string, string> $unreadable path => reason
     */
    private static function unreadableSection(array $unreadable): string
    {
        $rows = UnreadableNotes::rows($unreadable);

        return $rows === [] ? '' : "\n\n" . implode("\n", $rows);
    }

    /**
     * Handle /memory search <query>.
     */
    private static function search(MemoryStore $home, string $root, string $inputText, string $query): CommandResult
    {
        if ($query === '') {
            return self::help($inputText, Lang::t('host.memory.usage.search'));
        }

        try {
            // E694 slice-A: repo notes are searchable too. Same byte-stability
            // rule as list() — the grouped banners join the answer only
            // when the repo store actually contributes a hit. Both stores come
            // from MemoryWriter, the router the Memory tool's `recall` uses,
            // so the command and the tool search the same notes; each store
            // answers best match first (MemoryStore::search(), BM25, 5.3-1).
            $repoStore = self::writer($home, $root)->repository();
            $repoEntries = $repoStore?->search($query) ?? [];
            $entries = $home->search($query);
            $total = count($repoEntries) + count($entries);
            if ($total === 0) {
                $response = Lang::t('host.memory.search.none', ['query' => $query]);
            } elseif ($repoEntries === []) {
                $lines = [self::searchHeading($query, $total), ''];
                foreach ($entries as $entry) {
                    $lines = [...$lines, ...self::entryRows($entry, withScope: true)];
                }
                $response = implode("\n", $lines);
            } else {
                $lines = [self::searchHeading($query, $total), '', self::storeBanner(isRepo: true)];
                foreach ($repoEntries as $entry) {
                    $lines = [...$lines, ...self::entryRows($entry, withScope: true)];
                }
                if ($entries !== []) {
                    $lines = [...$lines, '', self::storeBanner(isRepo: false)];
                    foreach ($entries as $entry) {
                        $lines = [...$lines, ...self::entryRows($entry, withScope: true)];
                    }
                }
                $response = implode("\n", $lines);
            }
            // search() reads every scope, so a note that could not be read is
            // a note that could not be searched — say so (audit 15d-04).
            $response .= self::unreadableSection([
                ...($repoStore?->skipped() ?? []),
                ...$home->skipped(),
            ]);
        } catch (\Throwable $e) {
            $response = Lang::t('host.memory.error', ['error' => $e->getMessage()]);
        }

        return self::respond($inputText, $response);
    }

    /**
     * Resolve a memory id against BOTH stores in the order the fold uses.
     *
     * E25 piece 2 moved `--scope project` writes into the repo-local store,
     * which made the per-id commands self-contradictory: the surface could
     * answer "Memory created with ID: X" and then "Memory `X` not found.".
     * Resolution order is {@see \SugarCraft\Crush\Context\MemoryBlock::capture()}'s
     * fold law restated for ops — the repo copy claims shared ids, so the
     * entry the prompt SHOWS is the entry the command removes; a home-first
     * lookup would delete an invisible twin and leave the shown note standing.
     * The OWNING store rides back with the entry so delete()/update() mutate
     * the file the operator saw. No repo store resolvable (absent dir,
     * `''`-root, read-only tree, symlink escape — every {@see ProjectMemoryWriter::forRoot()}
     * refusal) degrades to the home-only behaviour that predates E25p2.
     *
     * @return array{0: ?\SugarCraft\Crush\Memory\MemoryEntry, 1: MemoryStore} the resolved
     *         pair: [0] the located entry, or NULL when the id lives in neither store;
     *         [1] the OWNING store — the one whose delete()/update() mutates the file
     *         behind the entry the operator saw. No-owner sentinel: when [0] is null the
     *         tuple is always [null, $home] — the HOME store rides back as a
     *         placeholder so the shape stays a pair, and callers MUST branch on element 0
     *         before touching the returned store (mutating the sentinel would aim at the
     *         wrong tree).
     */
    private static function locate(MemoryStore $home, string $root, string $id): array
    {
        return self::writer($home, $root)->locate($id) ?? [null, $home];
    }

    /**
     * The router `/memory` shares with the `Memory` tool and auto-memory
     * ({@see \SugarCraft\Crush\Memory\MemoryWriter}), over the session's home
     * store and project root — so a command, the tool and the consolidator
     * agree on which store an id names and which repository store exists.
     */
    private static function writer(MemoryStore $home, string $root): MemoryWriter
    {
        $home = $home;

        return MemoryWriter::new(static fn (): ?MemoryStore => $home, $root);
    }

    /**
     * The two display lines one entry claims in a list/search answer — the
     * exact row shape both commands shipped before store grouping. Search has
     * always named the scope inside the row; list never has, so $withScope is
     * the only dial the grouping needed.
     *
     * @return list<string>
     */
    private static function entryRows(MemoryEntry $entry, bool $withScope): array
    {
        $tags = empty($entry->tags()) ? '' : ' [' . implode(', ', $entry->tags()) . ']';
        $preview = mb_strlen($entry->content()) > 80
            ? mb_substr($entry->content(), 0, 80) . '…'
            : $entry->content();
        $scope = $withScope ? ' (scope: ' . $entry->scope() . ')' : '';

        return [
            '- **[' . $entry->type() . ']** `' . $entry->id() . '`' . $scope . $tags,
            '  ' . $preview,
        ];
    }

    /**
     * Section header naming the store the rows beneath it live in (E694
     * slice-A) — an id does not say which tree holds its file, so the group
     * says it. The repo banner spells the path through
     * {@see ProjectMemoryWriter::RELATIVE_DIRECTORY}: the dot-path literal
     * stays in exactly one source file, which is what the containment
     * inventories enumerate.
     */
    private static function storeBanner(bool $isRepo): string
    {
        return $isRepo
            ? Lang::t('host.memory.banner.repo', ['dir' => ProjectMemoryWriter::RELATIVE_DIRECTORY])
            : Lang::t('host.memory.banner.home');
    }

    /**
     * Handle /memory delete <id>.
     */
    private static function delete(MemoryStore $home, string $root, string $inputText, string $args): CommandResult
    {
        $id = trim($args);
        if ($id === '') {
            return self::help($inputText, Lang::t('host.memory.usage.delete'));
        }

        try {
            [$entry, $store] = self::locate($home, $root, $id);
            if ($entry === null) {
                $response = Lang::t('host.memory.not_found', ['id' => $id]);
            } else {
                $store->delete($id);
                $response = Lang::t('host.memory.deleted', ['id' => $id]);
            }
        } catch (\InvalidArgumentException $e) {
            $response = Lang::t('host.memory.error', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $response = Lang::t('host.memory.error', ['error' => $e->getMessage()]);
        }

        return self::respond($inputText, $response);
    }

    /**
     * Handle /memory clear --scope <scope> --confirm.
     */
    private static function clear(MemoryStore $home, string $root, string $inputText, string $args): CommandResult
    {
        // Parse --scope and --confirm flags
        if (!preg_match('/--scope\s+(user|project|agent)\s+--confirm/s', $args, $m)) {
            return self::help($inputText, Lang::t('host.memory.usage.clear'));
        }

        $scope = $m[1];

        // E694 ruling (round-76): bulk clear NEVER touches the repo store.
        // When the tree holds repo-local project notes, a `--scope project`
        // clear would wipe only the home half of "project memory" — exactly
        // the silent partial wipe of possibly-committed files the ruling
        // forbids. The refusal is unconditional (no escape flag exists);
        // per-id delete is the door. With the repo store empty there is
        // nothing to half-wipe and the pre-E25p2 home clear stands.
        if ($scope === 'project') {
            $repoNotes = ProjectMemoryWriter::forRoot($root)?->store()->list('project') ?? [];
            if ($repoNotes !== []) {
                return self::respond(
                    $inputText,
                    Lang::t('host.memory.clear.refused', ['dir' => ProjectMemoryWriter::RELATIVE_DIRECTORY]),
                );
            }
        }

        try {
            $home->clear($scope);
            $response = Lang::t('host.memory.cleared', ['scope' => $scope]);
        } catch (\Throwable $e) {
            $response = Lang::t('host.memory.error', ['error' => $e->getMessage()]);
        }

        return self::respond($inputText, $response);
    }

    /**
     * Handle /memory edit <id> <new_content>.
     */
    private static function edit(MemoryStore $home, string $root, string $inputText, string $args): CommandResult
    {
        // Parse: <id> <new_content> — split on first whitespace, id is first token, rest is content
        $firstSpace = strpos($args, ' ');
        if ($firstSpace === false) {
            return self::help($inputText, Lang::t('host.memory.usage.edit'));
        }

        $id = trim(substr($args, 0, $firstSpace));
        $newContent = trim(substr($args, $firstSpace + 1));

        if ($id === '' || $newContent === '') {
            return self::help($inputText, Lang::t('host.memory.usage.edit'));
        }

        try {
            [$entry, $store] = self::locate($home, $root, $id);
            if ($entry === null) {
                $response = Lang::t('host.memory.not_found', ['id' => $id]);
            } else {
                $store->update($id, $entry->withContent($newContent));
                $response = Lang::t('host.memory.updated', ['id' => $id]);
            }
        } catch (\InvalidArgumentException $e) {
            $response = Lang::t('host.memory.error', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $response = Lang::t('host.memory.error', ['error' => $e->getMessage()]);
        }

        return self::respond($inputText, $response);
    }

    /**
     * The search answer's heading. One whole sentence per number, so a
     * translation agrees its noun in its own grammar rather than through an
     * English pluraliser.
     */
    private static function searchHeading(string $query, int $total): string
    {
        return Lang::t($total === 1 ? 'host.memory.search.heading.one' : 'host.memory.search.heading.other', [
            'query' => $query,
            'count' => $total,
        ]);
    }
}
