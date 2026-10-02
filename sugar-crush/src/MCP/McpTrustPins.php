<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use SugarCraft\Crush\Support\AtomicFileWriter;

/**
 * What the user consented to when they trusted a project's `.mcp.json`: one
 * fingerprint per server, per project root (audit MCP-5).
 *
 * THE GAP. `trustedProjectMcp` names a ROOT, and used to be the whole
 * decision: once a root was listed, whatever `.mcp.json` said at the next
 * launch was started — so a `git pull`, a branch switch or a reviewed
 * contributor's checkout that changed a server's `command` to
 * `sh -c 'curl …|sh'` ran it at startup, in every permission mode, before any
 * tool call, with no new consent. The grant now binds to each server's
 * execution-shaping fields; a server whose fingerprint differs from the one
 * recorded, or that was not there when the record was made, is not started
 * until the user approves it again (`sugarcrush mcp trust`).
 *
 * WHAT IS FINGERPRINTED: the entry exactly as {@see McpClient} would build
 * it — after {@see McpForeignTranslate::normalizeEntry()} and the type alias,
 * so a foreign spelling (`environment`, a whole-argv `command` array) cannot
 * slip a change past the pin — minus {@see UNPINNED_KEYS}, which bound how
 * long a server may take or describe it, never what runs. So `command`,
 * `args`, `env`, `url`, `headers`, `path`, the `type`, and any key this list
 * does not name are all covered: a key nobody anticipated changes the
 * fingerprint, which is the conservative direction. `env` is pinned as
 * written — `${VAR}` references unresolved — so the user's own environment
 * moving does not re-prompt, and no secret value enters the record.
 *
 * WHERE: `~/.sugar-crush/mcp-trust.json`, beside `config.json` and written by
 * the tool, never by hand, so `config.json` stays the hand-authored grant and
 * this file holds only hashes and display summaries. Mode 0600, written
 * atomically. A file that cannot be read or decoded is an empty record: the
 * next launch of a trusted root records afresh, which is what an absent file
 * does — a party able to corrupt a file in the user's own config directory
 * could equally edit the grant itself.
 *
 * FIRST LAUNCH UNDER A GRANT. A trusted root with no record — a grant written
 * by hand, or one that predates this class — records the servers it starts
 * ("trust on first use"): that is the launch the user just opted in to, and
 * no weaker than the root-only grant it replaces. From then on a change is
 * refused.
 */
final class McpTrustPins
{
    /** The record's file name inside the user's config directory. */
    public const FILENAME = 'mcp-trust.json';

    /**
     * Entry keys left out of a fingerprint: they bound or describe a server,
     * they do not choose what runs or where it connects.
     */
    public const UNPINNED_KEYS = ['enabled', 'startTimeout', 'timeout', 'description'];

    private const VERSION = 1;

    /**
     * @param array<string, array<string, array{fingerprint: string, summary: string}>> $roots
     */
    private function __construct(
        private readonly string $path,
        private readonly array $roots,
    ) {}

    /** The record at $path, or an empty one when it is absent or unusable. */
    public static function load(string $path): self
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $roots = [];

        if (is_array($data) && ($data['version'] ?? null) === self::VERSION && is_array($data['roots'] ?? null)) {
            foreach ($data['roots'] as $root => $servers) {
                if (!is_string($root) || !is_array($servers)) {
                    continue;
                }
                foreach ($servers as $name => $pin) {
                    if (is_string($name) && is_array($pin)
                        && is_string($pin['fingerprint'] ?? null) && is_string($pin['summary'] ?? null)) {
                        $roots[$root][$name] = ['fingerprint' => $pin['fingerprint'], 'summary' => $pin['summary']];
                    }
                }
                $roots[$root] ??= [];
            }
        }

        return new self($path, $roots);
    }

    /** Where this record is read from and written to. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The servers recorded for $root, or null when the root has no record at
     * all — the first-launch case, distinct from a record of zero servers.
     *
     * @return array<string, array{fingerprint: string, summary: string}>|null
     */
    public function forRoot(string $root): ?array
    {
        return $this->roots[$root] ?? null;
    }

    /**
     * This record with $root's servers replaced by $servers.
     *
     * @param array<string, array{fingerprint: string, summary: string}> $servers
     */
    public function withRoot(string $root, array $servers): self
    {
        $roots = $this->roots;
        ksort($servers, \SORT_STRING);
        $roots[$root] = $servers;
        ksort($roots, \SORT_STRING);

        return new self($this->path, $roots);
    }

    /**
     * Persist the record, atomically and private to the user.
     *
     * @throws \RuntimeException when it cannot be written
     */
    public function save(): void
    {
        $json = json_encode(
            ['version' => self::VERSION, 'roots' => $this->roots],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
        AtomicFileWriter::write($this->path, $json . "\n", 0600);
    }

    /**
     * The pin for one entry: its fingerprint and a one-line display summary.
     *
     * @param mixed                $type  the entry's type after the alias, as McpClient sees it
     * @param array<string, mixed> $entry the normalised entry
     * @return array{fingerprint: string, summary: string}
     */
    public static function pin(mixed $type, array $entry): array
    {
        return ['fingerprint' => self::fingerprint($type, $entry), 'summary' => self::summary($type, $entry)];
    }

    /**
     * sha256 over the canonicalised entry. `serialize()`, not JSON: it is
     * total over every value a decoded `.mcp.json` can hold (invalid UTF-8
     * included), so two different entries can never encode alike.
     *
     * @param array<string, mixed> $entry
     */
    public static function fingerprint(mixed $type, array $entry): string
    {
        foreach (self::UNPINNED_KEYS as $key) {
            unset($entry[$key]);
        }
        $entry['type'] = $type;

        return hash('sha256', serialize(self::canonical($entry)));
    }

    /**
     * What a person needs to recognise the server: the command line, URL or
     * path, and the NAMES of its environment variables — never their values,
     * which may be literal secrets and which this summary is shown beside.
     *
     * @param array<string, mixed> $entry
     */
    public static function summary(mixed $type, array $entry): string
    {
        $text = match ($type) {
            'stdio' => trim(
                (is_string($entry['command'] ?? null) ? $entry['command'] : '')
                . ' ' . implode(' ', array_filter(
                    is_array($entry['args'] ?? null) ? $entry['args'] : [],
                    static fn ($a): bool => is_string($a),
                )),
            ),
            'http' => is_string($entry['url'] ?? null) ? $entry['url'] : '',
            'git' => is_string($entry['path'] ?? null) ? $entry['path'] : '(this project)',
            default => is_string($type) ? $type : get_debug_type($type),
        };

        $env = is_array($entry['env'] ?? null) ? array_keys($entry['env']) : [];
        if ($env !== []) {
            sort($env, \SORT_STRING);
            $text .= ' [env: ' . implode(', ', array_map('strval', $env)) . ']';
        }

        // One line, bounded: the summary is printed into a launch notice.
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', mb_scrub($text, 'UTF-8')));

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 119) . '…' : $text;
    }

    /**
     * Every entry of a decoded `mcpServers` object, translated the way
     * {@see McpClient::startServer()} translates it, as pins. Disabled entries
     * are not pinned (they are not started); an entry the shared canonicaliser
     * refuses is named in `invalid` instead.
     *
     * @param array<mixed> $servers
     * @return array{pins: array<string, array{fingerprint: string, summary: string}>, invalid: list<string>}
     */
    public static function pinsFor(array $servers): array
    {
        $pins = [];
        $invalid = [];
        foreach ($servers as $name => $config) {
            if (!is_string($name)) {
                continue;
            }
            if (!is_array($config)) {
                $invalid[] = $name;
                continue;
            }
            $type = $config['type'] ?? 'stdio';
            if (is_string($type)) {
                $type = McpForeignTranslate::TYPE_ALIASES[$type] ?? $type;
            }
            try {
                $entry = McpForeignTranslate::normalizeEntry($config);
            } catch (\RuntimeException) {
                $invalid[] = $name;
                continue;
            }
            if ($entry !== null) {
                $pins[$name] = self::pin($type, $entry);
            }
        }

        return ['pins' => $pins, 'invalid' => $invalid];
    }

    /**
     * Maps sorted by key at every depth; lists keep their order, which is
     * meaningful (`args`).
     */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonical($v);
        }
        if (!array_is_list($out)) {
            ksort($out, \SORT_STRING);
        }

        return $out;
    }
}
