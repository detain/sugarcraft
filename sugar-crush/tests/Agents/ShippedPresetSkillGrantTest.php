<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPresetRegistry;

/**
 * Skills QA fix round 1 (lane D STOP-seam): every tracked native agent preset
 * that declares a restrictive `tools:` list must also name `Skill`.
 *
 * Lane D put the fenced `<available-skills>` listing into every sub-agent
 * worker prompt; the orchestrator's ruling (Claude Code and opencode parity)
 * is that a worker shown the catalogue must be able to open it. `SkillTool`'s
 * permission class is `Read` — a Level-2 load is one escaped, fenced text
 * file, no hidden capability — and a preset without `Read` on its roster
 * would gain an inconsistent surface from the door, so the same line must
 * name `Read`; that precondition is pinned rather than assumed.
 *
 * These are the REAL shipped files parsed through the production reader, the
 * way `AgentPresetRegistryTest::testLoadsRealShippedCoderPreset()` reads it —
 * the point of the pin is the shipped data, so a fixture copy would pin
 * nothing. A preset with no `tools:` line parses to the empty list and
 * inherits the engine's full set (which already includes `Skill`), so only a
 * declared, non-empty list is policed here.
 */
final class ShippedPresetSkillGrantTest extends TestCase
{
    /** @return list<string> the preset trees this checkout ships */
    private static function presetTrees(): array
    {
        $root = \dirname(__DIR__, 3);

        return array_values(array_filter([
            $root . '/.sugar-crush/agents',
            $root . '/sugar-crush/.sugar-crush/agents',
        ], 'is_dir'));
    }

    /**
     * @return \Generator<string, array{string, string}> tree label => [registry tree, preset name]
     */
    public static function everyShippedPreset(): \Generator
    {
        $root = \dirname(__DIR__, 3);
        foreach (self::presetTrees() as $tree) {
            $label = $tree === $root . '/.sugar-crush/agents' ? 'root' : 'sugar-crush';
            foreach ((new AgentPresetRegistry([$tree]))->list() as $name => $preset) {
                yield $label . '/' . $name => [$tree, (string) $name];
            }
        }
    }

    public function testThePinActuallySeesTheShippedPresetTrees(): void
    {
        // Vacuity guard: the per-preset pin enumerates from disk, and a
        // relocated tree would silently enumerate nothing. The
        // rosterFromRealBundle() discipline — the bundle is only a real test
        // while these files exist.
        $this->assertGreaterThanOrEqual(
            6,
            count(iterator_to_array(self::everyShippedPreset())),
            'expected at least the six tracked presets (three per tree, root and sugar-crush/); '
            . 'the trees moved and the per-preset pin went blind',
        );
    }

    /**
     * @dataProvider everyShippedPreset
     */
    public function testEveryDeclaredToolListCarriesTheSkillDoor(string $tree, string $name): void
    {
        $preset = (new AgentPresetRegistry([$tree]))->load($name);

        // An empty list is also what "no `tools:` line" parses to — an
        // unconstrained grant inheriting the engine's full set, which already
        // holds the door. Only a declared restriction is policed, so the
        // defect legs below simply do not fire for it.
        $defects = [];
        if ($preset->tools !== [] && !in_array('Read', $preset->tools, true)) {
            $defects[] = 'has no Read — the Skill-door ruling excludes such a preset; revert its Skill grant instead';
        }
        if ($preset->tools !== [] && !in_array('Skill', $preset->tools, true)) {
            $defects[] = sprintf(
                'declares [%s], sees the skills listing with no door; add Skill (ruling: '
                . 'Claude/opencode parity, SkillTool is permission-class Read) — do not delete this pin',
                implode(', ', $preset->tools),
            );
        }

        $this->assertSame([], $defects, "preset {$name}: " . implode('; ', $defects));
    }
}
