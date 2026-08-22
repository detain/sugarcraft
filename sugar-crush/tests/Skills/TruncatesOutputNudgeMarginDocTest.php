<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * `TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES` is one half of a relationship
 * whose other half lives two namespaces away, and until now only the far half
 * knew about it.
 *
 * WHY IT MATTERS WHERE THE NOTE IS. Grep and Glob spend a
 * {@see SkillPathNudge::CALLER_BUDGET_DIVISOR} share of their output cap on the
 * path-scoped skill nudge, INSIDE the cap. Below a certain cap that share can
 * no longer hold a whole nudge and the model gets a clipped one.
 * {@see SkillPathNudge::smallestUnclippedCallerCap()} is that cap and exists
 * precisely to be cited — but someone lowering a tool's output cap arrives at
 * the constant, not at the nudge, and the constant said nothing. The guard that
 * enforces the margin lives in `SkillPathScopingWiringTest`, which is a file
 * that reader has no reason to open either.
 *
 * WHAT THIS FILE PINS, and why it is not a duplicate of that guard. That guard
 * asserts the RELATIONSHIP — the tightest shipped budget clears the ceiling by
 * the decided margin — and reds when the code drifts. This one asserts the
 * PROSE: every figure the constant's doc-block now quotes is re-derived here
 * and required to appear there. A figure in a comment is not a measurement, and
 * five rounds of this tree have produced a comment whose numbers had moved
 * underneath it. Change any of the four inputs and this reds with the number to
 * write instead.
 *
 * DELIBERATELY NOT ASSERTED HERE: that the margin is >= 2.0. That is
 * `SkillPathScopingWiringTest`'s claim, it is enforced there over the whole
 * shipped roster rather than over the two caps named in the comment, and
 * copying it would give the tree two guards that disagree the day a third tool
 * joins the roster. What is asserted is only that the doc-block's stated
 * DECISION (2.0x) is the constant that guard actually enforces — so the comment
 * cannot go on quoting a threshold nobody kept.
 *
 * @internal
 */
final class TruncatesOutputNudgeMarginDocTest extends TestCase
{
    private function docBlock(): string
    {
        $constant = new ReflectionClassConstant(Grep::class, 'DEFAULT_MAX_OUTPUT_BYTES');
        $doc = $constant->getDocComment();
        self::assertIsString(
            $doc,
            'TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES has lost its doc-block, and with it the only '
            . 'pointer from a tool output cap to the nudge ceiling that cap constrains',
        );

        // Continuation markers stripped BEFORE the whitespace collapse: a
        // doc-block wraps at 80 columns with a ` * ` on every line after the
        // first, so a phrase that spans a wrap is never those bytes in a row
        // and an assertion looking for one passes on a re-wrap.
        return (string) preg_replace(
            '/\s+/',
            ' ',
            (string) preg_replace('/^\s*\* ?/m', '', $doc),
        );
    }

    private function constantOf(string $class, string $name): int
    {
        $c = new ReflectionClassConstant($class, $name);

        return (int) $c->getValue();
    }

    /**
     * The four figures E87 decided, re-derived and required to be the ones the
     * comment quotes.
     */
    public function testTheCitedFiguresAreTheOnesTheCodeProduces(): void
    {
        $ceiling = SkillPathNudge::maxBytes();
        $divisor = SkillPathNudge::CALLER_BUDGET_DIVISOR;
        $smallest = SkillPathNudge::smallestUnclippedCallerCap();
        $grepCap = $this->constantOf(Grep::class, 'DEFAULT_MAX_OUTPUT_BYTES');
        $readCap = $this->constantOf(Read::class, 'DEFAULT_MAX_BYTES');

        self::assertSame(
            $ceiling * $divisor,
            $smallest,
            'smallestUnclippedCallerCap() is no longer ceiling x divisor; the doc-block derives it that way',
        );

        $doc = $this->docBlock();

        foreach ([
            'the nudge ceiling' => 'ceiling ' . number_format($ceiling) . ' bytes',
            'the divisor' => 'divisor ' . $divisor,
            'the smallest unclipped caller cap' => 'is ' . number_format($smallest),
            "this constant's own value" => "this constant's " . number_format($grepCap),
        ] as $what => $needle) {
            self::assertStringContainsString(
                $needle,
                $doc,
                sprintf(
                    'DEFAULT_MAX_OUTPUT_BYTES\'s doc-block no longer quotes %s as "%s". A figure in a '
                    . 'comment is not a measurement; re-derive it and rewrite the sentence.',
                    $what,
                    $needle,
                ),
            );
        }

        self::assertStringContainsString(
            sprintf('margin of %.2fx', $grepCap / $smallest),
            $doc,
            'the Grep/Glob margin quoted in DEFAULT_MAX_OUTPUT_BYTES\'s doc-block is not the one the '
            . 'constants produce (' . sprintf('%.2fx', $grepCap / $smallest) . ')',
        );
        self::assertStringContainsString(
            sprintf('is %.2fx', $readCap / $smallest),
            $doc,
            'the Read margin quoted in DEFAULT_MAX_OUTPUT_BYTES\'s doc-block is not the one the '
            . 'constants produce (' . sprintf('%.2fx', $readCap / $smallest) . ')',
        );
    }

    /**
     * The actionable floor the comment states must be the decided margin
     * applied to the derived cap — the number a reader lowering this constant
     * will actually stop at.
     */
    public function testTheActionableFloorIsTheDecidedMarginTimesTheDerivedCap(): void
    {
        $required = $this->requiredMarginFromTheGuard();
        $floor = (int) (SkillPathNudge::smallestUnclippedCallerCap() * $required);

        self::assertStringContainsString(
            'actionable floor for this constant is ' . number_format($floor),
            $this->docBlock(),
            sprintf(
                'DEFAULT_MAX_OUTPUT_BYTES\'s doc-block states an actionable floor that is not %.1fx the '
                . 'smallest unclipped caller cap. It should read %s.',
                $required,
                number_format($floor),
            ),
        );
    }

    /**
     * The doc-block must point at the far half by NAME, both the method and the
     * guard, or the loop it exists to close is open again.
     */
    public function testTheDocBlockNamesTheMethodAndTheGuardThatEnforceIt(): void
    {
        $doc = $this->docBlock();

        foreach ([
            'SkillPathNudge::smallestUnclippedCallerCap()',
            'SkillPathNudge::maxBytes()',
            'SkillPathNudge::CALLER_BUDGET_DIVISOR',
            'SkillPathScopingWiringTest::testTheShippedCapsClearTheCeilingByTheDecidedMargin()',
            'testEveryShippedNudgeBudgetClearsTheTrackerCeiling()',
        ] as $symbol) {
            self::assertStringContainsString(
                $symbol,
                $doc,
                "DEFAULT_MAX_OUTPUT_BYTES's doc-block no longer names {$symbol}. It is cited by SYMBOL "
                . 'rather than by line number precisely so it survives the next edit; if the symbol was '
                . 'renamed, follow it rather than dropping the pointer.',
            );
        }

    }

    /**
     * The tools that take this default and are NOT in the relationship must be
     * named — all of them, and nothing else.
     *
     * WHAT THIS REPLACED. `assertStringContainsString('Bash', $doc)` and the
     * same for `LspTool`. The census they stood for was CORRECT — constant
     * users are exactly `{Glob, Grep, Bash, LspTool}` and nudge spenders
     * exactly `{Glob, Grep, Read}` — but nothing derived it, so a fifth tool
     * taking this default with no nudge budget would leave the doc-block's
     * census incomplete with the suite green, in the one file whose stated
     * thesis is "a figure in a comment is not a measurement".
     *
     * THE PREDICATE IS THE ROSTER'S OWN, not a second definition of it.
     * "Spends a nudge share" is `hasProperty('skillNudge')`, which is the first
     * gate
     * {@see \SugarCraft\Crush\Tests\Integration\SkillPathScopingWiringTest}'s
     * `nudgeSpendRoster()` applies. Re-deriving the BUDGET SHAPE here as well
     * would give the tree two derivations that can disagree; this one asks only
     * the question the doc-block's sentence asks.
     */
    public function testTheBystanderCensusIsTheOneTheTreeProduces(): void
    {
        $users = $this->constantUsers();
        self::assertNotSame([], $users, 'nothing uses DEFAULT_MAX_OUTPUT_BYTES any more');

        $bystanders = [];
        foreach ($users as $short) {
            $class = 'SugarCraft\\Crush\\Tools\\BuiltIn\\' . $short;
            self::assertTrue(class_exists($class), $class . ' does not exist');
            if (!(new ReflectionClass($class))->hasProperty('skillNudge')) {
                $bystanders[] = $short;
            }
        }
        sort($bystanders);

        self::assertNotSame(
            [],
            $bystanders,
            'every user of DEFAULT_MAX_OUTPUT_BYTES now spends a nudge share, so the doc-block '
            . 'paragraph carving out the ones that do not describes an empty set and should say so',
        );

        $clause = $this->bystanderClause();
        $named = [];
        foreach ($this->builtInToolNames() as $short) {
            if (str_contains($clause, $short)) {
                $named[] = $short;
            }
        }
        sort($named);

        self::assertSame(
            $bystanders,
            $named,
            'DEFAULT_MAX_OUTPUT_BYTES\'s doc-block carves out a different set of tools than the tree '
            . 'produces. BOTH directions are the defect: a tool it omits leaves a reader believing '
            . 'the margin covers a cap it does not, and a tool it adds sends someone looking for a '
            . 'nudge budget that is there. Derived users: ' . implode(', ', $users) . '.',
        );
    }

    /**
     * Every built-in tool that references `DEFAULT_MAX_OUTPUT_BYTES`.
     *
     * TOKEN STREAM, not a text scan: this constant is discussed by name in
     * `TruncatesOutput`'s own prose and in this test's, and a text scan would
     * let a doc-block register as a use.
     *
     * @return list<string> short class names, sorted
     */
    private function constantUsers(): array
    {
        $found = [];

        foreach ($this->builtInToolFiles() as $short => $file) {
            $tokens = array_values(array_filter(
                \PhpToken::tokenize((string) file_get_contents($file)),
                static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
            ));
            foreach ($tokens as $i => $token) {
                if ($token->is(T_STRING)
                    && $token->text === 'DEFAULT_MAX_OUTPUT_BYTES'
                    && ($tokens[$i - 1]->text ?? '') === '::') {
                    $found[] = $short;
                    break;
                }
            }
        }
        sort($found);

        return $found;
    }

    /** @return array<string, string> short class name => file */
    private function builtInToolFiles(): array
    {
        $dir = __DIR__ . '/../../src/Tools/BuiltIn';
        $files = [];
        foreach ((array) glob($dir . '/*.php') as $file) {
            if (is_string($file)) {
                $files[basename($file, '.php')] = $file;
            }
        }
        ksort($files);

        self::assertNotSame([], $files, 'no built-in tools found; the census is broken');

        return $files;
    }

    /** @return list<string> */
    private function builtInToolNames(): array
    {
        return array_keys($this->builtInToolFiles());
    }

    /**
     * The doc-block paragraph that carves out the non-spending users.
     *
     * Scoped to the paragraph rather than searched for in the whole doc-block,
     * because the doc-block names `Grep` and `Glob` several times above as the
     * tools that DO spend — so a whole-doc-block search for a tool name answers
     * a different question than the one being asked.
     */
    private function bystanderClause(): string
    {
        $doc = $this->docBlock();
        $from = 'NOT EVERY USER OF THIS CONSTANT IS IN THAT RELATIONSHIP';
        $to = 'the margin says nothing about them.';

        $start = strpos($doc, $from);
        self::assertNotFalse(
            $start,
            "DEFAULT_MAX_OUTPUT_BYTES's doc-block no longer opens its bystander paragraph with "
            . "\"{$from}\"",
        );
        $end = strpos($doc, $to, $start);
        self::assertNotFalse(
            $end,
            "DEFAULT_MAX_OUTPUT_BYTES's doc-block's bystander paragraph no longer ends with "
            . "\"{$to}\"",
        );

        return substr($doc, $start, $end - $start + strlen($to));
    }

    /**
     * The decided margin the comment quotes must be the one the guard enforces.
     *
     * Read off the guard's own constant rather than restated, so the comment
     * cannot go on quoting a threshold that was changed there.
     */
    private function requiredMarginFromTheGuard(): float
    {
        $guard = \SugarCraft\Crush\Tests\Integration\SkillPathScopingWiringTest::class;
        self::assertTrue(
            class_exists($guard),
            'the guard DEFAULT_MAX_OUTPUT_BYTES points at no longer exists',
        );

        $reflection = new ReflectionClass($guard);
        self::assertTrue(
            $reflection->hasConstant('REQUIRED_CEILING_MARGIN'),
            'SkillPathScopingWiringTest no longer declares REQUIRED_CEILING_MARGIN, which is the '
            . 'threshold DEFAULT_MAX_OUTPUT_BYTES\'s doc-block quotes',
        );

        $margin = (float) (new ReflectionClassConstant($guard, 'REQUIRED_CEILING_MARGIN'))->getValue();

        self::assertStringContainsString(
            sprintf('keep at least %.1fx', $margin),
            $this->docBlock(),
            sprintf(
                'DEFAULT_MAX_OUTPUT_BYTES\'s doc-block quotes a decided margin that is not the %.1fx '
                . 'SkillPathScopingWiringTest::REQUIRED_CEILING_MARGIN enforces',
                $margin,
            ),
        );

        return $margin;
    }
}
