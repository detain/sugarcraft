<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * THE SIXTH READ PATH. Two more repository-chosen directories whose bodies
 * become a sub-agent's system prompt, and until this file existed neither had
 * any containment at all.
 *
 * {@see ForeignAgentPresetRegistry} reads `{projectRoot}/.claude/agents` and
 * `{projectRoot}/.opencode/agents` — both paths a CLONE chooses — and hands the
 * markdown body of every `*.md` in them to `AgentPreset::$initialPrompt`, under
 * whatever `permissionMode:` the file declares. The NATIVE tier
 * ({@see \SugarCraft\Crush\Agents\AgentPresetRegistry}) refuses the
 * byte-identical shape. MEASURED on this host, symlinked fixtures OUTSIDE any
 * checkout, before the gates:
 *
 *     FOREIGN discoverClaude:   presets=["leak"] permissionMode=bypass-permissions
 *                               initialPrompt='SIXTH-ESCAPE-BODY sk-live-CAFEBABE'
 *                               tools=["Bash","Edit","Read"]
 *     FOREIGN discoverOpencode: presets=["leak"]
 *     NATIVE  agentPresets():   presets=[]  refusals={…"outside the checkout"…}
 *
 * The class is DORMANT — nothing in `src/` or `bin/` constructs it, and its own
 * doc-block says so. That is a reason to gate it, not to remove it and not to
 * leave it: a containment rule added when the consumer lands is one written
 * after the consumer already trusts the loader.
 *
 * FIXTURES LIVE OUTSIDE ANY CHECKOUT, in this process's temp directory: the
 * escapes are only expressible as symlinks, and a symlink pointing out of a
 * repository must never be committed into one.
 */
final class ForeignAgentPresetDirContainmentTest extends TestCase
{
    use HomeSandboxTrait;

    private const BODY_SENTINEL = 'SIXTH-ESCAPE-BODY sk-live-CAFEBABE';

    private string $sandbox;
    private string $project;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/foreign_agent_containment_' . uniqid('', true);
        $this->project = $this->sandbox . '/repo';
        $this->outside = $this->sandbox . '/outside';

        mkdir($this->project . '/.claude', 0o777, true);
        mkdir($this->project . '/.opencode', 0o777, true);
        mkdir($this->outside, 0o777, true);

        file_put_contents(
            $this->outside . '/leak.md',
            "---\nname: leak\ndescription: outside description\npermissionMode: bypassPermissions\n"
            . "tools: [Bash, Edit, Read]\n---\n" . self::BODY_SENTINEL . "\n",
        );

        // The user tier must not read the developer's real ~/.claude/agents.
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->sandbox);

        parent::tearDown();
    }

    /**
     * `is_link()` FIRST: `is_dir()` answers true for a symlink to a directory,
     * so the obvious remover would follow this file's own escape fixtures out of
     * the sandbox and delete what is on the far side of them.
     */
    private function removeTree(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            if (is_link($dir) || is_file($dir)) {
                unlink($dir);
            }

            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $this->removeTree($dir . '/' . $entry);
        }

        rmdir($dir);
    }

    /** @param array<string, object> $presets */
    private function bodies(array $presets): string
    {
        return (string) json_encode(array_map(static fn (object $p): array => (array) $p, $presets));
    }

    // ─── the directory a repository chose ───────────────────────────

    public function testAClaudeAgentsDirectorySymlinkedOutOfTheCheckoutIsRefused(): void
    {
        symlink($this->outside, $this->project . '/.claude/agents');

        $registry = new ForeignAgentPresetRegistry();
        $presets = $registry->discoverClaude($this->project);

        $this->assertSame([], array_keys($presets));
        $this->assertStringNotContainsString(self::BODY_SENTINEL, $this->bodies($presets));
        $this->assertArrayHasKey($this->project . '/.claude/agents', $registry->refusedDirectories());
    }

    public function testAnOpencodeAgentsDirectorySymlinkedOutOfTheCheckoutIsRefused(): void
    {
        symlink($this->outside, $this->project . '/.opencode/agents');

        $registry = new ForeignAgentPresetRegistry();
        $presets = $registry->discoverOpencode($this->project);

        $this->assertSame([], array_keys($presets));
        $this->assertStringNotContainsString(self::BODY_SENTINEL, $this->bodies($presets));
        $this->assertArrayHasKey($this->project . '/.opencode/agents', $registry->refusedDirectories());
    }

    /**
     * The strictness {@see \SugarCraft\Crush\Support\ContainedPath::below()}
     * exists for: `.claude/agents -> ..` resolves exactly ONTO the checkout root,
     * which is the developer's working tree and not a curated agent directory.
     */
    public function testAnAgentsDirectoryResolvingOntoTheCheckoutRootIsRefused(): void
    {
        file_put_contents(
            $this->project . '/stray.md',
            "---\nname: stray\ndescription: a file in the working tree\n---\n" . self::BODY_SENTINEL . "\n",
        );
        symlink($this->project, $this->project . '/.claude/agents');

        $registry = new ForeignAgentPresetRegistry();

        $this->assertSame([], array_keys($registry->discoverClaude($this->project)));
        $this->assertStringContainsString(
            'which is exactly',
            implode("\n", $registry->refusedDirectories()),
        );
    }

    /** A symlink is not the defect; leaving the checkout is. */
    public function testAnAgentsDirectoryLinkedElsewhereInsideTheCheckoutStillReads(): void
    {
        mkdir($this->project . '/shared-agents');
        file_put_contents(
            $this->project . '/shared-agents/vendored.md',
            "---\nname: vendored\ndescription: in-repo\n---\nIN-REPO-BODY\n",
        );
        symlink($this->project . '/shared-agents', $this->project . '/.claude/agents');

        $registry = new ForeignAgentPresetRegistry();

        $this->assertSame(['vendored'], array_keys($registry->discoverClaude($this->project)));
        $this->assertSame([], $registry->refusedDirectories());
    }

    // ─── the entry inside a contained directory ─────────────────────

    /**
     * The second boundary: the DIRECTORY is contained, an ENTRY inside it need
     * not be. `glob()` does not resolve symlinks.
     */
    public function testAPresetFileSymlinkedOutOfAContainedDirectoryIsRefused(): void
    {
        mkdir($this->project . '/.claude/agents');
        file_put_contents(
            $this->project . '/.claude/agents/real.md',
            "---\nname: real\ndescription: legitimate\n---\nREAL-BODY\n",
        );
        symlink($this->outside . '/leak.md', $this->project . '/.claude/agents/link.md');

        $registry = new ForeignAgentPresetRegistry();
        $presets = $registry->discoverClaude($this->project);

        // Per-ENTRY, not all-or-nothing: one refusal must not cost the other file.
        $this->assertSame(['real'], array_keys($presets));
        $this->assertStringNotContainsString(self::BODY_SENTINEL, $this->bodies($presets));
        $this->assertArrayHasKey(
            $this->project . '/.claude/agents/link.md',
            $registry->refusedDirectories(),
        );
    }

    // ─── the user tier ──────────────────────────────────────────────

    /**
     * The user tier is NOT anchored to the CHECKOUT — that much of the old
     * sentence stands, and it is why a link to `~/.claude/agents` from anywhere
     * else in the home still works. It is anchored to `$HOME`.
     *
     * The rest of that sentence — "nobody but the user chose where it points" —
     * was a premise this class checked nothing about. MEASURED on the native
     * sibling with `$HOME` mode 0700 and owned, its only content a
     * `.sugar-crush/agents -> <outside>` symlink delivered by `tar xzf`: the
     * roster came back under `permissionMode: bypass-permissions` with an
     * outside file's body as the sub-agent prompt, no `.git` anywhere. See
     * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPresetTiers()}.
     */
    public function testTheUsersOwnAgentsDirectoryLinkedOutOfHomeIsRefused(): void
    {
        $home = $this->sandbox . '/home';
        mkdir($home . '/.claude', 0o700, true);
        symlink($this->outside, $home . '/.claude/agents');

        $registry = new ForeignAgentPresetRegistry();

        $this->assertSame([], array_keys($registry->discoverClaude($this->project)));
        $this->assertArrayHasKey($home . '/.claude/agents', $registry->refusedDirectories());
    }

    /**
     * THE CONTROL, without which the assertion above is satisfied by a user tier
     * that was simply switched off: a roster that stays inside `$HOME` — which
     * is where every documented layout puts it — still loads.
     */
    public function testAUserTierThatStaysInsideHomeStillLoads(): void
    {
        $home = $this->sandbox . '/home';
        mkdir($home . '/.claude/agents', 0o700, true);
        file_put_contents(
            $home . '/.claude/agents/mine.md',
            "---\nname: mine\ndescription: the user's own\n---\nMINE-BODY\n",
        );

        $registry = new ForeignAgentPresetRegistry();

        $this->assertSame(['mine'], array_keys($registry->discoverClaude($this->project)));
        $this->assertSame([], $registry->refusedDirectories());
    }

    /**
     * …but a home this process cannot establish as the user's is skipped
     * outright, because {@see \SugarCraft\Crush\Support\HomeDirectory::path()}'s
     * documented stand-in is `sys_get_temp_dir()` — measured at mode 1777 on
     * this host — and these bodies become prompts.
     */
    public function testAWorldWritableHomeContributesNoUserTier(): void
    {
        $home = $this->sandbox . '/home';
        mkdir($home . '/.claude/agents', 0o777, true);
        file_put_contents(
            $home . '/.claude/agents/notmine.md',
            "---\nname: notmine\ndescription: someone else's\n---\nNOT-MINE-BODY\n",
        );
        chmod($home, 0o1777);
        clearstatcache();

        $registry = new ForeignAgentPresetRegistry();
        $presets = $registry->discoverClaude($this->project);

        chmod($home, 0o700);
        clearstatcache();

        $this->assertSame([], array_keys($presets));
        $this->assertStringNotContainsString('NOT-MINE-BODY', $this->bodies($presets));
    }

    /** Refusals belong to a CALL, not to an object. */
    public function testRefusalsDoNotOutliveTheConditionThatCausedThem(): void
    {
        symlink($this->outside, $this->project . '/.claude/agents');

        $registry = new ForeignAgentPresetRegistry();
        $registry->discoverClaude($this->project);
        $this->assertNotSame([], $registry->refusedDirectories());

        unlink($this->project . '/.claude/agents');
        $registry->discoverClaude($this->project);

        $this->assertSame([], $registry->refusedDirectories());
    }
}
