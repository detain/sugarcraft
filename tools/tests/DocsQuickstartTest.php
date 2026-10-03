<?php

declare(strict_types=1);

/**
 * Guards the PHP code samples on every generated lib page.
 *
 * WHY. `docs/_data/<slug>.body.html` holds the quickstart that `tools/gen-docs.php`
 * publishes verbatim, and nothing ever ran it or even parsed it. The sugar-veil
 * page shipped `new Veil()` followed by `$veil->push(...)` and
 * `$veil->render(...)`, which are methods `Veil` has never had. Four other samples
 * did not parse at all (`fn() => echo ...`, a `[...]` placeholder argument, a
 * positional `…` after named arguments, and two imports bound to one name).
 *
 * WHAT IT CHECKS, cheapest first:
 *   1. Every `<pre><code>` block declares its language as `language-php`,
 *      `language-shell`, `language-text` or `language-yaml`. This makes the
 *      extraction exact rather than heuristic. The guard never has to guess
 *      where a shell transcript ends and PHP begins, because the blocks that
 *      mixed them were split.
 *   2. No non-PHP block carries PHP. Without this, marking a broken sample
 *      `language-shell` would get it past the guard.
 *   3. Every `language-php` block passes `php -l`.
 *   4. Every `SugarCraft\` symbol a sample names exists: imported or referenced
 *      types, static methods and constants/enum cases, public constructors,
 *      attribute and call arguments (named ones by name, positional ones by
 *      count), and instance methods on variables whose type follows from the
 *      sample (`new X`, `X::factory()` and fluent chains, typed through
 *      declared return types). An argument whose type the sample fixes must
 *      fit the parameter's declared SugarCraft type (checked only when the
 *      argument is a final class or an enum, so no unseen subclass could make
 *      it fit). A class the sample declares must implement
 *      every method of the SugarCraft interfaces it names. Unimported short
 *      names that match a SugarCraft type are reported as missing imports.
 *   5. The candy-layout quickstart is run from source, and the widths its
 *      comments promise must match what the solver returns.
 *
 * The first full run found, beyond the four parse errors: invented classes
 * (candy-ansi `AnsiState`, sugar-dash's `Layout\StackedGrid`, sugar-skate's
 * `Backend\MemoryStore`), invented methods (candy-serve `Repo::create()` and
 * `SSHServer::listen()`, sugar-calendar `withMinDate()`, sugar-post `from()`),
 * wrong arguments (sugar-reel `cols:`/`rows:`, candy-log `with('k', 'v')`), a
 * private constructor (sugar-table `new Table()`), and two `Model`
 * implementations without `subscriptions()` (candy-core, sugar-bits). The
 * argument-type check came later. candy-query's programmatic sample handed
 * `ConnectionFactory::fromDsn()`'s ConnectionConfig to
 * `App::start(DatabaseInterface $db)`, a TypeError that names and counts alone
 * passed.
 *
 * WHY STATIC, NOT AUTOLOADED. The `tools-guards` CI job runs with no
 * `composer install`, so there is no lib autoloader to ask. Every check reads
 * source instead: the PSR-4 map comes from each lib's composer.json, and the
 * class shapes come from token_get_all() over the mapped file. That keeps it
 * deterministic and fast (no network, no vendor/, one tokenizer pass per
 * referenced class). Where the source cannot answer, the check is skipped rather
 * than guessed: an ancestor outside SugarCraft and not built into PHP, a
 * `__call`/`__callStatic`, a union return type, or a variable whose type the
 * sample does not fix.
 *
 * Imports carry across a page's PHP blocks in document order, because the
 * pages read as one tutorial (candy-wish's second sample reuses the first
 * sample's `Server`/`Logger` imports).
 *
 * The checker is pinned against a fixture tree by the `testTheChecker*` cases.
 * Without that, a checker that always returned `[]` would pass every page check
 * as well as a correct one.
 */

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocsQuickstartTest extends TestCase
{
    use TmpTree;

    private const LANGUAGES = ['php', 'shell', 'text', 'yaml'];

    private const BLOCK_RE = '#<pre\b[^>]*>\s*<code\b([^>]*)>(.*?)</code>\s*</pre>#s';

    /** Per-root caches; the fixture tree and the real tree never share entries. */
    private static array $psr4 = [];
    private static array $types = [];
    private static array $shortNames = [];

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('docs_quickstart_test_');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }

    // -----------------------------------------------------------------
    // Extraction
    // -----------------------------------------------------------------

    /** @return list<array{lang: ?string, code: string}> in document order */
    private static function blocks(string $html): array
    {
        \preg_match_all(self::BLOCK_RE, $html, $m, \PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            $lang = \preg_match('/\bclass="(?:[^"]*\s)?language-([a-z]+)\b/', $match[1], $l) ? $l[1] : null;
            $out[] = [
                'lang' => $lang,
                'code' => \html_entity_decode($match[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            ];
        }

        return $out;
    }

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        foreach (\glob(self::root() . '/docs/_data/*.body.html') ?: [] as $file) {
            yield \basename($file, '.body.html') => [$file];
        }
    }

    public function testThePagesExist(): void
    {
        self::assertGreaterThan(40, \count(\iterator_to_array(self::pages())), 'docs/_data/*.body.html glob found almost nothing — the path is wrong, not the docs');
    }

    #[DataProvider('pages')]
    public function testEveryCodeBlockDeclaresAKnownLanguage(string $file): void
    {
        $html = (string) \file_get_contents($file);
        $blocks = self::blocks($html);
        self::assertSame(\substr_count($html, '<pre'), \count($blocks), 'a <pre> block the extractor does not recognise; use <pre class="code"><code class="language-…">');
        foreach ($blocks as $i => $block) {
            self::assertContains(
                $block['lang'],
                self::LANGUAGES,
                \sprintf('code block #%d must declare class="language-{%s}"; it starts: %s', $i, \implode('|', self::LANGUAGES), \strtok($block['code'], "\n")),
            );
        }
    }

    #[DataProvider('pages')]
    public function testNoNonPhpBlockCarriesPhp(string $file): void
    {
        foreach (self::blocks((string) \file_get_contents($file)) as $i => $block) {
            if ($block['lang'] === 'php') {
                continue;
            }
            self::assertSame([], self::phpSignals($block['code']), "block #{$i} is language-{$block['lang']} but contains PHP; split it and mark the PHP part language-php");
        }
    }

    /** @return list<string> the PHP-only constructs found in a non-PHP block */
    private static function phpSignals(string $code): array
    {
        $signals = [];
        foreach ([
            'use statement' => '/^\s*use\s+[A-Z]\w*\\\\/m',
            'method call' => '/\$[A-Za-z_]\w*\s*(?:\?->|->)\s*\w+\s*\(/',
            'static call' => '/\b[A-Z]\w*::[A-Za-z_]\w*\s*\(/',
            'new expression' => '/\bnew\s+\\\\?[A-Z]\w*(?:\\\\\w+)*\s*\(/',
            'open tag' => '/<\?php/',
        ] as $name => $re) {
            if (\preg_match($re, $code)) {
                $signals[] = $name;
            }
        }

        return $signals;
    }

    #[DataProvider('pages')]
    public function testEveryPhpBlockLints(string $file): void
    {
        $errors = [];
        foreach (self::blocks((string) \file_get_contents($file)) as $i => $block) {
            if ($block['lang'] !== 'php') {
                continue;
            }
            $error = $this->lint($block['code']);
            if ($error !== null) {
                $errors[] = "block #{$i}: {$error}";
            }
        }
        self::assertSame([], $errors, 'PHP quickstart does not parse');
    }

    /** null when `php -l` accepts the sample, else its message */
    private function lint(string $code): ?string
    {
        $path = $this->tmpDir . '/snippet_' . \md5($code) . '.php';
        \file_put_contents($path, self::asFile($code));
        $proc = \proc_open([\PHP_BINARY, '-n', '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('could not start php -l');
        }
        $out = \stream_get_contents($pipes[1]) . \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);

        return $exit === 0 ? null : \trim(\str_replace($path, 'snippet', $out));
    }

    private static function asFile(string $code): string
    {
        return \str_starts_with(\ltrim($code), '<?php') ? $code : "<?php\n" . $code . "\n";
    }

    #[DataProvider('pages')]
    public function testEveryPhpBlockNamesRealSugarCraftSymbols(string $file): void
    {
        $imports = [];
        $errors = [];
        foreach (self::blocks((string) \file_get_contents($file)) as $i => $block) {
            if ($block['lang'] !== 'php') {
                continue;
            }
            foreach (self::checkSnippet(self::root(), $block['code'], $imports) as $error) {
                $errors[] = "block #{$i}: {$error}";
            }
        }
        self::assertSame([], $errors, 'PHP quickstart names a SugarCraft symbol that does not exist, or calls it with arguments it does not accept');
    }

    // -----------------------------------------------------------------
    // The candy-layout quickstart is RUN, not just read
    // -----------------------------------------------------------------

    /**
     * Its comments promise exact widths. candy-layout has no runtime deps, so it
     * can run straight from source with no vendor/. The comments used to describe
     * intent ("fills remaining space", "greedy but clamped"), while the solver gave
     * fill(1) one cell and max(50) six. Each `Constraint::…` line has to carry the
     * width it really gets as a leading `// N` comment, and the sample is executed
     * to compare them.
     */
    public function testTheCandyLayoutQuickstartWidthsAreWhatTheSolverProduces(): void
    {
        $php = \array_values(\array_filter(
            self::blocks((string) \file_get_contents(self::root() . '/docs/_data/candy-layout.body.html')),
            static fn (array $b): bool => $b['lang'] === 'php',
        ));
        self::assertNotSame([], $php, 'candy-layout has no PHP quickstart');
        $code = $php[0]['code'];

        \preg_match_all('#^\s*Constraint::\w+\([^)]*\),?[ \t]*(//[^\n]*)?$#m', $code, $m);
        self::assertNotSame([], $m[0], 'no Constraint:: lines found in the candy-layout quickstart');
        $promised = [];
        foreach ($m[1] as $j => $comment) {
            self::assertMatchesRegularExpression('#^//\s*\d+\b#', $comment, 'every Constraint line must state its width as "// N": ' . \trim($m[0][$j]));
            \preg_match('#^//\s*(\d+)#', $comment, $n);
            $promised[] = (int) $n[1];
        }

        $src = \var_export(self::root() . '/candy-layout/src/', true);
        $script = "<?php\ndeclare(strict_types=1);\n"
            . "spl_autoload_register(static function (string \$c): void {\n"
            . "    if (str_starts_with(\$c, 'SugarCraft\\\\Layout\\\\') && is_file(\$f = {$src} . str_replace('\\\\', '/', substr(\$c, 18)) . '.php')) { require \$f; }\n"
            . "});\n"
            . "?>" . self::asFile($code)
            . "\n\$__widths = [];\n"
            . "foreach (get_defined_vars() as \$__v) { if (is_array(\$__v) && \$__v !== [] && array_is_list(\$__v) && \$__v[0] instanceof SugarCraft\\Layout\\Region) { foreach (\$__v as \$__r) { \$__widths[] = \$__r->width; } } }\n"
            . "echo json_encode(\$__widths);\n";
        $path = $this->tmpDir . '/layout_quickstart.php';
        \file_put_contents($path, $script);
        $out = \shell_exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($path) . ' 2>&1');
        $actual = \json_decode((string) $out, true);
        self::assertIsArray($actual, 'the candy-layout quickstart did not run: ' . $out);

        self::assertSame($promised, $actual, 'the widths in the candy-layout quickstart comments are not what GreedySolver returns');
    }

    // -----------------------------------------------------------------
    // The checker, pinned against a fixture tree
    // -----------------------------------------------------------------

    private function fixtureRoot(): string
    {
        $root = $this->tmpDir . '/tree';
        $files = [
            'fixture-lib/composer.json' => '{"autoload":{"psr-4":{"SugarCraft\\\\Fixture\\\\":"src/"}}}',
            'fixture-lib/src/Veil.php' => <<<'PHP'
                <?php
                namespace SugarCraft\Fixture;
                use SugarCraft\Fixture\Sub\Region;
                final class Veil extends Base implements Composites {
                    use Fades;
                    public const MAX = 100;
                    public function __construct(private int $opacity = 0) {}
                    public static function new(): self { return new self(); }
                    public function withBackdrop(int $percent): static { $f = function () { return 1; }; return clone $this; }
                    public function region(): Region { return Region::zero(); }
                    public function maybe(): ?Region { return null; }
                    public function either(): Region|int { return 1; }
                    private function secret(): void {}
                    public function spread(string ...$parts): void {}
                }
                PHP,
            'fixture-lib/src/Base.php' => "<?php\nnamespace SugarCraft\\Fixture;\nabstract class Base { public function inherited(): string { return ''; } }\n",
            'fixture-lib/src/Composites.php' => "<?php\nnamespace SugarCraft\\Fixture;\ninterface Composites { public function composite(string \$fg, string \$bg): string; }\n",
            'fixture-lib/src/Fades.php' => "<?php\nnamespace SugarCraft\\Fixture;\ntrait Fades { public function fade(float \$progress): static { return \$this; } }\n",
            'fixture-lib/src/Position.php' => "<?php\nnamespace SugarCraft\\Fixture;\nenum Position: string { case Center = 'c'; public function label(): string { switch (1) { case 1: return ''; } return ''; } }\n",
            'fixture-lib/src/Sub/Region.php' => "<?php\nnamespace SugarCraft\\Fixture\\Sub;\nfinal class Region { public static function zero(): self { return new self(); } public function width(): int { return 0; } }\n",
            'fixture-lib/src/Magic.php' => "<?php\nnamespace SugarCraft\\Fixture;\nfinal class Magic { public function __call(string \$n, array \$a): mixed { return null; } }\n",
            'fixture-lib/src/External.php' => "<?php\nnamespace SugarCraft\\Fixture;\nfinal class External extends \\Vendor\\Unknown\\Thing {}\n",
            'fixture-lib/src/Alias.php' => "<?php\nnamespace SugarCraft\\Fixture;\nclass_alias(\\SugarCraft\\Fixture\\Sub\\Region::class, Alias::class);\n",
            'fixture-lib/src/Tag.php' => "<?php\nnamespace SugarCraft\\Fixture;\n#[\\Attribute]\nfinal class Tag { public function __construct(public readonly string \$name, public readonly array \$extra = []) {} }\n",
            'fixture-lib/src/Sealed.php' => "<?php\nnamespace SugarCraft\\Fixture;\nfinal class Sealed { private function __construct() {} public static function new(): self { return new self(); } }\n",
            'fixture-lib/src/StringAlias.php' => "<?php\nnamespace SugarCraft\\Fixture;\nclass_alias('SugarCraft\\Fixture\\Sub\\Region', 'SugarCraft\\Fixture\\StringAlias');\n",
            'fixture-lib/src/Stage.php' => <<<'PHP'
                <?php
                namespace SugarCraft\Fixture;
                use SugarCraft\Fixture\Sub\Region;
                final class Stage {
                    public static function start(Composites $c, ?Region $r = null): self { return new self(); }
                    public static function place(Region|int $r): void {}
                    public function attach(#[\SensitiveParameter] Base $b, self $other): void {}
                    public function spread(Base $b, int ...$rest): void {}
                    public function hold(Base &$b, Composites $c = new Veil()): void {}
                }
                PHP,
            'fixture-lib/src/Holder.php' => "<?php\nnamespace SugarCraft\\Fixture;\nuse SugarCraft\\Fixture\\Sub\\Region;\nfinal class Holder { public function __construct(public readonly Region \$region, private readonly ?Composites \$c = null) {} }\n",
            // The candy-query shape: a factory whose first step returns a config, not the database.
            'fixture-lib/src/Db/Config.php' => "<?php\nnamespace SugarCraft\\Fixture\\Db;\nfinal readonly class Config { public function __construct(public string \$dsn) {} }\n",
            'fixture-lib/src/Db/Database.php' => "<?php\nnamespace SugarCraft\\Fixture\\Db;\ninterface Database { public function tables(): array; }\n",
            'fixture-lib/src/Db/Factory.php' => "<?php\nnamespace SugarCraft\\Fixture\\Db;\nfinal class Factory { public static function fromDsn(string \$dsn): Config { return new Config(\$dsn); } public static function fromConfig(Config \$config): Database { throw new \\LogicException(); } }\n",
            'fixture-lib/src/App.php' => "<?php\nnamespace SugarCraft\\Fixture;\nuse SugarCraft\\Fixture\\Db\\Database;\nfinal class App { public static function start(Database \$db, Position \$flavor = Position::Center): self { return new self(); } }\n",
        ];
        foreach ($files as $rel => $content) {
            $path = $root . '/' . $rel;
            if (!\is_dir(\dirname($path))) {
                \mkdir(\dirname($path), 0777, true);
            }
            \file_put_contents($path, $content);
        }

        return $root;
    }

    /** @return list<string> */
    private function check(string $code): array
    {
        $imports = [];

        return self::checkSnippet($this->fixtureRoot(), $code, $imports);
    }

    public function testTheCheckerAcceptsASampleThatOnlyNamesRealSymbols(): void
    {
        self::assertSame([], $this->check(<<<'PHP'
            use SugarCraft\Fixture\{Veil, Position, Alias};
            use SugarCraft\Fixture\Sub\Region as R;
            use SugarCraft\Fixture\{Stage, Holder, App};
            use SugarCraft\Fixture\Db\Factory;
            $veil = Veil::new()->withBackdrop(40)->fade(0.5);
            $veil = $veil->withBackdrop(percent: 10);
            echo $veil->composite('a', 'b') . $veil->inherited() . $veil->region()->width();
            $veil->maybe()->width();
            $x = (new Veil(opacity: 3))->withBackdrop(1);
            echo Position::Center->value, Position::from('c')->label(), Veil::MAX, Veil::class;
            $veil->spread(anything: 'x');
            $veil->either()->whatever();
            R::zero()->width();
            \SugarCraft\Fixture\Sealed::new();
            Alias::zero()->width();
            \SugarCraft\Fixture\StringAlias::zero()->width();
            $m = new \SugarCraft\Fixture\Magic();
            $m->anything();
            (new \SugarCraft\Fixture\External())->vendorMethod();
            $dt = new \DateTimeImmutable('2026-01-01');
            $dt->format('Y');
            final class Mine { public function f(Veil $v): R { return R::zero(); } }
            #[\SugarCraft\Fixture\Tag('n', extra: ['a', 'b'])]
            final class Done implements \SugarCraft\Fixture\Composites { public function composite(string $fg, string $bg): string { return $fg; } }
            final class Inherits extends Elsewhere implements \SugarCraft\Fixture\Composites {}
            $veil->composite(['x', 'y'][0], (string) 1);
            $veil->composite(...['a', 'b']);
            $unknownUserVar->push();
            $stage = Stage::start($veil, $veil->region());
            Stage::start(Veil::new(), r: R::zero());
            Stage::start(new \SugarCraft\Fixture\External());
            Stage::place(R::zero());
            $stage->attach($veil, $stage);
            $stage->hold($veil, Veil::new());
            $stage->spread(R::zero(), 1, 2);
            new Holder(region: R::zero());
            new Holder(Alias::zero(), $veil);
            $db = Factory::fromConfig(Factory::fromDsn('x'));
            App::start($db, Position::Center);
            App::start($db, Position::from('c'));
            PHP));
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenSamples(): iterable
    {
        yield 'fictional instance method (the old sugar-veil sample)' => [
            "use SugarCraft\\Fixture\\Veil;\n\$veil = new Veil();\n\$veil = \$veil->push('m');\n",
            'Veil has no method push()',
        ];
        yield 'fictional method deep in a fluent chain' => [
            "use SugarCraft\\Fixture\\Veil;\necho Veil::new()->withBackdrop(1)->render();\n",
            'Veil has no method render()',
        ];
        yield 'method on the declared return type of a call' => [
            "use SugarCraft\\Fixture\\Veil;\nVeil::new()->region()->height();\n",
            'Region has no method height()',
        ];
        yield 'variable reassigned through its own chain' => [
            "use SugarCraft\\Fixture\\Veil;\n\$v = Veil::new();\n\$v = \$v->region();\n\$v->withBackdrop(1);\n",
            'Region has no method withBackdrop()',
        ];
        yield 'missing static factory' => [
            "use SugarCraft\\Fixture\\Veil;\nVeil::create();\n",
            'Veil has no method create()',
        ];
        yield 'instance method called statically' => [
            "use SugarCraft\\Fixture\\Veil;\nVeil::withBackdrop(1);\n",
            'is not static',
        ];
        yield 'private method' => [
            "use SugarCraft\\Fixture\\Veil;\nVeil::new()->secret();\n",
            'is not public',
        ];
        yield 'unknown named argument' => [
            "use SugarCraft\\Fixture\\Veil;\nVeil::new()->withBackdrop(opacity: 1);\n",
            'has no parameter $opacity',
        ];
        yield 'unknown constructor argument' => [
            "use SugarCraft\\Fixture\\Veil;\nnew Veil(alpha: 1);\n",
            'has no parameter $alpha',
        ];
        yield 'unknown enum case' => [
            "use SugarCraft\\Fixture\\Position;\necho Position::Middle->value;\n",
            'Position has no constant or case Middle',
        ];
        yield 'unknown imported class' => [
            "use SugarCraft\\Fixture\\Missing;\n",
            'SugarCraft\\Fixture\\Missing does not exist',
        ];
        yield 'unknown fully-qualified class' => [
            "\$x = new \\SugarCraft\\Fixture\\Gone();\n",
            'SugarCraft\\Fixture\\Gone does not exist',
        ];
        yield 'SugarCraft class used without its import' => [
            "\$v = Veil::new();\n",
            'Veil is used without an import',
        ];
        yield 'instantiating an abstract class' => [
            "use SugarCraft\\Fixture\\Base;\nnew Base();\n",
            'cannot be instantiated',
        ];
        yield 'too many positional arguments' => [
            "use SugarCraft\\Fixture\\Veil;\nVeil::new()->composite('a', 'b', 'c');\n",
            'is given 3 positional arguments but declares 2',
        ];
        yield 'private constructor (the old sugar-veil `new Veil()`)' => [
            "use SugarCraft\\Fixture\\Sealed;\n\$s = new Sealed();\n",
            'Sealed::__construct() is not public',
        ];
        yield 'unknown attribute argument' => [
            "use SugarCraft\\Fixture\\Tag;\n#[Tag(label: 'x')]\nfinal class C {}\n",
            'Tag::__construct() has no parameter $label',
        ];
        yield 'sample class missing an interface method (the old Counter samples)' => [
            "use SugarCraft\\Fixture\\Composites;\nfinal class C implements Composites { public function other(): void {} }\n",
            'C implements Composites but does not define composite()',
        ];
        yield 'argument of the wrong type, through a variable (the candy-query sample)' => [
            "use SugarCraft\\Fixture\\App;\nuse SugarCraft\\Fixture\\Db\\Factory;\n\$pdo = Factory::fromDsn('mysql://u:p@h/db');\nApp::start(\$pdo);\n",
            'App::start() $db must be Database, but is given Config',
        ];
        yield 'argument of the wrong type, as an inline call' => [
            "use SugarCraft\\Fixture\\App;\nuse SugarCraft\\Fixture\\Db\\Factory;\nApp::start(Factory::fromDsn('x'));\n",
            'App::start() $db must be Database, but is given Config',
        ];
        yield 'named argument of the wrong type' => [
            "use SugarCraft\\Fixture\\{Stage, Veil};\nStage::start(Veil::new(), r: Veil::new());\n",
            'Stage::start() $r must be Region, but is given Veil',
        ];
        yield 'promoted constructor parameter of the wrong type' => [
            "use SugarCraft\\Fixture\\{Holder, Veil};\nnew Holder(Veil::new());\n",
            'Holder::__construct() $region must be Region, but is given Veil',
        ];
        yield 'class given where an enum is declared' => [
            "use SugarCraft\\Fixture\\App;\nuse SugarCraft\\Fixture\\Db\\Factory;\nuse SugarCraft\\Fixture\\Sub\\Region;\nApp::start(Factory::fromConfig(Factory::fromDsn('x')), Region::zero());\n",
            'App::start() $flavor must be Position, but is given Region',
        ];
        yield 'instance method parameter typed by an abstract parent' => [
            "use SugarCraft\\Fixture\\{Stage, Veil};\nuse SugarCraft\\Fixture\\Sub\\Region;\n\$s = Stage::start(Veil::new());\n\$s->attach(Region::zero(), \$s);\n",
            'Stage::attach() $b must be Base, but is given Region',
        ];
        yield 'type hint naming a missing class' => [
            "use SugarCraft\\Fixture\\Nope;\nfunction f(Nope \$n): void {}\n",
            'SugarCraft\\Fixture\\Nope does not exist',
        ];
    }

    #[DataProvider('brokenSamples')]
    public function testTheCheckerRejects(string $code, string $expected): void
    {
        $errors = $this->check($code);
        self::assertNotSame([], $errors, 'the checker accepted a broken sample');
        self::assertStringContainsString($expected, \implode("\n", $errors));
    }

    public function testImportsCarryAcrossBlocksOfOnePage(): void
    {
        $root = $this->fixtureRoot();
        $imports = [];
        self::assertSame([], self::checkSnippet($root, "use SugarCraft\\Fixture\\Veil;\n", $imports));
        self::assertSame([], self::checkSnippet($root, "Veil::new()->withBackdrop(1);\n", $imports));
        self::assertNotSame([], self::checkSnippet($root, "Veil::new()->nope();\n", $imports));
    }

    public function testTheLinterRejectsWhatPhpRejects(): void
    {
        self::assertNull($this->lint("\$a = fn () => 1;\n"));
        self::assertNotNull($this->lint("\$a = fn () => echo 'x';\n"));
        self::assertNotNull($this->lint("use A\\B;\nuse C\\B;\n"));
    }

    public function testNonPhpBlocksAreScannedForPhp(): void
    {
        self::assertSame([], self::phpSignals("candyshell confirm \"Really delete \$file?\" && rm \"\$file\"\nname=\$(candyshell input)\n"));
        self::assertNotSame([], self::phpSignals("./bin/app\n\nuse SugarCraft\\Core\\Program;\n"));
        self::assertNotSame([], self::phpSignals("\$player = Player::open('x');\n"));
    }

    // -----------------------------------------------------------------
    // Source index (PSR-4 from composer.json, shapes from token_get_all)
    // -----------------------------------------------------------------

    /** @return array<string, list<string>> namespace prefix => absolute dirs, longest prefix first */
    private static function psr4(string $root): array
    {
        if (isset(self::$psr4[$root])) {
            return self::$psr4[$root];
        }
        $map = [];
        foreach (\glob($root . '/*/composer.json') ?: [] as $manifest) {
            $json = \json_decode((string) \file_get_contents($manifest), true);
            foreach ((array) ($json['autoload']['psr-4'] ?? []) as $prefix => $dirs) {
                foreach ((array) $dirs as $dir) {
                    $map[$prefix][] = \dirname($manifest) . '/' . \rtrim((string) $dir, '/');
                }
            }
        }
        \uksort($map, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a) ?: \strcmp($a, $b));

        return self::$psr4[$root] = $map;
    }

    private static function typeFile(string $root, string $fqn): ?string
    {
        foreach (self::psr4($root) as $prefix => $dirs) {
            if (!\str_starts_with($fqn, $prefix)) {
                continue;
            }
            $rel = \str_replace('\\', '/', \substr($fqn, \strlen($prefix))) . '.php';
            foreach ($dirs as $dir) {
                if (\is_file($dir . '/' . $rel)) {
                    return $dir . '/' . $rel;
                }
            }
        }

        return null;
    }

    /** @return array<string, list<string>> short type name => FQNs, over every PSR-4 root */
    private static function shortNames(string $root): array
    {
        if (isset(self::$shortNames[$root])) {
            return self::$shortNames[$root];
        }
        $names = [];
        foreach (self::psr4($root) as $prefix => $dirs) {
            if (!\str_starts_with($prefix, 'SugarCraft\\')) {
                continue;
            }
            foreach ($dirs as $dir) {
                if (!\is_dir($dir)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && \preg_match('/^([A-Z]\w*)\.php$/', $f->getFilename(), $m)) {
                        $rel = \substr($f->getPathname(), \strlen($dir) + 1, -4);
                        $names[$m[1]][] = $prefix . \str_replace('/', '\\', $rel);
                    }
                }
            }
        }

        return self::$shortNames[$root] = $names;
    }

    private static function isBuiltin(string $fqn): bool
    {
        return \class_exists($fqn, false) || \interface_exists($fqn, false) || \enum_exists($fqn, false) || \trait_exists($fqn, false);
    }

    private static function isIdent(mixed $tok): bool
    {
        return \is_array($tok) && \preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*$/', $tok[1]) === 1;
    }

    private static function isName(mixed $tok): bool
    {
        return \is_array($tok) && \in_array($tok[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true);
    }

    /**
     * Significant tokens: whitespace, comments and the open tag dropped.
     *
     * @return list<string|array{int, string, int}>
     */
    private static function sigTokens(string $source): array
    {
        $out = [];
        foreach (\token_get_all($source) as $tok) {
            if (\is_array($tok) && \in_array($tok[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_INLINE_HTML], true)) {
                continue;
            }
            $out[] = $tok;
        }

        return $out;
    }

    private static function isOpen(mixed $tok): bool
    {
        return $tok === '{' || (\is_array($tok) && \in_array($tok[0], [\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES], true));
    }

    /**
     * Top-level `use` imports at brace depth 0, merged into $imports (lower-cased alias => FQN).
     *
     * @param list<mixed> $t
     * @param array<string, string> $imports
     * @return list<string> the FQNs imported, for the existence check
     */
    private static function readImports(array $t, array &$imports): array
    {
        $imported = [];
        $depth = 0;
        $n = \count($t);
        for ($i = 0; $i < $n; $i++) {
            if (self::isOpen($t[$i])) {
                $depth++;
                continue;
            }
            if ($t[$i] === '}') {
                $depth--;
                continue;
            }
            if ($depth !== 0 || !\is_array($t[$i]) || $t[$i][0] !== \T_USE || ($t[$i - 1] ?? null) === ')') {
                continue;
            }
            $j = $i + 1;
            if (\is_array($t[$j] ?? null) && \in_array($t[$j][0], [\T_FUNCTION, \T_CONST], true)) {
                continue;
            }
            while ($j < $n) {
                if (!self::isName($t[$j])) {
                    break;
                }
                $base = \ltrim($t[$j][1], '\\');
                $j++;
                if (\is_array($t[$j] ?? null) && $t[$j][0] === \T_NS_SEPARATOR && ($t[$j + 1] ?? null) === '{') {
                    $j += 2;
                    while ($j < $n && $t[$j] !== '}') {
                        if (self::isName($t[$j])) {
                            $fqn = $base . '\\' . \ltrim($t[$j][1], '\\');
                            $alias = \substr(\strrchr('\\' . $fqn, '\\'), 1);
                            if (\is_array($t[$j + 1] ?? null) && $t[$j + 1][0] === \T_AS) {
                                $alias = $t[$j + 2][1];
                                $j += 2;
                            }
                            $imports[\strtolower($alias)] = $fqn;
                            $imported[] = $fqn;
                        }
                        $j++;
                    }
                    $j++;
                } else {
                    $alias = \substr(\strrchr('\\' . $base, '\\'), 1);
                    if (\is_array($t[$j] ?? null) && $t[$j][0] === \T_AS) {
                        $alias = $t[$j + 1][1];
                        $j += 2;
                    }
                    $imports[\strtolower($alias)] = $base;
                    $imported[] = $base;
                }
                if (($t[$j] ?? null) === ',') {
                    $j++;
                    continue;
                }
                break;
            }
            $i = $j;
        }

        return $imported;
    }

    /**
     * Resolve a name token against a namespace + imports. Returns null for
     * self/static/parent, whose meaning depends on a class context.
     *
     * @param array<string, string> $imports
     */
    private static function resolveName(string $name, string $namespace, array $imports): ?string
    {
        if (\str_starts_with($name, '\\')) {
            return \substr($name, 1);
        }
        $lower = \strtolower($name);
        if (\in_array($lower, ['self', 'static', 'parent'], true)) {
            return null;
        }
        $first = \strtolower(\explode('\\', $name, 2)[0]);
        if (isset($imports[$first])) {
            $rest = \strpos($name, '\\') === false ? '' : \substr($name, \strpos($name, '\\'));

            return $imports[$first] . $rest;
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * The shape of one SugarCraft type, read from its PSR-4 file; null when the file is absent.
     *
     * @return array{fqn: string, kind: string, abstract: bool, final: bool, parent: ?string, supers: list<string>,
     *   methods: array<string, array{name: string, static: bool, public: bool, params: ?list<string>, types: ?list<?string>, return: ?string}>,
     *   constants: array<string, true>, namespace: string, imports: array<string, string>}|null
     */
    private static function type(string $root, string $fqn): ?array
    {
        $key = $root . "\0" . \strtolower($fqn);
        if (\array_key_exists($key, self::$types)) {
            return self::$types[$key];
        }
        self::$types[$key] = null;
        $file = self::typeFile($root, $fqn);
        if ($file === null) {
            return null;
        }
        $t = self::sigTokens((string) \file_get_contents($file));
        $n = \count($t);
        $namespace = '';
        for ($i = 0; $i < $n; $i++) {
            if (\is_array($t[$i]) && $t[$i][0] === \T_NAMESPACE && self::isName($t[$i + 1] ?? null)) {
                $namespace = $t[$i + 1][1];
                break;
            }
        }
        $imports = [];
        self::readImports($t, $imports);
        $short = \substr(\strrchr('\\' . $fqn, '\\'), 1);

        $depth = 0;
        $info = null;
        $bodyDepth = -1;
        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            if (self::isOpen($tok)) {
                $depth++;
                continue;
            }
            if ($tok === '}') {
                $depth--;
                if ($info !== null && $depth < $bodyDepth) {
                    break;
                }
                continue;
            }
            if (!\is_array($tok)) {
                continue;
            }
            if ($info === null) {
                $kinds = [\T_CLASS => 'class', \T_INTERFACE => 'interface', \T_TRAIT => 'trait', \T_ENUM => 'enum'];
                $prev = $t[$i - 1] ?? null;
                if (!isset($kinds[$tok[0]]) || (\is_array($prev) && $prev[0] === \T_DOUBLE_COLON) || !self::isIdent($t[$i + 1] ?? null) || \strcasecmp($t[$i + 1][1], $short) !== 0) {
                    continue;
                }
                $abstract = false;
                $final = false;
                for ($k = $i - 1; $k >= 0 && \is_array($t[$k]) && \in_array($t[$k][0], [\T_ABSTRACT, \T_FINAL, \T_READONLY], true); $k--) {
                    $abstract = $abstract || $t[$k][0] === \T_ABSTRACT;
                    $final = $final || $t[$k][0] === \T_FINAL;
                }
                $kind = $kinds[$tok[0]];
                $parent = null;
                $supers = [];
                $mode = null;
                for ($k = $i + 2; $k < $n && !self::isOpen($t[$k]); $k++) {
                    if (\is_array($t[$k]) && $t[$k][0] === \T_EXTENDS) {
                        $mode = 'extends';
                    } elseif (\is_array($t[$k]) && $t[$k][0] === \T_IMPLEMENTS) {
                        $mode = 'implements';
                    } elseif ($mode !== null && self::isName($t[$k])) {
                        $resolved = (string) self::resolveName($t[$k][1], $namespace, $imports);
                        if ($mode === 'extends' && $kind === 'class') {
                            $parent = $resolved;
                        }
                        $supers[] = $resolved;
                    }
                }
                $info = [
                    'fqn' => $namespace === '' ? $short : $namespace . '\\' . $t[$i + 1][1],
                    'kind' => $kind,
                    'abstract' => $abstract || $kind !== 'class',
                    // An enum cannot be extended either, so a value of it has exactly its declared type.
                    'final' => ($final && $kind === 'class') || $kind === 'enum',
                    'parent' => $parent,
                    'supers' => $supers,
                    'methods' => [],
                    'constants' => [],
                    'namespace' => $namespace,
                    'imports' => $imports,
                ];
                $i = $k - 1;
                $bodyDepth = $depth + 1;
                continue;
            }
            if ($depth !== $bodyDepth) {
                continue;
            }
            if ($tok[0] === \T_USE) {
                for ($k = $i + 1; $k < $n && $t[$k] !== ';' && !self::isOpen($t[$k]); $k++) {
                    if (self::isName($t[$k])) {
                        $info['supers'][] = (string) self::resolveName($t[$k][1], $namespace, $imports);
                    }
                }
            } elseif ($tok[0] === \T_CASE && self::isIdent($t[$i + 1] ?? null)) {
                $info['constants'][$t[$i + 1][1]] = true;
            } elseif ($tok[0] === \T_CONST) {
                for ($k = $i + 1; $k < $n && $t[$k] !== ';'; $k++) {
                    if (self::isIdent($t[$k]) && ($t[$k + 1] ?? null) === '=') {
                        $info['constants'][$t[$k][1]] = true;
                    }
                }
            } elseif ($tok[0] === \T_FUNCTION && self::isIdent($t[$i + 1] ?? null) && ($t[$i + 2] ?? null) === '(') {
                $static = false;
                $public = true;
                for ($k = $i - 1; $k >= 0 && \is_array($t[$k]) && \in_array($t[$k][0], [\T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_STATIC, \T_ABSTRACT, \T_FINAL], true); $k--) {
                    $static = $static || $t[$k][0] === \T_STATIC;
                    $public = $public && !\in_array($t[$k][0], [\T_PROTECTED, \T_PRIVATE], true);
                }
                $close = self::matching($t, $i + 2);
                $params = [];
                $types = [];
                $paren = 0;
                // The declared type of each parameter, as written: the tokens
                // between the previous top-level ',' (or the opening paren) and
                // the variable, minus modifiers, attributes, '&' and '...'.
                $typeBuf = '';
                $afterVar = false;
                for ($k = $i + 2; $k <= $close; $k++) {
                    $tk = $t[$k];
                    if ($tk === '(' || $tk === '[' || (\is_array($tk) && $tk[0] === \T_ATTRIBUTE)) {
                        $paren++;
                    } elseif ($tk === ')' || $tk === ']') {
                        $paren--;
                    } elseif ($paren === 1 && $tk === ',') {
                        $typeBuf = '';
                        $afterVar = false;
                    } elseif ($paren === 1 && \is_array($tk) && $tk[0] === \T_VARIABLE) {
                        if (\is_array($t[$k - 1] ?? null) && $t[$k - 1][0] === \T_ELLIPSIS) {
                            $params = null;
                            $types = null;
                            break;
                        }
                        $params[] = \substr($tk[1], 1);
                        $types[] = $typeBuf === '' ? null : $typeBuf;
                        $afterVar = true;
                    } elseif ($paren === 1 && !$afterVar && $tk !== '&'
                        && !(\is_array($tk) && \in_array($tk[0], [\T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_READONLY, \T_ELLIPSIS, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG], true))) {
                        $typeBuf .= \is_array($tk) ? $tk[1] : $tk;
                    }
                }
                $return = null;
                if (($t[$close + 1] ?? null) === ':') {
                    $return = '';
                    for ($k = $close + 2; $k < $n && !self::isOpen($t[$k]) && $t[$k] !== ';'; $k++) {
                        $return .= \is_array($t[$k]) ? $t[$k][1] : $t[$k];
                    }
                }
                $info['methods'][\strtolower($t[$i + 1][1])] = [
                    'name' => $t[$i + 1][1],
                    'static' => $static,
                    'public' => $public,
                    'params' => $params,
                    'types' => $types,
                    'return' => $return,
                ];
            }
        }

        if ($info === null) {
            // A façade file that only aliases a canonical type: class_alias(Canonical::class, This::class).
            for ($i = 0; $i < $n; $i++) {
                if (\is_array($t[$i]) && $t[$i][0] === \T_STRING && \strcasecmp($t[$i][1], 'class_alias') === 0 && ($t[$i + 1] ?? null) === '(') {
                    $arg = $t[$i + 2] ?? null;
                    if (self::isName($arg)) {
                        $canonical = self::resolveName($arg[1], $namespace, $imports);
                    } elseif (\is_array($arg) && $arg[0] === \T_CONSTANT_ENCAPSED_STRING) {
                        // class_alias('SugarCraft\Forms\Spinner\Spinner', ...) — a string literal is always fully qualified.
                        $canonical = \ltrim(\str_replace(['\\\\', "\\'"], ['\\', "'"], \substr($arg[1], 1, -1)), '\\');
                    } else {
                        break;
                    }
                    if ($canonical !== null && \strcasecmp($canonical, $fqn) !== 0) {
                        $info = self::type($root, $canonical);
                    }
                    break;
                }
            }
        }

        return self::$types[$key] = $info;
    }

    /** @param list<mixed> $t */
    private static function matching(array $t, int $open): int
    {
        $pairs = ['(' => ')', '[' => ']'];
        $o = $t[$open];
        $c = $pairs[$o];
        $depth = 0;
        $n = \count($t);
        for ($k = $open; $k < $n; $k++) {
            if ($t[$k] === $o) {
                $depth++;
            } elseif ($t[$k] === $c) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return $n - 1;
    }

    /**
     * Method lookup through the hierarchy.
     *
     * @return array{status: 'found'|'missing'|'open', method?: array, owner?: string}
     */
    private static function findMethod(string $root, string $fqn, string $method, int $guard = 0): array
    {
        $lower = \strtolower($method);
        if (!\str_starts_with($fqn, 'SugarCraft\\')) {
            // Engine classes that proxy members through internal handlers
            // rather than __call: FFI resolves C functions by name at runtime.
            if (\in_array(\strtolower($fqn), ['ffi', 'ffi\\cdata', 'simplexmlelement', 'stdclass'], true)) {
                return ['status' => 'open'];
            }
            if (self::isBuiltin($fqn)) {
                $ref = new \ReflectionClass($fqn);
                if ($ref->hasMethod($method)) {
                    $m = $ref->getMethod($method);

                    return ['status' => 'found', 'owner' => $fqn, 'method' => [
                        'name' => $m->getName(),
                        'static' => $m->isStatic(),
                        'public' => $m->isPublic(),
                        'params' => $m->isVariadic() ? null : \array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $m->getParameters()),
                        // Only SugarCraft parameter types are type-checked; an engine method has none.
                        'types' => null,
                        'return' => $m->hasReturnType() ? (string) $m->getReturnType() : null,
                    ]];
                }

                return ['status' => $ref->hasMethod('__call') || $ref->hasMethod('__callStatic') ? 'open' : 'missing'];
            }

            return ['status' => 'open'];
        }
        $info = self::type($root, $fqn);
        if ($info === null || $guard > 20) {
            return ['status' => 'open'];
        }
        if (isset($info['methods'][$lower])) {
            return ['status' => 'found', 'owner' => $info['fqn'], 'method' => $info['methods'][$lower]];
        }
        if ($info['kind'] === 'enum' && \in_array($lower, ['cases', 'from', 'tryfrom'], true)) {
            return ['status' => 'found', 'owner' => $info['fqn'], 'method' => [
                'name' => $method, 'static' => true, 'public' => true, 'params' => null, 'types' => null,
                'return' => $lower === 'cases' ? 'array' : 'static',
            ]];
        }
        $open = false;
        foreach ($info['supers'] as $super) {
            $r = self::findMethod($root, $super, $method, $guard + 1);
            if ($r['status'] === 'found') {
                return $r;
            }
            $open = $open || $r['status'] === 'open';
        }
        if ($open || isset($info['methods']['__call']) || isset($info['methods']['__callstatic'])) {
            return ['status' => 'open'];
        }
        foreach ($info['supers'] as $super) {
            $r = self::findMethod($root, $super, '__call', $guard + 1);
            if ($r['status'] !== 'missing') {
                return ['status' => 'open'];
            }
        }

        return ['status' => 'missing'];
    }

    /** @return 'found'|'missing'|'open' */
    private static function findConstant(string $root, string $fqn, string $name, int $guard = 0): string
    {
        if (!\str_starts_with($fqn, 'SugarCraft\\')) {
            if (self::isBuiltin($fqn)) {
                return (new \ReflectionClass($fqn))->hasConstant($name) ? 'found' : 'missing';
            }

            return 'open';
        }
        $info = self::type($root, $fqn);
        if ($info === null || $guard > 20) {
            return 'open';
        }
        if (isset($info['constants'][$name])) {
            return 'found';
        }
        $open = false;
        foreach ($info['supers'] as $super) {
            $r = self::findConstant($root, $super, $name, $guard + 1);
            if ($r === 'found') {
                return 'found';
            }
            $open = $open || $r === 'open';
        }

        return $open ? 'open' : 'missing';
    }

    /** The type a declared return type names, or null when it is not a single class. */
    private static function returnType(string $root, ?string $declared, string $owner, string $called): ?string
    {
        if ($declared === null) {
            return null;
        }
        $type = \ltrim(\trim($declared), '?');
        if ($type === '' || \strpbrk($type, '|&()') !== false) {
            return null;
        }
        $lower = \strtolower($type);
        if ($lower === 'static') {
            return $called;
        }
        if ($lower === 'self') {
            return $owner;
        }
        if (\in_array($lower, ['array', 'string', 'int', 'float', 'bool', 'void', 'never', 'mixed', 'null', 'false', 'true', 'iterable', 'callable', 'object', 'closure', 'generator', 'parent'], true)) {
            return null;
        }
        if (!\str_starts_with($owner, 'SugarCraft\\')) {
            return \ltrim($type, '\\');
        }
        $info = self::type($root, $owner);

        return $info === null ? null : self::resolveName($type, $info['namespace'], $info['imports']);
    }

    // -----------------------------------------------------------------
    // The sample checker
    // -----------------------------------------------------------------

    /**
     * Every reason a sample's SugarCraft references are wrong; [] when none.
     *
     * @param array<string, string> $imports page-level imports, updated in place
     * @return list<string>
     */
    private static function checkSnippet(string $root, string $code, array &$imports): array
    {
        $t = self::sigTokens(self::asFile($code));
        $n = \count($t);
        $errors = [];
        $error = static function (string $message) use (&$errors): void {
            $errors[$message] = $message;
        };

        foreach (self::readImports($t, $imports) as $fqn) {
            if (\str_starts_with($fqn, 'SugarCraft\\') && self::type($root, $fqn) === null && !self::isNamespace($root, $fqn)) {
                $error("imported {$fqn} does not exist");
            }
        }

        // Types the sample declares itself are not SugarCraft's to check.
        $local = [];
        for ($i = 0; $i < $n; $i++) {
            if (\is_array($t[$i]) && \in_array($t[$i][0], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM], true) && self::isIdent($t[$i + 1] ?? null)
                && !(\is_array($t[$i - 1] ?? null) && $t[$i - 1][0] === \T_DOUBLE_COLON)) {
                $local[\strtolower($t[$i + 1][1])] = true;
            }
        }

        // A class the sample declares must implement every method of the
        // SugarCraft interfaces it names. Checked only when nothing else can
        // supply them (no parent class, no traits): the candy-core and
        // sugar-bits Counter samples omitted Model::subscriptions() and could
        // never have been loaded.
        for ($i = 0; $i < $n; $i++) {
            if (!\is_array($t[$i]) || $t[$i][0] !== \T_CLASS || !self::isIdent($t[$i + 1] ?? null)
                || (\is_array($t[$i - 1] ?? null) && $t[$i - 1][0] === \T_DOUBLE_COLON)) {
                continue;
            }
            $className = $t[$i + 1][1];
            $interfaces = [];
            $extends = false;
            $mode = null;
            for ($k = $i + 2; $k < $n && !self::isOpen($t[$k]); $k++) {
                if (\is_array($t[$k]) && $t[$k][0] === \T_EXTENDS) {
                    $extends = true;
                    $mode = null;
                } elseif (\is_array($t[$k]) && $t[$k][0] === \T_IMPLEMENTS) {
                    $mode = 'implements';
                } elseif ($mode === 'implements' && self::isName($t[$k])) {
                    $interfaces[] = (string) self::resolveName($t[$k][1], '', $imports);
                }
            }
            $methods = [];
            $traits = false;
            $depth = 0;
            for ($body = $k; $body < $n; $body++) {
                if (self::isOpen($t[$body])) {
                    $depth++;
                } elseif ($t[$body] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && \is_array($t[$body]) && $t[$body][0] === \T_USE) {
                    $traits = true;
                } elseif ($depth === 1 && \is_array($t[$body]) && $t[$body][0] === \T_FUNCTION && self::isIdent($t[$body + 1] ?? null)) {
                    $methods[\strtolower($t[$body + 1][1])] = true;
                }
            }
            if ($extends || $traits) {
                continue;
            }
            foreach ($interfaces as $interface) {
                foreach (self::interfaceMethods($root, $interface) as $lower => $name) {
                    if (!isset($methods[$lower])) {
                        $error("{$className} implements " . self::short($interface) . " but does not define {$name}()");
                    }
                }
            }
        }

        $resolve = static function (array $tok) use ($imports, $local): ?string {
            if ($tok[0] === \T_STRING && isset($local[\strtolower($tok[1])])) {
                return null;
            }

            return self::resolveName($tok[1], '', $imports);
        };

        // Every class-position name: must exist when it is SugarCraft's, and must be imported when it is unqualified.
        $useDepth = 0;
        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            if (!self::isName($tok)) {
                continue;
            }
            $prev = $t[$i - 1] ?? null;
            $next = $t[$i + 1] ?? null;
            $prevId = \is_array($prev) ? $prev[0] : $prev;
            $isClassPosition = (\is_array($next) && \in_array($next[0], [\T_DOUBLE_COLON, \T_VARIABLE, \T_ELLIPSIS], true))
                || \in_array($prevId, [\T_NEW, \T_INSTANCEOF, \T_EXTENDS, \T_IMPLEMENTS, \T_ATTRIBUTE], true)
                || ($prev === ':' && ($t[$i - 2] ?? null) === ')')
                || ($prev === '?' && ($t[$i - 2] ?? null) === ':')
                || ($prev === ',' && self::inHeritageList($t, $i));
            if (!$isClassPosition || self::inImport($t, $i)) {
                continue;
            }
            $fqn = $resolve($tok);
            if ($fqn === null) {
                continue;
            }
            if (\str_starts_with($fqn, 'SugarCraft\\')) {
                if (self::type($root, $fqn) === null) {
                    $error("{$fqn} does not exist");
                }
                continue;
            }
            if ($tok[0] === \T_STRING && !self::isBuiltin($fqn) && isset(self::shortNames($root)[$tok[1]])) {
                $error("{$tok[1]} is used without an import (" . \implode(' or ', \array_slice(self::shortNames($root)[$tok[1]], 0, 3)) . ')');
            }
        }

        // Calls, constants and instance chains.
        $vars = [];
        $done = [];
        $argType = null;
        $chain = static function (int $i, bool $seeded = false, ?string $seedType = null) use (&$vars, &$done, &$argType, $t, $n, $root, $resolve, $error): ?array {
            $done[$i] = true;
            $tok = $t[$i];
            $type = null;
            $end = $i;
            if ($seeded) {
                $type = $seedType;
            } elseif (\is_array($tok) && \in_array($tok[0], [\T_NEW, \T_ATTRIBUTE], true)) {
                // `#[Attr(...)]` constructs Attr exactly as `new Attr(...)` would.
                $name = $t[$i + 1] ?? null;
                if (!self::isName($name)) {
                    return null;
                }
                $fqn = $resolve($name);
                $end = $i + 1;
                if ($fqn !== null && (\str_starts_with($fqn, 'SugarCraft\\') || self::isBuiltin($fqn))) {
                    $type = $fqn;
                    $info = \str_starts_with($fqn, 'SugarCraft\\') ? self::type($root, $fqn) : null;
                    if ($tok[0] === \T_NEW && $info !== null && $info['abstract']) {
                        $error("{$fqn} is {$info['kind']}" . ($info['kind'] === 'class' ? ' (abstract)' : '') . ' and cannot be instantiated');
                    }
                    if (($t[$i + 2] ?? null) === '(') {
                        $end = self::matching($t, $i + 2);
                        $ctor = self::findMethod($root, $fqn, '__construct');
                        if ($ctor['status'] === 'found' && $tok[0] === \T_NEW && !$ctor['method']['public']) {
                            $error(self::short($fqn) . '::__construct() is not public; construct it through its factory');
                        }
                        if ($ctor['status'] === 'found') {
                            self::checkArgs($t, $i + 2, $end, $ctor['method'], self::short($fqn) . '::__construct()', $error, $root, $ctor['owner'], $argType);
                        }
                    }
                } elseif (($t[$i + 2] ?? null) === '(') {
                    $end = self::matching($t, $i + 2);
                }
            } elseif (self::isName($tok) && \is_array($t[$i + 1] ?? null) && $t[$i + 1][0] === \T_DOUBLE_COLON) {
                $fqn = $resolve($tok);
                $member = $t[$i + 2] ?? null;
                $end = $i + 2;
                if ($fqn === null || !(\str_starts_with($fqn, 'SugarCraft\\') || self::isBuiltin($fqn)) || !self::isIdent($member) || \strtolower($member[1]) === 'class') {
                    if (self::isIdent($member) && ($t[$i + 3] ?? null) === '(') {
                        $end = self::matching($t, $i + 3);
                    }
                } elseif (($t[$i + 3] ?? null) === '(') {
                    $end = self::matching($t, $i + 3);
                    $found = self::findMethod($root, $fqn, $member[1]);
                    $label = self::short($fqn) . '::' . $member[1] . '()';
                    if ($found['status'] === 'missing') {
                        $error(self::short($fqn) . " has no method {$member[1]}() ({$fqn})");
                    } elseif ($found['status'] === 'found') {
                        if (!$found['method']['static']) {
                            $error("{$label} is not static");
                        } elseif (!$found['method']['public']) {
                            $error("{$label} is not public");
                        }
                        self::checkArgs($t, $i + 3, $end, $found['method'], $label, $error, $root, $found['owner'], $argType);
                        $type = self::returnType($root, $found['method']['return'], $found['owner'], $fqn);
                    }
                } else {
                    $status = self::findConstant($root, $fqn, $member[1]);
                    if ($status === 'missing') {
                        $error(self::short($fqn) . " has no constant or case {$member[1]} ({$fqn})");
                    } elseif ($status === 'found') {
                        $info = \str_starts_with($fqn, 'SugarCraft\\') ? self::type($root, $fqn) : null;
                        $type = $info !== null && $info['kind'] === 'enum' ? $fqn : null;
                    }
                }
            } elseif (\is_array($tok) && $tok[0] === \T_VARIABLE) {
                $type = $vars[$tok[1]] ?? null;
            } else {
                return null;
            }

            // Postfix: ->method(...) / ?->method(...) / ->property
            $j = $end + 1;
            while ($j < $n && \is_array($t[$j]) && \in_array($t[$j][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $member = $t[$j + 1] ?? null;
                if (!self::isIdent($member)) {
                    return ['type' => null, 'end' => $end];
                }
                if (($t[$j + 2] ?? null) !== '(') {
                    $type = null;
                    $end = $j + 1;
                    $j = $end + 1;
                    continue;
                }
                $close = self::matching($t, $j + 2);
                if ($type !== null) {
                    $found = self::findMethod($root, $type, $member[1]);
                    $label = self::short($type) . '::' . $member[1] . '()';
                    if ($found['status'] === 'missing') {
                        $error(self::short($type) . " has no method {$member[1]}() ({$type})");
                        $type = null;
                    } elseif ($found['status'] === 'found') {
                        if (!$found['method']['public']) {
                            $error("{$label} is not public");
                        }
                        self::checkArgs($t, $j + 2, $close, $found['method'], $label, $error, $root, $found['owner'], $argType);
                        $type = self::returnType($root, $found['method']['return'], $found['owner'], $type);
                    } else {
                        $type = null;
                    }
                }
                $end = $close;
                $j = $end + 1;
            }

            return ['type' => $type, 'end' => $end];
        };

        // `(new X(...))->chain` — the parenthesised form the samples use for one-liners.
        $expr = static function (int $i) use ($chain, &$done, $t): ?array {
            // A '(' straight after a name, variable or ')' opens an argument
            // list, not a grouping: `->use(new Logger(...))->serve()` must not
            // hand Logger to serve().
            $before = $t[$i - 1] ?? null;
            $grouping = !(self::isIdent($before) || self::isName($before) || $before === ')' || $before === ']'
                || (\is_array($before) && $before[0] === \T_VARIABLE));
            if ($grouping && ($t[$i] ?? null) === '(' && \is_array($t[$i + 1] ?? null) && $t[$i + 1][0] === \T_NEW) {
                $inner = $chain($i + 1);
                if ($inner === null || ($t[$inner['end'] + 1] ?? null) !== ')') {
                    return $inner;
                }
                // Continue the member chain after the closing paren with the inner type.
                return $chain($inner['end'] + 1, true, $inner['type']);
            }
            if (!isset($t[$i]) || isset($done[$i])) {
                return null;
            }

            return $chain($i);
        };

        // The type of one call argument, when it is a single expression the
        // chain walker can type (a variable, `new X`, `X::f()`, a fluent chain).
        $argType = static function (int $start, int $stop) use ($expr): ?string {
            $r = $expr($start);

            return $r !== null && $r['end'] + 1 === $stop ? $r['type'] : null;
        };

        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            if (\is_array($tok) && $tok[0] === \T_VARIABLE && ($t[$i + 1] ?? null) === '=' && !(\is_array($t[$i - 1] ?? null) && \in_array($t[$i - 1][0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true))) {
                $done[$i] = true;
                $rhs = $expr($i + 2);
                if ($rhs !== null && ($t[$rhs['end'] + 1] ?? null) === ';' && $rhs['type'] !== null) {
                    $vars[$tok[1]] = $rhs['type'];
                } else {
                    unset($vars[$tok[1]]);
                }
                continue;
            }
            if (isset($done[$i])) {
                continue;
            }
            $expr($i);
        }

        return \array_values($errors);
    }

    /**
     * Every method a SugarCraft interface (and the interfaces it extends) declares.
     *
     * @return array<string, string> lower-cased name => declared name
     */
    private static function interfaceMethods(string $root, string $fqn, int $guard = 0): array
    {
        $info = \str_starts_with($fqn, 'SugarCraft\\') ? self::type($root, $fqn) : null;
        if ($info === null || $info['kind'] !== 'interface' || $guard > 20) {
            return [];
        }
        $methods = [];
        foreach ($info['methods'] as $lower => $method) {
            $methods[$lower] = $method['name'];
        }
        foreach ($info['supers'] as $super) {
            $methods += self::interfaceMethods($root, $super, $guard + 1);
        }

        return $methods;
    }

    private static function short(string $fqn): string
    {
        return \substr(\strrchr('\\' . $fqn, '\\'), 1);
    }

    private static function isNamespace(string $root, string $fqn): bool
    {
        foreach (self::shortNames($root) as $fqns) {
            foreach ($fqns as $candidate) {
                if (\str_starts_with($candidate, $fqn . '\\')) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<mixed> $t */
    private static function inImport(array $t, int $i): bool
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if ($t[$k] === ';' || self::isOpen($t[$k]) && !(\is_array($t[$k - 1] ?? null) && $t[$k - 1][0] === \T_NS_SEPARATOR) || $t[$k] === '}') {
                return false;
            }
            if (\is_array($t[$k]) && $t[$k][0] === \T_USE) {
                return ($t[$k - 1] ?? null) !== ')';
            }
        }

        return false;
    }

    /** @param list<mixed> $t */
    private static function inHeritageList(array $t, int $i): bool
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if (self::isName($t[$k]) || $t[$k] === ',') {
                continue;
            }

            return \is_array($t[$k]) && \in_array($t[$k][0], [\T_IMPLEMENTS, \T_EXTENDS], true);
        }

        return false;
    }

    /**
     * Argument names, positional count and, where both sides are known, types.
     *
     * A type is checked only when the parameter declares a single SugarCraft
     * class, interface or enum, and the argument's type is a final SugarCraft
     * class or an enum, so a subclass the source cannot see could never make the
     * call legal. The candy-query sample passed `ConnectionFactory::fromDsn()`'s
     * ConnectionConfig straight to `App::start(DatabaseInterface $db)`, which
     * names and counts alone could not catch.
     *
     * @param list<mixed> $t
     * @param array{params: ?list<string>, types?: ?list<?string>} $method
     * @param (\Closure(int, int): ?string)|null $argType the type of the
     *   expression spanning [start, stop), or null when the sample does not fix it
     */
    private static function checkArgs(array $t, int $open, int $close, array $method, string $label, \Closure $error, string $root = '', ?string $owner = null, ?\Closure $argType = null): void
    {
        if ($method['params'] === null) {
            return;
        }
        $depth = 0;
        $positional = 0;
        $spread = false;
        /** @var list<array{start: int, stop: int, param: ?int}> $args */
        $args = [];
        $current = null;
        for ($k = $open + 1; $k <= $close; $k++) {
            $tok = $t[$k];
            if ($depth === 0 && ($k === $close || $tok === ',')) {
                if ($current !== null) {
                    $current['stop'] = $k;
                    $args[] = $current;
                    $current = null;
                }
                continue;
            }
            // An argument starts right after the opening paren or a top-level comma.
            if ($depth === 0 && ($k === $open + 1 || $t[$k - 1] === ',')) {
                if (self::isIdent($tok) && ($t[$k + 1] ?? null) === ':') {
                    $index = \array_search($tok[1], $method['params'], true);
                    if ($index === false) {
                        $error("{$label} has no parameter \${$tok[1]}");
                    }
                    $current = ['start' => $k + 2, 'stop' => $close, 'param' => $index === false ? null : $index];
                } elseif (\is_array($tok) && $tok[0] === \T_ELLIPSIS) {
                    $spread = true;
                } else {
                    $current = ['start' => $k, 'stop' => $close, 'param' => $spread ? null : $positional];
                    $positional++;
                }
            }
            if ($tok === '(' || $tok === '[' || self::isOpen($tok)) {
                $depth++;
            } elseif ($tok === ')' || $tok === ']' || $tok === '}') {
                $depth--;
            }
        }
        if (!$spread && $positional > \count($method['params'])) {
            $error("{$label} is given {$positional} positional arguments but declares " . \count($method['params']));
        }
        if ($argType === null || $owner === null || ($method['types'] ?? null) === null) {
            return;
        }
        foreach ($args as $arg) {
            if ($arg['param'] === null || !isset($method['params'][$arg['param']])) {
                continue;
            }
            $declared = self::paramType($root, $method['types'][$arg['param']] ?? null, $owner);
            if ($declared === null) {
                continue;
            }
            $actual = $argType($arg['start'], $arg['stop']);
            $info = $actual !== null && \str_starts_with($actual, 'SugarCraft\\') ? self::type($root, $actual) : null;
            if ($info === null || !$info['final']) {
                continue;
            }
            if (self::isA($root, $info['fqn'], $declared) === false) {
                $error("{$label} \${$method['params'][$arg['param']]} must be " . self::short($declared) . ', but is given ' . self::short($info['fqn']) . " ({$info['fqn']})");
            }
        }
    }

    /** The SugarCraft type a declared parameter type names, or null when it is not a single SugarCraft class, interface or enum. */
    private static function paramType(string $root, ?string $declared, string $owner): ?string
    {
        if ($declared === null) {
            return null;
        }
        $type = \ltrim(\trim($declared), '?');
        if ($type === '' || \strpbrk($type, '|&()') !== false) {
            return null;
        }
        $info = self::type($root, $owner);
        if ($info === null) {
            return null;
        }
        $fqn = \strtolower($type) === 'self' ? $info['fqn'] : self::resolveName($type, $info['namespace'], $info['imports']);
        if ($fqn === null || !\str_starts_with($fqn, 'SugarCraft\\')) {
            return null;
        }
        $target = self::type($root, $fqn);

        return $target === null || $target['kind'] === 'trait' ? null : $target['fqn'];
    }

    /** Whether SugarCraft type $sub is $super or extends/implements it; null when an ancestor is out of the source's reach. */
    private static function isA(string $root, string $sub, string $super, int $guard = 0): ?bool
    {
        if (\strcasecmp($sub, $super) === 0) {
            return true;
        }
        if (!\str_starts_with($sub, 'SugarCraft\\')) {
            // A built-in type cannot extend or implement a SugarCraft one.
            return self::isBuiltin($sub) ? false : null;
        }
        $info = self::type($root, $sub);
        if ($info === null || $guard > 20) {
            return null;
        }
        if (\strcasecmp($info['fqn'], $super) === 0) {
            return true;
        }
        $unknown = false;
        foreach ($info['supers'] as $parent) {
            $r = self::isA($root, $parent, $super, $guard + 1);
            if ($r === true) {
                return true;
            }
            $unknown = $unknown || $r === null;
        }

        return $unknown ? null : false;
    }
}
