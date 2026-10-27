<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Trust;

/**
 * The things a project root can be granted, one case per grant door (C-SEC-5.a).
 *
 * WHY AN ENUM AND NOT FOUR BOOLEAN CALLERS. The four `trustedProject*` lists
 * have existed for as long as the trust prompts have, but nothing in the tree
 * could answer the QUESTION "what does this root actually need?" without
 * re-deriving it per surface — the launch notice knew MCP, the settings editor
 * knew the lists by key string, and hooks and commands each carried their own
 * refusal sentence. A census needs a vocabulary, and an enum is the smallest
 * one that cannot drift: adding a fifth grant means adding a case, and every
 * `match` below refuses to compile-check-free at the call sites the same way a
 * silently-missing array key would.
 *
 * WHAT `mcp-pin` IS. It is not a fifth settings key — the pins ride the MCP
 * grant itself ({@see \SugarCraft\Code\MCP\McpTrustPins}). It is a separate
 * PENDING STATE: a root can hold the MCP grant and still be refused, because
 * a server's command changed after the grant recorded its fingerprint. The
 * answer is the same door (`mcp trust` re-records), so the case shares
 * {@see configKey()} with `mcp`; what it does NOT share is the ask text,
 * because "you never trusted this" and "what you trusted changed" are two
 * different sentences a user deserves to read.
 */
enum TrustTarget: string
{
    /** The project's `.mcp.json` may start its servers. */
    case Mcp = 'mcp';

    /** A declared MCP server drifted from its recorded fingerprint. */
    case McpPin = 'mcp-pin';

    /** The project's `.candy-code/hooks.yaml` (and `.claude` settings pair) may load. */
    case Hooks = 'hooks';

    /** The project's command files may run their `` !`cmd` `` shell form. */
    case Commands = 'commands';

    /** The project's `.candy-code/settings.json` pair may contribute the settings layer. */
    case Settings = 'settings';

    /**
     * The user-tier settings list that records this target's grant.
     *
     * Exactly the four keys
     * {@see \SugarCraft\Code\Tests\Config\TrustKeyDocumentationDriftTest}
     * censuses — there is no fifth, and {@see self::McpPin} deliberately
     * shares the MCP list because its approval IS a re-trust of the root.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::Mcp, self::McpPin => 'trustedProjectMcp',
            self::Hooks => 'trustedProjectHooks',
            self::Commands => 'trustedProjectCommands',
            self::Settings => 'trustedProjectSettings',
        };
    }

    /**
     * The `server.trust.ask.*` row naming why a prompt about this target
     * is being asked. The `mcp` row shipped with the MCP prompt
     * ({@see \SugarCraft\Code\Protocol\TrustAsks::discover()}); the other four
     * land with this census so every target has its sentence before any
     * surface renders one.
     */
    public function askKey(): string
    {
        return match ($this) {
            self::Mcp => 'server.trust.ask.mcp',
            self::McpPin => 'server.trust.ask.mcp_pin',
            self::Hooks => 'server.trust.ask.hooks',
            self::Commands => 'server.trust.ask.commands',
            self::Settings => 'server.trust.ask.settings',
        };
    }

    /**
     * Whether answering this target goes through the MCP door — the one grant
     * that writes a settings list AND records fingerprints in one action.
     */
    public function grantsThroughMcpDoor(): bool
    {
        return $this === self::Mcp || $this === self::McpPin;
    }
}
