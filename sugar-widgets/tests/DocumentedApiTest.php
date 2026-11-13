<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Fleet guard for the prose-column-phantom-API pattern (docs-review 2026-11-03,
 * C1/C2): the site body (docs/_data/sugar-widgets.body.html) once sold
 * Viewport::new(content) and Progress::new(ratio) — call forms that did not
 * exist (the first type-errors, the second silently rendered 0%). The README
 * was clean; only the generated-site source lied, and no test read it.
 *
 * This guard censuses every Class/Method row of the site API table against
 * reflection through a fail-closed roster: an unmapped class column, a stale
 * roster entry, or a method token that does not resolve all redden. Roster
 * targets are the RESOLVED classes — alias re-exports intentionally resolve
 * to their sugar-forms canonicals, which is exactly what a reader calling
 * the documented name gets.
 */
final class DocumentedApiTest extends TestCase
{
    /**
     * Short class-column token => FQCN a reader's `use` of that name resolves to.
     */
    private const CLASS_ROSTER = [
        'TextArea'     => \SugarCraft\Forms\TextArea\TextArea::class,
        'FilePicker'   => \SugarCraft\Forms\FilePicker\FilePicker::class,
        'Button'       => \SugarCraft\Widgets\Button\Button::class,
        'Help'         => \SugarCraft\Widgets\Help\Help::class,
        'ItemList'     => \SugarCraft\Forms\ItemList\ItemList::class,
        'OptionRow'    => \SugarCraft\Widgets\Menu\OptionRow::class,
        'Paginator'    => \SugarCraft\Widgets\Paginator\Paginator::class,
        'Progress'     => \SugarCraft\Widgets\Progress\Progress::class,
        'Rule'         => \SugarCraft\Widgets\Rule\Rule::class,
        'SortDirection' => \SugarCraft\Widgets\Table\SortDirection::class,
        'SortState'    => \SugarCraft\Widgets\Table\SortState::class,
        'Spinner'      => \SugarCraft\Forms\Spinner\Spinner::class,
        'Stopwatch'    => \SugarCraft\Widgets\Stopwatch\Stopwatch::class,
        'Table'        => \SugarCraft\Widgets\Table\Table::class,
        'Tabs'         => \SugarCraft\Widgets\Tabs\Tabs::class,
        'TextEdit'     => \SugarCraft\Widgets\Input\TextEdit::class,
        'TextInput'    => \SugarCraft\Forms\TextInput\TextInput::class,
        'Timer'        => \SugarCraft\Widgets\Timer\Timer::class,
        'Tree'         => \SugarCraft\Widgets\Tree\Tree::class,
        'ValidateOn'   => \SugarCraft\Forms\TextInput\ValidateOn::class,
        'Viewport'     => \SugarCraft\Forms\Viewport\Viewport::class,
    ];

    /**
     * Rows whose Method column is prose (enum/DTO descriptions) — their
     * parenthesised call tokens are censused like any other row.
     */
    private const SITE_BODY = __DIR__ . '/../../docs/_data/sugar-widgets.body.html';

    /**
     * Language keywords that legitimately appear before `(` in doc pseudo-code
     * (the withColorFunc row's `fn(int $row, int $col): Style`) but are never
     * method names.
     */
    private const STOP_TOKENS = ['fn', 'match', 'echo', 'print', 'array', 'list'];

    /**
     * Collects callable-name tokens from a Method cell without falling for
     * prose parentheses: the cell-leading `name(...)`, every `<code>name(</code>`
     * snippet, and every ` / name(` compound chain ("left() / right() / …").
     *
     * @return list<string>
     */
    private static function callTokens(string $cell): array
    {
        $tokens = [];
        if (preg_match('#^([A-Za-z_][A-Za-z0-9_]*)\s*\(#', $cell, $m) === 1) {
            $tokens[] = $m[1];
        }
        if (preg_match_all('#<code>([A-Za-z_][A-Za-z0-9_]*)\s*\(#', $cell, $m) > 0) {
            array_push($tokens, ...$m[1]);
        }
        if (preg_match_all('#\)\s*/\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(#', $cell, $m) > 0) {
            array_push($tokens, ...$m[1]);
        }
        return array_values(array_unique(array_filter(
            $tokens,
            static fn(string $t): bool => !in_array($t, self::STOP_TOKENS, true),
        )));
    }

    public function testTheSiteBodyExistsInTheMonorepoCheckout(): void
    {
        if (!is_file(self::SITE_BODY)) {
            // Split-repo clones carry no root docs/ tree; the monorepo CI
            // (and the sugarcraft dev checkout) is the authority for this guard.
            self::markTestSkipped('monorepo docs tree not present');
        }
        self::assertIsReadable(self::SITE_BODY);
    }

    public function testEveryDocumentedApiRowResolvesAgainstReflection(): void
    {
        if (!is_file(self::SITE_BODY)) {
            self::markTestSkipped('monorepo docs tree not present');
        }
        $body = (string) file_get_contents(self::SITE_BODY);
        $rows = [];
        self::assertGreaterThan(10, preg_match_all(
            '#<tr><td>([A-Za-z\\\\]+)</td><td>(.*?)</td>#s',
            $body,
            $rows,
            PREG_SET_ORDER,
        ), 'the API table vanished from the site body');

        $observed = [];
        foreach ($rows as [, $classToken, $methodCell]) {
            $observed[$classToken] = true;
            $fqcn = self::CLASS_ROSTER[$classToken]
                ?? throw new \LogicException(sprintf(
                    'site body documents class "%s" with no reflection roster entry — '
                    . 'verify the name resolves, then add it to CLASS_ROSTER',
                    $classToken,
                ));
            self::assertTrue(
                class_exists($fqcn) || enum_exists($fqcn),
                sprintf('roster target %s (documented as %s) does not exist', $fqcn, $classToken),
            );
            foreach (self::callTokens($methodCell) as $method) {
                self::assertTrue(
                    method_exists($fqcn, $method),
                    sprintf(
                        'site body documents %s::%s() (class column "%s") which does not exist on %s',
                        $classToken,
                        $method,
                        $classToken,
                        $fqcn,
                    ),
                );
                // PB-WIDGETS-11(a): existence is not reachability. A reader
                // copying the documented call form only works if the method is
                // PUBLIC — the Timer row once advertised the private tick().
                $reflection = new \ReflectionMethod($fqcn, $method);
                self::assertTrue(
                    $reflection->isPublic(),
                    sprintf(
                        'site body documents %s::%s() as caller API but it is not public on %s — '
                        . 'rewrite the row against the public surface',
                        $classToken,
                        $method,
                        $fqcn,
                    ),
                );
            }
        }

        // Fail-closed staleness both ways: every roster entry must still be cited.
        foreach (array_keys(self::CLASS_ROSTER) as $stale) {
            self::assertArrayHasKey(
                $stale,
                $observed,
                sprintf('roster entry "%s" is no longer cited in the site body — prune it', $stale),
            );
        }
    }

    /**
     * PB-WIDGETS-11(b): the façade law makes the alias inventory a load-bearing
     * claim — README.md's counted list is the single canonical record after the
     * N-13 collapse. Derive the truth from src/ (every directory carrying a
     * class_alias into SugarCraft\Forms) and reconcile BOTH directions against
     * the README sentence, including its numeral: a new alias re-export, a
     * retired one, or a re-worded inventory all redden here fail-closed.
     */
    public function testTheAliasFamilyCensusMatchesTheReadmeInventory(): void
    {
        $srcRoot = realpath(__DIR__ . '/../src');
        self::assertIsString($srcRoot, 'src/ vanished from the lib checkout');

        // Both shipped spellings: class_alias('SugarCraft\Forms\...', ...) and
        // class_alias(\SugarCraft\Forms\...\Foo::class, ...).
        $aliasPattern = '/class_alias\(\s*[\x27"]?\\\\?SugarCraft\\\\Forms\\\\/';

        $census = [];
        foreach (scandir($srcRoot) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($srcRoot . '/' . $entry)) {
                continue;
            }
            foreach (glob($srcRoot . '/' . $entry . '/*.php') ?: [] as $file) {
                if (preg_match($aliasPattern, (string) file_get_contents($file)) === 1) {
                    $census[$entry] = true;
                    break;
                }
            }
        }
        $fromSrc = array_keys($census);
        self::assertNotEmpty($fromSrc, 'no alias re-export directories found in src/ — the census scanner broke');

        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        self::assertSame(
            1,
            preg_match(
                '#(\d+) deprecated alias re-exports from `sugarcraft/sugar-forms` \(([^)]+)\)#',
                $readme,
                $m,
            ),
            'the README canonical alias inventory sentence drifted — every consumer of this guard parses it',
        );

        $documented = array_values(array_filter(array_map(
            static fn(string $name): string => trim($name),
            explode(',', $m[2]),
        ), static fn(string $name): bool => $name !== ''));

        self::assertCount(
            (int) $m[1],
            $documented,
            sprintf('README claims %d alias re-exports but enumerates %d', $m[1], count($documented)),
        );
        self::assertEqualsCanonicalizing(
            $documented,
            $fromSrc,
            'README alias inventory and the src/ class_alias census disagree (fail-closed both directions)',
        );
    }

    public function testTheFabricatedCallFormsStayDead(): void
    {
        if (!is_file(self::SITE_BODY)) {
            self::markTestSkipped('monorepo docs tree not present');
        }
        $body = (string) file_get_contents(self::SITE_BODY);
        // C1/C2 permanent re-pins: the exact fabricated rows must never return.
        self::assertStringNotContainsString('<td>new(content)</td>', $body);
        self::assertStringNotContainsString('<td>Create progress bar</td>', $body);
    }

    public function testViewportTakesDimensionsNotContent(): void
    {
        $factory = new \ReflectionMethod(\SugarCraft\Forms\Viewport\Viewport::class, 'new');
        $params = $factory->getParameters();
        self::assertCount(2, $params);
        self::assertSame('width', $params[0]->getName());
        self::assertSame(80, $params[0]->getDefaultValue());
        self::assertSame('height', $params[1]->getName());
        self::assertSame(24, $params[1]->getDefaultValue());
        self::assertTrue(method_exists(\SugarCraft\Forms\Viewport\Viewport::class, 'setContent'));
    }

    public function testProgressFactoryAcceptsTheDocumentedRatio(): void
    {
        $factory = new \ReflectionMethod(\SugarCraft\Widgets\Progress\Progress::class, 'new');
        $params = $factory->getParameters();
        self::assertCount(1, $params);
        self::assertSame('percent', $params[0]->getName());
        self::assertTrue($params[0]->isOptional());
        $bar = \SugarCraft\Widgets\Progress\Progress::new(0.42);
        self::assertSame(0.42, $bar->percent);
    }

    public function testTheFixedApiSurfaceIsReachable(): void
    {
        // Rows this lane added to the site body must be backed by real API.
        self::assertTrue(method_exists(\SugarCraft\Widgets\Tree\Tree::class, 'withStyle'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Timer\Timer::class, 'tick'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Stopwatch\Stopwatch::class, 'tick'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Help\Help::class, 'new'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Help\Help::class, 'withKeyDescGap'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Help\Help::class, 'withColumnGap'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Paginator\Paginator::class, 'withTotalPages'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Tabs\Tabs::class, 'withZoneManager'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Menu\OptionRow::class, 'renderRow'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Table\Table::class, 'styleFunc'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Table\Table::class, 'getStyleFunc'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Table\SortDirection::class, 'toggle'));
        self::assertTrue(method_exists(\SugarCraft\Widgets\Table\SortDirection::class, 'marker'));
    }

    public function testTickMessagesCarryTheDocumentedEpochSlot(): void
    {
        foreach ([
            \SugarCraft\Widgets\Timer\TickMsg::class,
            \SugarCraft\Widgets\Stopwatch\TickMsg::class,
        ] as $msg) {
            $ctor = new \ReflectionMethod($msg, '__construct');
            $params = $ctor->getParameters();
            self::assertCount(2, $params, $msg . ' lost its epoch slot');
            self::assertSame('epoch', $params[1]->getName());
            self::assertTrue($params[1]->isOptional(), $msg . ' epoch must default for legacy ticks');
        }
    }
}
