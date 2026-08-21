<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Support\ContainedPath;

/**
 * {@see ContainedPath}'s class doc-block inventory, DERIVED instead of trusted.
 *
 * The inventory is a security argument — "one implementation, and here is every
 * place that does not use it" — and it was hand-maintained across three rounds,
 * drifting each time. Every figure it quotes is measured here, per file, so the
 * prose cannot outlive the tree.
 *
 * WHAT CHANGED IN THIS REVISION, and why the previous bound statement was not
 * good enough. It read: "this counts the compares that are WRITTEN … it cannot
 * catch a read path that never had a compare." True, and much too flattering.
 * Two classes of defect were measured passing straight through the old
 * regex-based instrument, and NEITHER of them is a read path without a compare:
 *
 *  (a) A NEUTERED GATE KEEPS ITS COUNT. Replacing `InstructionFileLoader::loadRoot()`'s
 *      `if (!ContainedPath::within($path, $this->repoRoot)) { continue; }` with
 *      the bare statement `ContainedPath::within($path, $this->repoRoot);` —
 *      call present, RESULT DISCARDED, escape fully restored — left this file at
 *      `OK (5 tests, 14 assertions)`. The escape was caught, but by
 *      {@see \SugarCraft\Crush\Tests\Context\InstructionFileLoaderContainmentTest}
 *      (2 failures), not by the instrument whose whole job is to see gates.
 *
 *  (b) FIVE REAL HAND-SPELLED CONTAINMENT COMPARES WENT UNCOUNTED. Each was
 *      added to `src/Support/HomeDirectory.php` in turn and the inventory stayed
 *      `OK (5 tests, 14 assertions)` every time: arguments swapped
 *      (`str_starts_with($b . '/', $p)`), interpolated (`"$b/"`),
 *      `DIRECTORY_SEPARATOR`, `strncmp(…) === 0`, and a variable class name
 *      (`$c = ContainedPath::class; $c::within(…)`).
 *
 * So the instrument is no longer a line-regex. Both halves are derived from
 * `token_get_all()`:
 *
 *  - the ROUTED half counts a call site only when its RESULT IS USED, and a
 *    discarded result is a hard failure with its file and line named
 *    ({@see testNoRoutedContainmentCallHasItsResultDiscarded()});
 *  - the HAND-SPELLED half parses each `str_starts_with`/`strncmp`/
 *    `substr_compare` call's ARGUMENT LIST and asks whether any argument is
 *    boundary-suffixed, in any of the five spellings above.
 *
 * WHAT CHANGED AGAIN, because the previous revision's own two headline claims
 * were both false in the commit that made them:
 *
 *  (c) "ROUTED counts a call only when its result is consumed, and a discarded
 *      result is a hard failure." It was decided by the ONE token two places
 *      before the call, against the set `['{', '}', ';']` — while the doc-block
 *      claimed `:` was in it too. Probed directly, SEVEN discarded shapes
 *      reported `used: true`; mutating `InstructionFileLoader::loadRoot()`'s gate
 *      to `$unusedGateResult = ContainedPath::within(…);` left this file at
 *      `OK (26 tests, 39 assertions)` while `loadRoot()` returned
 *      `TOP-SECRET-AAA sk-live-DEADBEEF` out of a file symlinked out of the
 *      checkout. All seven are now data rows on
 *      {@see testTheInstrumentTellsAGateFromADiscardedCall()} and the rule is
 *      the STATEMENT rather than a token — see {@see routedCallsIn()}.
 *
 *  (d) "its blind spots are now MEASURED." Four more were measured that fell in
 *      none of the stated categories:
 *      `strncasecmp($p, $b . '/', …)` (the case-insensitive-filesystem
 *      spelling — the FUNCTION LIST was closed at three names),
 *      `sprintf('%s/', $b)`, a heredoc boundary, and `$b . '/' . ''` (only the
 *      LAST concat operand was examined). All four are now data rows on
 *      {@see testTheInstrumentRecognisesEverySpellingItClaimsTo()}.
 *
 * AND AGAIN, because the STATEMENT rule that replaced the one-token rule was
 * itself wrong in both directions:
 *
 *  (e) TWO FALSE GREENS — the direction this instrument exists to remove. The rule
 *      was "a prefix made only of value-neutral operators is an expression
 *      statement", i.e. anything ELSE meant used; so `$x && ContainedPath::within($a,
 *      $b);` reported `used: true` on the strength of `$x` and `&&`, while the
 *      statement is an expression statement and the call is its LAST operand, whose
 *      value nothing reads. And a closure — `$gate = function () { return
 *      ContainedPath::within($a, $b); };`, never invoked — reported `used: true`
 *      because `return` genuinely consumes a value inside a function that never
 *      runs. Consumption is now NAMED rather than inferred from "not neutral"
 *      ({@see prefixConsumesTheValue()}), and when the consumer is an anonymous
 *      function the same question is asked of ITS value
 *      ({@see enclosingAnonymousFunction()}).
 *
 *  (f) TWO FALSE REDS, which fail
 *      {@see testNoRoutedContainmentCallHasItsResultDiscarded()} on correct code
 *      rather than letting an escape through — the cheaper direction, and still a
 *      defect. A NAMED ARGUMENT's `:` (`f(anchoredIn: ContainedPath::below(…))`)
 *      was the third kind of `:` and was absent from the enumeration in
 *      {@see statementStartIn()}, which listed only the ternary and the
 *      `case`/label forms. And `ContainedPath::within($a, $b) or throw new …;`
 *      has an empty prefix while being a gate — in `a OP b` with a
 *      short-circuiting `OP`, `a`'s value is read to decide whether `b` runs
 *      ({@see shortCircuitsAfter()}).
 *
 *      A THIRD, found by running the corrected rule against `src/` rather than
 *      against the data provider: `)` was treated as a statement boundary, on the
 *      claim that a `)` reachable back from a class expression "can only be a
 *      control-structure header". `if (is_dir($dir) && !ContainedPath::below($dir,
 *      $root))` — src/Memory/ForeignMemoryImporter.php:224 — reaches back over `!`
 *      and `&&` to the `)` of `is_dir($dir)`, so the statement was cut mid-condition
 *      and a live gate read as discarded. A `)` is now stepped over to its matching
 *      `(`.
 *
 *      The ternary disambiguation itself was correct in both directions and is
 *      untouched.
 *
 * THE BOUND THAT REMAINS, re-measured after that widening rather than restated.
 * This instrument sees a containment compare when it is a call to one of the
 * four functions in {@see COMPARE_FUNCTIONS} carrying a separator-suffixed
 * argument in one of three token shapes, or a `::within()`/`::below()` whose
 * result the enclosing statement consumes. It does NOT see:
 *
 *  - a compare whose separator was concatenated onto a variable in an EARLIER
 *    statement (`$prefix = $b . '/';` then `str_starts_with($p, $prefix)`);
 *  - a compare built out of `preg_match()`, `substr()`, `strpos()` or
 *    `str_contains()`;
 *  - a compare whose boundary is a bare LITERAL (`str_starts_with($p, '/srv/')`),
 *    excluded on purpose — see {@see isBoundarySuffixed()} clause 3 for why the
 *    argument must also mention a variable, and for the six files that
 *    condition keeps out;
 *  - a compare living in a dependency;
 *  - a result assigned to a PROPERTY, an array element or anything else that is not
 *    a bare `$var` at the start of the statement, and then never read
 *    ({@see resultIsUsed()} handles the `$var = …` shape only — for any right-hand
 *    side now, but only for that left-hand one);
 *  - a discarded result inside a NAMED function or method nobody calls: the closure
 *    recursion above stops at a named declaration on purpose, since "who calls this
 *    method" is a whole-program question and this instrument's unit is a statement;
 *  - a read path with no compare at all — which is what
 *    `InstructionFileLoader::loadRoot()` and `loadForPath()` were while this
 *    file's ancestor listed the file as audited, and what
 *    `WorktreeConfig::new()` was one round later.
 *
 * The first three are asserted as misses in
 * {@see testTheInstrumentsOwnBlindSpotsAreMeasuredNotAssumed()}, so the bound is
 * a measurement rather than a claim. THE LAST ONE IS THE ONE THAT KEEPS
 * BITING: it has now produced the sixth, seventh, eighth and ninth read paths,
 * and no widening of this instrument will ever catch it. Only a reviewer reading
 * the read paths does.
 */
final class ContainedPathInventoryTest extends TestCase
{
    /**
     * The functions a path-against-boundary prefix compare can be written with.
     *
     * `strncasecmp` is the fourth and it was the one that mattered: it is how a
     * containment compare is written for a CASE-INSENSITIVE filesystem, and with
     * the list closed at three it was invisible to the instrument and to every
     * category of the instrument's own stated bound.
     */
    private const COMPARE_FUNCTIONS = ['str_starts_with', 'strncmp', 'strncasecmp', 'substr_compare'];

    /**
     * Which `src/` files hold an ENFORCING containment call, and how many.
     *
     * PUBLIC because {@see ReadPathCensusTest} asks it: that instrument enumerates
     * read/execute SINKS and requires each to name its gate, and a row claiming
     * `CONTAINED` is checked against this map. Sharing the constant is what stops
     * the two instruments holding two answers to "which files hold a gate" — the
     * same reason {@see \SugarCraft\Crush\Support\ContainedPath} is one predicate.
     *
     * It is a LITERAL that {@see testTheRoutedCallSiteInventory()} asserts against a
     * derivation over `src/`, not a hand-maintained list: the derivation is what
     * makes it true, and its publication is what makes it reusable.
     *
     * @var array<string, int>
     */
    public const ROUTED_CALL_SITES = [
        'Agents/AgentPresetRegistry.php' => 3,
        'Agents/ForeignAgentPresetRegistry.php' => 2,
        'Agents/WorktreeConfig.php' => 2,
        'Agents/WorktreeManager.php' => 2,
        'Cli/Bootstrap.php' => 1,
        'Commands/CommandLoader.php' => 2,
        'Commands/CommandSpec.php' => 1,
        'Config/LayeredSettings.php' => 2,
        'Context/InstructionFileLoader.php' => 6,
        'Context/RepoMapBlock.php' => 3,
        'Memory/ForeignMemoryImporter.php' => 2,
        'Providers/ProviderFactory.php' => 2,
        'Skills/SkillLoader.php' => 3,
        'Workflows/WorkflowRegistry.php' => 3,
    ];

    private string $srcDir;

    protected function setUp(): void
    {
        $this->srcDir = \dirname(__DIR__, 2) . '/src';
    }

    /**
     * `Config/LayeredSettings.php` is the THIRTEENTH file, and it arrived with
     * the reads it gates: the project tier's `.sugar-crush/settings.json` and
     * `settings.local.json` are chosen by whoever wrote the repository, so the
     * settings DIRECTORY and each file inside it are both bounded before a byte
     * is read. TWO compares for the layer as a whole rather than two per file —
     * `below()` on the directory once, then `within()` per file — which is the
     * anchor-plus-entry pair `Commands/CommandLoader.php` holds and NOT
     * `Cli/Bootstrap.php`'s single compare, because here the boundary is not the
     * root: `.sugar-crush` is a directory inside it that one committed symlink
     * relocates wholesale, and a per-file check inside a relocated directory
     * passes. Both boundaries are driven by
     * {@see \SugarCraft\Crush\Tests\Config\LayeredSettingsTest} — the
     * directory's by one test, the per-file compare by TWO, and the second of
     * those is the one that pins WHAT the per-file compare is against. It was
     * one test each until a mutation swapping the per-file boundary from the
     * settings directory to the project root survived: the only case that
     * existed pointed its link OUTSIDE the checkout, which the root-level
     * compare catches too. `…AnInTreeFileOutsideTheSettingsDirIsRefused` is the
     * case that separates them, and the sentence this replaces claimed a
     * coverage that did not exist.
     *
     * `Commands/CommandSpec.php` is the twelfth file to acquire a routed call
     * site (FOURTH in this map's order, which is alphabetical), and it arrived with the read
     * it gates: {@see \SugarCraft\Crush\Commands\CommandSpec::includeFile()}
     * resolves an `@path` written inside a command file — a path chosen by
     * whoever authored that `*.md`, which for the project tier is whoever wrote
     * the repository — and confines it to the checkout before a byte is read.
     * ONE compare rather than the anchor-plus-entry pair its sibling
     * `Commands/CommandLoader.php` holds, for `Cli/Bootstrap.php`'s reason: the
     * boundary here IS the root, and a tree cannot be confined to itself.
     *
     * `Context/RepoMapBlock.php` is the FOURTEENTH file, and it too arrived with
     * the read it gates: the `<repo-map>` prompt block walks the directories a
     * manifest's `autoload.psr-4` names, and those values are written by whoever
     * wrote the repository — `"../../.."` is a legal one. ONE compare, for
     * `Cli/Bootstrap.php`'s reason: the boundary IS the root, and a tree cannot
     * be confined to itself. `within()` and not `below()`, because a prefix
     * mapped to `""` means the package root itself.
     *
     * "THIRTY-FOUR call sites in FOURTEEN files", per file — the sum and the key
     * count of {@see ROUTED_CALL_SITES} as it stands below, which is what
     * {@see testTheRoutedCallSiteInventory()} checks against the derivation over
     * `src/`. (It read "twenty-seven in eleven", then "twenty-eight in twelve",
     * then "THIRTY in THIRTEEN" — and that last one was ALREADY WRONG when
     * `RepoMapBlock` arrived: the map summed to thirty-one across thirteen
     * files, so the sentence had drifted by one without anybody noticing. That
     * is the cost of a restatement no test asserts, and it is recorded here
     * rather than quietly corrected.) Each count is one read
     * decision, so a dropped gate shows up as the file's number falling — which
     * is the half of #89 an instrument like this genuinely covers.
     *
     * A THIRD RESTATEMENT OF THIS SENTENCE EXISTS, and it was five call sites
     * and three files behind when this round found it: the class doc-block of
     * {@see \SugarCraft\Crush\Support\ContainedPath} itself, which still said
     * "TWENTY-SEVEN call sites in ELEVEN files". The paragraph above says a
     * restatement no test asserts is the defect, and then left one unasserted
     * one file away. {@see testContainedPathsOwnDocBlockRestatesThisInventory()}
     * now reads the sentence back out of that file and compares it to this
     * array, so the two cannot drift apart again.
     *
     * `Cli/Bootstrap.php` is the eleventh file, and it arrived with the READ it
     * gates: `mcpClient()` resolves `$root/.mcp.json`, a repository-chosen file
     * whose entries `proc_open()` arbitrary commands, and nothing in `src/` built
     * an {@see \SugarCraft\Crush\MCP\McpClient} before it — which is why
     * {@see ReadPathCensusTest}'s row for `MCP/McpClient.php` read
     * `CALLER_SUPPLIED — nothing in src/ builds one yet` and now reads
     * `CONTAINED_UPSTREAM:Cli/Bootstrap.php`. ONE compare rather than the
     * anchor-plus-entry pair, because the boundary here IS `$root` and a tree
     * cannot be confined to itself; the same shape as `WorktreeManager`'s two
     * entry-level compares.
     *
     * `Providers/ProviderFactory.php` is the tenth file and was the same
     * `__DIR__`-relative construction as `WorktreeConfig`'s, closed a round earlier:
     * `__DIR__ . '/../../.sugar-crush/config.dev.json'`, read by
     * `fromProjectConfig()`, `projectProviderConfig()` and — at launch —
     * `Bootstrap::availableProviders()`, with no containment of any kind. It was on
     * NEITHER inventory: nothing was written for this one to count, and the dot-path
     * census classified the string rather than the read.
     *
     * `WorkflowRegistry`'s THIRD is this round's, and it is the clearest case yet
     * of what this instrument cannot do: the file's row was GREEN at 2 while its
     * user tier — the directory whose `.php` files it `require`s — had no
     * containment call of any kind. A count of what is written cannot miss a
     * deletion and cannot see an absence. That is what
     * {@see ReadPathCensusTest} was added for.
     *
     * The two foreign readers were the previous round's additions and were not
     * omissions of wording: {@see \SugarCraft\Crush\Agents\ForeignAgentPresetRegistry}
     * and {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter} each held
     * repository-chosen read paths with NO compare at all, in classes whose
     * doc-blocks honestly said they were unwired.
     *
     * {@see \SugarCraft\Crush\Agents\WorktreeConfig} and
     * {@see \SugarCraft\Crush\Agents\WorktreeManager} are this round's, and they
     * were the NINTH read path — invisible to this instrument for the reason
     * stated in its own bound (no compare was written, so there was nothing to
     * count) and invisible to
     * {@see \SugarCraft\Crush\Tests\Cli\ProjectTierRefusalInventoryTest} for a
     * different one: it classified `.sugar-crush/config.json` as user-tier from
     * the STRING, which is true of `Bootstrap`'s call site and false of
     * `WorktreeConfig::new()`'s. One string covering two tiers is a defect that
     * test now refuses by construction.
     */
    public function testTheRoutedCallSiteInventory(): void
    {
        $this->assertSame(
            self::ROUTED_CALL_SITES,
            $this->countPerFile($this->routedCalls(...), skip: 'Support/ContainedPath.php'),
        );
    }

    /**
     * The sentence at the head of `ContainedPath`'s own inventory paragraph,
     * read back out of the file and compared to {@see ROUTED_CALL_SITES}.
     *
     * Written because that restatement drifted for three rounds while sitting
     * six lines under "THE INVENTORY BELOW IS NOT MAINTAINED BY HAND" — it read
     * "TWENTY-SEVEN call sites in ELEVEN files" against a derived thirty-two in
     * fourteen. Both halves are spelled in WORDS there, which is the form that
     * makes a stale number read as prose rather than as a figure, so both are
     * parsed rather than eyeballed. The per-file breakdown in that paragraph is
     * deliberately NOT asserted: it is an argument about which file gates what,
     * and pinning its wording would make every legitimate edit red.
     */
    public function testContainedPathsOwnDocBlockRestatesThisInventory(): void
    {
        $words = [
            'ELEVEN' => 11, 'TWELVE' => 12, 'THIRTEEN' => 13, 'FOURTEEN' => 14,
            'FIFTEEN' => 15, 'SIXTEEN' => 16, 'SEVENTEEN' => 17, 'EIGHTEEN' => 18,
            'TWENTY-SEVEN' => 27, 'TWENTY-EIGHT' => 28, 'TWENTY-NINE' => 29,
            'THIRTY' => 30, 'THIRTY-ONE' => 31, 'THIRTY-TWO' => 32,
            'THIRTY-THREE' => 33, 'THIRTY-FOUR' => 34, 'THIRTY-FIVE' => 35,
            'THIRTY-SIX' => 36, 'THIRTY-SEVEN' => 37, 'THIRTY-EIGHT' => 38,
        ];

        $source = (string) file_get_contents($this->srcDir . '/Support/ContainedPath.php');

        $this->assertSame(
            1,
            preg_match('/([A-Z]+(?:-[A-Z]+)?) call sites in ([A-Z]+(?:-[A-Z]+)?) files ask this class/', $source, $m),
            'ContainedPath must still restate its inventory in the shape this test can read',
        );

        $this->assertArrayHasKey($m[1], $words, 'unrecognised number word for the call-site count: ' . $m[1]);
        $this->assertArrayHasKey($m[2], $words, 'unrecognised number word for the file count: ' . $m[2]);

        $this->assertSame(
            array_sum(self::ROUTED_CALL_SITES),
            $words[$m[1]],
            'ContainedPath\'s doc-block disagrees with the derived call-site total',
        );
        $this->assertSame(
            count(self::ROUTED_CALL_SITES),
            $words[$m[2]],
            'ContainedPath\'s doc-block disagrees with the derived file count',
        );
    }

    /**
     * FINDING (a), the one the old instrument could not see. A call whose result
     * is thrown away is not a gate, and there is no legitimate reason to write
     * one: both methods are pure predicates. This fails LOUDLY with file and
     * line rather than merely declining to count, because a discarded
     * containment result is always a defect.
     */
    public function testNoRoutedContainmentCallHasItsResultDiscarded(): void
    {
        $discarded = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            foreach ($this->routedCalls($path) as $call) {
                if (!$call['used']) {
                    $discarded[] = "src/{$relative}:{$call['line']}";
                }
            }
        }

        $this->assertSame(
            [],
            $discarded,
            'ContainedPath::within()/below() called for effect, which they have none of: '
            . implode(', ', $discarded),
        );
    }

    /**
     * "EIGHT spellings remain by hand, in FOUR files" — plus the two the
     * inventory deliberately EXCLUDES, named here so the exclusion is a recorded
     * decision rather than a hole. `WorktreeManager`'s pair matches relative paths
     * against a glob directory; it is not a boundary compare.
     */
    public function testTheHandSpelledInventoryIncludingItsStatedExclusion(): void
    {
        $counts = $this->countPerFile($this->handSpelledCompares(...), skip: 'Support/ContainedPath.php');

        $this->assertSame(
            [
                'Agents/WorktreeManager.php' => 2,
                'Hooks/BuiltIn/BashEscapeDenyHook.php' => 1,
                'Tools/BuiltIn/Glob.php' => 1,
                'Tools/IgnoreRules.php' => 1,
                'Tools/PathJail.php' => 5,
            ],
            $counts,
        );

        unset($counts['Agents/WorktreeManager.php']);
        $this->assertSame(8, array_sum($counts), 'containment spellings still by hand');
        $this->assertCount(4, $counts, 'files still holding one');
    }

    /**
     * `ContainedPath` itself holds exactly ONE prefix compare. That is the whole
     * point of the class, and it is the number that must never rise.
     */
    public function testTheClassItselfHoldsExactlyOneCompare(): void
    {
        $this->assertCount(
            1,
            $this->handSpelledCompares($this->srcDir . '/Support/ContainedPath.php'),
        );
    }

    /**
     * FINDING (b), driven: the five spellings that slipped past the line regex,
     * each parsed rather than pattern-matched. Without this, a widening that
     * quietly failed to widen would read as zero-drift.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function compareSpellings(): array
    {
        return [
            'the canonical form' => ["str_starts_with(\$real, \$rootReal . '/');", 1],
            'against $this' => ["str_starts_with(\$p, \$this->root . '/');", 1],
            'arguments swapped' => ["str_starts_with(\$b . '/', \$p);", 1],
            'interpolated separator' => ['str_starts_with($p, "$b/");', 1],
            'braced interpolation' => ['str_starts_with($p, "{$b}/");', 1],
            'DIRECTORY_SEPARATOR' => ['str_starts_with($p, $b . DIRECTORY_SEPARATOR);', 1],
            'strncmp' => ["strncmp(\$p, \$b . '/', strlen(\$b) + 1) === 0;", 1],
            'substr_compare' => ["substr_compare(\$p, \$b . '/', 0, strlen(\$b) + 1) === 0;", 1],
            'nested call in the first argument' => [
                "str_starts_with(\$realPath . '/', rtrim(\$realBoundary, '/') . '/');",
                1,
            ],
            // The four measured misses of the previous revision — three of them
            // spellings this tail-only scanner could not reach, one a function
            // name the list did not hold.
            'strncasecmp, the case-insensitive filesystem spelling' => [
                "strncasecmp(\$p, \$b . '/', strlen(\$b) + 1) === 0;",
                1,
            ],
            'a separator that is not the last concat operand' => ["str_starts_with(\$p, \$b . '/' . '');", 1],
            'built with sprintf' => ["str_starts_with(\$p, sprintf('%s/', \$b));", 1],
            'built with a heredoc' => ["str_starts_with(\$p, <<<T\n\$b/\nT);", 1],
            // The controls. An absolute-path test is not a containment test, and
            // there are many of those.
            'an absolute-path test' => ["str_starts_with(\$path, '/');", 0],
            'an option-flag test' => ["str_starts_with(\$token, '-');", 0],
            // The false positive a line regex produced on
            // src/Hooks/BuiltIn/BashEscapeDenyHook.php:107 — a separator concat
            // that belongs to a DIFFERENT expression on the same line.
            'a separator concat outside the call' => [
                "\$base = str_starts_with(\$token, '/') ? \$token : \$root . '/' . \$token;",
                0,
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('compareSpellings')]
    public function testTheInstrumentRecognisesEverySpellingItClaimsTo(string $code, int $expected): void
    {
        $this->assertCount($expected, $this->handSpelledComparesIn("<?php\n" . $code . "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function routedShapes(): array
    {
        return [
            'negated in a condition' => ['if (!ContainedPath::within($a, $b)) { return null; }', true],
            'returned' => ['return ContainedPath::below($dir, $anchor);', true],
            // The READ of `$ok` is load-bearing in this row and was added with the
            // generalised assignment rule: an assignment whose target is never read
            // discards its value however the right-hand side computed it, so a
            // chain assigned to a dead variable is no longer a gate. The row's
            // subject is the CHAIN, so it keeps a live target.
            'in a boolean chain' => ['$ok = $x !== null && ContainedPath::below($a, $b); return $ok;', true],
            'fully qualified' => ['if (\\SugarCraft\\Crush\\Support\\ContainedPath::within($a, $b)) { }', true],
            'through a variable class name' => ['if ($c::within($a, $b)) { }', true],
            'a ternary arm, whose `:` is NOT a statement start' => [
                '$x = $cond ? false : ContainedPath::within($a, $b); return $x;',
                true,
            ],
            'assigned and then read' => [
                'function f() { $ok = ContainedPath::within($a, $b); if (!$ok) { return; } }',
                true,
            ],
            // The neutered gate — finding (a) — and the SEVEN further shapes the
            // one-token-wide rule reported as `used: true`.
            'result discarded' => ['ContainedPath::within($a, $b);', false],
            'result discarded through a variable' => ['$c::below($a, $b);', false],
            'in a switch case' => ['switch ($x) { case 1: ContainedPath::within($a, $b); }', false],
            'in an alternative-syntax if' => ['if ($x): ContainedPath::within($a, $b); endif;', false],
            'as a braceless else body' => ['if ($x) foo(); else ContainedPath::within($a, $b);', false],
            'as a braceless if body' => ['if ($x) ContainedPath::within($a, $b);', false],
            'assigned and never read' => [
                'function f() { $unusedGateResult = ContainedPath::within($a, $b); return 1; }',
                false,
            ],
            'cast and discarded' => ['(bool) ContainedPath::within($a, $b);', false],
            'double-negated and discarded' => ['!!ContainedPath::within($a, $b);', false],
            // THIS ROUND'S FOUR. Two false GREENS — the direction the instrument
            // exists to remove — and two false REDS, which fail
            // testNoRoutedContainmentCallHasItsResultDiscarded() on correct code.
            'the LAST operand of a discarded boolean chain' => ['$x && ContainedPath::within($a, $b);', false],
            'returned from a closure nobody calls' => [
                'function f() { $gate = function () { return ContainedPath::within($a, $b); }; return 1; }',
                false,
            ],
            'returned from an arrow function nobody calls' => [
                'function f() { $gate = fn () => ContainedPath::within($a, $b); return 1; }',
                false,
            ],
            'the CONTROL: returned from a closure that IS called' => [
                'function f() { $gate = function () { return ContainedPath::within($a, $b); }; return $gate(); }',
                true,
            ],
            'the CONTROL: a closure passed straight to a caller' => [
                'array_filter($x, function () { return ContainedPath::within($a, $b); });',
                true,
            ],
            'a named argument, whose `:` is the third kind' => [
                'f(anchoredIn: ContainedPath::below($a, $b));',
                true,
            ],
            'used to decide a throw' => ['ContainedPath::within($a, $b) or throw new \\RuntimeException();', true],
            'used to guard a right-hand side' => ['ContainedPath::within($a, $b) && $this->read($a);', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routedShapes')]
    public function testTheInstrumentTellsAGateFromADiscardedCall(string $code, bool $used): void
    {
        $calls = $this->routedCallsIn("<?php\n" . $code . "\n");

        $this->assertCount(1, $calls);
        $this->assertSame($used, $calls[0]['used']);
    }

    /**
     * THE BOUND, MEASURED. Two shapes this instrument is known not to see,
     * asserted as misses so the doc-block's limitation is a fact rather than a
     * hedge. Both are legitimate containment compares; neither is counted.
     *
     * If a future revision teaches the scanner one of these, this test fails and
     * the bound statement above has to be rewritten — which is the point.
     */
    public function testTheInstrumentsOwnBlindSpotsAreMeasuredNotAssumed(): void
    {
        $viaVariable = "<?php\n\$prefix = \$b . '/';\nif (!str_starts_with(\$p, \$prefix)) { return; }\n";
        $this->assertCount(0, $this->handSpelledComparesIn($viaVariable), 'separator bound to a variable first');

        $viaRegex = "<?php\nif (!preg_match('#^' . preg_quote(\$b, '#') . '/#', \$p)) { return; }\n";
        $this->assertCount(0, $this->handSpelledComparesIn($viaRegex), 'a compare built out of preg_match()');

        $viaLiteral = "<?php\nif (!str_starts_with(\$p, '/srv/jail/')) { return; }\n";
        $this->assertCount(0, $this->handSpelledComparesIn($viaLiteral), 'a boundary held in a bare literal');

        // The ROUTED half's residue, in the same place, so both halves' bounds
        // are one measurement: a result parked on a PROPERTY and never read is
        // a discarded gate this reports as used.
        $viaProperty = "<?php\nclass C { function f() { \$this->ok = ContainedPath::within(\$a, \$b); } }\n";
        $this->assertTrue(
            $this->routedCallsIn($viaProperty)[0]['used'],
            'a result assigned to a property is reported as used whether or not it is ever read',
        );
    }

    /**
     * The measured divergence behind the corrected `BashEscapeDenyHook` entry:
     * this class refuses a path it cannot resolve, and a file about to be CREATED
     * does not resolve. `false` therefore fails CLOSED at a `!within(...)` deny
     * site — the opposite of what the old entry claimed — and the real cost of
     * consolidating that hook is over-denial.
     */
    public function testAnUnresolvablePathIsRefusedWhichIsWhyTheDenyHookStaysHandSpelled(): void
    {
        $root = sys_get_temp_dir() . '/contained_path_inventory_' . uniqid();
        mkdir($root . '/sub', 0o777, true);

        try {
            $real = (string) realpath($root);

            // The case the old entry cited as inverting into an allow.
            $this->assertFalse(ContainedPath::within('/nonexistent', $real));

            // The case that actually diverges: in-root, lexically fine, not there yet.
            $this->assertFalse(ContainedPath::within($real . '/newfile.txt', $real));

            // The control: the same path once it exists.
            file_put_contents($real . '/newfile.txt', '');
            $this->assertTrue(ContainedPath::within($real . '/newfile.txt', $real));
        } finally {
            @unlink($root . '/newfile.txt');
            @rmdir($root . '/sub');
            @rmdir($root);
        }
    }

    // ─── the instrument ─────────────────────────────────────────────

    /** @return array<string, string> path relative to `src/` => absolute path, sorted by key */
    private function sourceFiles(): array
    {
        $files = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), \strlen($this->srcDir) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @param  callable(string): list<mixed> $scan
     * @return array<string, int> path relative to `src/` => matches, sorted by key
     */
    private function countPerFile(callable $scan, string $skip): array
    {
        $counts = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            if ($relative === $skip) {
                continue;
            }

            $n = \count($scan($path));
            if ($n > 0) {
                $counts[$relative] = $n;
            }
        }

        return $counts;
    }

    /** @return list<array{line: int, used: bool}> */
    private function routedCalls(string $path): array
    {
        return $this->routedCallsIn((string) file_get_contents($path));
    }

    /**
     * Every `ContainedPath::within()`/`::below()` call in $code, with whether its
     * RESULT IS USED.
     *
     * THE PREVIOUS RULE WAS ONE TOKEN WIDE AND REINTRODUCED THE DEFECT IT WAS
     * WRITTEN TO CLOSE. It read "used is decided by the token immediately
     * preceding the class expression: a call that starts a statement (previous
     * significant token is `;`, `{`, `}`, `:` or the open tag) has nowhere to put
     * its answer" — and `:` was in the prose and NOT in the code, which was
     * `[';', '{', '}']`. Probed directly, SEVEN discarded-result shapes reported
     * `used: true`:
     *
     *     case 1: ContainedPath::within($a, $b);          before = ':'
     *     if ($x): ContainedPath::within($a, $b); endif;  before = ':'
     *     if ($x) foo(); else ContainedPath::within(…);   before = T_ELSE
     *     if ($x) ContainedPath::within($a, $b);          before = ')'
     *     $ok = ContainedPath::within($a, $b);            $ok never read
     *     (bool) ContainedPath::within($a, $b);           before = T_BOOL_CAST
     *     !!ContainedPath::within($a, $b);                before = '!'
     *
     * Mutating `InstructionFileLoader::loadRoot()`'s gate to the fifth shape left
     * this whole file at `OK (26 tests, 39 assertions)` while `loadRoot()`
     * returned `TOP-SECRET-AAA sk-live-DEADBEEF` out of a file symlinked clean
     * out of the checkout and `refusedPaths()` was `[]`.
     *
     * SO THE RULE IS NOW THE STATEMENT, not the token. The enclosing statement's
     * first token is found ({@see statementStartIn()}), and everything between it
     * and the call is examined: a prefix made only of VALUE-NEUTRAL operators —
     * `!`, `@`, `+`, `-`, `~`, a cast, a grouping paren — is an expression
     * statement, and an expression statement throws its value away. An empty
     * prefix is the bare call.
     *
     * THE ASSIGNMENT SHAPE IS DIFFERENT and is handled separately: `$ok =
     * ContainedPath::within(…);` is a legitimate gate when `$ok` is later read
     * and a discarded result when it is not, which is a question about the rest
     * of the function rather than about the statement. The variable's other
     * occurrences are counted inside the ENCLOSING FUNCTION
     * ({@see variableIsReadElsewhere()}) — file-wide would let an unrelated
     * function's identically-named variable vouch for this one, which is the
     * false-green direction.
     *
     * A VARIABLE class expression counts, because `$c = ContainedPath::class;
     * $c::within(…)` is a real call this class's own inventory used to miss.
     * There is no other `::within(`/`::below(` in the package for it to
     * over-count, which {@see testTheRoutedCallSiteInventory()} pins.
     *
     * @return list<array{line: int, used: bool}>
     */
    private function routedCallsIn(string $code): array
    {
        $tokens = $this->significantTokens($code);
        $calls = [];

        foreach ($tokens as $i => $token) {
            if (!$this->isToken($token, \T_DOUBLE_COLON)) {
                continue;
            }

            $subject = $tokens[$i - 1] ?? null;
            $method = $tokens[$i + 1] ?? null;

            if (!$this->isToken($method, \T_STRING)
                || !\in_array(strtolower((string) $method[1]), ['within', 'below'], true)
            ) {
                continue;
            }

            $isContainedPath = $this->isToken($subject, \T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED)
                && str_ends_with((string) $subject[1], 'ContainedPath');

            if (!$isContainedPath && !$this->isToken($subject, \T_VARIABLE)) {
                continue;
            }

            $calls[] = [
                'line' => \is_array($subject) ? (int) $subject[2] : 0,
                'used' => $this->resultIsUsed($tokens, $i - 1),
            ];
        }

        return $calls;
    }

    /**
     * Does the call whose class expression sits at $subject put its answer
     * anywhere? See {@see routedCallsIn()} for the rule and the seven shapes
     * that motivated it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function resultIsUsed(array $tokens, int $subject): bool
    {
        $start = $this->statementStartIn($tokens, $subject);

        /** @var list<array{0: int, 1: string, 2: int}|string> $prefix */
        $prefix = \array_slice($tokens, $start, $subject - $start);

        // `$ok = … <call> …;` — a gate exactly when `$ok` is read again, whatever
        // sits between the `=` and the call.
        //
        // THE PREVIOUS VERSION REQUIRED THE PREFIX TO BE EXACTLY `$ok =`, which
        // made the rule depend on the SHAPE of the right-hand side rather than on
        // whether the answer is ever read: `$ok = ContainedPath::within(…);` with
        // `$ok` unused was correctly discarded, while
        // `$gate = fn () => ContainedPath::within(…);` with `$gate` unused was
        // reported as a gate — the closure false-green, arriving through the
        // arrow-function spelling that has no brace for
        // {@see enclosingAnonymousFunction()} to find. An assignment whose target is
        // never read discards its value however it was computed.
        if ($this->isToken($prefix[0] ?? null, \T_VARIABLE) && ($prefix[1] ?? null) === '=') {
            return $this->variableIsReadElsewhere($tokens, $start, (string) $prefix[0][1]);
        }

        if (!$this->prefixConsumesTheValue($prefix) && !$this->shortCircuitsAfter($tokens, $subject)) {
            return false;
        }

        // THE VALUE GOES SOMEWHERE — but "somewhere" can be the return value of a
        // closure NOBODY CALLS, which is a discarded gate one level up. Measured
        // false-green: `$gate = function () { return ContainedPath::within($a,
        // $b); };` with `$gate` never invoked reported `used: true`, because
        // `return` consumes a value perfectly well inside a function that never
        // runs. So when the consumer is an ANONYMOUS function, the same question is
        // asked of ITS value — which lands on the `$gate = …` assignment shape
        // above and answers correctly in both directions.
        //
        // A NAMED function or method returns null from the search below and the
        // answer stays "used": chasing a private method's callers is a different
        // (whole-program) question, and this instrument's bound is a statement.
        $closure = $this->enclosingAnonymousFunction($tokens, $start);

        return $closure === null || $closure >= $start
            ? true
            : $this->resultIsUsed($tokens, $closure);
    }

    /**
     * Does anything between the statement's first token and the call CONSUME the
     * call's value?
     *
     * THE PREVIOUS RULE WAS "anything at all other than a value-neutral operator",
     * and it produced a false GREEN — the direction this instrument exists to
     * remove. Measured: `$x && ContainedPath::within($a, $b);` reported
     * `used: true` because `$x` and `&&` are not value-neutral, while the statement
     * is an expression statement whose value is thrown away and the call is its
     * LAST operand. A short-circuit chain uses every operand's value EXCEPT the
     * last one, whose value is the statement's — see {@see shortCircuitsAfter()}
     * for the other half of that symmetry.
     *
     * So consumption is named rather than inferred from "not neutral":
     *
     *  - an ASSIGNMENT operator (`=` and the whole compound family) — the value is
     *    stored;
     *  - `return`, `throw`, `yield`, `print`, `echo` — the value leaves the
     *    statement;
     *  - `=>` — the value is an arrow function's result or an array element;
     *  - an UNBALANCED `(` or `[` — the call is an argument, an array element or a
     *    control-structure condition, so something outside it reads the answer.
     *    This is what makes `if (…)`, `while (…)`, `match (…)` and
     *    `foo(within(…))` all report used without enumerating them.
     *
     * Everything else — variables, `&&`, `||`, `?`, `??`, comparisons, casts,
     * literals, balanced calls — leaves the value flowing to the statement's own
     * value, which an expression statement discards.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $prefix
     */
    private function prefixConsumesTheValue(array $prefix): bool
    {
        $depth = 0;

        foreach ($prefix as $token) {
            if (\in_array($token, ['(', '['], true)) {
                ++$depth;

                continue;
            }

            if (\in_array($token, [')', ']'], true)) {
                --$depth;

                continue;
            }

            if ($token === '=') {
                return true;
            }

            if ($this->isToken(
                $token,
                \T_DOUBLE_ARROW,
                \T_RETURN,
                \T_THROW,
                \T_YIELD,
                \T_PRINT,
                \T_ECHO,
                \T_PLUS_EQUAL,
                \T_MINUS_EQUAL,
                \T_MUL_EQUAL,
                \T_DIV_EQUAL,
                \T_MOD_EQUAL,
                \T_POW_EQUAL,
                \T_CONCAT_EQUAL,
                \T_AND_EQUAL,
                \T_OR_EQUAL,
                \T_XOR_EQUAL,
                \T_SL_EQUAL,
                \T_SR_EQUAL,
                \T_COALESCE_EQUAL,
            )) {
                return true;
            }
        }

        return $depth > 0;
    }

    /**
     * Is the call followed, inside the same statement, by an operator that READS
     * its value to decide what happens next?
     *
     * THE OTHER HALF OF THE SHORT-CIRCUIT SYMMETRY, and a measured false RED
     * without it: `ContainedPath::within($a, $b) or throw new \RuntimeException();`
     * is a gate — the value decides whether the right-hand side runs — and the
     * prefix rule sees an empty prefix and calls it discarded. In `a OP b` with a
     * short-circuiting `OP`, `a`'s value is used and `b`'s is the expression's.
     *
     * Scanned at the statement's own nesting depth so a `&&` inside the call's own
     * argument list, or inside a later parenthesised group, does not vouch for it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function shortCircuitsAfter(array $tokens, int $subject): bool
    {
        $depth = 0;

        for ($i = $subject + 1, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];

            if (\in_array($token, ['(', '[', '{'], true)) {
                ++$depth;

                continue;
            }

            if (\in_array($token, [')', ']', '}'], true)) {
                // The statement's own group closing — a call's argument list opens
                // at depth 0 and closes back to it, so a NEGATIVE depth means the
                // enclosing group ended and the statement with it.
                if (--$depth < 0) {
                    return false;
                }

                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if ($token === ';' || $token === ',') {
                return false;
            }

            if (\in_array($token, ['?'], true)
                || $this->isToken($token, \T_BOOLEAN_AND, \T_BOOLEAN_OR, \T_LOGICAL_AND, \T_LOGICAL_OR, \T_COALESCE)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The token index of the innermost ANONYMOUS function enclosing $from, or null
     * when the enclosing scope is a named function, a method, or the file itself.
     *
     * TWO SHAPES, because closures have two spellings and only one of them has a
     * brace: an arrow function's body is an expression terminated by the enclosing
     * statement, so it is searched for FIRST and abandoned at the first `;` — a
     * completed `$f = fn() => 1;` sitting earlier in the same scope must not be
     * mistaken for an enclosing one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function enclosingAnonymousFunction(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if ($this->isToken($token, \T_FN)) {
                return $i;
            }

            if (\in_array($token, [';', '{', '}'], true)) {
                break;
            }
        }

        $depth = 0;
        for ($i = $from - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if ($token === '}') {
                ++$depth;

                continue;
            }

            if ($token !== '{') {
                continue;
            }

            if ($depth > 0) {
                --$depth;

                continue;
            }

            return $this->anonymousFunctionTokenBefore($tokens, $i);
        }

        return null;
    }

    /**
     * The `function` token of the anonymous function whose body opens at $brace, or
     * null when that brace opens anything else — a named function, a class, a
     * control structure.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function anonymousFunctionTokenBefore(array $tokens, int $brace): ?int
    {
        for ($i = $brace - 1; $i >= 0 && $i > $brace - 200; --$i) {
            $token = $tokens[$i];

            if ($this->isToken($token, \T_FUNCTION)) {
                // `function (` / `function &(` is anonymous; `function name(` is
                // not, and a named function's callers are out of this
                // instrument's scope.
                $next = $tokens[$i + 1] ?? null;

                return $next === '(' || $next === '&' ? $i : null;
            }

            if (\in_array($token, [';', '{', '}'], true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * The index of the first token of the statement containing $from.
     *
     * `:` IS A BOUNDARY (`case 1:`, `default:`, `if (…):`, a goto label) EXCEPT
     * in a ternary, where the value is consumed by the surrounding expression —
     * so a `:` is only accepted as a boundary when no `?` precedes it inside the
     * same statement. Getting that backwards in either direction is a
     * false-green: a ternary arm read as a statement start would report every
     * `$x ? … : ContainedPath::within(…)` as discarded.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function statementStartIn(array $tokens, int $from): int
    {
        for ($i = $from - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if ($token === ':') {
                // The scan runs BACKWARDS, so the `?` of a ternary is reached
                // AFTER its `:` — the question has to be asked here rather than
                // remembered from earlier, which is the direction an earlier
                // draft of this method got wrong.
                if ($this->hasTernaryQuestionMarkBefore($tokens, $i)) {
                    continue;
                }

                // THE THIRD KIND OF `:`, absent from this method's own enumeration
                // for a round: a NAMED ARGUMENT. `f(anchoredIn: ContainedPath::below(…))`
                // put a statement boundary in the middle of an argument list, so the
                // call's prefix came out empty and a real gate was reported as a
                // DISCARDED result — a false RED, which fails
                // {@see testNoRoutedContainmentCallHasItsResultDiscarded()} on
                // correct code. `name:` is a T_STRING directly after `(` or `,`,
                // which is what tells it from `case 1:`, `default:`, `if (…):` and a
                // goto label.
                if ($this->isNamedArgumentColon($tokens, $i)) {
                    continue;
                }

                return $i + 1;
            }

            // `)` IS NOT A STATEMENT BOUNDARY, and treating it as one was a
            // measured false RED. The claim was that "a `)` immediately reachable
            // back from a call's class expression can only be a control-structure
            // header — `if (…) X::within();` — since no PHP expression yields a
            // class name from a call", which forgets the operand case:
            // `if (is_dir($dir) && !ContainedPath::below($dir, $root))`
            // (src/Memory/ForeignMemoryImporter.php:224) reaches back over `!` and
            // `&&` to the `)` of `is_dir($dir)`, and cutting the statement there
            // left a prefix of `&& !` — no assignment, no keyword, balanced — i.e.
            // a real gate reported as a discarded call.
            //
            // So a `)` is stepped OVER to its matching `(`, which keeps the whole
            // condition in the prefix and lets the unbalanced-paren rule in
            // {@see prefixConsumesTheValue()} see that something outside reads the
            // answer. An UNMATCHED `)` really is the end of an enclosing group, and
            // there the statement does start.
            if ($token === ')') {
                $open = $this->matchingOpenParenIn($tokens, $i);
                if ($open === null) {
                    return $i + 1;
                }

                $i = $open;

                continue;
            }

            if (\in_array($token, [';', '{', '}'], true)) {
                return $i + 1;
            }

            if ($this->isToken($token, \T_OPEN_TAG, \T_ELSE, \T_DO)) {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * The index of the `(` matching the `)` at $close, or null when there is none.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function matchingOpenParenIn(array $tokens, int $close): ?int
    {
        $depth = 0;

        for ($i = $close; $i >= 0; --$i) {
            if ($tokens[$i] === ')') {
                ++$depth;

                continue;
            }

            if ($tokens[$i] === '(' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Is the `:` at $colon a named argument's (`f(name: $value)`)?
     *
     * The label form `name:` requires the token before it to be a plain T_STRING
     * and the one before THAT to open or continue an argument list. A `case 1:` has
     * a number or a constant expression before it and T_CASE further back; an
     * alternative-syntax `if (…):` has `)`; a goto label sits after `;`, `{` or `}`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isNamedArgumentColon(array $tokens, int $colon): bool
    {
        $name = $tokens[$colon - 1] ?? null;
        $opener = $tokens[$colon - 2] ?? null;

        return $this->isToken($name, \T_STRING) && ($opener === '(' || $opener === ',');
    }

    /**
     * Is the `:` at $colon a ternary's, rather than a `case`/`default`/
     * alternative-syntax/label/named-argument one?
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function hasTernaryQuestionMarkBefore(array $tokens, int $colon): bool
    {
        for ($i = $colon - 1; $i >= 0; --$i) {
            $token = $tokens[$i];

            if ($token === '?') {
                return true;
            }

            if (\in_array($token, [';', '{', '}', ':'], true)
                || $this->isToken($token, \T_OPEN_TAG, \T_CASE, \T_DEFAULT)
            ) {
                return false;
            }
        }

        return false;
    }

    /**
     * Is $name read anywhere in the function enclosing $statementStart, other
     * than at the assignment itself?
     *
     * Scoped to the enclosing function by brace depth, falling back to the whole
     * token list for a snippet with no function in it (which is what the data
     * providers feed). File-wide would be the false-green direction: another
     * function's `$ok` would vouch for this one's.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function variableIsReadElsewhere(array $tokens, int $statementStart, string $name): bool
    {
        [$from, $to] = $this->enclosingFunctionRange($tokens, $statementStart);

        for ($i = $from; $i <= $to; ++$i) {
            if ($i === $statementStart) {
                continue;
            }

            if ($this->isToken($tokens[$i] ?? null, \T_VARIABLE) && (string) $tokens[$i][1] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The token range of the function body containing $index, or the whole list.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: int}
     */
    private function enclosingFunctionRange(array $tokens, int $index): array
    {
        $depth = 0;
        for ($i = $index; $i >= 0; --$i) {
            $token = $tokens[$i];

            if ($token === '}') {
                ++$depth;
            } elseif ($token === '{') {
                if ($depth === 0) {
                    // The opening brace of the block containing $index. Walk
                    // back over the signature looking for `function`.
                    for ($j = $i - 1; $j >= 0 && $j > $i - 200; --$j) {
                        if ($this->isToken($tokens[$j], \T_FUNCTION)) {
                            return [$i, $this->matchingBraceIn($tokens, $i)];
                        }

                        if (\in_array($tokens[$j], [';', '}', '{'], true)) {
                            break;
                        }
                    }

                    // A nested block (if/foreach) — keep climbing.
                    $index = $i;
                } else {
                    --$depth;
                }
            }
        }

        return [0, \count($tokens) - 1];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function matchingBraceIn(array $tokens, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = \count($tokens); $i < $n; ++$i) {
            if ($tokens[$i] === '{' || $this->isToken($tokens[$i], \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES)) {
                ++$depth;
            } elseif ($tokens[$i] === '}') {
                if (--$depth === 0) {
                    return $i;
                }
            }
        }

        return \count($tokens) - 1;
    }

    /** @return list<array{line: int, function: string}> */
    private function handSpelledCompares(string $path): array
    {
        return $this->handSpelledComparesIn((string) file_get_contents($path));
    }

    /**
     * Every call to one of {@see COMPARE_FUNCTIONS} in $code that passes a
     * BOUNDARY-SUFFIXED argument — the ` . '/'` (or its four other spellings)
     * that makes a prefix test a containment test rather than an absolute-path
     * test.
     *
     * Parsed rather than pattern-matched, because a line regex cannot tell an
     * argument from the rest of the line: `$base = str_starts_with($token, '/')
     * ? $token : $root . '/' . $token;` (src/Hooks/BuiltIn/BashEscapeDenyHook.php:107)
     * was counted as a containment compare by the previous instrument, and it is
     * not one.
     *
     * @return list<array{line: int, function: string}>
     */
    private function handSpelledComparesIn(string $code): array
    {
        $tokens = $this->significantTokens($code);
        $found = [];

        foreach ($tokens as $i => $token) {
            if (!$this->isToken($token, \T_STRING)
                || !\in_array(strtolower((string) $token[1]), self::COMPARE_FUNCTIONS, true)
            ) {
                continue;
            }

            // A method or a declaration of the same name is not a call to it.
            $before = $tokens[$i - 1] ?? null;
            if ($this->isToken($before, \T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION)) {
                continue;
            }

            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            foreach ($this->arguments($tokens, $i + 1) as $argument) {
                if ($this->isBoundarySuffixed($argument)) {
                    $found[] = ['line' => (int) $token[2], 'function' => (string) $token[1]];

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The top-level arguments of the call whose `(` sits at $open.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<list<array{0: int, 1: string, 2: int}|string>>
     */
    private function arguments(array $tokens, int $open): array
    {
        $depth = 0;
        $arguments = [];
        $current = [];

        for ($i = $open, $n = \count($tokens); $i < $n; ++$i) {
            $token = $tokens[$i];

            // `"{$b}/"` opens its brace as T_CURLY_OPEN — an ARRAY token — and
            // closes it with a bare `}`. Counting only the string form left the
            // close unbalanced, which ended the argument list early and made the
            // braced-interpolation spelling invisible.
            if ($this->isToken($token, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES)) {
                ++$depth;
            } elseif (\in_array($token, ['(', '[', '{'], true)) {
                ++$depth;
                if ($depth === 1) {
                    continue;
                }
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                --$depth;
                if ($depth === 0) {
                    if ($current !== []) {
                        $arguments[] = $current;
                    }

                    return $arguments;
                }
            } elseif ($token === ',' && $depth === 1) {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        return $arguments;
    }

    /**
     * Does this argument carry a path separator glued onto something else?
     *
     * THE WHOLE ARGUMENT IS SCANNED, not its last two tokens, and that is the
     * fix for four measured misses. The previous version examined only the
     * TAIL, so:
     *
     *     str_starts_with($p, $b . '/' . '')      0 — only the last operand seen
     *     str_starts_with($p, sprintf('%s/', $b)) 0 — the tail is `)`
     *     str_starts_with($p, <<<T\n$b/\nT)       0 — heredoc, tail is T_END_HEREDOC
     *
     * and a fourth miss was in the FUNCTION list rather than here:
     * `strncasecmp($p, $b . '/', strlen($b) + 1) === 0`, a case-insensitive
     * containment compare of exactly the kind a case-insensitive filesystem
     * calls for, was invisible because {@see COMPARE_FUNCTIONS} closed at three
     * names.
     *
     * The three shapes recognised anywhere in the argument:
     *
     *  1. `… . '/'`, `… . "/"`, `… . DIRECTORY_SEPARATOR` — a separator
     *     CONCATENATED onto something;
     *  2. a `"…/"`-style interpolated part (`"$b/"`, `"{$b}/"`, and the heredoc
     *     body, which tokenises the same way) whose literal text ends in `/`
     *     once trailing whitespace is removed — the heredoc's closing newline is
     *     part of that token;
     *  3. a plain string literal LONGER THAN ONE CHARACTER ending in `/`, which
     *     is what makes `sprintf('%s/', …)` visible.
     *
     * A bare `'/'` is NOT one of them — `str_starts_with($path, '/')` is an
     * absolute-path test and there are many of those in `src/`, which is why
     * clause 3 carries the length condition rather than matching any literal
     * ending in a separator.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argument
     */
    private function isBoundarySuffixed(array $argument): bool
    {
        foreach ($argument as $i => $token) {
            // 1. a separator concatenated on.
            if (($argument[$i - 1] ?? null) === '.') {
                if ($this->isToken($token, \T_CONSTANT_ENCAPSED_STRING)
                    && \in_array((string) $token[1], ["'/'", '"/"'], true)
                ) {
                    return true;
                }

                if ($this->isToken($token, \T_STRING) && (string) $token[1] === 'DIRECTORY_SEPARATOR') {
                    return true;
                }
            }

            // 2. an interpolated or heredoc part ending in a separator.
            if ($this->isToken($token, \T_ENCAPSED_AND_WHITESPACE)
                && str_ends_with(rtrim((string) $token[1]), '/')
            ) {
                return true;
            }

            // 3. a multi-character literal ending in a separator, in an argument
            //    that also mentions a VARIABLE. The variable is what separates
            //    `sprintf('%s/', $b)` from `str_starts_with($url, 'https://')`
            //    — measured, this condition is the difference between the five
            //    files the inventory names and eleven, the six extra being URL
            //    and prefix literals in WebFetch, WebSearch, ImportResolver,
            //    LspClient, TeamManager and Bootstrap. A containment compare
            //    tests a path against a BOUNDARY, and a boundary held in a
            //    literal is not one a repository can move.
            if ($this->isToken($token, \T_CONSTANT_ENCAPSED_STRING) && $this->mentionsAVariable($argument)) {
                $literal = trim((string) $token[1], '\'"');
                if (\strlen($literal) > 1 && str_ends_with($literal, '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $argument */
    private function mentionsAVariable(array $argument): bool
    {
        foreach ($argument as $token) {
            if ($this->isToken($token, \T_VARIABLE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * $code's tokens with whitespace and comments dropped.
     *
     * Comments are dropped rather than skipped per line because a doc-comment
     * `{@see ContainedPath::within()}` is a cross-reference, not a call site,
     * and counting those is what inflated an earlier hand count.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private function significantTokens(string $code): array
    {
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (\is_array($token)
                && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)
            ) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /** @param array{0: int, 1: string, 2: int}|string|null $token */
    private function isToken(mixed $token, int ...$kinds): bool
    {
        return \is_array($token) && \in_array($token[0], $kinds, true);
    }
}
