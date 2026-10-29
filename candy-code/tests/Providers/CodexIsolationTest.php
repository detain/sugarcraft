<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Providers\CodexInvocation;

/**
 * C-PRV-4.b: Codex isolation, mode → `-s`, `--output-schema`.
 *
 * The pins here walk the shipped shape: an explicit `-s` always rides the
 * argv (the pre-step "only when configured" gap was the root cause), the host
 * gate's permission mode NARROWS it (plan and read-only modes send
 * `read-only` over a configured `workspace-write`, never widen), `isolate`
 * (default true) adds `--ignore-user-config` so a foreign config.toml's MCP
 * servers and profiles drop out, and a requested JSON schema rides
 * `--output-schema` through a 0600 temp file that the finally arm removes.
 *
 * @see CodexInvocation
 * @see \SugarCraft\Code\Providers\CodexProvider
 */
final class CodexIsolationTest extends TestCase
{
    public function testTheProbeRecordNamesTheVerifiedCliVersion(): void
    {
        // House doctrine (ClaudeCodeInvocation::PROBED_CLAUDE_VERSION): the
        // argv this file spells is only honest against a recorded probe.
        $this->assertSame('0.154.0', CodexInvocation::PROBED_CODEX_VERSION);
    }
}
