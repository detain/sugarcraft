<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\RawMsg;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\Tui\ScreenReaderMode;
use SugarCraft\Style\Border;
use SugarCraft\Style\Border\TitleAnchor;

/**
 * C-TUI-6.i: the screen-reader switch. Off is the byte-identical world
 * every 6.j capture pin leans on; on, the helpers shed the decoration a
 * screen reader cannot narrate. The setting is user-tier (a checked-out
 * project must not flip the operator's accessibility mode) and Live
 * (a save applies on the next read, no reload) — both facts about the
 * definition, plus the read path through the settings memo, are pinned
 * here rather than left to the schema roster alone.
 */
final class ScreenReaderModeTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-screen-reader-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        UiSettings::forget();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);

        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function writeConfig(array $values): void
    {
        file_put_contents($this->home . '/.candy-code/config.json', json_encode($values, JSON_THROW_ON_ERROR));
        UiSettings::forget();
    }

    public function testOffIsTheDefaultAndEveryHelperIsAPassthrough(): void
    {
        self::assertFalse(ScreenReaderMode::enabled());

        $border = Border::rounded()->withTitle('files', TitleAnchor::TopLeft);
        self::assertSame($border, ScreenReaderMode::border($border), 'off hands the SAME border back');
        self::assertSame('┌──┐ │x│ └──┘ ⠋ →', ScreenReaderMode::glyph('┌──┐ │x│ └──┘ ⠋ →'), 'off paints every byte unchanged');
        self::assertSame('Resume session', ScreenReaderMode::menuRow(2, 5, 'Resume session', true), 'off adds no marker, no position');
        self::assertNull(ScreenReaderMode::cue(), 'off cues nothing — the unwired call site is a no-op');
    }

    public function testTheDefinitionIsUserTierAndLive(): void
    {
        $definition = SettingsSchema::byKey('screenReader');
        self::assertNotNull($definition);
        self::assertSame(SettingCategory::Interface, $definition->category);
        self::assertSame(ApplyMode::Live, $definition->applyMode);
        self::assertTrue($definition->layered);
        self::assertFalse($definition->projectSettable, 'the operator\'s accessibility mode is not a project\'s to flip');
        self::assertTrue(in_array('screenReader', SettingsSchema::layeredKeys(), true));
        self::assertFalse(in_array('screenReader', SettingsSchema::projectTierKeys(), true));
        self::assertTrue(in_array('screenReader', LayeredSettings::userTierOnlyKeys(), true), 'and it is on the user-tier-only roster that SETTINGS.md tabulates');
    }

    public function testAProjectFileCannotSetTheKey(): void
    {
        // The write-side door: project layers are filtered through
        // PROJECT_TIER_KEYS by {@see LayeredSettings::only()} inside
        // projectLayer(), so a trusted project spelling the key is dropped
        // before anything reads it. A control key from the roster proves the
        // leg is not vacuous.
        $project = $this->home . '/project';
        mkdir($project . '/.candy-code', 0o700, true);
        $control = LayeredSettings::PROJECT_TIER_KEYS[0];
        file_put_contents(
            $project . '/' . LayeredSettings::SHARED_PATH,
            json_encode(['screenReader' => true, $control => true], JSON_THROW_ON_ERROR),
        );

        $layer = LayeredSettings::projectLayer($project, true);
        self::assertArrayNotHasKey('screenReader', $layer, 'a trusted project spelling the key is not merged at all');
        self::assertArrayHasKey($control, $layer, 'the same file\'s project-tier key does land — the door is the roster, not the file');
    }

    public function testASaveAppliesOnTheNextReadWithNoReload(): void
    {
        self::assertFalse(ScreenReaderMode::enabled());

        $this->writeConfig(['screenReader' => true]);
        self::assertTrue(ScreenReaderMode::enabled(), 'UiSettings::forget() — which Chat::applySettings() calls for every live key on save — is the whole reload story');

        $this->writeConfig(['screenReader' => false]);
        self::assertFalse(ScreenReaderMode::enabled(), 'and switching back is just as live');
    }

    /**
     * The memo IS the cost story of this switch — `AgentLiveRegistry` asks
     * `enabled()` on every spinner frame, so the merged read must happen once,
     * not per frame — and it is the Live story too: the read is held, and
     * exactly two things drop it. Nothing else in this class pinned that:
     * {@see writeConfig()} calls `forget()` itself, so the save-applies test
     * above passes even with the memo deleted outright. Each leg here fails
     * under one named removal (round-1 mutation proofs, FIX-1.md):
     *  - a deleted memo answers leg one from the file and serves `true`;
     *  - an inert `forget()` answers leg two from the held values;
     *  - a fingerprint that dropped the session tier answers leg three stale.
     */
    public function testTheReadIsHeldAndOnlyASaveOrASessionWriteDropsIt(): void
    {
        self::assertFalse(ScreenReaderMode::enabled(), 'the first read arms the held values');

        // Leg one: a HAND edit is not a save. The fingerprint covers the config
        // path, $HOME and the session tier — never the files' contents — so a
        // per-frame read does not re-open anything. That is the documented
        // "applies at the next launch, or the next save", stated as behaviour.
        file_put_contents(
            $this->home . '/.candy-code/config.json',
            json_encode(['screenReader' => true], JSON_THROW_ON_ERROR),
        );
        self::assertFalse(ScreenReaderMode::enabled(), 'the memo serves: the edit on disk is not consulted again');

        // Leg two: the invalidation a save actually performs. `Chat::applySettings()`
        // calls `UiSettings::forget()` for every `UiSettings::KEYS` key — including
        // `screenReader` — and that drop is the entire reload.
        UiSettings::forget();
        self::assertTrue(ScreenReaderMode::enabled(), 'forget() is the whole reload story');

        // Leg three: the session tier rides the fingerprint, so a session-only
        // write applies on its own terms — the overlay is above `config.json` in
        // the merged read, and a stale hold would silently win a save that the
        // settings view just reported as applied.
        SessionSettings::apply(['screenReader' => false]);
        self::assertFalse(ScreenReaderMode::enabled(), 'a session write busts the memo with no forget() at all');
    }

    public function testAnInvalidSpellingFallsBackToOff(): void
    {
        $this->writeConfig(['screenReader' => 'loud']);
        self::assertFalse(ScreenReaderMode::enabled(), 'a non-bool value reads as the schema default, never as truthy noise');
    }

    public function testOnBlanksTheFrameButKeepsEveryTitle(): void
    {
        $this->writeConfig(['screenReader' => true]);

        $border = Border::normal()
            ->withTitle('files', TitleAnchor::TopLeft)
            ->withTitle('2', TitleAnchor::TopRight);
        $blanked = ScreenReaderMode::border($border);

        $hidden = Border::hidden();
        foreach (['top', 'bottom', 'left', 'right', 'topLeft', 'topRight', 'bottomLeft', 'bottomRight', 'middleLeft', 'middleRight', 'middle', 'middleTop', 'middleBottom'] as $slot) {
            self::assertSame($hidden->{$slot}, $blanked->{$slot}, $slot);
        }
        self::assertSame($border->titles(), $blanked->titles(), 'the title TEXT survives — it is the one part a reader can narrate');
    }

    public function testOnTurnsBoxDrawingToSpacesAndTouchesNothingElse(): void
    {
        $this->writeConfig(['screenReader' => true]);

        // Every Box Drawing slot the renderer paints: horizontals, verticals,
        // corners, tees, crosses, plus the rounded and double faces of the
        // same block.
        self::assertSame('            ', ScreenReaderMode::glyph('┌─┬┐│ ├┼┤╭─╮'));
        self::assertSame('             ', ScreenReaderMode::glyph('╔═╦╗║ ╠═╣ └┴─'));
        // Off-block: the braille spinner (motion is pinned elsewhere —
        // AgentLiveRegistryTest), arrows, block shades, and text.
        self::assertSame('⠋ ↑ ▒ x', ScreenReaderMode::glyph('⠋ ↑ ▒ x'));
    }

    public function testOnGivesTheMenuRowATextualMarkerAndPosition(): void
    {
        $this->writeConfig(['screenReader' => true]);

        self::assertSame('> Resume session 2/5', ScreenReaderMode::menuRow(2, 5, 'Resume session', true), 'selection reads as text, not reverse video alone');
        self::assertSame('  Quit 5/5', ScreenReaderMode::menuRow(5, 5, 'Quit', false), 'unselected rows hold the column');
    }

    public function testOnCuesWithABelAsACmd(): void
    {
        $this->writeConfig(['screenReader' => true]);

        $cue = ScreenReaderMode::cue();
        self::assertInstanceOf(\Closure::class, $cue);
        // The Cmd shape: invoking the closure yields the sentinel RawMsg the
        // Program writes verbatim — BEL, the one cue every screen reader
        // terminal answers with a tone.
        $msg = $cue();
        self::assertInstanceOf(RawMsg::class, $msg);
        self::assertSame("\x07", $msg->bytes);
    }

    /**
     * The helpers' distinguishing bytes at the edges the sampled pins above
     * cannot see. Three holes each one closes:
     *  - `BOX_LAST`/`BOX_FIRST` are loop bounds, so an off-by-one there is
     *    invisible to a fixture of sampled glyphs — only the code points on
     *    either side of a bound prove the range is exactly U+2500-U+257F;
     *  - an embedded title is bracketed by junction runes that ARE box glyphs,
     *    which is the one case where "shed the frame" and "keep the title"
     *    collide: the shed must stop at the frame, byte for byte;
     *  - the marker column must answer `$selected` and nothing else, including
     *    at `1/1`, where a position-based shortcut would still read plausible.
     */
    public function testTheShapesAreExactAtTheBlockBoundsAndTheJunctionsRideWithTheTitle(): void
    {
        $this->writeConfig(['screenReader' => true]);

        // U+24FF and U+2580 are the code points immediately outside the block;
        // U+2500 and U+257F are its first and last.
        self::assertSame(
            "\u{24FF}  \u{2580}",
            ScreenReaderMode::glyph("\u{24FF}\u{2500}\u{257F}\u{2580}"),
            'the blanked run starts at U+2500 and ends at U+257F, one space each',
        );

        $border = Border::normal()
            ->withEmbeddedTitle('cpu', TitleAnchor::TopCenter, open: '┐', close: '┌');
        $blanked = ScreenReaderMode::border($border);
        $titles = $blanked->titles();
        self::assertArrayHasKey('TopCenter', $titles, 'the anchor an embedded title rode is kept');
        $junctioned = $titles['TopCenter'][0];
        self::assertSame('cpu', $junctioned->text);
        self::assertTrue($junctioned->embedded, 'it is still an EMBEDDED title, not flattened to a plain one');
        self::assertSame('┐', $junctioned->open, 'the title\'s own box glyphs are title text: the shed never touches them');
        self::assertSame('┌', $junctioned->close);
        self::assertSame(' ', $blanked->top, 'while the frame it sits in goes blank');

        self::assertSame('> One 1/1', ScreenReaderMode::menuRow(1, 1, 'One', true), 'first of one reads as selected');
        self::assertSame('  One 1/1', ScreenReaderMode::menuRow(1, 1, 'One', false), 'and first of one still holds the column');
    }

    /** Copied byte-for-byte from UiSettingsTest (the sandbox-teardown house shape). */
    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
