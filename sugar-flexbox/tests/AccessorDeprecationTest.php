<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Table\Column;
use SugarCraft\Flexbox\Table\Table;
use SugarCraft\Flexbox\Viewport;

/**
 * Pins the accessor-deprecation era (the bare-accessor rename wave).
 *
 * Viewport's dimension accessors were renamed to their bare twins —
 * width()/height() were unclaimed in-lib (the mutators were the
 * setWidth()/setHeight() spellings, so there was no fluent
 * collision; FBX-06 collapsed that second vocabulary into
 * withSize(), and the survivors are pinned here as reshape
 * forwarders).
 * The legacy get*() spellings stay byte-identical one-line BC
 * forwarders marked '@deprecated' + 'REMOVED-IN: 2.0.0' until v2.0.0.
 * The delegates still answer through the canonical widgets viewport's
 * own names; that hop belongs to the wrapped class, not this lib's era.
 */
final class AccessorDeprecationTest extends TestCase
{
    private const MARKER = 'REMOVED-IN: 2.0.0';

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: list<mixed>, 4: 'same'|'equal'}>
     */
    public static function deprecatedAccessors(): array
    {
        return [
            'Viewport::getWidth' => [Viewport::class, 'getWidth', 'width', [], 'same'],
            'Viewport::getHeight' => [Viewport::class, 'getHeight', 'height', [], 'same'],
            // Fluent forwarders answer with a NEW wrapper each call, so the
            // BC leg compares structure (every property of both instances);
            // a mis-wired twin differs on the delegate it hands back.
            'Viewport::withScrollbar' => [Viewport::class, 'withScrollbar', 'showScrollbar', [true], 'equal'],
            // Table's mutation-flavoured builders (FBX-05): every returned
            // Table is a fresh clone-based instance, so equality is the
            // honest comparison — a mis-wired twin differs on contents.
            'Table::addColumn' => [Table::class, 'addColumn', 'withColumn', [Column::make('Age', 5)], 'equal'],
            'Table::addRow' => [Table::class, 'addRow', 'withRow', [['Bob']], 'equal'],
            'Table::sortBy' => [Table::class, 'sortBy', 'withSort', [0, true], 'equal'],
            'Table::filter' => [Table::class, 'filter', 'withFilter', ['li'], 'equal'],
            'Table::setCursor' => [Table::class, 'setCursor', 'withCursor', [0], 'equal'],
            // The trust-renamed pair (FBX-49): forwarders answer with the
            // exact stored strings, structurally identical to the raw* twins.
            'Table::currentRow' => [Table::class, 'currentRow', 'rawCurrentRow', [], 'same'],
            'Table::currentCell' => [Table::class, 'currentCell', 'rawCurrentCell', [0], 'same'],
            // The static factory BC alias (FBX-24): AGENTS.md bans
            // ::make(); new() is the house shape. Static forwarders ride
            // the same legs — the structural regex accepts the self::
            // delegate form.
            'Column::make' => [Column::class, 'make', 'new', ['Name', 10], 'equal'],
            // The sizing collapse (FBX-06): setWidth()/setHeight() were the
            // second vocabulary — they now forward to withSize(), which
            // needs the OTHER dimension from the receiver, so the BC leg
            // reshapes the canonical call through a closure instead of
            // passing the same args twice. The structural leg keeps the
            // one-line + marker law; for reshape rows it validates the
            // delegation expression (and the same-arity equality is
            // skipped, since the two legs take different args).
            'Viewport::setWidth' => [Viewport::class, 'setWidth', 'withSize', [30], 'equal',
                static fn (Viewport $v): array => [30, $v->height()]],
            'Viewport::setHeight' => [Viewport::class, 'setHeight', 'withSize', [15], 'equal',
                static fn (Viewport $v): array => [$v->width(), 15]],
            // The factory-name misuse guard (FBX-22): one line, same args,
            // but the canonical call sits inside a ternary that REFUSES
            // instance misuse instead of silently resetting geometry —
            // the reshape flag relaxes exactly the body-shape check here.
            'Viewport::withContent' => [Viewport::class, 'withContent', 'fromContent', ['abc', 20, 10], 'equal-static'],
        ];
    }

    private static function fixture(string $class): object
    {
        /** @var class-string $class */
        return match ($class) {
            Viewport::class => Viewport::new(40, 12),
            Table::class => Table::new()->withColumn(Column::make('Name', 10))->withRow(['Alice']),
            Column::class => Column::new('Name', 10),
            default => self::fail("no fixture for {$class}"),
        };
    }

    /**
     * BC leg: the deprecated name must return exactly what the bare
     * twin returns, on the same live object, with the same arguments.
     *
     * @param class-string $class
     * @param list<mixed> $args
     * @param 'same'|'equal' $mode
     */
    #[DataProvider('deprecatedAccessors')]
    public function testTheDeprecatedForwarderMatchesTheBareTwin(string $class, string $legacy, string $bare, array $args, string $mode, ?callable $reshape = null): void
    {
        $object = self::fixture($class);
        $this->assertTrue(method_exists($object, $bare), $class . "::" . $bare . "() must exist");
        $bareArgs = $reshape !== null ? $reshape($object) : $args;
        if ($mode === 'equal-static') {
            // The BC seat is private behind __callStatic (FBX-22): the
            // comparison must take the static leg BOTH external forms
            // actually have in common.
            $this->assertEquals($class::$bare(...$bareArgs), $class::$legacy(...$args));
            return;
        }
        if ($mode === 'equal') {
            $this->assertEquals($object->{$bare}(...$bareArgs), $object->{$legacy}(...$args));
            return;
        }
        $this->assertSame($object->{$bare}(...$bareArgs), $object->{$legacy}(...$args));
    }

    /**
     * Structural leg: every swept forwarder is a one-line return
     * statement carrying the @deprecated + REMOVED-IN markers and
     * naming its bare twin in the docblock.
     *
     * @param class-string $class
     */
    #[DataProvider('deprecatedAccessors')]
    public function testEverySweptForwarderCarriesMarkersAndSingleReturn(string $class, string $legacy, string $bare, array $args, string $mode = 'same', ?callable $reshape = null): void
    {
        $method = new \ReflectionMethod($class, $legacy);
        $doc = (string) $method->getDocComment();
        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString(self::MARKER, $doc);
        $this->assertStringContainsString('{@see self::' . $bare . '()}', $doc);
        if ($mode === 'equal-static') {
            // The hook-hidden BC seat (FBX-22): deliberately PRIVATE —
            // both external call forms reach it through the class's
            // __call/__callStatic routing, which the equality leg and
            // the misuse pin exercise.
            $this->assertTrue($method->isPrivate());
        } else {
            $this->assertTrue($method->isPublic());
        }
        $lines = array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        );
        $this->assertCount(1, $lines, $class . '::' . $legacy . ' must be a single-line forwarder');
        if ($reshape !== null) {
            // Reshaped or guarded body: still ONE line delegating to the
            // bare name, but the call may sit inside an expression (the
            // FBX-22 misuse refusal wraps it in a ternary).
            $this->assertMatchesRegularExpression(
                '/(?:public|private) (?:static )?function ' . preg_quote($legacy, '/') . '\(.*\)[^{]*\{ return [^;]*' . preg_quote($bare, '/') . '\([^;]*\)[^;]*; \}\s*$/',
                $lines[0]
            );
        } else {
            $this->assertMatchesRegularExpression(
                '/(?:public|private) (?:static )?function ' . preg_quote($legacy, '/') . '\(.*\)[^{]*\{ return (?:\$this->|self::)' . preg_quote($bare, '/') . '\((\$\w+(, \$\w+)*)?\); \}\s*$/',
                $lines[0]
            );
        }
        $bareMethod = new \ReflectionMethod($class, $bare);
        $this->assertTrue($bareMethod->isPublic());
        $this->assertStringNotContainsString(self::MARKER, (string) $bareMethod->getDocComment());
        if ($reshape === null) {
            $this->assertSame($method->getNumberOfParameters(), $bareMethod->getNumberOfParameters());
        }
        $this->assertSame((string) $method->getReturnType(), (string) $bareMethod->getReturnType());
    }

    /**
     * Census leg: discovery-first scan of src/ finds exactly the MARKED
     * forwarders — a marked method outside the tested pairs, or a tested
     * pair whose mark drifted away, reddens this leg. Any method carrying
     * the REMOVED-IN marker joins the roster obligation, whichever name
     * shape it renames (get* accessor, boolean toggle, future verbs); an
     * unmarked addition is out of scope for this scan; the inverse leg
     * that accuses unmarked accessors exists only on sugar-dashboard.
     */
    public function testTheSrcCensusFindsExactlyTheTestedPairs(): void
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize(file_get_contents($file->getPathname()));
            foreach ($tokens as $i => $token) {
                if ($token->id !== T_FUNCTION) {
                    continue;
                }
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j]->id === T_WHITESPACE) {
                        continue;
                    }
                    break;
                }
                $name = $tokens[$j]->text;
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (
                        $tokens[$k]->id === T_WHITESPACE
                        || $tokens[$k]->id === T_PUBLIC
                        || $tokens[$k]->id === T_PRIVATE
                        || $tokens[$k]->id === T_PROTECTED
                        || $tokens[$k]->id === T_STATIC
                        || $tokens[$k]->id === T_FINAL
                        || $tokens[$k]->id === T_ABSTRACT
                    ) {
                        continue;
                    }
                    break;
                }
                if ($tokens[$k]->id !== T_DOC_COMMENT || !str_contains($tokens[$k]->text, self::MARKER)) {
                    continue;
                }
                $found[] = basename($file->getFilename(), '.php') . '::' . $name;
            }
        }
        $expected = [];
        foreach (self::deprecatedAccessors() as [$class, $legacy]) {
            $expected[] = substr($class, (int) strrpos($class, '\\') + 1) . '::' . $legacy;
        }
        sort($found);
        sort($expected);
        $this->assertSame($expected, $found, 'src/ census drifted from the tested pairs');
        $this->assertCount(14, $found);
    }

    /**
     * Docs leg: the README Accessor-deprecation table must list exactly
     * the swept pairs (nothing added, nothing dropped).
     */
    public function testTheReadmeTableAgreesWithTheSrcCensus(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__) . '/README.md');
        $sections = preg_split('/^## /m', $readme, -1, PREG_SPLIT_NO_EMPTY);
        $section = (string) $sections[
            array_key_first(array_filter(
                $sections,
                static fn (string $s): bool => str_starts_with($s, 'Accessor deprecation')
            ))
        ];
        preg_match_all(
            '/^\| `[^`]*` \| `(?P<class>[^`]+)` \| `(?P<legacy>[a-z][A-Za-z]+)\(\)` \| `(?P<bare>[a-z][A-Za-z]+)\(\)` \|$/m',
            $section,
            $rows,
            PREG_SET_ORDER
        );
        $this->assertCount(14, $rows, 'README Accessor-deprecation table must list exactly the swept pairs');
        $tabled = [];
        foreach ($rows as $row) {
            $tabled[$row['class'] . '::' . $row['legacy']] = $row['bare'];
        }
        foreach (self::deprecatedAccessors() as [$class, $legacy, $bare]) {
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            $this->assertArrayHasKey($short . '::' . $legacy, $tabled);
            $this->assertSame($bare, $tabled[$short . '::' . $legacy]);
        }
    }
}
