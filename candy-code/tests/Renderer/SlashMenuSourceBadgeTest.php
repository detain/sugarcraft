<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Skills\SkillSource;

/**
 * INT-3's display half: the "/" popup badges an imported row with BOTH axes —
 * whose disk tier AND whose convention — so an operator whose `~/.claude`
 * habits were imported can see WHICH import answers a name next to WHICH
 * tier owns it. The tier badge is ISS-036's (pinned by
 * SlashMenuTierBadgeTest); these rows pin the source leg added beside it and
 * the composition of the pair, in this file's own renamed helpers because
 * the drift guard is right that two files carrying one helper is one helper
 * pretending to be two.
 */
final class SlashMenuSourceBadgeTest extends TestCase
{
    /** @param array<string, CommandSpec> $commands */
    private function popupFrame(array $commands, string $buf, int $cols = 160): string
    {
        return Renderer::render((new Chat(
            backend: new EchoBackend(),
            inputBuf: $buf,
            customCommands: $commands,
        ))->withSize($cols, 40));
    }

    /** The one popup row carrying $needle, ANSI-stripped. */
    private function onlyRow(string $frame, string $needle): string
    {
        $found = [];
        foreach (explode("\n", $frame) as $line) {
            $plain = (string) preg_replace('/\e\[[0-9;:]*m/', '', $line);
            if (str_contains($plain, $needle)) {
                $found[] = $plain;
            }
        }
        self::assertCount(1, $found, "exactly one frame row must carry '$needle'");

        return $found[0];
    }

    private function importedSpec(string $name, ?string $tier, SkillSource $source): CommandSpec
    {
        return CommandSpec::new(
            name: $name,
            description: 'an imported row',
            category: 'Custom',
            template: 'body',
            tier: $tier,
            source: $source,
        );
    }

    public function testAForeignUserRowCarriesItsTierBadgeAndItsConventionBadge(): void
    {
        $row = $this->onlyRow(
            $this->popupFrame(['notes' => $this->importedSpec('notes', 'user', SkillSource::Opencode)], '/not'),
            '/notes [user] [opencode]',
        );
        self::assertStringContainsString('an imported row', $row, 'the two badges never crowd out the description at full width');
    }

    public function testAForeignProjectRowBadgesClaudeAsItsConvention(): void
    {
        $row = $this->onlyRow(
            $this->popupFrame(['legacy' => $this->importedSpec('legacy', 'project', SkillSource::Claude)], '/leg'),
            '/legacy [project] [claude]',
        );
        self::assertStringContainsString('[claude]', $row);
    }

    public function testAnUntieredImportedRowBadgesOnlyItsConvention(): void
    {
        // The axes are independent, and the popup says so: a row with no tier
        // (an in-process caller) wearing a foreign format still shows the
        // format and invents no tier.
        $row = $this->onlyRow(
            $this->popupFrame(['synth' => $this->importedSpec('synth', null, SkillSource::Claude)], '/synt'),
            '/synth [claude]',
        );
        self::assertStringNotContainsString('[user]', $row);
        self::assertStringNotContainsString('[project]', $row);
    }

    public function testTheNativeBadgeRowIsUntouchedByTheSourceLeg(): void
    {
        // Byte-compat with SlashMenuTierBadgeTest's needles: a tier-only row
        // must not have grown a trailing space or an empty second badge.
        $row = $this->onlyRow(
            $this->popupFrame(['deploy' => CommandSpec::new(name: 'deploy', description: 'a file row', category: 'Custom', template: 'body', tier: 'project')], '/dep'),
            '/deploy [project]',
        );
        self::assertStringNotContainsString('[opencode]', $row);
        self::assertStringNotContainsString('[claude]', $row);
    }

    public function testWidthPressureCutsTheDescriptionNotEitherBadge(): void
    {
        $crowded = CommandSpec::new(
            name: 'compact',
            description: 'an imported row whose description is far too long to fit beside two badges on this width',
            category: 'Custom',
            template: 'body',
            tier: 'user',
            source: SkillSource::Claude,
        );

        $row = $this->onlyRow($this->popupFrame(['compact' => $crowded], '/comp', 48), '/compact [user] [claude]');
        self::assertStringNotContainsString('this width', $row, 'the description tail absorbs both badges (ISS-036 budget, INT-3 adds the bytes it absorbs)');
    }
}
