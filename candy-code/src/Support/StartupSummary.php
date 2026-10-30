<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use React\Promise\PromiseInterface;
use SugarCraft\Code\App\StartupSummaryGatheredMsg;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\Help;
use SugarCraft\Code\Cli\UpdateCheck;
use SugarCraft\Code\Agents\MemoryScope;
use SugarCraft\Code\Context\ProjectMemoryWriter;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillManager;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tui\Components\PaneLabel;
use SugarCraft\Code\Workspace\GitRunner;
use SugarCraft\Code\Lang;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Style\Style;

/**
 * The startup summary that replaces the old empty-conversation placeholder
 * (VIS-1, plan_comparison 6.2).
 *
 * A fresh launch used to paint one faint line and nothing else; the summary
 * answers the four questions a user arriving in an unfamiliar checkout
 * actually has — what is running, where am I, what does it already know, and
 * what should I try — out of facts the running process ALREADY holds: the
 * Composer version, the live backend's provider and model, the permission
 * mode, the git branch, the instruction documents the system prompt splices,
 * and the memory/skill/MCP inventories the host commands enumerate.
 *
 * It is VIEW-SIDE LIGHT by construction (VIS-1-P2): the transcript is
 * untouched, so no row is persisted, no compaction budget is spent, and
 * clearing history brings the summary back rather than having buried it —
 * and `view()` itself resolves NOTHING expensive. {@see compose()} is pure —
 * it lays out a parsed fact bag and reads nothing. The cheap facts (version,
 * backend, mode, cwd, tip) are recomputed per frame behind
 * {@see tryFact()}'s fail-soft; the expensive ones (git branch, instruction
 * documents, memory/skill/MCP inventories) are gathered off the paint path
 * by {@see gatherCmd()} and land through {@see store()}, exactly as the
 * context pane's "read, never fetched" law spells it. Until a bag lands —
 * and for any fact whose read threw — the block paints the rows it can
 * establish, because a summary line is never worth a paint-time stall or
 * fatal.
 *
 * The tip rotates over a const roster by `crc32(session id)` — deterministic
 * per conversation, stable across frames, and with no timer and no RNG, so
 * golden-style pins stay byte-reproducible.
 */
final class StartupSummary
{
    /**
     * The tip roster. Index by `crc32(session id) % count()` (see
     * {@see tipIndex()}); a seventh tip is a seventh key and nothing else —
     * no consumer pins a numeral outside this array.
     */
    public const TIP_KEYS = [
        'tui.startup.tip.0',
        'tui.startup.tip.1',
        'tui.startup.tip.2',
        'tui.startup.tip.3',
        'tui.startup.tip.4',
        'tui.startup.tip.5',
    ];

    /** The segment separator, the same middle dot the status bar joins with. */
    private const SEP = ' · ';

    /**
     * Rendered frames memoized by the fact key. The summary only exists while
     * the transcript is empty, but an idle repaint used to re-derive git and
     * disk inventories every frame; the key carries every fact that can
     * change what is painted, so a mode switch or a model swap re-derives and
     * an idle spinner tick does not.
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * Expensive fact bags gathered off the paint path, keyed by
     * {@see deriveGatherKey()} — session+root only, because every gathered
     * fact is mode/provider/model-independent. The {@see gatherCmd()}
     * producer fills it and {@see store()} drops the memoized frames of the
     * same session+root, so the next paint composes cheap facts plus the
     * fresh bag under whatever full key that frame carries. A session+root
     * with no bag yet is the "still gathering" state: {@see render()} paints
     * cheap rows.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $gathered = [];

    /**
     * The painted block: the summary rows, each one faint (ISS-096's lesson —
     * the placeholder's mood was always "quiet context", which is what a
     * Style spells, not what literal delimiters fake).
     *
     * NEVER gathers here (VIS-1-P2): on a memo miss with no landed bag this
     * paints the cheap rows only; once {@see store()} has landed the
     * expensive bag for this session+root, the full frame composes and
     * memoizes.
     */
    public static function render(Chat $chat): string
    {
        $mode = $chat->currentPermissionMode()?->value;
        [$provider, $model] = self::providerAndModel($chat);
        $key = self::deriveKey($chat, $mode, $provider, $model);
        $cheap = self::cheapFacts($chat, $mode, $provider, $model);

        $expensive = self::$gathered[self::deriveGatherKey($chat)] ?? null;
        if ($expensive === null) {
            return self::faintRows(self::compose($cheap));
        }

        return self::$cache[$key] ??= self::faintRows(
            self::compose([...$cheap, ...$expensive])
        );
    }

    /**
     * The Cmd that gathers the expensive facts off the paint path (VIS-1-P2).
     * Factored as a Cmd — a `git branch` child and a full skill scan belong
     * on the launch/command channel, where the Program already tolerates
     * work of that shape, never inside `view()`.
     *
     * The read itself runs when the Cmd runs (not when it is constructed),
     * and lands a {@see StartupSummaryGatheredMsg}; the run-control arm hands
     * the bag to {@see store()} on the settled model, so it is keyed by the
     * session and root of the Chat that will paint it.
     */
    public static function gatherCmd(Chat $chat): \Closure
    {
        return Cmd::promise(static fn (): PromiseInterface => \React\Promise\resolve(
            new StartupSummaryGatheredMsg(self::gatherExpensive($chat)),
        ));
    }

    /**
     * Park a gathered bag under this Chat's session+root key, and drop every
     * memoized frame of that session+root so the next paint composes the
     * fresh facts under whatever full key that frame carries.
     *
     * @param array<string, mixed> $facts
     */
    public static function store(Chat $chat, array $facts): void
    {
        $bagKey = self::deriveGatherKey($chat);
        self::$gathered[$bagKey] = $facts;

        // Invalidate by the BAG's scope, not by one frame key: a re-gather
        // (a branch moved under /clear) makes every memoized frame of this
        // session+root stale, whatever mode/provider/model it was painted
        // with — and the full frame key starts with exactly the bag key.
        foreach (array_keys(self::$cache) as $frameKey) {
            if (str_starts_with($frameKey, $bagKey . "\0")) {
                unset(self::$cache[$frameKey]);
            }
        }
    }

    /** The memo key for the frame this Chat paints: every fact that moves it. */
    private static function deriveKey(Chat $chat, ?string $mode, ?string $provider, ?string $model): string
    {
        return ($chat->currentSessionId() ?? '') . "\0" . $chat->projectRoot()
            . "\0" . ($mode ?? '') . "\0" . ($provider ?? '') . "\0" . ($model ?? '');
    }

    /**
     * The expensive bag's key: session id + project root — the only two
     * things a gathered fact depends on (branch, instruction documents,
     * skills and MCP all read at the root; memory is the Chat's own store at
     * that root). Mode, provider and model move only the CHEAP header rows,
     * never the bag, so keying the bag by the full frame key stranded a
     * cheap-only paint after a Shift+Tab toggle or a /model switch: the frame
     * key moved, the bag did not, and no re-gather fires before /clear or a
     * session switch (round-1 review MAJOR).
     */
    private static function deriveGatherKey(Chat $chat): string
    {
        return ($chat->currentSessionId() ?? '') . "\0" . $chat->projectRoot();
    }

    /**
     * Lay the parsed facts out as the summary's raw rows.
     *
     * Pure: given the same bag it returns the same text, and the only fact it
     * consults outside its argument is the home directory, purely to shorten
     * the display path (the way a shell prompt does). The bag is PARSED here,
     * key by key — a missing or
     * misshapen fact degrades to "not measured" (null) or "nothing to show"
     * ([]) rather than trusting the producer, so the one place the block's
     * layout lives is also the one place its shape is settled. Rows:
     *
     *  1. app + version, provider, model, permission mode
     *  2. working directory (home shortened to `~`), git branch
     *  3. the cached update notice, when the last background check found a
     *     newer release (C-SES-6.e; an absent fact simply drops the row)
     *  4. instruction documents with their sizes, memory/skill/MCP counts
     *  5. the conversation's tip
     *
     * @param array<string, mixed> $facts
     */
    public static function compose(array $facts): string
    {
        $segments = static function (array $values): array {
            $kept = [];
            foreach ($values as $value) {
                if (\is_string($value) && $value !== '') {
                    $kept[] = $value;
                }
            }

            return $kept;
        };

        $header = Lang::t('tui.startup.app_line', ['version' => (string) ($facts['version'] ?? 'unknown')]);
        foreach ($segments([$facts['provider'] ?? null, $facts['model'] ?? null, $facts['mode'] ?? null]) as $segment) {
            $header .= self::SEP . $segment;
        }

        $place = self::shortenHome((string) ($facts['cwd'] ?? ''));
        $branch = $facts['branch'] ?? null;
        if (\is_string($branch) && $branch !== '') {
            $place .= self::SEP . $branch;
        }

        $knowledge = [];
        $files = $facts['instructionFiles'] ?? [];
        if (\is_array($files)) {
            foreach ($files as $document) {
                if (\is_array($document) && \is_string($document['name'] ?? null) && \is_int($document['bytes'] ?? null)) {
                    $name = $document['name'];
                    // Only the gathered bag marks provenance; a truthy check
                    // on the exact `true` keeps a misshapen value a plain row.
                    if (($document['personal'] ?? null) === true) {
                        $name = Lang::t('chat.startup.personal', ['name' => $name]);
                    }
                    $knowledge[] = $name . ' (' . self::bytes($document['bytes']) . ')';
                }
            }
        }
        // The whole-key literals are load-bearing: the census in
        // tests/LangParityTest.php scans quoted call-site keys for entries
        // that exist in lang/en.php, and a key assembled by concatenation is
        // invisible to it (and to whoever greps for it later).
        foreach (['memories', 'skills', 'mcpServers'] as $fact) {
            $count = $facts[$fact] ?? null;
            if (!\is_int($count)) {
                continue;
            }
            $knowledge[] = match ([$fact, $count === 1]) {
                ['memories', true] => Lang::t('tui.startup.memory.one', ['count' => $count]),
                ['memories', false] => Lang::t('tui.startup.memory.many', ['count' => $count]),
                ['skills', true] => Lang::t('tui.startup.skill.one', ['count' => $count]),
                ['skills', false] => Lang::t('tui.startup.skill.many', ['count' => $count]),
                ['mcpServers', true] => Lang::t('tui.startup.mcp.one', ['count' => $count]),
                ['mcpServers', false] => Lang::t('tui.startup.mcp.many', ['count' => $count]),
            };
        }

        $tip = Lang::t('tui.startup.tip_label', [
            'tip' => Lang::t(self::TIP_KEYS[((int) ($facts['tipIndex'] ?? 0)) % count(self::TIP_KEYS)]),
        ]);

        $update = $facts['update'] ?? null;
        $update = \is_string($update) ? $update : '';

        return self::rowsToString([$header, $place, $update, $knowledge === [] ? '' : implode(self::SEP, $knowledge), $tip]);
    }

    /**
     * The conversation's tip slot: crc32 over the session id, or over the
     * working directory while the session has no id yet (a fresh launch has a
     * stable summary seed before it has a stable id). Deterministic by
     * design — see the class docblock for why neither a timer nor an RNG is
     * in play.
     */
    public static function tipIndex(?string $sessionId, string $fallbackSeed = ''): int
    {
        return crc32($sessionId ?? $fallbackSeed) % count(self::TIP_KEYS);
    }

    /** Drop every memoized frame and landed bag — test seam, as the other caches. */
    public static function clearCacheForTesting(): void
    {
        self::$cache = [];
        self::$gathered = [];
    }

    /**
     * The facts a frame can settle without touching git or the disk scan:
     * the Composer version, the live backend's provider and model, the
     * permission mode, the working directory, and the conversation's tip
     * slot. Read on the paint path on purpose — every one is an in-process
     * value this process already holds (or a const version string), so
     * {@see render()} never stalls, gathered bag or not.
     *
     * @return array{version: string, provider: ?string, model: ?string, mode: ?string, cwd: string, tipIndex: int, update: ?string}
     */
    private static function cheapFacts(Chat $chat, ?string $mode, ?string $provider, ?string $model): array
    {
        $root = $chat->projectRoot();

        return [
            'version' => self::tryFact(static fn(): string => Help::versionString()) ?? 'unknown',
            'provider' => $provider,
            'model' => $model,
            'mode' => $mode,
            'cwd' => $root,
            'tipIndex' => self::tipIndex($chat->currentSessionId(), $root),
            // C-SES-6.e: the PREVIOUS background check's cached notice — a
            // per-process memo read, never the network, so paint stays
            // stall-free. The server-chosen version inside it passes the same
            // one-line choke as the served model above.
            'update' => self::tryFact(static function (): ?string {
                $notice = UpdateCheck::cachedNotice();

                return $notice === null ? null : self::oneLine($notice);
            }),
        ];
    }

    /**
     * The expensive half of the fact bag — the reads that spawned a git child
     * and re-scanned the skill tiers from `view()` before VIS-1-P2 moved
     * them onto the Cmd channel. Each read is individually fail-soft: the
     * value is null (a count), null (no branch), or [] (no documents) when
     * its subsystem is unavailable, throws, or reports nothing, so a landed
     * bag always paints what the process can actually establish.
     *
     * @return array{branch: ?string, instructionFiles: list<array{name: string, bytes: int, personal: bool}>, memories: ?int, skills: ?int, mcpServers: ?int}
     */
    private static function gatherExpensive(Chat $chat): array
    {
        $root = $chat->projectRoot();

        return [
            'branch' => self::branch($root),
            'instructionFiles' => self::tryFact(
                static fn(): array => self::instructionFiles($root),
            ) ?? [],
            'memories' => self::tryFact(static fn(): int => self::memoryCount($chat, $root)),
            'skills' => self::tryFact(static fn(): int => self::skillCount($root)),
            'mcpServers' => self::tryFact(static fn(): int => self::mcpCount($root)),
        ];
    }

    /**
     * The backend's provider and served model, using the same rule the status
     * bar's model segment reads (served model wins, the configured one is the
     * fallback, and a backend that is not the engine reports neither).
     *
     * The served id is a string a REMOTE SERVER chose, so — exactly as
     * `Renderer::modelIndicator()` does before it paints the bar — it enters
     * the fact bag through the one-line choke {@see oneLine()}: the memo key,
     * the painted header row, and every later embed of this fact carry only
     * the sanitised form, never the raw wire bytes.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function providerAndModel(Chat $chat): array
    {
        $fact = self::tryFact(static function () use ($chat): array {
            $backend = $chat->backend();
            if (!$backend instanceof EngineBackend) {
                return [null, null];
            }

            $model = $backend->servedModel() ?? $backend->model();

            return [$backend->provider()->name(), self::oneLine($model)];
        });

        return $fact ?? [null, null];
    }

    /**
     * The same one-line choke the status bar applies to this very value
     * (`Renderer::oneLine()`): sugar-core's audited ANSI/CR sweep and
     * zone-sentinel strip, then the fold to a single row. A provider id must
     * never smuggle an escape, a control byte, a break, or a forged
     * U+E000/U+E001 pair into the startup frame — or into the memo that
     * re-paints it every idle tick.
     */
    private static function oneLine(string $text): string
    {
        return PaneLabel::of(Sanitize::stripZoneSentinels(Sanitize::untrustedForDisplay($text)));
    }

    /** The `git branch --show-current` read — EnvironmentBlock's precedent. */
    private static function branch(string $root): ?string
    {
        return self::tryFact(static function () use ($root): ?string {
            if (!GitRunner::available()) {
                return null;
            }
            $result = GitRunner::new($root)->run('branch', '--show-current');
            $branch = trim($result['stdout']);

            // Detached HEAD answers empty with exit 0 — a real state, not a
            // failure, and not a fact worth a segment either.
            return $result['ok'] && $branch !== '' ? $branch : null;
        });
    }

    /**
     * The instruction documents the summary lists: the repository-shipped
     * ones the system prompt splices, then the operator's personal document
     * (VIS-1-P1 — `/context` lists `loadPersonal()` but this block used to
     * show only `loadDocuments()`, so the file that follows the user across
     * checkouts was invisible in exactly the view that answers "what does it
     * already know"). Carried with a personal marker for {@see compose()},
     * and deduplicated by resolved path: a personal file that IS a listed
     * repository document (same realpath, or a path that resolves to nothing
     * yet falls back to its raw spelling for the comparison) is not shown
     * twice. {@see Bootstrap::instructionLoader()} is the launch's own
     * memoized loader, so this reads nothing the prompt did not already read.
     *
     * @return list<array{name: string, bytes: int, personal: bool}>
     */
    private static function instructionFiles(string $root): array
    {
        $loader = Bootstrap::instructionLoader($root);
        $files = [];
        $seen = [];
        foreach ($loader->loadDocuments() as $document) {
            $canonical = realpath($document['path']);
            $seen[$canonical === false ? $document['path'] : $canonical] = true;
            $files[] = ['name' => basename($document['path']), 'bytes' => $document['bytes'], 'personal' => false];
        }
        foreach ($loader->loadPersonal() as $document) {
            $canonical = realpath($document['path']);
            $path = $canonical === false ? $document['path'] : $canonical;
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;
            $files[] = ['name' => basename($document['path']), 'bytes' => $document['bytes'], 'personal' => true];
        }

        return $files;
    }

    /** Notes across every scope of both stores, deduplicated by store identity. */
    private static function memoryCount(Chat $chat, string $root): int
    {
        $stores = [];
        $home = $chat->memoryStore();
        if ($home !== null) {
            $stores[spl_object_id($home)] = $home;
        }
        $project = ProjectMemoryWriter::forRoot($root)?->store();
        if ($project !== null) {
            $stores[spl_object_id($project)] = $project;
        }

        $total = 0;
        foreach ($stores as $store) {
            foreach (MemoryScope::cases() as $scope) {
                $total += count($store->list($scope));
            }
        }

        return $total;
    }

    /**
     * The skills this session could load and would let the user invoke. The
     * live registry is private to the backend, so — exactly as
     * `/skills`'s own listing does — the count re-runs the launch's cheap
     * tier scan rather than borrowing a reference that does not exist.
     */
    private static function skillCount(string $root): int
    {
        $registry = new SkillRegistry();
        (new SkillManager(new SkillLoader(), $registry))->loadAll($root, Bootstrap::bundles($root));

        return count($registry->getUserInvocable());
    }

    /** The trusted config's MCP server rows — the inventory `/mcp` reports. */
    private static function mcpCount(string $root): int
    {
        return count(Bootstrap::mcpServerInventory($root)['servers']);
    }

    /**
     * Run one fact read; a throw is the same "not established" answer as an
     * unavailable subsystem. Fail-soft on {@see \Throwable} on purpose — the
     * summary must never take down a frame over a git probe or a stray scan.
     *
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T|null
     */
    private static function tryFact(callable $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Home as `~`, the way shell prompts shorten their own root. */
    private static function shortenHome(string $path): string
    {
        $home = \rtrim(HomeDirectory::resolved() ?? '', '/');
        if ($home !== '' && ($path === $home || str_starts_with($path, $home . '/'))) {
            return '~' . substr($path, \strlen($home));
        }

        return $path;
    }

    /** The same byte humanisation `/context` prints its row sizes with. */
    private static function bytes(int $bytes): string
    {
        return $bytes < 1024
            ? $bytes . ' B'
            : rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
    }

    /**
     * Drop empty rows and join what is left.
     *
     * @param list<string> $rows
     */
    private static function rowsToString(array $rows): string
    {
        return implode("\n", array_values(array_filter($rows, static fn(string $row): bool => $row !== '')));
    }

    /**
     * Style each row faint on its own. One wrapper around the whole block
     * would open the SGR once and close it after the last row, so the
     * inter-row newlines would ride outside the faint and the first-row pin
     * the shell tests keep would not describe every line.
     */
    private static function faintRows(string $rows): string
    {
        return implode("\n", array_map(
            static fn(string $row): string => Style::new()->faint()->render($row),
            explode("\n", $rows),
        ));
    }
}
