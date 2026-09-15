<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * THE ONE VOCABULARY for reading FOREIGN MCP config spellings.
 *
 * E710 promoted the tables that {@see McpClient::startServer()} grew in E708
 * (the type-alias map and the entry canonicaliser) to this class so the
 * `sugarcrush mcp import claude|opencode <path>` translator and the launch
 * path read foreign spellings through the SAME renames. That sharing is the
 * law behind this file's existence: a second hand-typed mapping would be a
 * second answer, and the two would drift the first time a spelling was added
 * to one side. McpClient keeps its behaviour byte-identically — it now calls
 * here; nothing else about the read changed.
 *
 * WHAT LIVES HERE AND WHAT DOES NOT. The loader reads the foreign shapes and
 * DISCARDS the foreignness at the entry (it never re-emits); the importer
 * additionally re-types the alias into the emitted entry, canonicalises key
 * ORDER, and records, as plain sentences, every rename it performed — because
 * a translation that printed a different file without saying what moved is
 * the silent-drop class E708 closed on the load side. The whole-document
 * entry points below are the importer's; {@see normalizeEntry()} and
 * {@see TYPE_ALIASES} are the shared core both sides use.
 */
final class McpForeignTranslate
{
    /**
     * E708 — foreign `type` spellings, renamed to this port's names BEFORE
     * {@see McpClient::startServer()} dispatch so the factory's match stays exactly the
     * four-transport census the docs page records (an alias arm inside the
     * `match` would make the page's "the types ARE the constructs" claim
     * count wrong). `local`/`remote` are the names opencode's config gives to
     * stdio and HTTP; nothing else in this file invents a synonym.
     *
     * @var array<string, string>
     */
    public const TYPE_ALIASES = [
        'local' => 'stdio',
        'remote' => 'http',
    ];

    /**
     * Which top-level block each importable dialect carries its servers in.
     * Claude's `.mcp.json` and this port's own file share the container name —
     * that import is near-passthrough by construction — while opencode spells
     * it `mcp`, which the translation renames on the way out.
     *
     * @var array<string, string>
     */
    public const CONTAINERS = [
        'claude' => 'mcpServers',
        'opencode' => 'mcp',
    ];

    /**
     * The canonical key order the importer emits an entry in. A foreign file
     * spells its keys in whatever order its writer chose; the printed block is
     * this port's document, so its keys arrive in the order docs/MCP.md's
     * worked example shows them. Keys not listed ride through AFTER the listed
     * ones in their original order — the reorder is display, never deletion.
     *
     * @var list<string>
     */
    public const CANONICAL_KEY_ORDER = ['type', 'command', 'args', 'url', 'headers', 'env', 'startTimeout', 'path'];

    /**
     * E708 — read one entry's FOREIGN spellings into the shape
     * {@see McpClient::buildServer()} constructs, before any class is named.
     *
     * WHY BEFORE THE MATCH AND NOT INSIDE AN ARM: a real opencode `.mcp.json`
     * block pasted unchanged used to lose its env maps in silence —
     * `environment` was read nowhere, so `searxng` spawned with no
     * `SEARXNG_URL` and no report anywhere said so — and its `enabled: false`
     * started anyway. Renaming here (rather than growing `local`/`remote`
     * arms there) keeps the factory's match the four-transport census
     * docs/MCP.md records, and it means every downstream reader —
     * `resolveEnv`, the per-arm key reads the AX doc-arm pins — sees exactly
     * one spelling of each key.
     *
     * PRECEDENCE, stated once because both files exist in the wild: the
     * explicit sugar-crush spelling wins where two spellings of the SAME slot
     * disagree — `env` over `environment`. A `command` ARRAY is not the rival
     * of the string form, it is the same slot typed differently: the head
     * becomes the program and the tail joins an already-present `args` list
     * AFTER the array's own pieces, because the array is a whole argv and the
     * `args` key extends it.
     *
     * E710 note: the loader and the importer both call THIS method; it is the
     * shared vocabulary its class doc-block argues for. The importer adds the
     * emitted-side concerns (alias re-typed into the entry, key order,
     * `enabled: true` stripped once the decision it states is honoured) in
     * {@see translateDocument()} — never by re-spelling the rules here.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null the entry in canonical shape; null
     *         when the entry itself declines to run (`enabled: false`), which
     *         {@see McpClient::startServer()} reports rather than forgets
     *
     * @throws \RuntimeException a malformed shape — the loader carries it into
     *         the per-entry report; the importer names the server and refuses
     *         the file (same family as the factory's unknown-type throw)
     */
    public static function normalizeEntry(array $config): ?array
    {
        if (array_key_exists('enabled', $config) && !is_bool($config['enabled'])) {
            throw new \RuntimeException('"enabled" must be a boolean when present');
        }
        if (($config['enabled'] ?? true) === false) {
            return null;
        }

        if (array_key_exists('environment', $config)) {
            // A null `env` is a declared-but-empty slot, not an explicit
            // spelling that won the precedence — honouring its mere presence
            // would drop BOTH maps, the silent-drop class this normalisation
            // exists to end (r81-rv MINOR-1).
            if (!array_key_exists('env', $config) || $config['env'] === null) {
                $config['env'] = $config['environment'];
            }
            // Gone either way, won or lost: a slot that keeps two spellings
            // after canonicalisation invites a second reader to disagree
            // with the precedence.
            unset($config['environment']);
        }

        $command = $config['command'] ?? null;
        if (is_array($command)) {
            if ($command === [] || !array_is_list($command)) {
                throw new \RuntimeException('"command" array must be a non-empty list');
            }
            $head = array_shift($command);
            if (!is_string($head) || $head === '') {
                throw new \RuntimeException('"command" array must start with a non-empty string');
            }
            foreach ($command as $piece) {
                if (!is_string($piece)) {
                    throw new \RuntimeException('"command" array must hold strings');
                }
            }
            $args = $config['args'] ?? [];
            if (!is_array($args) || !array_is_list($args)) {
                throw new \RuntimeException('"args" must be a list');
            }
            $config['command'] = $head;
            $config['args'] = [...$command, ...$args];
        }

        return $config;
    }

    /**
     * Translate one whole decoded foreign document into this port's
     * `mcpServers` shape, printing-side strict: a document that does not look
     * like the named dialect, or an entry the shared canonicaliser rejects,
     * throws rather than emitting a half-translated block.
     *
     * WHAT THE NOTES ARE. One sentence per thing the translation actually
     * did — a container renamed, a type aliased, a whole-argv array split, an
     * `environment` map renamed (or overruled by its own `env` twin), an
     * `enabled: true` flag dropped once its decision was honoured, an entry
     * `enabled: false` removed from the imported set. An entry that needed
     * nothing adds nothing: a note per server would train the reader to
     * skim, and the skimmed note is the silent drop wearing a hat.
     *
     * @param string $dialect a key of {@see CONTAINERS}
     * @param array<array-key, mixed> $document the decoded foreign file
     *
     * @return array{servers: array<string, array<string, mixed>>, notes: list<string>}
     *
     * @throws \RuntimeException the dialect is not importable, the container
     *         is absent or not an object, or an entry is malformed (named)
     */
    public static function translateDocument(string $dialect, array $document): array
    {
        $container = self::CONTAINERS[$dialect] ?? throw new \RuntimeException(
            \sprintf('no importer dialect named "%s"', $dialect),
        );

        $block = $document[$container] ?? null;
        if (!is_array($block)) {
            throw new \RuntimeException(\sprintf(
                'no "%2$s" block to import — this is not %1$s config',
                $dialect,
                $container,
            ));
        }

        $servers = [];
        $notes = [];

        // opencode declares its servers under `mcp`; the emitted document
        // always carries THIS port's container, so the rename is the first
        // thing a reader of the notes learns happened.
        if ($container !== self::CONTAINERS['claude']) {
            $notes[] = \sprintf('moved the "%s" block to "%s"', $container, self::CONTAINERS['claude']);
        }

        foreach ($block as $name => $entry) {
            $name = (string) $name;

            if (!is_array($entry)) {
                throw new \RuntimeException(\sprintf('"%s" is not a server object', $name));
            }

            $notesForEntry = [];

            $foreignType = $entry['type'] ?? null;
            $typeIsAliased = is_string($foreignType) && isset(self::TYPE_ALIASES[$foreignType]);
            $commandWasArgv = is_array($entry['command'] ?? null);
            $hadEnvironment = array_key_exists('environment', $entry);
            $envKept = $hadEnvironment
                && (!array_key_exists('env', $entry) || $entry['env'] === null);
            $hadEnabledFlag = array_key_exists('enabled', $entry);

            try {
                $canonical = self::normalizeEntry($entry);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException(\sprintf('"%s": %s', $name, $e->getMessage()), previous: $e);
            }

            if ($canonical === null) {
                // The entry declined to run; saying so IS the translation —
                // dropping it quietly would rebuild, at import time, the
                // exact silent skip E708 closed at launch time.
                $notes[] = \sprintf('dropped "%s" — its own "enabled": false declines to start', $name);
                continue;
            }

            // The loader resolves the alias into a local variable and never
            // re-types the entry (it does not re-emit it). The importer emits,
            // so the alias must land IN the entry or the printed block would
            // still carry the foreign spelling its own reader just renamed.
            if ($typeIsAliased) {
                /** @var string $foreignType */
                $canonical['type'] = self::TYPE_ALIASES[$foreignType];
                $notesForEntry[] = \sprintf('type "%s" became "%s"', $foreignType, $canonical['type']);
            }
            if ($commandWasArgv) {
                $notesForEntry[] = 'whole-argv "command" array split into "command" + "args"';
            }
            if ($hadEnvironment) {
                $notesForEntry[] = $envKept
                    ? '"environment" map renamed to "env"'
                    : '"environment" map dropped — its own "env" wins the precedence';
            }
            if ($hadEnabledFlag) {
                // Honour the decision, then drop the flag: the block imports
                // what runs, and an "enabled": true here states a default this
                // file's own reader already assumes.
                unset($canonical['enabled']);
                $notesForEntry[] = 'redundant "enabled": true dropped';
            }

            $servers[$name] = self::orderEntryKeys($canonical);
            foreach ($notesForEntry as $note) {
                $notes[] = \sprintf('%s: %s', $name, $note);
            }
        }

        return ['servers' => $servers, 'notes' => $notes];
    }

    /**
     * Re-order one canonical entry's keys for emission: {@see
     * CANONICAL_KEY_ORDER} first, then anything unknown in arrival order.
     * Presentational only — the map itself is normalizeEntry()'s output.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function orderEntryKeys(array $entry): array
    {
        $ordered = [];
        foreach (self::CANONICAL_KEY_ORDER as $key) {
            if (array_key_exists($key, $entry)) {
                $ordered[$key] = $entry[$key];
                unset($entry[$key]);
            }
        }

        return array_merge($ordered, $entry);
    }
}
