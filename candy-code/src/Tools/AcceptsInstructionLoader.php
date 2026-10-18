<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools;

use SugarCraft\Code\Context\InstructionFileLoader;

/**
 * A {@see Tool} whose `execute()` lets the session's
 * {@see InstructionFileLoader} deliver nested instruction documents on touch
 * (Read, Glob, Grep, Edit, Write, ApplyPatch — the ISS-065 leak set) and can
 * therefore be re-pointed at a loader view of its own.
 *
 * WHY A WITHER, THE SISTER OF {@see AcceptsWorktreeJail}. The delegation
 * tools are built ONCE against the session's loader
 * ({@see \SugarCraft\Code\Tools\Catalog\ToolBuildContext::$loader}); a run
 * {@see \SugarCraft\Code\Backend\EngineBackend::turnTools()} binds to a
 * {@see \SugarCraft\Code\Tools\DelegatesToEngine engine} carrying a
 * {@see InstructionFileLoader::forDelegationView() delegation view} instead
 * arrives with those same instances still pointing at the parent. Only the
 * place that hands the run its tools — {@see \SugarCraft\Code\Tools\BuiltIn\TaskTool}'s
 * delegation path — knows both halves, so that is where the swap happens.
 *
 * The returned instance is a copy: the receiver keeps its own loader, because
 * the parent backend's tool list is the same objects.
 */
interface AcceptsInstructionLoader
{
    /**
     * This tool, answering instruction-document delivery from `$loader` in
     * place of the one it was built on. Every other collaborator — the
     * session's ledger, the nudges, the jail — is carried over unchanged.
     */
    public function withInstructionLoader(InstructionFileLoader $loader): static;
}
