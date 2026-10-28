<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentMode;
use SugarCraft\Code\Agents\AgentPresetRegistry;
use SugarCraft\Code\Agents\Effort;
use SugarCraft\Code\Agents\Isolation;
use SugarCraft\Code\Permissions\PermissionMode;

/**
 * Roadmap 4.1-1: the preset fields a delegated run acts on refuse a value
 * they cannot spell, rather than falling back to a default the author did not
 * write. `effort: maximum` used to load as `medium`, and `permissionMode:
 * plna` as the session's mode — both a preset doing something other than
 * what its file says, with nothing said.
 */
final class PresetUnsupportedFieldFailsLoudTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sc_preset_loud_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unspellable(): iterable
    {
        yield 'effort' => ['effort', 'maximum', 'low, medium, high, xhigh, max'];
        yield 'permissionMode' => ['permissionMode', 'plna', 'default, accept-edits, plan, auto, dont-ask, bypass-permissions'];
        yield 'isolation' => ['isolation', 'container', 'none, worktree'];
        yield 'mode' => ['mode', 'primarry', 'primary, subagent, all'];
    }

    #[DataProvider('unspellable')]
    public function testAnUnspellableValueSkipsThatFileNamingTheAcceptedValues(string $field, string $value, string $accepted): void
    {
        file_put_contents($this->dir . '/bad.md', "---\ndescription: B\n{$field}: {$value}\n---\nbody\n");
        file_put_contents($this->dir . '/good.md', "---\ndescription: G\n---\nbody\n");

        $registry = new AgentPresetRegistry([$this->dir]);
        $presets = $registry->list();

        $this->assertArrayHasKey('good', $presets, 'one bad file costs only that file');
        $this->assertArrayNotHasKey('bad', $presets);
        $why = $registry->skippedFiles()[$this->dir . '/bad.md'] ?? '';
        $this->assertStringContainsString("`{$field}: {$value}` is not a value candy-code knows", $why);
        $this->assertStringContainsString($accepted, $why);
    }

    public function testEverySpellingTheEnumsAcceptStillLoads(): void
    {
        file_put_contents(
            $this->dir . '/ok.md',
            "---\ndescription: O\neffort: XHigh\npermissionMode: acceptEdits\nisolation: worktree\nmode: SubAgent\n---\nbody\n",
        );

        $preset = (new AgentPresetRegistry([$this->dir]))->list()['ok'];

        $this->assertSame(Effort::XHigh, $preset->effort);
        $this->assertSame(PermissionMode::AcceptEdits, $preset->permissionMode, 'Claude Code\'s camel spelling still resolves');
        $this->assertSame(Isolation::Worktree, $preset->isolation);
        $this->assertSame(AgentMode::Subagent, $preset->mode, 'the C-AGT-4.a presenting filter resolves camel too');
    }

    public function testAbsentFieldsAreTheirNoOpDefaultsAndNoEffortIsNull(): void
    {
        file_put_contents($this->dir . '/plain.md', "---\ndescription: P\n---\nbody\n");

        $preset = (new AgentPresetRegistry([$this->dir]))->list()['plain'];

        $this->assertNull($preset->effort, 'no declared effort leaves the provider\'s own default');
        $this->assertSame(PermissionMode::Default, $preset->permissionMode);
        $this->assertNull($preset->isolation);
        $this->assertSame('inherit', $preset->model);
        $this->assertSame(AgentMode::All, $preset->mode, 'an absent mode presents everywhere');
    }
}
