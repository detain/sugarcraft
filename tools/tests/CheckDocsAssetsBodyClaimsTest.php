<?php

declare(strict_types=1);

/**
 * Guard for the body-claim legs (i)-(k) of `tools/check-docs-assets.php`:
 * class and method names, example files and `.vhs` references that a
 * `docs/_data/<slug>.body.html` names must exist in the tree. Each leg has a
 * passing fixture and a failing fixture, and the failing arm names the
 * offender exactly, so a wrong body claim cannot be waved through by a leg
 * that quietly stopped matching.
 *
 * HOW TO RUN: see the long note at the top of CheckPathReposTest.php.
 */

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../check-docs-assets.php';

final class CheckDocsAssetsBodyClaimsTest extends TestCase
{
    use TmpTree;

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('check-docs-claims-');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testACoherentBodyCarriesNoClaimFindings(): void
    {
        $root = $this->makeFixture();

        $this->assertSame([], $this->claimsFor($root, $this->cleanBody()));
    }

    public function testAMethodNoDeclarationCarriesIsNamed(): void
    {
        $root = $this->makeFixture();

        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names Widget::vanish(), but no declaration of Widget carries that method'],
            $this->claimsFor($root, '<pre><code>Widget::vanish();</code></pre>'),
        );
    }

    public function testAMethodReachedThroughATraitOrAParentIsAccepted(): void
    {
        $root = $this->makeFixture();

        // Gadget declares neither method: stamped() comes from its trait,
        // render() from the parent Widget it extends.
        $this->assertSame([], $this->claimsFor($root, '<pre><code>Gadget::stamped(); Gadget::render();</code></pre>'));
    }

    public function testAClassNoSrcDeclaresIsNamedWhetherCalledOrConstructed(): void
    {
        $root = $this->makeFixture();

        // Both shapes name the same missing class, so it is reported once.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class Phantom, which no <slug>/src declares'],
            $this->claimsFor($root, '<pre><code>Phantom::build(); $p = new Phantom();</code></pre>'),
        );
    }

    public function testAClassMentionedInProseDoesNotExemptItsClaims(): void
    {
        $root = $this->makeFixture();

        // Only code blocks declare illustrative classes; prose saying "class
        // Ghost" must not hide a claim about a class that does not exist.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class Ghost, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>The class Ghost is only mentioned in prose.</p><pre><code>Ghost::boo();</code></pre>'),
        );
    }

    public function testAPhpBuiltInClassIsResolvedNatively(): void
    {
        $root = $this->makeFixture();

        $this->assertSame([], $this->claimsFor($root, '<pre><code>DateTimeImmutable::createFromFormat(\'Y\', \'2026\');</code></pre>'));
    }

    public function testAPhpBuiltInMethodThatDoesNotExistIsNamed(): void
    {
        $root = $this->makeFixture();

        $this->assertSame(
            ["claims: docs/_data/alpha.body.html names DateTimeImmutable::nope(), which PHP's DateTimeImmutable does not define"],
            $this->claimsFor($root, '<pre><code>DateTimeImmutable::nope();</code></pre>'),
        );
    }

    public function testAnEnumsImpliedMethodsAreAcceptedAndTheRestAreNamed(): void
    {
        $root = $this->makeFixture();

        // cases() exists on every enum, from()/tryFrom() on backed ones only.
        $this->assertSame([], $this->claimsFor($root, '<pre><code>Mode::from(\'a\'); Mode::tryFrom(\'a\'); Mode::cases();</code></pre>'));

        $this->assertSame(
            [
                'claims: docs/_data/alpha.body.html names Mode::make(), but no declaration of Mode carries that method',
                'claims: docs/_data/alpha.body.html names Pure::from(), but no declaration of Pure carries that method',
            ],
            $this->claimsFor($root, '<pre><code>Mode::make(); Pure::from(\'x\');</code></pre>'),
        );
    }

    public function testAThirdPartyOrBodyDeclaredNameIsIllustrativeNotAClaim(): void
    {
        $root = $this->makeFixture();

        // A fully qualified name outside SugarCraft\, a name imported from
        // outside it, and a class the snippet declares itself are the reader's
        // or a dependency's code, never a claim about this tree.
        $this->assertSame([], $this->claimsFor($root,
            '<pre><code>use App\Tracker;'
            . "\n" . 'final class Local {}'
            . "\n" . '\React\EventLoop\Loop::get(); new Tracker(); Local::make();</code></pre>',
        ));
    }

    public function testAnExampleFileThatExistsIsAccepted(): void
    {
        $root = $this->makeFixture();

        $this->assertSame([], $this->claimsFor($root,
            '<p>Run <code>php examples/demo.php</code>, <code>./examples/demo.php</code>, or '
            . '<code>https://github.com/sugarcraft/alpha/blob/master/examples/demo.php</code>.</p>',
        ));
    }

    public function testAnExampleFileThatDoesNotExistIsNamedByItsOwningPackage(): void
    {
        $root = $this->makeFixture();

        $this->assertSame([
            'claims: docs/_data/alpha.body.html names alpha/examples/missing.php, which does not exist',
            'claims: docs/_data/alpha.body.html names alpha/examples/nope.php, which does not exist',
            'claims: docs/_data/alpha.body.html names beta/examples/gone.php, which does not exist',
        ], $this->claimsFor($root,
            '<p><code>php examples/missing.php</code>, '
            . '<code>https://github.com/sugarcraft/alpha/blob/master/examples/nope.php</code>, '
            . '<code>beta/examples/gone.php</code>.</p>',
        ));
    }

    public function testAVhsReferenceThatExistsIsAccepted(): void
    {
        $root = $this->makeFixture();

        $this->assertSame([], $this->claimsFor($root,
            '<p>See <code>.vhs/demo.tape</code>, '
            . '<code>https://github.com/sugarcraft/alpha/blob/master/.vhs/demo.tape</code> and '
            . '<code>https://raw.githubusercontent.com/detain/sugarcraft/master/alpha/.vhs/demo.gif</code>.</p>',
        ));
    }

    public function testAVhsReferenceThatDoesNotExistIsNamed(): void
    {
        $root = $this->makeFixture();

        $this->assertSame([
            'vhs: docs/_data/alpha.body.html names alpha/.vhs/ghost.tape, which does not exist',
            'vhs: docs/_data/alpha.body.html names beta/.vhs/x.gif, which does not exist',
        ], $this->claimsFor($root,
            '<p><code>https://github.com/sugarcraft/alpha/blob/master/.vhs/ghost.tape</code> and '
            . '<code>https://raw.githubusercontent.com/detain/sugarcraft/master/beta/.vhs/x.gif</code>.</p>',
        ));
    }

    public function testTheRepositoryBodiesPassEveryClaimLeg(): void
    {
        // The drift pin: a body edit that names a class, method, example or tape
        // the tree does not carry reddens this file, not the published page.
        $this->assertSame([], \CheckDocsAssets::bodyClaims(\dirname(__DIR__, 2)));
    }

    /** @return list<string> the claim findings for one body written into the fixture */
    private function claimsFor(string $root, string $html): array
    {
        $this->put("$root/docs/_data/alpha.body.html", '<html><body>' . $html . '</body></html>');

        return \CheckDocsAssets::bodyClaims($root);
    }

    /**
     * A fixture tree with one package, alpha, that has a class, a trait-using
     * subclass, a backed and a pure enum, an example and a tape; the body the
     * tests write names only what exists here.
     */
    private function makeFixture(): string
    {
        $root = $this->tmpDir;
        $this->put("$root/alpha/src/Widget.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            class Widget
            {
                public static function make(): self
                {
                    return new self();
                }

                public function render(): string
                {
                    return '';
                }
            }

            PHP);
        $this->put("$root/alpha/src/Concerns/Stamp.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha\Concerns;

            trait Stamp
            {
                public function stamped(): string
                {
                    return 'x';
                }
            }

            PHP);
        $this->put("$root/alpha/src/Gadget.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            use SugarCraft\Alpha\Concerns\Stamp;

            final class Gadget extends Widget
            {
                use Stamp;
            }

            PHP);
        $this->put("$root/alpha/src/Mode.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            enum Mode: string
            {
                case A = 'a';
            }

            PHP);
        $this->put("$root/alpha/src/Pure.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            enum Pure
            {
                case X;
            }

            PHP);
        $this->put("$root/alpha/examples/demo.php", "<?php\necho 1;\n");
        $this->put("$root/alpha/.vhs/demo.tape", "Output demo.gif\n");
        $this->put("$root/alpha/.vhs/demo.gif", 'x');

        return $root;
    }

    private function cleanBody(): string
    {
        return '<p>Build a <code>Widget</code> with <code>Widget::make()</code>, then '
            . '<code>Gadget::stamped()</code> and <code>Gadget::render()</code>.</p>'
            . '<pre class="code"><code class="language-php">use SugarCraft\Alpha\Gadget;'
            . "\n" . 'use SugarCraft\Alpha\Mode;'
            . "\n" . '$gadget = new Gadget();'
            . "\n" . '$mode = Mode::from(\'a\');'
            . "\n" . '$all = Mode::cases();'
            . '</code></pre>'
            . '<p>Run <code>php examples/demo.php</code> and see <code>.vhs/demo.tape</code>.</p>';
    }

    private function put(string $path, string $contents): void
    {
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException("could not create directory for $path");
        }
        file_put_contents($path, $contents);
    }
}
