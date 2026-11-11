<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentMentions;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tests\Support\RosterAgent;

/**
 * Polarity table of the line-initial address door (RS-AGENT-C2): which
 * typed lines become '@agent:<name>' rewrites and which travel byte-
 * identical — the whole feature is this table, and every cell is pinned
 * from both sides (rewrite fires ONLY for addresses, prose survives EVERY
 * non-address shape the grammar could misfire on). The two manager-wired
 * doors at the tail (draftThrough/refusalThrough — mention-r3 NIT-1)
 * pin the roster closure those doors build over a live AgentManager, the
 * shape Chat::userTurnMessage() actually calls.
 */
final class AgentMentionsTest extends TestCase
{
    /** The whole roster of this fixture: byte-exact keys, gate-style closure. */
    private static function roster(string ...$names): \Closure
    {
        $held = array_fill_keys($names, true);

        return static fn (string $name): bool => isset($held[$name]);
    }

    public function testLineInitialAddressRewritesToTheReservedSpelling(): void
    {
        self::assertSame(
            '@agent:researcher fix the flaky parser',
            AgentMentions::draft('@researcher fix the flaky parser', self::roster('researcher')),
        );
    }

    public function testTheWholeRestOfTheLineTravelsVerbatimBehindTheToken(): void
    {
        // Multi-space, tabs, newlines and a second sentence — the substr
        // boundary is the END of the first token, not a re-join of words.
        self::assertSame(
            "@agent:coder  a\n\tb  c",
            AgentMentions::draft("@coder  a\n\tb  c", self::roster('coder')),
        );
    }

    public function testBareAddressWithoutATaskStillRewrites(): void
    {
        // The skill gate rewrites a bare '/shipit' to '$shipit'; an
        // addressee chosen with an empty ask is the user's own line.
        self::assertSame('@agent:coder', AgentMentions::draft('@coder', self::roster('coder')));
    }

    public function testMidLineMentionIsProse(): void
    {
        // The discriminator against a door that lost its anchor: 'fix @coder'
        // asks the model to work, it does not address anybody.
        self::assertNull(AgentMentions::draft('fix @coder now', self::roster('coder')));
        // Anchor-discrimination pin (MAJOR-1): the row above survives an
        // anchor loss only by COINCIDENCE of byte offsets — an unanchored
        // match still computes its rest from 1+strlen(name), and for 'coder'
        // at column 4 that rest begins 'oder', not a space. Here the roster
        // HOLDS the two-letter name and the mid-line '@dd' sits exactly three
        // columns past the offset: rest lands on ' @dd x', the token-end
        // check passes, and a door without the line-initial '^' rewrites
        // prose into '@agent:dd @dd x'. Only the anchor keeps mid-line prose
        // prose — this row is that anchor's pin.
        self::assertNull(AgentMentions::draft('abc @dd x', self::roster('dd')));
    }

    public function testEmailLikeAndDomainTokensAreProse(): void
    {
        // 'a@b.com' has no '@' at column zero at all; '@b.com' fails the name
        // grammar (dot); '@a@b.com' fails it twice.
        self::assertNull(AgentMentions::draft('a@b.com writes in', self::roster('b')));
        self::assertNull(AgentMentions::draft('@b.com writes in', self::roster('b')));
        self::assertNull(AgentMentions::draft('@a@b.com writes in', self::roster('a')));
    }

    public function testJunkAfterTheNameIsNotAnAddress(): void
    {
        // The name must be the WHOLE first token: '@coder.md' is a path the
        // file-mention leg may attach, and a line-initial '@coder.md exists'
        // that began with a name-shaped prefix is still a sentence about a file.
        self::assertNull(AgentMentions::draft('@coder.md exists', self::roster('coder')));
    }

    public function testAlreadyReservedKeywordSpellingsAreNeverRewritten(): void
    {
        $roster = self::roster('agent', 'diff', 'x');
        self::assertNull(AgentMentions::draft('@agent:x do it', $roster));
        self::assertNull(AgentMentions::draft('@diff:HEAD~3 what changed', $roster));
    }

    public function testBareAddressToAKnownNameWithColonJunkStaysProse(): void
    {
        // '@coder:x' — the COLON is the reserved vocabulary's shape whatever
        // the name, and the roster's business is not to guess which keyword
        // the user meant to write.
        self::assertNull(AgentMentions::draft('@coder:x hi', self::roster('coder')));
    }

    public function testTheBareKeywordHeadsStayProseEvenForAHeldName(): void
    {
        // '@' plus the bare 'diff' spelling is still a keyword mention (the
        // only head ContextMentions accepts WITHOUT a colon), so the door
        // declines even for a run holding an agent literally named diff.
        self::assertNull(AgentMentions::draft('@diff HEAD~3', self::roster('diff')));
    }

    /**
     * The guard behind {@see testTheBareKeywordHeadsStayProseEvenForAHeldName()}:
     * the reserved heads that survive as keyword mentions with NO colon come
     * from the mention channel's own grammar, re-derived here so the hard-
     * coded head list above cannot drift silently when the keyword channel
     * changes.
     *
     * @throws \ReflectionException
     */
    public function testTheColonlessKeywordSetIsDerivedFromTheChannelGrammar(): void
    {
        $pattern = (new \ReflectionClass(\SugarCraft\Code\Attachments\ContextMentions::class))
            ->getConstant('KEYWORD_PATTERN');
        self::assertIsString($pattern);

        $heads = ['agent', 'diff', 'mcp', 'pr', 'session'];
        $colonless = [];
        $withTail = [];

        foreach ($heads as $head) {
            // Which bare heads satisfy the keyword grammar without a tail
            // (those are the collision set for a bare address), and whether
            // every head still reserves its colon form (the 'mcp' two-colon
            // shape probed alongside) - both read from the channel itself,
            // never from prose here.
            $colonless[$head] = \preg_match($pattern, $head) === 1;
            $withTail[$head] = \preg_match($pattern, $head.':x') === 1
                || \preg_match($pattern, $head.':x:y') === 1;
        }

        self::assertSame(
            ['diff'],
            \array_keys($colonless, true, true),
            'bare heads that stay valid keyword mentions without a colon; a new one would '
            .'collide with the bare-address door and must be adjudicated there, not here',
        );
        self::assertSame(
            $heads,
            \array_keys($withTail, true, true),
            'every word head must reserve a colon keyword form; if one ever stops, the '
            .'hard-coded RESERVED_HEADS list drifts from the channel',
        );

        foreach ($heads as $head) {
            self::assertTrue(AgentMentions::isReservedHead($head));
        }

        self::assertFalse(AgentMentions::isReservedHead('coder'));
    }

    public function testUnknownNameAndEmptyAndClosedRostersAllDeclineToNothing(): void
    {
        // Null IS the "prose" answer — the refusal text is Chat's decision,
        // not this gate's; a gate that refused would fork the message.
        self::assertNull(AgentMentions::draft('@nosuch hi', self::roster('coder')));
        self::assertNull(AgentMentions::draft('@coder hi', self::roster()));
        self::assertNull(AgentMentions::draft('@coder hi', null));
    }

    public function testThrowingRosterFailsClosedToProse(): void
    {
        // The mention leg's law: a roster closure that throws must not take
        // the submit down with it.
        $boom = static fn (string $name): bool => throw new \RuntimeException('roster offline');
        self::assertNull(AgentMentions::draft('@coder hi', $boom));
        self::assertFalse(AgentMentions::routes('coder', $boom));
    }

    public function testLookupIsByteExactNotCaseFolded(): void
    {
        // The agent door everywhere else (AgentManager::get, the Task 'agent'
        // argument, the 'agent:' keyword leg) is byte-exact; the rewrite
        // copies the typed bytes and the roster answers on them.
        self::assertNull(AgentMentions::draft('@Coder hi', self::roster('coder')));
        self::assertSame('@agent:Coder hi', AgentMentions::draft('@Coder hi', self::roster('Coder')));
    }

    public function testAddressedNameCarriesEveryOtherPolarity(): void
    {
        self::assertSame('coder', AgentMentions::addressedName('@coder hi'));
        self::assertSame('coder', AgentMentions::addressedName('@coder'));
        self::assertSame('a-b_c9', AgentMentions::addressedName('@a-b_c9 do things'));
        self::assertNull(AgentMentions::addressedName('@'));
        self::assertNull(AgentMentions::addressedName('@9coder hi'));
        self::assertNull(AgentMentions::addressedName(' @coder hi'));
        self::assertNull(AgentMentions::addressedName('plain prose'));
        // A trailing hyphen is inside the name grammar: '@coder-' ends at
        // whitespace and IS an address token — the name the roster is asked
        // about is 'coder-'. The shapes that are NOT addresses are the ones
        // where a non-boundary character continues the token:
        self::assertSame('coder-', AgentMentions::addressedName('@coder- hi'));
    }

    public function testHyphenatedPresetNamesAddressLikeAnyOther(): void
    {
        self::assertSame(
            '@agent:sc-probe-agent hello',
            AgentMentions::draft('@sc-probe-agent hello', self::roster('sc-probe-agent')),
        );
    }

    public function testRoutesIsTheSharedTruthForBothDoors(): void
    {
        // draft() rewrites exactly what routes() says yes to, the refusal
        // fires exactly on what refuses() says yes to, and reserved heads
        // and a throwing roster are refused by NEITHER door — the one name
        // cannot be both rewritten and refused, and one cannot be neither
        // unless it is a keyword head (whose notices are the channel's).
        $roster = self::roster('coder');
        self::assertTrue(AgentMentions::routes('coder', $roster));
        self::assertFalse(AgentMentions::routes('nosuch', $roster));
        self::assertFalse(AgentMentions::routes('diff', $roster));
        self::assertFalse(AgentMentions::routes('agent', $roster));
        self::assertFalse(AgentMentions::routes('coder', self::roster()));

        self::assertTrue(AgentMentions::refuses('nosuch', $roster));
        self::assertTrue(AgentMentions::refuses('coder', self::roster()));
        self::assertFalse(AgentMentions::refuses('coder', $roster));
        self::assertFalse(AgentMentions::refuses('diff', $roster), 'the keyword head belongs to the channel, not the refusal');
        self::assertFalse(AgentMentions::refuses('agent', $roster));

        $boom = static fn (string $name): bool => throw new \RuntimeException('roster offline');
        self::assertFalse(AgentMentions::refuses('coder', $boom), 'an unanswerable roster sends the line as prose, not as a refusal');

        foreach (['coder', 'nosuch', 'diff', 'agent'] as $name) {
            self::assertFalse(
                AgentMentions::routes($name, $roster) && AgentMentions::refuses($name, $roster),
                'the two doors must never both claim '.$name,
            );
        }
    }

    public function testMidSentenceAddressCasingStillIsNotAnAddress(): void
    {
        // The vacuity pair for the anchor mutation: the ONLY way a '@name'
        // rewrite ever fires is column zero; 'as@coder' would be rewritten by
        // a door that lost the '^@' anchor.
        self::assertNull(AgentMentions::draft('as@coder hi', self::roster('coder')));
    }

    /**
     * draftThrough() (mention-r3 NIT-1): the manager-wired rewrite door.
     * Every row above drives draft() with a hand-built closure; this pins
     * the closure the door BUILDS over a live AgentManager — roster from
     * get(), and the no-manager door that leaves bare '@' names as prose.
     */
    public function testDraftThroughRoutesTheLiveManagerRoster(): void
    {
        $manager = $this->managerHolding('coder');

        // Held name: rewritten exactly as the closure-side rows spell it.
        self::assertSame('@agent:coder fix X', AgentMentions::draftThrough($manager, '@coder fix X'));
        // Not held: this door stays silent — refusalThrough() beside it
        // owns the unknown-name answer, and a line cannot get both.
        self::assertNull(AgentMentions::draftThrough($manager, '@ghost fix X'));
        // Byte-exact lookup rides through the manager: get() is case-
        // sensitive, so a capitalised near-miss is not an address either.
        self::assertNull(AgentMentions::draftThrough($manager, '@Coder fix X'));
        // No manager at all: nothing to delegate TO, every '@' is prose.
        self::assertNull(AgentMentions::draftThrough(null, '@coder fix X'));
    }

    /**
     * refusalThrough() (mention-r3 NIT-1): the refusal behind that door,
     * pinned verbatim for both roster shapes (names-the-agents tail and
     * the empty-roster admission), plus the three shapes that must cost
     * NO notice: a held name, a reserved keyword head, and no manager.
     */
    public function testRefusalThroughAnswersTheUnknownAddressBesideTheDraftDoor(): void
    {
        $manager = $this->managerHolding('coder', 'researcher');

        self::assertSame(
            '@ghost reads like an agent address, and this run holds no agent named "ghost" '
            . "— the line was not delegated and no turn was sent. The agents here are: coder, researcher. "
            . 'A name the roster holds delegates from the front of the line; put any '
            . "character in front of this one (a backslash works) and it travels to "
            . 'the model as the plain prose it is.',
            AgentMentions::refusalThrough($manager, '@ghost fix X'),
        );
        // Empty roster: the refusal admits there is nobody, naming none.
        self::assertStringContainsString(
            'This run has no agents to delegate to at all.',
            AgentMentions::refusalThrough($this->managerHolding(), '@ghost fix X') ?? '',
        );
        // A HELD name never refuses — draft() owns that answer.
        self::assertNull(AgentMentions::refusalThrough($manager, '@coder fix X'));
        // A reserved keyword head keeps its channel meaning whatever the
        // roster says: the keyword leg answers for it, not this door.
        self::assertNull(AgentMentions::refusalThrough($manager, '@agent:ghost fix X'));
        // No manager: with no roster to name, '@anything' stays prose.
        self::assertNull(AgentMentions::refusalThrough(null, '@ghost fix X'));
    }

    /** A live manager whose roster holds exactly $names. */
    private function managerHolding(string ...$names): AgentManager
    {
        $manager = new AgentManager(
            $this->createMock(ProviderInterface::class),
            new SkillRegistry(),
        );

        foreach ($names as $name) {
            $manager->register(RosterAgent::named($name));
        }

        return $manager;
    }
}
