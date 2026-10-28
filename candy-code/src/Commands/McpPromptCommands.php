<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\CommandParser;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\Tools\McpToolBridge;

/**
 * MCP-2 (plan_comparison §3, inv-65a A2): the server roster's prompts,
 * surfaced as `/mcp__<server>__<prompt>` commands.
 *
 * THE SHAPE IS A FILE-BASED COMMAND. Each launch-time `prompts/list` row
 * becomes a {@see CommandSpec} the "/-" popup, the dispatch chain, and the
 * empty-expansion refusal all already know how to handle — a prompt row is
 * merged into Chat's `$customCommands` at construction, exactly once per
 * process, because the MCP roster is launch-frozen and a keystroke path must
 * never round-trip a server. What is NOT file-based is the body: at launch
 * only `prompts/list` metadata exists, so the spec's `$template` carries a
 * JSON MARKER naming {server, prompt, declared arguments}, and the real body
 * is fetched by {@see materialize()} at submission — `prompts/get` on Enter,
 * never on open, riding the transport's existing per-exchange bound (E646:
 * no new timers).
 *
 * THE NAMES COMPOSE THE ONE WAY TOOLS DO: `McpToolBridge::NAME_PREFIX` plus
 * segments through the ONE `McpToolBridge::sanitize` (the AZ-pinned law that
 * exactly one sanitizer shapes every `mcp__` identifier). A command and a
 * tool may therefore share a spelling — harmless, they live on different
 * surfaces (dispatch reads names, the wire reads tools), and the popup's
 * `[mcp]` tier badge says which provenance a row came from.
 *
 * PRECEDENCE: a disk file of the same name always wins — the merge order in
 * Chat's constructor places project and user files AFTER these rows over the
 * same array keys, so `project-file > user-file > mcp-prompt` holds by
 * construction, and the launcher who wrote a command file never has a server
 * shadow it.
 *
 * TRUST: the materialized spec keeps {@see TIER}, and the shell-posture table
 * ({@see CommandShellPosture}) reads that tier as third-party: `` !`…` ``
 * forms inside a fetched body are REFUSED outright, whatever
 * `trustedProjectCommands` lists — a grant that vouches for this checkout's
 * own files says nothing about a body fetched over the wire (C-SEC-3, see
 * {@see \SugarCraft\Code\Host\TurnController::refuseCommandShell()}). A
 * server is third-party text by a stronger argument than the `git clone` a
 * project file arrives by: the operator never chose this server's bytes.
 */
final class McpPromptCommands
{
    /** The $tier stamp marking a row (and only a row) sourced from a server's prompt roster. */
    public const TIER = 'mcp';

    /** The marker member naming this class's placeholder bodies. */
    private const MARKER_KEY = 'mcp-prompt';

    /** Longest popup description quoted from a server, in characters. */
    private const DESCRIPTION_MAX_CHARS = 200;

    private function __construct() {}

    /**
     * The launch-time command rows for every allowed prompt server.
     *
     * Fail-open by design: no client, no capability, a refused or paging
     * `prompts/list` — each costs the roster, never the launch. A chat that
     * dies because one configured server dislikes `prompts/list` would hand
     * every MCP-less project a crash for a feature it cannot see.
     *
     * @param \Closure(): ?McpClient|null $clientProvider the session's MCP
     *        client when this process has one, consulted once
     * @return array<string, CommandSpec> keyed by command name (no leading "/")
     */
    public static function specs(?\Closure $clientProvider): array
    {
        if ($clientProvider === null) {
            return [];
        }

        try {
            $client = $clientProvider();
            $roster = $client === null ? [] : $client->promptsRoster();
        } catch (\Throwable) {
            return [];
        }

        $specs = [];
        foreach ($roster as $server => $rows) {
            foreach ($rows as $row) {
                if (!\is_array($row) || !\is_string($row['name'] ?? null) || $row['name'] === '') {
                    continue;
                }
                $spec = self::spec((string) $server, $row);
                // First row wins a sanitized-name collision (two servers whose
                // names differ only by characters sanitize erases, or one
                // server listing a name twice): the popup must show one row
                // per name, and dispatch reads the map by name.
                if (!isset($specs[$spec->name])) {
                    $specs[$spec->name] = $spec;
                }
            }
        }

        return $specs;
    }

    /**
     * Whether $spec is one of this class's launch-time placeholders (the
     * marker is present and decodes). The dispatch arm in Chat asks this
     * BEFORE expanding, so a placeholder body is never scanned as a template.
     */
    public static function isMcpPrompt(CommandSpec $spec): bool
    {
        return $spec->tier === self::TIER && self::decode($spec->template ?? '') !== null;
    }

    /**
     * Expand a placeholder into the spec the template machinery will see:
     * `prompts/get` the marker's prompt with $arguments mapped positionally
     * onto the prompt's declared argument names, then hand back the same row
     * with the fetched body as its template.
     *
     * Null is every honest refusal — no client, a server that threw, a body
     * with no text parts, a prompt the wire no longer knows. The caller sends
     * null down the empty-expansion path, which refuses visibly and puts the
     * line back in the box; a fetched prompt is never half-guessed.
     *
     * Non-text content parts refuse the WHOLE prompt rather than dropping the
     * image: a prompt that ships a picture beside its text means the pair,
     * and silently forwarding only the text would send a prompt whose prose
     * refers at content the model never received.
     */
    public static function materialize(CommandSpec $spec, string $arguments, ?\Closure $clientProvider): ?CommandSpec
    {
        $marker = self::decode($spec->template ?? '');
        if ($marker === null || $clientProvider === null) {
            return null;
        }

        try {
            $client = $clientProvider();
            if ($client === null) {
                return null;
            }
            $result = $client->prompt($marker['server'], $marker['prompt'], self::argumentMap($marker['arguments'], $arguments));
        } catch (\Throwable) {
            return null;
        }

        $texts = [];
        foreach ($result['messages'] ?? [] as $message) {
            if (!\is_array($message)) {
                return null;
            }
            $content = $message['content'] ?? null;
            if (!\is_array($content) || ($content['type'] ?? null) !== 'text' || !\is_string($content['text'] ?? null)) {
                return null;
            }
            $texts[] = $content['text'];
        }

        $body = rtrim(implode("\n\n", $texts));
        if ($body === '') {
            return null;
        }

        return new CommandSpec(
            name: $spec->name,
            description: $spec->description,
            category: $spec->category,
            argumentHint: $spec->argumentHint,
            template: $body,
            tier: self::TIER,
        );
    }

    /**
     * The one precedence decision of the command map, spelled the way
     * {@see \SugarCraft\Code\Chat::mergeCustomCommandRows()} spells it: disk
     * rows merge OVER launch-time MCP prompt rows, so a name a human typed
     * into a command file always wins the map key against a server that
     * sanitizes to the same spelling. Chat keeps its private copy; this is
     * the shared home the served host (C-MCP-2.a) reads, so the two hosts
     * cannot drift their precedence while claiming parity in the docs.
     *
     * @param array<string, CommandSpec> $mcpSpecs
     * @param array<string, CommandSpec> $diskSpecs
     *
     * @return array<string, CommandSpec>
     */
    public static function mergeUnderDisk(array $mcpSpecs, array $diskSpecs): array
    {
        return array_merge($mcpSpecs, $diskSpecs);
    }

    /**
     * One roster row → one placeholder spec.
     *
     * @param array<mixed> $row the raw `prompts/list` entry
     */
    private static function spec(string $server, array $row): CommandSpec
    {
        $promptName = (string) $row['name'];
        $description = self::flat((string) ($row['description'] ?? ''));
        $declared = [];
        $hintParts = [];
        foreach ($row['arguments'] ?? [] as $argument) {
            if (!\is_array($argument) || !\is_string($argument['name'] ?? null) || $argument['name'] === '') {
                continue;
            }
            $declared[] = $argument['name'];
            $hintParts[] = ($argument['required'] ?? false) ? '<' . $argument['name'] . '>' : '[' . $argument['name'] . ']';
        }

        return new CommandSpec(
            name: McpToolBridge::NAME_PREFIX . McpToolBridge::sanitize($server) . '__' . McpToolBridge::sanitize($promptName),
            description: $description === ''
                ? sprintf('MCP prompt "%s" from server "%s"', self::clipped($promptName), self::clipped($server))
                : self::clipped($description),
            category: 'MCP',
            argumentHint: $hintParts === [] ? null : implode(' ', $hintParts),
            template: (string) json_encode([
                self::MARKER_KEY => 1,
                'server' => $server,
                'prompt' => $promptName,
                'arguments' => $declared,
            ]),
            tier: self::TIER,
        );
    }

    /**
     * Positional command-line arguments onto the prompt's declared names, in
     * declaration order; surplus values are dropped (the server judges what it
     * asked for, not the speller of the moment) and missing ones are omitted
     * so the server applies its own defaults or refuses.
     *
     * @param list<string> $declared
     * @return array<string, string>
     */
    private static function argumentMap(array $declared, string $arguments): array
    {
        if ($declared === []) {
            return [];
        }
        $positional = (new CommandParser())->parse('/c ' . $arguments)?->args ?? [];

        return array_combine(
            \array_slice($declared, 0, \count($positional)),
            \array_slice($positional, 0, \count($declared)),
        ) ?: [];
    }

    /**
     * @return ?array{server: string, prompt: string, arguments: list<string>}
     */
    private static function decode(string $template): ?array
    {
        $decoded = json_decode($template, true);
        if (!\is_array($decoded) || ($decoded[self::MARKER_KEY] ?? null) !== 1) {
            return null;
        }
        if (!\is_string($decoded['server'] ?? null) || !\is_string($decoded['prompt'] ?? null) || !\is_array($decoded['arguments'] ?? null)) {
            return null;
        }

        return [
            'server' => $decoded['server'],
            'prompt' => $decoded['prompt'],
            'arguments' => array_values(array_filter($decoded['arguments'], 'is_string')),
        ];
    }

    /** Control characters (newlines included) out of server text; a popup row is one line. */
    private static function flat(string $text): string
    {
        return trim((string) preg_replace('/[\p{C}]+/u', ' ', $text));
    }

    private static function clipped(string $text): string
    {
        return mb_strlen($text) > self::DESCRIPTION_MAX_CHARS
            ? mb_substr($text, 0, self::DESCRIPTION_MAX_CHARS - 1) . '…'
            : $text;
    }
}
