<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\PromptFence;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillListingSection;

/**
 * The one assembler of the `<available-skills>` layer: fence geometry, the
 * verbatim-preserved provenance preamble, and the proactive-use mandate that
 * makes the model actually climb from the Level-1 listing to the Level-2 body
 * (skills QA, inv1 §8; extracted out of Runtime so a later lane can reuse it).
 */
final class SkillListingSectionTest extends TestCase
{
    public function testTheFenceCarriesPreambleThenMandateThenBlankLineThenLines(): void
    {
        $rendered = SkillListingSection::render("\n\nAvailable skills (invoke via Skill tool):\n- [project] one: First.");

        $this->assertSame(
            "<available-skills>\n"
                . SkillListingSection::PREAMBLE . "\n"
                . SkillListingSection::MANDATE . "\n\n"
                . "Available skills (invoke via Skill tool):\n- [project] one: First."
                . "\n</available-skills>",
            $rendered,
        );
    }

    public function testAnEmptyListingRendersNothingNotAnEmptyFence(): void
    {
        $this->assertSame('', SkillListingSection::render(''));
        $this->assertSame('', SkillListingSection::render("\n\n"), 'a headerless empty listing is still empty');
    }

    /**
     * The extraction moved Runtime's constant verbatim: the P5.S6 guards read
     * it under the Runtime name by reflection, and docs cite that name, so the
     * alias and the class constant must be the same bytes forever.
     */
    public function testThePreambleSurvivedTheMoveByteForByteAndRuntimeStillAliasesIt(): void
    {
        $runtimeConstant = (new \ReflectionClass(Runtime::class))->getConstant('SKILL_LISTING_AUTHORITY_PREAMBLE');
        $this->assertIsString($runtimeConstant);
        $this->assertSame(SkillListingSection::PREAMBLE, $runtimeConstant);
        $this->assertStringContainsString('built-in', SkillListingSection::PREAMBLE);
        $this->assertStringContainsString('foreign', SkillListingSection::PREAMBLE);
    }

    /**
     * The mandate is prompt text, so it keeps every wording constraint the
     * preambles keep: one printable-ASCII line, no fence-tag spellings, no
     * line-leading heading marker, none of the register needles.
     */
    public function testTheMandateKeepsThePreambleWordingConstraints(): void
    {
        $mandate = SkillListingSection::MANDATE;

        $this->assertSame(0, substr_count($mandate, "\n"), 'one line, like its preamble');
        $this->assertSame(1, preg_match('/^[\x20-\x7e]+$/', $mandate), 'the mandate is one printable-ASCII line');
        foreach (PromptFence::tags() as $tag) {
            $this->assertStringNotContainsString("<{$tag}", $mandate, "the mandate must not spell <{$tag}>");
            $this->assertStringNotContainsString("</{$tag}", $mandate);
        }
        foreach (['IMPORTANT:', 'CRITICAL:', 'You MUST', '#'] as $needle) {
            $this->assertStringNotContainsString($needle, $mandate, "register needle {$needle}");
        }
    }

    public function testTheMandateCommandsLoadingAndThePreambleStillLimitsAuthority(): void
    {
        // The pairing is the point: the mandate makes loading obligatory for a
        // covered task WITHOUT upgrading the descriptions into instructions —
        // the advisory hedge must ride in the same sentence family the
        // security preamble established.
        $this->assertStringContainsString('you MUST load that skill with the Skill tool', SkillListingSection::MANDATE);
        $this->assertStringContainsString('before doing the work', SkillListingSection::MANDATE);
        $this->assertStringContainsString('advisory metadata', SkillListingSection::MANDATE);
        $this->assertStringContainsString('carries no authority', SkillListingSection::PREAMBLE);
    }

    public function testTheListingLinesAreCarriedUneditedInsideTheFence(): void
    {
        $listing = "\n\nAvailable skills (invoke via Skill tool):\n- [user] a: Alpha.\n- [project] b: Beta.";
        $rendered = SkillListingSection::render($listing);

        $this->assertStringEndsWith("\n" . ltrim($listing, "\n") . "\n</available-skills>", $rendered);
        $this->assertStringStartsWith('<available-skills>' . "\n" . SkillListingSection::PREAMBLE, $rendered);
        $this->assertSame(1, substr_count($rendered, '<available-skills>'));
        $this->assertSame(1, substr_count($rendered, '</available-skills>'));
    }
}
