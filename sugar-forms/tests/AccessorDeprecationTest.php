<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Field;
use SugarCraft\Forms\Field\Color;
use SugarCraft\Forms\Field\Confirm;
use SugarCraft\Forms\Field\Date;
use SugarCraft\Forms\Field\FilePicker;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\MultiSelect;
use SugarCraft\Forms\Field\Note;
use SugarCraft\Forms\Field\NumberField;
use SugarCraft\Forms\Field\RadioPills;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Field\Slider;
use SugarCraft\Forms\Field\Text;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Forms\TextInput\TextInput;
use SugarCraft\Forms\Viewport\Viewport;

/**
 * Accessor-era census guard (bare-accessor era, 2026-10-24; ruling 3b
 * continuation / W-G3 exception precedent set by sugar-style f7c2efe2d).
 *
 * sugar-forms declares 53 public get* accessors in src/. Their dispositions:
 *
 *  - 34 are MARKED BC forwarders (ERA_MARKED below): each bare twin is the
 *    canonical read and the get* form is a one-line @deprecated delegation
 *    carrying REMOVED-IN: 2.0.0 (the two Field-interface rows are abstract
 *    contract members with the same marker — interfaces cannot carry the
 *    delegating body, so their structural legs assert marker and presence
 *    only). Four of the 34 rode already-delegating pre-era aliases whose
 *    bodies were byte-untouched (Confirm::get*→bare, Form::getFocusedField,
 *    TextArea::getRowOffset); the era added the marker, not the delegation.
 *  - 19 are NAME-BLOCKED documented exceptions (COLLISION_EXCEPTIONS): the
 *    bare twin is a live fluent setter (title()/nextLabel()/width()/
 *    height()/styles()) — PHP admits one method per name — or, for
 *    RadioPills and the interface itself, the contract binding keeps the
 *    family prefix-spelled. The per-exception legs assert the in-source
 *    NOTE (accessor era) and that the bare twin is still a live setter;
 *    the bidirectional census leg names any roster row that leaves the
 *    source. A rename or "fix" therefore fails this guard loudly — the
 *    NOTE text itself documents why the get* spelling is kept.
 *
 * Legs: bidirectional census==roster discovery (with a sentinel fixture
 * proving the walk is not blind), marked-set equality (stripping a marker
 * names the pair), per-pair structural + BC identity legs, per-exception
 * NOTE/setter legs, and the README table tie.
 */
final class AccessorDeprecationTest extends TestCase
{
    private const MARKER = 'REMOVED-IN: 2.0.0';

    /**
     * legacy accessor => [bare twin, forwarder call-args, identity args].
     * Field:: rows are interface contract members (no body to assert).
     */
    private const ERA_MARKED = [
        'Field::getDescription'             => ['description', '', []],
        'Field::getError'                   => ['error', '', []],
        'Color::getDescription'             => ['description', '', []],
        'Color::getError'                   => ['error', '', []],
        'Confirm::getDescription'           => ['description', '', []],
        'Confirm::getError'                 => ['error', '', []],
        'Date::getDescription'              => ['description', '', []],
        'Date::getError'                    => ['error', '', []],
        'FilePicker::getDescription'        => ['description', '', []],
        'FilePicker::getError'              => ['error', '', []],
        'Input::getDescription'             => ['description', '', []],
        'Input::getError'                   => ['error', '', []],
        'MultiSelect::getDescription'       => ['description', '', []],
        'MultiSelect::getError'             => ['error', '', []],
        'Note::getDescription'              => ['description', '', []],
        'Note::getError'                    => ['error', '', []],
        'NumberField::getDescription'       => ['description', '', []],
        'NumberField::getError'             => ['error', '', []],
        'RadioPills::getDescription'        => ['description', '', []],
        'RadioPills::getError'              => ['error', '', []],
        'Select::getDescription'            => ['description', '', []],
        'Select::getError'                  => ['error', '', []],
        'Slider::getDescription'            => ['description', '', []],
        'Slider::getError'                  => ['error', '', []],
        'Text::getDescription'              => ['description', '', []],
        'Text::getError'                    => ['error', '', []],
        'Form::getString'                   => ['string', '$key, $default', ['nokey', 'd']],
        'Form::getInt'                      => ['int', '$key, $default', ['nokey', 7]],
        'Form::getBool'                     => ['bool', '$key, $default', ['nokey', true]],
        'Form::getArray'                    => ['array', '$key', ['nokey']],
        'Form::getFocusedField'             => ['focusedField', '', []],
        'TextArea::getRowOffset'            => ['rowOffset', '', []],
        'Viewport::getWidth'                => ['width', '', []],
        'Viewport::getHeight'               => ['height', '', []],
    ];

    /**
     * Accessors whose bare name is taken: [bare, kind] where kind is
     * 'setter' (bare() is a live fluent setter on this class),
     * 'contract' (RadioPills: local bare free, interface binding blocks the
     * family) or 'interface' (the Field declaration itself).
     */
    private const COLLISION_EXCEPTIONS = [
        'Field::getTitle'       => ['title', 'interface'],
        'Color::getTitle'       => ['title', 'setter'],
        'Confirm::getTitle'     => ['title', 'setter'],
        'Date::getTitle'        => ['title', 'setter'],
        'FilePicker::getTitle'  => ['title', 'setter'],
        'Input::getTitle'       => ['title', 'setter'],
        'MultiSelect::getTitle' => ['title', 'setter'],
        'Note::getTitle'        => ['title', 'setter'],
        'NumberField::getTitle' => ['title', 'setter'],
        'RadioPills::getTitle'  => ['title', 'contract'],
        'Select::getTitle'      => ['title', 'setter'],
        'Slider::getTitle'      => ['title', 'setter'],
        'Text::getTitle'        => ['title', 'setter'],
        'Note::getNextLabel'    => ['nextLabel', 'setter'],
        'Note::getHeight'       => ['height', 'setter'],
        'TextArea::getWidth'    => ['width', 'setter'],
        'TextArea::getHeight'   => ['height', 'setter'],
        'TextInput::getWidth'   => ['width', 'setter'],
        'TextInput::getStyles'  => ['styles', 'setter'],
    ];

    /** @return array<string, array{0:string, 1:array{0:string,1:string,2:list<mixed>}}> */
    public static function markedPairProvider(): array
    {
        $out = [];
        foreach (self::ERA_MARKED as $legacy => $row) {
            $out[$legacy] = [$legacy, $row];
        }

        return $out;
    }

    /** Class-level marked pairs only: interface members have no runtime instance. */
    public static function markedInstancePairProvider(): array
    {
        return array_filter(
            self::markedPairProvider(),
            static fn (array $v): bool => !str_starts_with($v[0], 'Field::'),
        );
    }

    /** @return array<string, array{0:string, 1:array{0:string,1:string}}> */
    public static function exceptionProvider(): array
    {
        $out = [];
        foreach (self::COLLISION_EXCEPTIONS as $legacy => $row) {
            $out[$legacy] = [$legacy, $row];
        }

        return $out;
    }

    public function testEveryPublicGetAccessorCarriesAKnownDisposition(): void
    {
        $found    = self::publicGetAccessorsIn(dirname(__DIR__) . '/src');
        $rostered = array_merge(array_keys(self::ERA_MARKED), array_keys(self::COLLISION_EXCEPTIONS));
        sort($found);
        sort($rostered);
        self::assertSame(
            $rostered,
            $found,
            'public get* accessors on disk and the disposition rosters have diverged (add a row / remove a stale row)',
        );
        self::assertCount(53, $found, 'the era census was derived from 53 declarations');
    }

    public function testTheSentinelViolationCannotEscapeTheDiscoveryWalk(): void
    {
        $dir = sys_get_temp_dir() . '/forms-accessor-sentinel-' . getmypid();
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) {
            self::fail('could not create ' . $dir);
        }
        try {
            file_put_contents($dir . '/SentinelLeak.php', <<<'PHP'
                <?php
                declare(strict_types=1);
                namespace Sentinel;
                final class SentinelLeak
                {
                    public function getSentinelLeak(): bool { return true; }
                }
                PHP);
            self::assertSame(
                ['SentinelLeak::getSentinelLeak'],
                self::publicGetAccessorsIn($dir),
                'the discovery walk is blind — the census above proves nothing',
            );
        } finally {
            unlink($dir . '/SentinelLeak.php');
            @rmdir($dir);
        }
    }

    /**
     * Marked-set equality: the set of src/ methods whose docblock carries the
     * REMOVED-IN marker is exactly ERA_MARKED — stripping a marker from a
     * forwarder (or planting one elsewhere) names the diff.
     */
    public function testTheMarkedSetIsExactlyTheRoster(): void
    {
        $marked = [];
        foreach (self::phpFilesIn(dirname(__DIR__) . '/src') as $file) {
            foreach (self::methodDocblocks((string) file_get_contents($file)) as $name => $doc) {
                if (str_contains($doc, self::MARKER)) {
                    $marked[] = $name;
                }
            }
        }
        sort($marked);
        $expected = array_keys(self::ERA_MARKED);
        sort($expected);
        self::assertSame($expected, $marked, 'the REMOVED-IN population drifted from the marked roster');
    }

    /**
     * @param array{0:string, 1:string, 2:list<mixed>} $row
     */
    #[DataProvider('markedPairProvider')]
    public function testEachMarkedForwarderHasItsBareTwinAndCarriesTheMarkers(string $legacy, array $row): void
    {
        [$bare, $callArgs] = $row;
        [$short, $method]   = explode('::', $legacy);
        $class              = self::classNamed($short);
        $reflection         = new \ReflectionClass($class);

        self::assertTrue($reflection->hasMethod($method), "roster row {$legacy} is stale — gone from disk");
        self::assertTrue($reflection->hasMethod($bare), "bare twin {$bare}() missing for {$legacy}");
        $forwarder = $reflection->getMethod($method);
        $twin      = $reflection->getMethod($bare);

        $doc = (string) self::docblockOf($forwarder);
        self::assertStringContainsString('@deprecated', $doc, "{$legacy} must stay @deprecated");
        self::assertStringContainsString(self::MARKER, $doc, "{$legacy} lost its REMOVED-IN marker");
        self::assertStringContainsString('{@see self::' . $bare . '()}', $doc, "{$legacy} must name its twin");
        self::assertStringNotContainsString(
            self::MARKER,
            (string) self::docblockOf($twin),
            'the bare twin must not be deprecation-marked',
        );
        self::assertSame($twin->isStatic(), $forwarder->isStatic(), "{$legacy} staticness drifted");
        self::assertSame((string) $twin->getReturnType(), (string) $forwarder->getReturnType(), "{$legacy} return type drifted");
        self::assertSame($twin->getNumberOfParameters(), $forwarder->getNumberOfParameters(), "{$legacy} arity drifted");

        if ($reflection->isInterface()) {
            return; // abstract contract member: marker + twin presence is the whole leg
        }
        $body = self::methodBody($forwarder);
        self::assertSame(1, substr_count($body, 'return '), "{$legacy} must be a one-line delegation");
        self::assertMatchesRegularExpression(
            '/return \$this->' . preg_quote($bare, '/') . '\(' . str_replace('/', '\\/', preg_quote($callArgs, '/')) . '\);/',
            $body,
            "{$legacy} must delegate to {$bare}(){$callArgs}",
        );
    }

    /**
     * @param array{0:string, 1:string, 2:list<mixed>} $row
     */
    #[DataProvider('markedInstancePairProvider')]
    public function testTheDeprecatedForwarderReturnsWhatTheBareTwinReturns(string $legacy, array $row): void
    {
        [$bare, , $args]  = $row;
        [$short, $method] = explode('::', $legacy);
        $object           = self::fixture($short);
        self::assertEquals($object->{$bare}(...$args), $object->{$method}(...$args));
    }

    /**
     * @param array{0:string, 1:string} $row
     */
    #[DataProvider('exceptionProvider')]
    public function testEachCollisionExceptionIsStillBlockedAndCarriesItsNote(string $legacy, array $row): void
    {
        [$bare, $kind]    = $row;
        [$short, $method] = explode('::', $legacy);
        $class            = self::classNamed($short);
        $reflection       = new \ReflectionClass($class);

        self::assertTrue($reflection->hasMethod($method), "roster row {$legacy} is stale — gone from disk");
        $own = (string) self::docblockOf($reflection->getMethod($method));
        self::assertStringContainsString('NOTE (accessor era, 2026-10-24)', $own, "{$legacy} lost its in-source NOTE");
        self::assertStringContainsString("this {$method}() read", $own, "the NOTE at {$legacy} does not speak about {$legacy} itself");
        self::assertStringNotContainsString(self::MARKER, $own, "{$legacy} is LIVE API — it must not carry REMOVED-IN");
        self::assertStringNotContainsString('@deprecated', $own, "{$legacy} is LIVE API — it must not be @deprecated");

        if ($kind === 'interface') {
            self::assertFalse($reflection->hasMethod($bare), "Field must not declare a bare {$bare}() the setter-blocked classes cannot implement");

            return;
        }
        if ($kind === 'contract') {
            self::assertTrue((new \ReflectionClass(Field::class))->hasMethod($method), "contract-bound read: Field::{$method}() must still be the declared name");

            return;
        }
        self::assertTrue($reflection->hasMethod($bare), "{$legacy}: bare {$bare}() vanished — did the vocabulary ruling land? Re-derive.");
        $setter = $reflection->getMethod($bare);
        self::assertGreaterThanOrEqual(1, $setter->getNumberOfParameters(), "{$bare}() must still be a setter");
        self::assertSame('self', (string) $setter->getReturnType(), "{$bare}() must still return self (fluent)");
    }

    public function testTheReadmeCarriesTheEraSectionAndAgreesWithTheCensus(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertStringContainsString('## Accessor deprecation (2026-10-24)', $readme);
        $flat   = (string) preg_replace('/\s+/u', ' ', $readme);
        self::assertStringContainsString(
            count(self::COLLISION_EXCEPTIONS) . ' name-blocked reads keep their',
            $flat,
            'the README exception tally drifted from the roster',
        );
        $section = (string) preg_split('/^## /m', $readme, -1, PREG_SPLIT_NO_EMPTY)[
            array_key_first(array_filter(
                preg_split('/^## /m', $readme, -1, PREG_SPLIT_NO_EMPTY),
                static fn (string $s): bool => str_starts_with($s, 'Accessor deprecation'),
            ))
        ];
        preg_match_all(
            '/^\| `(?P<class>[A-Za-z]+)::(?P<legacy>get[A-Za-z]+)\(\)` \| `(?P<bare>[a-z][A-Za-z]+)\(\)` \|$/m',
            $section,
            $rows,
            PREG_SET_ORDER,
        );
        $tabled = [];
        foreach ($rows as $row) {
            $tabled[$row['class'] . '::' . $row['legacy']] = $row['bare'];
        }
        ksort($tabled);
        $expected = [];
        foreach (self::ERA_MARKED as $legacy => [$bare]) {
            if (str_starts_with($legacy, 'Field::')) {
                continue; // interface contract members are named in prose, not the table
            }
            $expected[$legacy] = $bare;
        }
        ksort($expected);
        self::assertSame($expected, $tabled, 'README Accessor-deprecation table must tie the class-level era pairs');
    }

    // ─── helpers ────────────────────────────────────────────────────────

    /** @return list<string> "ShortClass::getFoo" — classes AND interfaces */
    private static function publicGetAccessorsIn(string $dir): array
    {
        $found = [];
        foreach (self::phpFilesIn($dir) as $file) {
            $tokens = \PhpToken::tokenize((string) file_get_contents($file));
            $class  = null;
            $pendingPublic = false;
            foreach ($tokens as $index => $token) {
                if ($token->id === \T_CLASS || $token->id === \T_INTERFACE) {
                    $class = $tokens[$index + 2]->text;
                } elseif ($token->id === \T_PUBLIC) {
                    $pendingPublic = true;
                } elseif ($token->id === \T_FUNCTION && $pendingPublic && $class !== null) {
                    $name = $tokens[$index + 2]->text;
                    if (preg_match('/^get[A-Z]/', $name) === 1) {
                        $found[] = $class . '::' . $name;
                    }
                    $pendingPublic = false;
                } elseif ($token->id !== \T_WHITESPACE && $token->id !== \T_STATIC && $token->id !== \T_READONLY) {
                    $pendingPublic = false;
                }
            }
        }
        sort($found);

        return $found;
    }

    /** @return list<string> */
    private static function phpFilesIn(string $dir): array
    {
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<string,string> "ShortClass::method" => docblock */
    private static function methodDocblocks(string $source): array
    {
        $docs   = [];
        $tokens = \PhpToken::tokenize($source);
        $class  = null;
        $lastDoc = null;
        foreach ($tokens as $index => $token) {
            if ($token->id === \T_CLASS || $token->id === \T_INTERFACE) {
                $class = $tokens[$index + 2]->text;
            } elseif ($token->id === \T_DOC_COMMENT) {
                $lastDoc = $token->text;
            } elseif ($token->id === \T_FUNCTION) {
                if ($class !== null && $lastDoc !== null) {
                    $docs[$class . '::' . $tokens[$index + 2]->text] = $lastDoc;
                }
                $lastDoc = null;
            } elseif ($token->id !== \T_WHITESPACE && $token->id !== \T_PUBLIC && $token->id !== \T_STATIC
                && $token->id !== \T_PROTECTED && $token->id !== \T_PRIVATE && $token->id !== \T_ABSTRACT
                && $token->id !== \T_READONLY) {
                $lastDoc = null;
            }
        }

        return $docs;
    }

    private static function docblockOf(\ReflectionMethod $method): ?string
    {
        $doc = $method->getDocComment();

        return $doc === false ? null : $doc;
    }

    private static function methodBody(\ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /** @return class-string */
    private static function classNamed(string $short): string
    {
        return match ($short) {
            'Field'       => Field::class,
            'Color'       => Color::class,
            'Confirm'     => Confirm::class,
            'Date'        => Date::class,
            'FilePicker'  => FilePicker::class,
            'Input'       => Input::class,
            'MultiSelect' => MultiSelect::class,
            'Note'        => Note::class,
            'NumberField' => NumberField::class,
            'RadioPills'  => RadioPills::class,
            'Select'      => Select::class,
            'Slider'      => Slider::class,
            'Text'        => Text::class,
            'Form'        => Form::class,
            'TextArea'    => TextArea::class,
            'TextInput'   => TextInput::class,
            'Viewport'    => Viewport::class,
            default       => self::fail("roster class {$short} is not wired into the probe"),
        };
    }

    private static function fixture(string $short): object
    {
        return match ($short) {
            'Color'       => Color::new('k'),
            'Confirm'     => Confirm::new('k'),
            'Date'        => Date::new('k'),
            'FilePicker'  => FilePicker::new('k'),
            'Input'       => Input::new('k'),
            'MultiSelect' => MultiSelect::new('k'),
            'Note'        => Note::new('k'),
            'NumberField' => NumberField::new('k', 0, 10, 1),
            'RadioPills'  => RadioPills::new('k', ['a', 'b']),
            'Select'      => Select::new('k'),
            'Slider'      => Slider::new('k', 0, 10, 1),
            'Text'        => Text::new('k'),
            'Form'        => Form::new(Color::new('k')),
            'TextArea'    => TextArea::new(),
            'Viewport'    => Viewport::new(10, 5),
            default       => self::fail("no fixture for {$short}"),
        };
    }
}
