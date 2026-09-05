<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Triggers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Triggers\IntentTrigger;
use SugarCraft\Crush\Context\Triggers\KeywordTrigger;
use SugarCraft\Crush\Context\Triggers\PathTrigger;
use SugarCraft\Crush\Context\Triggers\Trigger;

/**
 * Behaviour tests for the P6.S1 trigger union ({@see Trigger} and its three
 * final implementers). These are value tests, not shape tests: every case
 * calls the real matcher/projector and asserts an exact bool/list/string/int,
 * both polarities, plus the pathological input the step is about.
 *
 * The core of the step — whole-word keyword anchoring (§4.20) — is pinned in
 * {@see self::testKeywordMatchesWholeWords()}, whose `rethinking`/`thinking`
 * /`bethinks` non-matches go red the instant the `/\b…\b/iu` anchoring is
 * weakened to a naive unanchored scan (the deletion experiment). The lifetime
 * dedup semantics derived in the KeywordTrigger docblock (per-word key,
 * instance scope, union-on-merge, fresh-on-withWords) are pinned with exact
 * bools and ledgers so any change of mechanism reddens a test.
 */
final class TriggerTest extends TestCase
{
    // -----------------------------------------------------------------
    // 1. KeywordTrigger — whole-word anchoring (the point of the step)
    // -----------------------------------------------------------------

    public function testKeywordMatchesWholeWordsAcrossPunctuationAndCase(): void
    {
        $trigger = KeywordTrigger::new(['think']);

        self::assertTrue($trigger->matches('re-think'));
        self::assertTrue($trigger->matches('think!'));
        self::assertTrue($trigger->matches('Think.'));
        self::assertTrue($trigger->matches('we should think'));
    }

    public function testKeywordDoesNotMatchEmbeddedSubstrings(): void
    {
        // The historical bug: unanchored includes() matched "rethinking" for
        // "think". The anchored matcher must reject every fused form.
        $trigger = KeywordTrigger::new(['think']);

        self::assertFalse($trigger->matches('rethinking'));
        self::assertFalse($trigger->matches('thinking'));
        self::assertFalse($trigger->matches('bethinks'));
        // Underscore is a word constituent, so re_think is ONE whole word.
        self::assertFalse($trigger->matches('re_think'));
    }

    public function testKeywordMatchingIsUnicodeCaseInsensitive(): void
    {
        // The `u` modifier gives full Unicode case folding: "café" matches
        // "CAFÉ". Without `u`, é is a boundary byte and this could not work.
        $trigger = KeywordTrigger::new(['café']);

        self::assertTrue($trigger->matches('a CAFÉ break'));
        self::assertTrue($trigger->matches('I like café'));

        // Unicode-aware boundaries fuse adjacent letters into one word, so
        // "uncafé" is NOT the whole word "café" — same discipline as ASCII.
        self::assertFalse($trigger->matches('uncafé'));
    }

    public function testKeywordMatchedWordsReturnsDeclarationOrderSubset(): void
    {
        $trigger = KeywordTrigger::new(['think', 'reflect', 'reason']);

        // Multiple words declared, only the ones present come back, in order.
        self::assertSame(['think', 'reflect'], $trigger->matchedWords('let us think and reflect'));
        // Polarity: a word fused into a bigger token is excluded — neither
        // "reflecting" nor "thinking" contains the whole words "reflect"/"think".
        self::assertSame([], $trigger->matchedWords('reflecting is not thinking'));
        // Only the standalone word among several matches, in declaration order.
        self::assertSame(['reflect'], $trigger->matchedWords('reflect on it without thinking'));
        // Polarity: nothing present.
        self::assertSame([], $trigger->matchedWords('no signal here'));
    }

    // -----------------------------------------------------------------
    // 2. KeywordTrigger — lifetime dedup ledger
    // -----------------------------------------------------------------

    public function testKeywordMatchesIsPureAndNeverTouchesLedger(): void
    {
        $trigger = KeywordTrigger::new(['think']);

        // matches()/matchedWords() are pure: repeated calls stay true and the
        // ledger stays empty regardless of how often the word hits.
        self::assertTrue($trigger->matches('think'));
        self::assertTrue($trigger->matches('think'));
        self::assertSame(['think'], $trigger->matchedWords('think'));
        self::assertSame(['think'], $trigger->matchedWords('think'));
        self::assertSame([], $trigger->firedWords());
    }

    public function testKeywordFiresOnlyOnFirstAnnouncementOfEachWord(): void
    {
        $trigger = KeywordTrigger::new(['think', 'reflect']);

        // First "think" fires true and records it.
        self::assertTrue($trigger->fires('please think'));
        self::assertSame(['think'], $trigger->firedWords());

        // A repeated prompt hitting only spent words is false.
        self::assertFalse($trigger->fires('think again'));
        self::assertSame(['think'], $trigger->firedWords());

        // A distinct word still fires after the first is spent.
        self::assertTrue($trigger->fires('now reflect'));
        self::assertSame(['think', 'reflect'], $trigger->firedWords());

        // Both now spent.
        self::assertFalse($trigger->fires('think and reflect'));
    }

    public function testKeywordFireLedgerKeysAreLowerCased(): void
    {
        $trigger = KeywordTrigger::new(['Think']);

        // The dedup key is mb_strtolower(word), so case variants share a slot.
        self::assertTrue($trigger->fires('THINK about it'));
        self::assertSame(['think'], $trigger->firedWords());
        self::assertFalse($trigger->fires('think more'));
    }

    public function testKeywordMergeFiredFromUnionsSiblingLedger(): void
    {
        $left = KeywordTrigger::new(['think', 'reflect']);
        $left->fires('think');

        $right = KeywordTrigger::new(['think', 'reflect']);
        $right->fires('reflect');

        // Rejoin: left absorbs right's announcements.
        $left->mergeFiredFrom($right);

        self::assertSame(['think', 'reflect'], $left->firedWords());
        // reflect is now known to left, so it no longer fires there.
        self::assertFalse($left->fires('reflect again'));
        // The merge is one-way; the sibling ledger is untouched.
        self::assertSame(['reflect'], $right->firedWords());
    }

    public function testKeywordWithWordsReturnsFreshLedgerAndLeavesReceiverAlone(): void
    {
        $original = KeywordTrigger::new(['think', 'reflect']);
        $original->fires('think');

        $successor = $original->withWords(['think', 'reflect']);

        // New value object, fresh ledger.
        self::assertNotSame($original, $successor);
        self::assertSame([], $successor->firedWords());
        self::assertTrue($successor->fires('think'));

        // Original ledger is untouched by the successor's life.
        self::assertSame(['think'], $original->firedWords());
    }

    public function testKeywordExposesWordsAsDeclaredValue(): void
    {
        $trigger = KeywordTrigger::new(['think', 'reflect']);

        self::assertSame(['think', 'reflect'], $trigger->words);
    }

    // -----------------------------------------------------------------
    // 3. PathTrigger — glob dialect + matcher-not-gatekeeper
    // -----------------------------------------------------------------

    public function testPathDoubleStarCrossesSeparators(): void
    {
        $trigger = PathTrigger::new(['src/**/test.php']);

        // `**/` matches zero or more leading segments.
        self::assertTrue($trigger->matches('src/test.php'));
        self::assertTrue($trigger->matches('src/a/b/test.php'));

        // `**` on its own spans everything including separators.
        $broad = PathTrigger::new(['**']);
        self::assertTrue($broad->matches('a/b/c/d.txt'));
    }

    public function testPathSingleStarAndQuestionDoNotCrossSeparators(): void
    {
        $star = PathTrigger::new(['src/*.php']);
        self::assertTrue($star->matches('src/a.php'));
        self::assertFalse($star->matches('src/deep/x.php'));

        $q = PathTrigger::new(['src/?x.php']);
        self::assertTrue($q->matches('src/ax.php'));
        self::assertFalse($q->matches('src/abx.php')); // two chars, one ?
        self::assertFalse($q->matches('src/x.php')); // zero chars, ? needs exactly one
    }

    public function testPathMatchingIsCaseSensitive(): void
    {
        $trigger = PathTrigger::new(['Makefile']);

        self::assertTrue($trigger->matches('Makefile'));
        self::assertFalse($trigger->matches('makefile'));
    }

    public function testPathMatchesAbsolutePathsOutsideRepoRootAsMatcherNotGatekeeper(): void
    {
        // DESIGN PIN: PathTrigger answers only "does this string match this
        // pattern". It has no repository-root notion and touches no filesystem,
        // so an absolute out-of-root path MATCHES a broad glob — matching is
        // not authorisation; the containment gate belongs to the P6.S2 loader.
        $trigger = PathTrigger::new(['**']);

        self::assertTrue($trigger->matches('/etc/passwd'));

        $prefixed = PathTrigger::new(['/etc/**']);
        self::assertTrue($prefixed->matches('/etc/passwd'));
    }

    public function testPathMatchingGlobsReturnsDeclarationOrderSubset(): void
    {
        $trigger = PathTrigger::new(['*.php', 'src/*.php', 'docs/**']);

        self::assertSame(['src/*.php'], $trigger->matchingGlobs('src/index.php'));
        // "a.php" matches only `*.php`; `src/*.php` cannot cross the separator.
        self::assertSame(['*.php'], $trigger->matchingGlobs('a.php'));
        self::assertSame([], $trigger->matchingGlobs('README.md'));
    }

    public function testPathTrailingDoubleStarRequiresSeparator(): void
    {
        $trigger = PathTrigger::new(['src/**']);

        self::assertFalse($trigger->matches('src'));
        self::assertTrue($trigger->matches('src/'));
        self::assertTrue($trigger->matches('src/a/b'));
    }

    public function testPathExposesGlobsAsDeclaredValue(): void
    {
        $trigger = PathTrigger::new(['*.php', 'docs/**']);

        self::assertSame(['*.php', 'docs/**'], $trigger->globs);
    }

    // -----------------------------------------------------------------
    // 4. IntentTrigger — truncation boundary
    // -----------------------------------------------------------------

    public function testIntentTruncationEmitsExactCharCountEndingInEllipsis(): void
    {
        $description = 'This intent description is deliberately far longer than the ceiling imposed on it for the listing.';
        $trigger = IntentTrigger::new($description, 20);

        self::assertTrue($trigger->isTruncated());
        $cut = $trigger->truncated();

        // maxChars-1 leading characters + the ellipsis = exactly maxChars chars.
        self::assertSame(20, mb_strlen($cut));
        self::assertSame(mb_substr($description, 0, 19) . '…', $cut);
        self::assertSame('…', mb_substr($cut, -1));
        // description remains the full truth.
        self::assertSame($description, $trigger->description);
    }

    public function testIntentTruncationNeverSplitsMultiByteCharacter(): void
    {
        // Each Japanese kana/ideograph is 3 bytes; a byte-based cut of 4 units
        // would split a codepoint. mb_substr keeps whole characters.
        $trigger = IntentTrigger::new('日本語テキストです', 4);

        self::assertTrue($trigger->isTruncated());
        $cut = $trigger->truncated();

        self::assertSame('日本語…', $cut);
        self::assertSame(4, mb_strlen($cut));
        self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
        // 3 kept 3-byte ideographs + a 3-byte ellipsis = 12 bytes, whole chars.
        self::assertSame(12, strlen($cut));
    }

    public function testIntentShortDescriptionPassesThroughIdentical(): void
    {
        $trigger = IntentTrigger::new('a concise intent', 160);

        self::assertFalse($trigger->isTruncated());
        self::assertSame('a concise intent', $trigger->truncated());
    }

    public function testIntentDescriptionAtExactCeilingIsNotTruncated(): void
    {
        // Boundary: length == maxChars passes through (the check is strict >).
        $trigger = IntentTrigger::new('abcde', 5);

        self::assertFalse($trigger->isTruncated());
        self::assertSame('abcde', $trigger->truncated());
    }

    public function testIntentMaxCharsOneEmitsBareEllipsis(): void
    {
        $trigger = IntentTrigger::new('abcdef', 1);

        self::assertTrue($trigger->isTruncated());
        self::assertSame('…', $trigger->truncated());
        self::assertSame(1, mb_strlen($trigger->truncated()));
    }

    public function testIntentDefaultCeilingIsConstantAndApplied(): void
    {
        self::assertSame(160, IntentTrigger::DEFAULT_MAX_CHARS);

        $default = IntentTrigger::new('short');
        self::assertSame(160, $default->maxChars);

        // A 161-char description trips the default ceiling by exactly one.
        $justOver = IntentTrigger::new(str_repeat('x', 161));
        self::assertTrue($justOver->isTruncated());
        self::assertSame(160, mb_strlen($justOver->truncated()));
    }

    public function testIntentWithSettersReturnNewInstancesAndLeaveReceiverAlone(): void
    {
        $original = IntentTrigger::new('the original description', 160);

        $ceilinged = $original->withMaxChars(5);
        self::assertNotSame($original, $ceilinged);
        self::assertSame(160, $original->maxChars);
        self::assertSame(5, $ceilinged->maxChars);
        self::assertSame('the …', $ceilinged->truncated());

        $reworded = $original->withDescription('a different description entirely');
        self::assertNotSame($original, $reworded);
        self::assertSame('the original description', $original->description);
        self::assertSame('a different description entirely', $reworded->description);
        // Ceiling is carried through withDescription.
        self::assertSame(160, $reworded->maxChars);
    }

    // -----------------------------------------------------------------
    // 5. Marker interface + immutability of every with*()
    // -----------------------------------------------------------------

    public function testEveryConcreteTriggerIsAnInstanceTheMarkerInterface(): void
    {
        $keyword = KeywordTrigger::new(['think']);
        $path = PathTrigger::new(['*.php']);
        $intent = IntentTrigger::new('do a thing');

        self::assertInstanceOf(Trigger::class, $keyword);
        self::assertInstanceOf(Trigger::class, $path);
        self::assertInstanceOf(Trigger::class, $intent);
    }

    public function testPathWithGlobsReturnsNewInstanceAndLeavesReceiverAlone(): void
    {
        $original = PathTrigger::new(['*.php']);
        $successor = $original->withGlobs(['src/**']);

        self::assertNotSame($original, $successor);
        self::assertSame(['*.php'], $original->globs);
        self::assertSame(['src/**'], $successor->globs);
        // Successor is independent of the original's patterns.
        self::assertFalse($original->matches('src/a.php'));
        self::assertTrue($successor->matches('src/a.php'));
    }

    // -----------------------------------------------------------------
    // 6. Fail-fast constructors (both polarities, exact exception)
    // -----------------------------------------------------------------

    public function testEmptyKeywordListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KeywordTrigger requires at least one word; an empty trigger can never fire.');

        KeywordTrigger::new([]);
    }

    public function testBlankKeywordEntryThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('KeywordTrigger word 1 must not be blank.');

        KeywordTrigger::new(['think', '   ']);
    }

    public function testEmptyGlobListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PathTrigger requires at least one glob; an empty trigger can never fire.');

        PathTrigger::new([]);
    }

    public function testBlankGlobEntryThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PathTrigger glob 0 must not be blank.');

        PathTrigger::new(['  ']);
    }

    public function testBlankIntentDescriptionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IntentTrigger requires a non-blank description; an unnamed intent cannot inform the model.');

        IntentTrigger::new('   ');
    }

    public function testIntentCeilingBelowOneThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IntentTrigger maxChars must be at least 1, 0 given.');

        IntentTrigger::new('a description', 0);
    }

    public function testValidConstructorsDoNotThrowAndReturnPopulatedValues(): void
    {
        // Polarity: the same shapes with valid input construct cleanly.
        $keyword = KeywordTrigger::new(['think']);
        $path = PathTrigger::new(['src/**/test.php']);
        $intent = IntentTrigger::new('summarise the change', 80);

        self::assertSame(['think'], $keyword->words);
        self::assertSame(['src/**/test.php'], $path->globs);
        self::assertSame('summarise the change', $intent->description);
        self::assertSame(80, $intent->maxChars);
    }
}
