<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillSource;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\TerminalBackground;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Sprinkles\Theme as SprinklesTheme;

/**
 * Tests for SkillSource — provenance tagging for foreign skill imports.
 */
final class SkillSourceTest extends TestCase
{
    // -------------------------------------------------------------------------
    // badge()
    // -------------------------------------------------------------------------

    public function testNativeBadgeIsEmpty(): void
    {
        // Native is the default — no visual noise for the common case.
        $this->assertSame('', SkillSource::Native->badge());
    }

    public function testClaudeBadge(): void
    {
        $this->assertSame('[claude]', SkillSource::Claude->badge());
    }

    public function testOpencodeBadge(): void
    {
        $this->assertSame('[opencode]', SkillSource::Opencode->badge());
    }

    public function testAgentSkillsSpecBadge(): void
    {
        $this->assertSame('[spec]', SkillSource::AgentSkillsSpec->badge());
    }

    // -------------------------------------------------------------------------
    // color()
    // -------------------------------------------------------------------------

    /**
     * The badge is meant to be scannable without reading it, so no two sources
     * may share a hue.
     *
     * DOMAIN: asserted for each offered palette against that palette's OWN
     * background, i.e. a terminal themed to match the theme the user picked.
     * On a terminal background no palette token is legible against (a mid
     * grey), {@see Theme} escalates several tokens onto one monochrome floor
     * and this promise does not hold - see SkillSource::color()'s docblock for
     * why legibility wins that tie.
     */
    public function testEachSourceHasItsOwnBadgeColorInEveryPalette(): void
    {
        foreach (Theme::names() as $name) {
            TerminalBackground::forget();
            $sprinkles = self::sprinklesFor($name);
            TerminalBackground::observe(new BackgroundColorMsg(
                $sprinkles->background->r,
                $sprinkles->background->g,
                $sprinkles->background->b,
            ));

            $theme = Theme::byName($name);
            $rgb = array_map(
                static fn(SkillSource $s): string => $s->color($theme)->toHex(),
                SkillSource::cases()
            );

            $this->assertCount(count(SkillSource::cases()), array_unique($rgb), "hue collision under {$name}");
        }

        TerminalBackground::forget();
    }

    public function testClaudeBadgeUsesTheWarningToken(): void
    {
        $theme = Theme::byName('dark');

        $this->assertSame($theme->shellWarning->toHex(), SkillSource::Claude->color($theme)->toHex());
    }

    /** `adaptive` resolves to dark/light, so it has no background token of its own. */
    private static function sprinklesFor(string $name): SprinklesTheme
    {
        return match ($name) {
            'light' => SprinklesTheme::light(),
            'dracula' => SprinklesTheme::dracula(),
            'tokyoNight' => SprinklesTheme::tokyoNight(),
            'ansi' => SprinklesTheme::ansi(),
            default => SprinklesTheme::dark(),
        };
    }

    // -------------------------------------------------------------------------
    // enum shape — backed values used for (de)serialization
    // -------------------------------------------------------------------------

    public function testBackedValues(): void
    {
        $this->assertSame('native', SkillSource::Native->value);
        $this->assertSame('claude', SkillSource::Claude->value);
        $this->assertSame('opencode', SkillSource::Opencode->value);
        $this->assertSame('spec', SkillSource::AgentSkillsSpec->value);
    }

    public function testFromValueRoundTrips(): void
    {
        foreach (SkillSource::cases() as $case) {
            $this->assertSame($case, SkillSource::from($case->value));
        }
    }

    // -------------------------------------------------------------------------
    // Skill::$source — default + threading
    // -------------------------------------------------------------------------

    public function testSkillDefaultsToNativeSource(): void
    {
        // Skill::parse() never sets $source explicitly; the constructor
        // default is what makes every existing (pre-provenance) call site
        // keep working unmodified.
        $skill = Skill::parse("---\ndescription: test\n---\nBody.", 'test-skill');

        $this->assertSame(SkillSource::Native, $skill->source);
    }

    public function testSkillConstructorAcceptsExplicitSource(): void
    {
        $skill = new Skill(
            name: 'imported-skill',
            description: 'Imported from Claude Code',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: 'Body.',
            sourcePath: '/path/SKILL.md',
            source: SkillSource::Claude,
        );

        $this->assertSame(SkillSource::Claude, $skill->source);
        $this->assertSame('[claude]', $skill->source->badge());
    }

    public function testWithNamePreservesForeignSource(): void
    {
        // Regression guard: withName() enumerates every constructor arg by
        // hand. Before source: $this->source was added to that call, a
        // rename would silently reset provenance back to Native, making a
        // renamed foreign skill indistinguishable from a native one.
        $foreign = new Skill(
            name: 'imported-skill',
            description: 'Imported',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: 'Body.',
            sourcePath: '/path/SKILL.md',
            source: SkillSource::Opencode,
        );

        $renamed = $foreign->withName('renamed-skill');

        $this->assertSame('renamed-skill', $renamed->name);
        $this->assertSame(SkillSource::Opencode, $renamed->source);
    }
}
