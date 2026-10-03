<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\RepoMap;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\RepoMap\PhpSymbolExtractor;
use SugarCraft\Crush\RepoMap\Tag;

final class PhpSymbolExtractorTest extends TestCase
{
    /** @return list<string> "type scope::name@line" for every definition */
    private function defs(string $source): array
    {
        $out = [];
        foreach (PhpSymbolExtractor::new()->extract($source, 'src/X.php') as $tag) {
            if ($tag->isDefinition()) {
                $out[] = $tag->type . ' ' . ($tag->scope === '' ? '' : $tag->scope . '::') . $tag->name . '@' . $tag->line;
            }
        }

        return $out;
    }

    /** @return list<string> referenced names, in order */
    private function refs(string $source): array
    {
        $out = [];
        foreach (PhpSymbolExtractor::new()->extract($source, 'src/X.php') as $tag) {
            if (!$tag->isDefinition()) {
                $out[] = $tag->name;
            }
        }

        return $out;
    }

    public function testClassLikesFunctionsAndMembersAreDefinitions(): void
    {
        $src = <<<'PHP'
<?php
namespace App;
const TOP = 1;
function helper(): void {}
interface Shape { public function area(): float; }
trait Greets { public function hi(): string { return 'hi'; } }
enum Suit: string {
    case Hearts = 'H';
    const Wild = self::Hearts;
}
abstract class Box {
    public const A = 1, B = 2;
    const string TYPED = 'x';
    private ?\Foo\Thing $thing = null;
    protected static int $count = 0;
    public function __construct(private readonly Widget $widget, int $plain, public int $n = 0) {}
    abstract protected function make(): static;
    public function &byRef(): array { return []; }
}
PHP;

        self::assertSame([
            'const TOP@3',
            'function helper@4',
            'interface Shape@5',
            'method Shape::area@5',
            'trait Greets@6',
            'method Greets::hi@6',
            'enum Suit@7',
            'case Suit::Hearts@8',
            'const Suit::Wild@9',
            'class Box@11',
            'const Box::A@12',
            'const Box::B@12',
            'const Box::TYPED@13',
            'property Box::thing@14',
            'property Box::count@15',
            'method Box::__construct@16',
            'property Box::widget@16',
            'property Box::n@16',
            'method Box::make@17',
            'method Box::byRef@18',
        ], $this->defs($src));
    }

    public function testClosuresArrowFunctionsAnonymousClassesAndClassConstantsAreNotDefinitions(): void
    {
        $src = <<<'PHP'
<?php
$f = function () use ($x) { return 1; };
$g = fn ($y) => $y;
$o = new class { public function inner(): void { $v = 1; } };
$n = Foo::class;
function outer(): void { $local = 1; }
PHP;

        // The anonymous class's method is still a method (its body is a
        // class body), but it has no scope name; nothing else is declared.
        self::assertSame(['method inner@4', 'function outer@6'], $this->defs($src));
    }

    public function testMethodBodyVariablesAreNotProperties(): void
    {
        $src = <<<'PHP'
<?php
class C {
    public function run(): void { static $memo = []; $x = 1; }
}
PHP;

        self::assertSame(['class C@2', 'method C::run@3'], $this->defs($src));
    }

    public function testReferencesUseTheLastNamespaceSegmentAndSkipImportsAndReservedWords(): void
    {
        $src = <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Model;
use Foo\Bar;
use Foo\{Baz, Qux};
class C extends \Base\Parent_ implements Shape {
    use Greets;
    public function go(self $s, int $n): ?Bar {
        $b = new Bar(name: $n);
        $b->render(Baz::make(), \Lib\helper(), true, null);
        return $this->widget;
    }
}
PHP;

        self::assertSame(
            ['Parent_', 'Shape', 'Greets', 'Bar', 'Bar', 'render', 'Baz', 'make', 'helper', 'widget'],
            $this->refs($src),
        );
    }

    public function testATopLevelClosureUseDoesNotSwallowTheClosureBody(): void
    {
        $src = <<<'PHP'
<?php
$f = function () use ($x) { return Target::call(); };
PHP;

        self::assertSame(['Target', 'call'], $this->refs($src));
    }

    public function testEveryTagCarriesThePathAndLine(): void
    {
        $tags = PhpSymbolExtractor::new()->extract("<?php\n\nclass Here {}\n", 'lib/Here.php');

        self::assertCount(1, $tags);
        self::assertSame('lib/Here.php', $tags[0]->relPath);
        self::assertSame(3, $tags[0]->line);
        self::assertSame(Tag::KIND_DEFINITION, $tags[0]->kind);
    }

    public function testSourceWithASyntaxErrorStillYieldsWhatItCan(): void
    {
        $src = "<?php\nclass Half {\n    public function ok(): void {}\n    public function broken( {\n";

        self::assertSame(['class Half@2', 'method Half::ok@3'], \array_slice($this->defs($src), 0, 2));
    }

    public function testExtractFileReadsDiskAndSkipsOversizedOrMissingFiles(): void
    {
        $dir = \sys_get_temp_dir() . '/php_symbols_' . \getmypid() . '_' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0700, true);
        try {
            \file_put_contents($dir . '/A.php', "<?php\nfinal class A {}\n");
            \file_put_contents($dir . '/Big.php', "<?php\nclass Big {}\n" . \str_repeat('//', PhpSymbolExtractor::MAX_FILE_BYTES));

            $extractor = PhpSymbolExtractor::new();
            self::assertSame('A', $extractor->extractFile($dir . '/A.php', 'A.php')[0]->name);
            self::assertSame([], $extractor->extractFile($dir . '/Big.php', 'Big.php'));
            self::assertSame([], $extractor->extractFile($dir . '/Missing.php', 'Missing.php'));
        } finally {
            @\unlink($dir . '/A.php');
            @\unlink($dir . '/Big.php');
            \rmdir($dir);
        }
    }

    public function testItFindsEveryMethodChatDeclares(): void
    {
        // A real 19k-line file is the regression net for the brace and
        // scope tracking: every `function name(` the source declares must
        // come back as a method of Chat, no more and no fewer.
        $path = \dirname(__DIR__, 2) . '/src/Chat.php';
        \preg_match_all('/^\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+&?(\w+)\s*\(/m', (string) \file_get_contents($path), $m);

        $methods = [];
        foreach (PhpSymbolExtractor::new()->extractFile($path, 'src/Chat.php') as $tag) {
            if ($tag->type === Tag::TYPE_METHOD) {
                self::assertSame('Chat', $tag->scope);
                $methods[] = $tag->name;
            }
        }

        $expected = $m[1];
        \sort($expected);
        \sort($methods);
        self::assertNotEmpty($expected);
        self::assertSame($expected, $methods);
    }
}
