<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;

/**
 * Every wired built-in tool ships a description of at least three sentences.
 *
 * `description()` is the model's only first-contact documentation for a tool it
 * has not yet called this session, and a one-line sentence cannot carry the four
 * things the Anthropic rubric asks for — what it does, when NOT to reach for it,
 * what each parameter means, and what it does not return. The plan's P9.S4 step
 * rewrote five such descriptions to multi-sentence man-pages; this is the guard
 * that keeps the whole corpus there, so the next terse tool cannot slip back in.
 *
 * THE DOMAIN IS THE WIRED BUILT-INS, and it is derived rather than listed, in
 * the same move {@see BuiltInToolCorpus} made for the rest of the tool suite: the
 * roster is every tool {@see BuiltInToolCorpus::instances()} builds, MINUS every
 * class named in {@see BuiltInToolCorpus::dynamicToolClasses()}. That subtraction
 * is by CLASS KIND, not by a list of names this file maintains: the excluded
 * bridge wraps one descriptor discovered from a project's MCP server, so the text
 * it returns is that server's prose, not anything authored in `src/` — holding it
 * to this repo's sentence floor would test a string this codebase does not write.
 * The companion guard asserts the walked set equals `classNames()` minus that same
 * dynamic list, so a new wired tool enters here automatically and an exemption
 * cannot be granted silently.
 *
 * A SENTENCE is a run ending in `.`, `!` or `?` followed by whitespace or the end
 * of the string — {@see countSentences()} — the same house counting style used to
 * size the corpus elsewhere. It is deliberately a low bar: three such terminators
 * is the smallest shape that can state what a tool does and what it does not.
 */
final class ToolDescriptionSentenceFloorTest extends TestCase
{
    /**
     * One case per wired built-in tool, keyed by the tool's own public name so a
     * red line names the offender.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function wiredBuiltInDescriptions(): iterable
    {
        $dynamic = BuiltInToolCorpus::dynamicToolClasses();

        foreach (BuiltInToolCorpus::instances() as $tool) {
            if (in_array($tool::class, $dynamic, true)) {
                continue;
            }

            yield $tool->name() => [$tool->name(), $tool->description()];
        }
    }

    /**
     * @dataProvider wiredBuiltInDescriptions
     */
    public function testEveryWiredBuiltInDescriptionMakesAtLeastThreeSentences(string $name, string $description): void
    {
        $sentences = self::countSentences($description);

        $this->assertGreaterThanOrEqual(
            3,
            $sentences,
            sprintf(
                '%s::description() makes %d sentence(s); the corpus floor is 3, so that a '
                . 'first-time caller learns what the tool does, when not to use it, and what it '
                . 'returns without paying a wasted turn to find out. Offending text: %s',
                $name,
                $sentences,
                $description,
            ),
        );
    }

    /**
     * The floor walk covers exactly the wired tools, and the dynamic MCP bridge is
     * out by the class-kind rule rather than a name list.
     *
     * Without this, the provider could quietly shrink to a single tool — say the
     * bridge had been dropped from {@see BuiltInToolCorpus::dynamicToolClasses()}
     * and then thrown on construction — and "every wired description clears 3"
     * would be true of a corpus nobody is looking at.
     */
    public function testTheWalkIsTheWiredCorpusMinusTheDynamicBridges(): void
    {
        $dynamic = BuiltInToolCorpus::dynamicToolClasses();
        $expected = array_values(array_diff(BuiltInToolCorpus::classNames(), $dynamic));
        sort($expected);

        $walked = [];
        foreach (BuiltInToolCorpus::instances() as $tool) {
            if (in_array($tool::class, $dynamic, true)) {
                continue;
            }

            $walked[] = $tool::class;
        }
        sort($walked);

        $this->assertNotEmpty(
            $dynamic,
            'the dynamic-tool list is empty, so the class-kind subtraction this floor rests on '
            . 'is doing nothing — re-derive the domain before trusting the count',
        );
        $this->assertSame(
            $expected,
            $walked,
            'the sentence-floor walk no longer covers exactly the wired built-ins',
        );
    }

    /**
     * Count sentences as terminators followed by whitespace or end-of-string.
     *
     * `preg_match_all()` returns the count; the cast keeps the return type honest
     * for the one failure mode (a malformed pattern), which cannot happen with this
     * literal but must not widen the signature to `int|false`.
     */
    private static function countSentences(string $text): int
    {
        return (int) preg_match_all('/[.!?](\s|$)/', $text);
    }
}
