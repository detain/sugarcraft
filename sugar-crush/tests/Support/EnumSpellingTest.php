<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;
use SugarCraft\Crush\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Crush\Agents\Effort;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Agents\MemoryScope;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Support\EnumSpelling;
use SugarCraft\Crush\Tests\Skills\TemporaryDirectoryTrait;

/**
 * The two spellings of one value, and the silent downgrade that came of
 * knowing only one of them.
 *
 * `.sugar-crush/agents/coder.md` -- tracked in this repository, one of the
 * three presets shipped with it -- declares `permissionMode: acceptEdits`,
 * Claude Code's spelling of sugar-crush's `accept-edits`. The native reader's
 * hand-written `match (strtolower(...))` had kebab arms only and a
 * `default => PermissionMode::Default` catch-all, so that preset resolved to
 * Default and said nothing about it. It failed in the SAFE direction --
 * Default asks about more than AcceptEdits does -- which is exactly why
 * nobody noticed.
 *
 * The fixture files here, not the tracked preset, are what these tests assert
 * against: the parser is what was wrong, and a test that reads the shipped
 * data would go green the moment someone "fixed" the data instead.
 */
final class EnumSpellingTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/sc_enum_spelling_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @dataProvider permissionModeSpellings
     */
    public function testBothSpellingsOfEveryPermissionModeResolve(string $spelling, PermissionMode $expected): void
    {
        $this->assertSame($expected, EnumSpelling::resolve(PermissionMode::class, $spelling));
    }

    /** @return iterable<string, array{0: string, 1: PermissionMode}> */
    public static function permissionModeSpellings(): iterable
    {
        yield 'kebab accept-edits' => ['accept-edits', PermissionMode::AcceptEdits];
        yield 'camel acceptEdits' => ['acceptEdits', PermissionMode::AcceptEdits];
        yield 'kebab bypass-permissions' => ['bypass-permissions', PermissionMode::BypassPermissions];
        yield 'camel bypassPermissions' => ['bypassPermissions', PermissionMode::BypassPermissions];
        yield 'kebab dont-ask' => ['dont-ask', PermissionMode::DontAsk];
        yield 'camel dontAsk' => ['dontAsk', PermissionMode::DontAsk];
        yield 'single word plan' => ['plan', PermissionMode::Plan];
        yield 'single word auto' => ['auto', PermissionMode::Auto];
        yield 'explicit default' => ['default', PermissionMode::Default];
        yield 'shouty kebab' => ['ACCEPT-EDITS', PermissionMode::AcceptEdits];
    }

    /**
     * The resolver reports "I do not know this" rather than choosing for the
     * caller -- that separation is the whole point, because a default that
     * lives inside the lookup is a default nobody can see.
     */
    public function testAGenuinelyUnknownValueResolvesToNullNotADefault(): void
    {
        $this->assertNull(EnumSpelling::resolve(PermissionMode::class, 'no-such-mode'));
        $this->assertNull(EnumSpelling::resolve(PermissionMode::class, ''));
        $this->assertNull(EnumSpelling::resolve(PermissionMode::class, null));
        $this->assertNull(EnumSpelling::resolve(PermissionMode::class, ['accept-edits']));
        $this->assertNull(EnumSpelling::resolve(PermissionMode::class, true));
    }

    /**
     * The plain lowercase lookup runs FIRST, so a value with no case boundary
     * to speak of is never mangled by the split. `xhigh` is the live example:
     * folded blindly, `xHigh` would become `x-high`, which is not a case.
     */
    public function testValuesWithoutACaseBoundaryAreNotSplit(): void
    {
        $this->assertSame(Effort::XHigh, EnumSpelling::resolve(Effort::class, 'xhigh'));
        $this->assertSame(Effort::XHigh, EnumSpelling::resolve(Effort::class, 'xHigh'));
        $this->assertSame(Effort::XHigh, EnumSpelling::resolve(Effort::class, 'XHigh'));
        $this->assertSame(MemoryScope::Local, EnumSpelling::resolve(MemoryScope::class, 'Local'));
        $this->assertSame(Isolation::Worktree, EnumSpelling::resolve(Isolation::class, 'Worktree'));
    }

    /**
     * End to end through the NATIVE reader, which is the one that was broken.
     * `acceptEdits` is the exact string the shipped `coder.md` carries.
     */
    public function testNativePresetReaderHonoursTheCamelCasePermissionMode(): void
    {
        $preset = $this->loadNative("permissionMode: acceptEdits");

        $this->assertSame(PermissionMode::AcceptEdits, $preset->permissionMode);
    }

    public function testNativePresetReaderStillHonoursTheKebabPermissionMode(): void
    {
        $preset = $this->loadNative('permissionMode: accept-edits');

        $this->assertSame(PermissionMode::AcceptEdits, $preset->permissionMode);
    }

    public function testNativePresetReaderStillDefaultsAnUnknownPermissionMode(): void
    {
        $preset = $this->loadNative('permissionMode: no-such-mode');

        $this->assertSame(PermissionMode::Default, $preset->permissionMode);
    }

    public function testNativePresetReaderHonoursCamelCaseAcrossTheOtherThreeFields(): void
    {
        $preset = $this->loadNative("memory: local\neffort: xHigh\nisolation: Worktree");

        $this->assertSame(MemoryScope::Local, $preset->memory);
        $this->assertSame(Effort::XHigh, $preset->effort);
        $this->assertSame(Isolation::Worktree, $preset->isolation);
    }

    public function testNativePresetReaderKeepsEachFieldsDocumentedFallback(): void
    {
        $preset = $this->loadNative("memory: nonsense\neffort: nonsense\nisolation: nonsense");

        $this->assertSame(MemoryScope::User, $preset->memory);
        $this->assertSame(Effort::Medium, $preset->effort);
        $this->assertNull($preset->isolation);
    }

    /**
     * The foreign reader already did this, and must keep doing it: the two
     * readers now share one resolver, so a regression here would be the same
     * regression there.
     */
    public function testForeignPresetReaderStillHonoursTheCamelCasePermissionMode(): void
    {
        $dir = $this->root . '/proj/.claude/agents';
        mkdir($dir, 0o700, true);
        file_put_contents(
            $dir . '/foreign.md',
            "---\nname: foreign\ndescription: A foreign preset\npermissionMode: bypassPermissions\n---\n\nBody.\n",
        );

        $presets = (new ForeignAgentPresetRegistry())->discoverClaude($this->root . '/proj');

        $this->assertArrayHasKey('foreign', $presets);
        $this->assertSame(PermissionMode::BypassPermissions, $presets['foreign']->permissionMode);
    }

    private function loadNative(string $extraFrontmatter): \SugarCraft\Crush\Agents\AgentPreset
    {
        $dir = $this->root . '/agents';
        @mkdir($dir, 0o700, true);
        file_put_contents(
            $dir . '/native.md',
            "---\nname: native\ndescription: A native preset\n{$extraFrontmatter}\n---\n\nBody.\n",
        );

        return (new AgentPresetRegistry([$dir]))->load('native');
    }
}
