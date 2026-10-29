<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use SugarCraft\Code\Permissions\PermissionMode;

/**
 * The seam for providers whose wire carries its OWN permission dial.
 *
 * C-PRV-4.a (plan_comparison). A CLI-driving provider spawns a second agent
 * loop — the real `claude` binary answers a print-mode request with tool
 * calls of its own — and that loop enforces ITS permission state, not this
 * process's. Without the seam, Shift+Tab in the host changes the host gate
 * while the child keeps whatever mode its (absent) flag implies: the dial
 * the user sees is not the dial enforcing the turn. This interface lets
 * {@see \SugarCraft\Code\Backend\EngineBackend} hand each turn's provider
 * clone the gate's CURRENT {@see PermissionMode}, the same per-turn overlay
 * shape C-PRV-3.a shipped for `ShapesServiceTier`.
 *
 * The contract is NARROWING, and "narrowing" names the law: the provider
 * translates the host mode into its own wire vocabulary and never grants
 * past what the host gate itself holds. A mode the host has not entered
 * must not appear on the child's argv — in particular the child's bypass
 * spelling rides only when the host gate IS in bypass (where the operator
 * deliberately granted it), never as a default, and never as an inferred
 * "the child would otherwise stall" convenience.
 *
 * Mirroring the sibling `ShapesServiceTier` doctrine: the roster is
 * deliberately narrow — a provider that does not implement this is NOT
 * silently ignored in the "it should have" sense; it simply has no dial to
 * bind, because its wire carries no permission vocabulary (the HTTP
 * providers never spawn an agent loop at all). The CLI relays that do carry
 * one implement this and get the turn-by-turn rebind for free.
 */
interface HonoursHostPermissionMode
{
    /**
     * A clone carrying the host gate's current mode, or null when no gate
     * exists (a headless one-shot with no PermissionGate) — in which case
     * the provider sends its dial exactly as it did before this seam: the
     * seam adds a translation, it never invents a mode.
     *
     * The argument is the typed host enum, NOT a wire word: each provider
     * owns its own spelling (the `claude` child spells default `manual`,
     * bypass `bypassPermissions`, and so on), and keeping the words behind
     * the translation is what stops a host-side rename from silently
     * shipping a stale CLI dial.
     */
    public function withHostPermissionMode(?PermissionMode $mode): static;
}
