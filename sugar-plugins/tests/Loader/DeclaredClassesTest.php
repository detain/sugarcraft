<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Loader;

use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Loader\DeclaredClasses;
use SugarCraft\Plugins\Tests\Support\Plugins;
use SugarCraft\Plugins\Tests\Support\Scratch;

final class DeclaredClassesTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            Scratch::remove($dir);
        }
        $this->dirs = [];
    }

    /** Write $source to a fresh file and return its realpath. */
    private function file(string $source): string
    {
        $dir = Scratch::dir('declared');
        $this->dirs[] = $dir;

        return (string) realpath(Scratch::put($dir . '/Impl.php', $source));
    }

    public function testItListsEveryNamedTypeWithItsNamespace(): void
    {
        $path = $this->file(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Alpha\One {
                final class Greeter {}
                interface Speaks {}
                trait Hums {}
                enum Mood: string { case Up = 'up'; }
            }

            namespace Beta {
                abstract class Base {}
            }
            PHP);

        $this->assertSame(
            ['Alpha\One\Greeter', 'Alpha\One\Speaks', 'Alpha\One\Hums', 'Alpha\One\Mood', 'Beta\Base'],
            DeclaredClasses::of($path),
        );
    }

    public function testAnonymousClassesClassConstantsAndRelativeNamesAreNotDeclarations(): void
    {
        $path = $this->file(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Gamma;

            $anon = new class {};
            $name = \stdClass::class;
            $relative = namespace\helper();

            function helper(): string
            {
                return Greeter::class;
            }
            PHP);

        $this->assertSame([], DeclaredClasses::of($path));
    }

    public function testAMissingFileDeclaresNothingAndCollidesWithNothing(): void
    {
        $missing = '/no/such/plugin/Impl.php';

        $this->assertSame([], DeclaredClasses::of($missing));
        $this->assertNull(DeclaredClasses::declaredElsewhere($missing));
    }

    public function testAFreeNameIsNotReportedAsDeclaredElsewhere(): void
    {
        $path = $this->file(Plugins::pluginSource('PluginFixtures\\' . Plugins::tag(), 'Free', 'free'));

        $this->assertNull(DeclaredClasses::declaredElsewhere($path));
    }

    public function testAFileMayRedeclareItsOwnTypeWhenItIsAlreadyLoadedFromThatPath(): void
    {
        $path = $this->file(Plugins::pluginSource('PluginFixtures\\' . Plugins::tag(), 'Own', 'own'));
        require_once $path;

        $this->assertNull(DeclaredClasses::declaredElsewhere($path));
    }

    public function testAnEngineTypeOfTheSameNameIsReportedAsDeclaredElsewhere(): void
    {
        $path = $this->file("<?php\n\ndeclare(strict_types=1);\n\nfinal class stdClass {}\n");

        $this->assertSame('stdClass', DeclaredClasses::declaredElsewhere($path));
    }

    public function testItListsEveryGlobalFunctionButNoMethodOrClosure(): void
    {
        $path = $this->file(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Alpha {
                function one(): void {}
                function &byRef(): int { return $x; }
            }

            namespace Beta;

            function two(): void {}

            const NOT_A_FUNCTION = 1;

            if (!function_exists('Beta\\guarded')) {
                function guarded(): void {}
            }

            final class Holder
            {
                public function method(): void {}

                public function viaClosure(): \Closure
                {
                    return function (): void {};
                }
            }

            $closure = function (): void {};
            PHP);

        $this->assertSame(['Alpha\one', 'Alpha\byRef', 'Beta\two', 'Beta\guarded'], DeclaredClasses::functionsOf($path));
        $this->assertSame(['Beta\Holder'], DeclaredClasses::of($path));
    }

    public function testAFunctionNestedInAPlainBlockRedeclaringAnEngineFunctionIsReportedBeforeRequire(): void
    {
        // The engine's strlen() is not a file, so the nested declaration would fatal
        // at require time with no catchable error. It must be refused before that.
        $path = $this->file("<?php\n\ndeclare(strict_types=1);\n\nif (true) {\n    function strlen(string \$s): int { return 0; }\n}\n");

        $this->assertSame(['strlen'], DeclaredClasses::functionsOf($path));
        $this->assertSame('strlen', DeclaredClasses::declaredElsewhere($path));
    }

    public function testAFunctionNestedInAnotherFunctionsBodyIsListed(): void
    {
        $path = $this->file("<?php\n\ndeclare(strict_types=1);\n\nfunction outer(): void\n{\n    function inner(): void {}\n}\n");

        $this->assertSame(['outer', 'inner'], DeclaredClasses::functionsOf($path));
    }

    public function testAMethodNamedAsAnEngineFunctionIsNotAGlobalCollision(): void
    {
        $path = $this->file("<?php\n\ndeclare(strict_types=1);\n\nfinal class Shouter\n{\n    public function strlen(string \$s): int { return 0; }\n}\n");

        $this->assertSame([], DeclaredClasses::functionsOf($path));
        $this->assertNull(DeclaredClasses::declaredElsewhere($path));
    }

    public function testAnonymousClassMethodsStayOutOfTheGlobalListAfterAClosureArgument(): void
    {
        // The closure's brace sits inside the argument list, so it is not the body
        // brace; the anonymous class's own methods must stay out of the global list.
        $path = $this->file(<<<'PHP'
            <?php

            declare(strict_types=1);

            $obj = new class(function () { return 1; }) {
                public function strlen(): int { return 0; }
            };
            PHP);

        $this->assertSame([], DeclaredClasses::functionsOf($path));
        $this->assertNull(DeclaredClasses::declaredElsewhere($path));
    }

    public function testAFunctionAnotherFileDeclaresIsReportedBeforeRequireWouldFatal(): void
    {
        $ns = 'PluginFixtures\\' . Plugins::tag();
        $first = $this->file("<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\nfunction helper(): void {}\n");
        require_once $first;
        $second = $this->file("<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\nfunction helper(): void {}\n");

        $this->assertSame($ns . '\\helper', DeclaredClasses::declaredElsewhere($second));
        $this->assertNull(DeclaredClasses::declaredElsewhere($first), 'a file may keep its own function');
    }

    public function testAnEngineFunctionOfTheSameNameIsReportedAsDeclaredElsewhere(): void
    {
        $path = $this->file("<?php\n\ndeclare(strict_types=1);\n\nfunction str_repeat(): void {}\n");

        $this->assertSame('str_repeat', DeclaredClasses::declaredElsewhere($path));
    }
}
