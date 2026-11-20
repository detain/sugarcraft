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

    public function testAnEnumClaimWrittenInAnotherCaseKeepsItsImpliedMethods(): void
    {
        $root = $this->makeFixture();

        // PHP class names are case-insensitive, so MODE is the enum Mode: its implied
        // from() and cases() must still be found when the qualified claim is not in
        // the declaration's case. The non-implied method is still named.
        $this->assertSame([], $this->claimsFor($root,
            '<pre><code>\\SugarCraft\\Alpha\\MODE::from(\'a\'); \\SugarCraft\\Alpha\\MODE::tryFrom(\'a\'); \\SugarCraft\\Alpha\\PURE::cases();</code></pre>',
        ));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names SugarCraft\Alpha\MODE::make(), but no declaration of SugarCraft\Alpha\MODE carries that method'],
            $this->claimsFor($root, '<pre><code>\\SugarCraft\\Alpha\\MODE::make();</code></pre>'),
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

    public function testANestedOrOtherExtensionVhsReferenceIsChecked(): void
    {
        $root = $this->makeFixture();

        // A subdirectory and a non-tape, non-gif file are claims too: the leg
        // does not stop at a flat tape-or-gif name.
        $this->assertSame([
            'vhs: docs/_data/alpha.body.html names alpha/.vhs/missing/x.gif, which does not exist',
            'vhs: docs/_data/alpha.body.html names alpha/.vhs/nothere.png, which does not exist',
        ], $this->claimsFor($root,
            '<p><code>alpha/.vhs/missing/x.gif</code> and <code>.vhs/nothere.png</code>.</p>',
        ));
    }

    public function testAQualifiedClaimIsCheckedAgainstItsOwnNamespace(): void
    {
        $root = $this->makeFixture();

        // Widget exists, but only as SugarCraft\Alpha\Widget.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class SugarCraft\Beta\Widget, which no <slug>/src declares'],
            $this->claimsFor($root, '<pre><code>\SugarCraft\Beta\Widget::make();</code></pre>'),
        );
    }

    public function testAQualifiedClaimIsNotSatisfiedByASameNamedClassElsewhere(): void
    {
        $root = $this->makeFixture();
        $this->putBetaWidget($root);

        // beta's Widget carries vanish(); alpha's does not, and the claim names alpha's.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names SugarCraft\Alpha\Widget::vanish(), but no declaration of SugarCraft\Alpha\Widget carries that method'],
            $this->claimsFor($root, '<pre><code>\SugarCraft\Alpha\Widget::vanish();</code></pre>'),
        );
    }

    public function testAnImportedNameResolvesToTheClassItImports(): void
    {
        $root = $this->makeFixture();
        $this->putBetaWidget($root);

        $this->assertSame([], $this->claimsFor($root, "<pre><code>use SugarCraft\\Beta\\Widget;\nWidget::vanish();</code></pre>"));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names SugarCraft\Alpha\Widget::vanish(), but no declaration of SugarCraft\Alpha\Widget carries that method'],
            $this->claimsFor($root, "<pre><code>use SugarCraft\\Alpha\\Widget;\nWidget::vanish();</code></pre>"),
        );
    }

    public function testABodyImportingOneShortNameFromTwoClassesIsCheckedAgainstEither(): void
    {
        $root = $this->makeFixture();
        // alpha's Gadget\Widget carries vanish(); the plain Widget does not. The
        // body imports both under the short name Widget in two snippets.
        $this->put("$root/alpha/src/Gadget/Widget.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha\Gadget;

            final class Widget
            {
                public static function vanish(): void
                {
                }
            }

            PHP);

        $this->assertSame([], $this->claimsFor($root,
            '<pre><code>use SugarCraft\\Alpha\\Widget;</code></pre>'
            . '<pre><code>use SugarCraft\\Alpha\\Gadget\\Widget;' . "\n" . 'Widget::vanish();</code></pre>',
        ));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names Widget::nope(), but no declaration of Widget carries that method'],
            $this->claimsFor($root,
                '<pre><code>use SugarCraft\\Alpha\\Widget;</code></pre>'
                . '<pre><code>use SugarCraft\\Alpha\\Gadget\\Widget;' . "\n" . 'Widget::nope();</code></pre>',
            ),
        );
    }

    public function testAWronglyCasedSugarcraftNamespaceIsStillChecked(): void
    {
        $root = $this->makeFixture();

        // PHP namespaces are case-insensitive, so this names alpha's Widget, which
        // has no vanish(); it must be checked rather than exempted as third-party.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names Sugarcraft\Alpha\Widget::vanish(), but no declaration of Sugarcraft\Alpha\Widget carries that method'],
            $this->claimsFor($root, '<pre><code>\Sugarcraft\Alpha\Widget::vanish();</code></pre>'),
        );
        $this->assertSame([], $this->claimsFor($root, '<pre><code>\sugarcraft\alpha\Widget::make();</code></pre>'));
    }

    public function testAFilesystemPlaceholderPathIsNotAClaim(): void
    {
        $root = $this->makeFixture();

        // /path/to/... is the reader's own checkout, not a path in this tree.
        $this->assertSame([], $this->claimsFor($root,
            '<p><code>sh /path/to/examples/nothere.sh</code> and <code>/path/to/.vhs/ghost.tape</code>.</p>',
        ));
    }

    public function testATopLevelImportDoesNotGiveAClassTheImportedMethods(): void
    {
        $root = $this->makeFixture();

        // Plain imports Widget for a type hint and extends nothing: it carries no
        // make(), and PHP would fatal on Plain::make().
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names Plain::make(), but no declaration of Plain carries that method'],
            $this->claimsFor($root, '<pre><code>Plain::make();</code></pre>'),
        );
    }

    public function testEveryTraitInACommaListIsFollowed(): void
    {
        $root = $this->makeFixture();

        // Both uses two traits in one statement; vanish() comes from the second.
        $this->assertSame([], $this->claimsFor($root, '<pre><code>Both::stamped(); Both::vanish();</code></pre>'));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names Both::nope(), but no declaration of Both carries that method'],
            $this->claimsFor($root, '<pre><code>Both::nope();</code></pre>'),
        );
    }

    public function testAThirdPartyUrlIsNotAClaimAboutALocalPackage(): void
    {
        $root = $this->makeFixture();

        // The owner is someone else, so no package here can hold these paths.
        $this->assertSame([], $this->claimsFor($root,
            '<p><code>https://github.com/someone/else/blob/main/examples/x.py</code> and '
            . '<code>https://raw.githubusercontent.com/someone/else/main/.vhs/x.gif</code>.</p>',
        ));
    }

    public function testAMethodInheritedFromAPhpBuiltInParentIsAccepted(): void
    {
        $root = $this->makeFixture();

        // Err extends PHP's RuntimeException, so getMessage() is inherited; nope() is nowhere.
        $this->assertSame([], $this->claimsFor($root, '<pre><code>Err::getMessage();</code></pre>'));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names Err::nope(), but no declaration of Err carries that method'],
            $this->claimsFor($root, '<pre><code>Err::nope();</code></pre>'),
        );
    }

    public function testDeriveRunsTheBodyClaimsLeg(): void
    {
        $root = $this->makeFixture();
        $this->put("$root/docs/_data/alpha.body.html", '<html><body><pre><code>Widget::vanish();</code></pre></body></html>');

        // A leg that bodyClaims() carries but derive() never calls would stay
        // green on every tree; the finding must come out of derive() itself.
        $this->assertContains(
            'claims: docs/_data/alpha.body.html names Widget::vanish(), but no declaration of Widget carries that method',
            \CheckDocsAssets::derive($root),
        );
    }

    public function testTheRepositoryBodiesPassEveryClaimLegAndTheLegsScannedThem(): void
    {
        // The drift pin: a body edit that names a class, method, example or tape
        // the tree does not carry reddens this file, not the published page.
        // The census floor keeps an empty findings list from passing on a tree the
        // legs never read (RT-20): the real corpus has 69 bodies and ~1700 claims.
        $census = [];
        $this->assertSame([], \CheckDocsAssets::bodyClaims(\dirname(__DIR__, 2), $census));
        $this->assertGreaterThanOrEqual(60, $census['bodies']);
        $this->assertGreaterThanOrEqual(1500, $census['claims']);
    }

    public function testAnAliasedImportResolvesToTheClassItBinds(): void
    {
        $root = $this->makeFixture();

        // `use X as W` binds W to X: a claim through W is checked against X, and a
        // third-party alias is illustrative, exactly as an unaliased import is.
        $this->assertSame([], $this->claimsFor($root, "<pre><code>use SugarCraft\\Alpha\\Widget as W;\nW::make();</code></pre>"));
        $this->assertSame([], $this->claimsFor($root, "<pre><code>use App\\Ext as E;\nE::make();</code></pre>"));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names SugarCraft\Alpha\Widget::nope(), but no declaration of SugarCraft\Alpha\Widget carries that method'],
            $this->claimsFor($root, "<pre><code>use SugarCraft\\Alpha\\Widget as W;\nW::nope();</code></pre>"),
        );
    }

    public function testAConstructorInProseIsNotAClaimButOneInCodeIs(): void
    {
        $root = $this->makeFixture();

        // "a new Session" in running text names no class the body uses; the same
        // shape inside a code block is a claim.
        $this->assertSame([], $this->claimsFor($root, '<p>Each new Session (opened by the loop) keeps its own state.</p>'));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class Session, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>Each new Session (opened by the loop).</p><pre><code>$s = new Session();</code></pre>'),
        );
    }

    public function testAPrivateOrProtectedMethodDoesNotSatisfyAStaticCall(): void
    {
        $root = $this->makeFixture();
        $this->put("$root/alpha/src/Vault.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            final class Vault
            {
                public static function open(): void
                {
                }

                private static function secret(): void
                {
                }

                protected function guard(): void
                {
                }
            }

            PHP);

        // PHP fatals on Vault::secret() and Vault::guard() from outside the class,
        // so a body naming them as API is wrong; the public open() is not.
        $this->assertSame([], $this->claimsFor($root, '<pre><code>Vault::open();</code></pre>'));
        $this->assertSame(
            [
                'claims: docs/_data/alpha.body.html names Vault::secret(), but no declaration of Vault carries that method',
                'claims: docs/_data/alpha.body.html names Vault::guard(), but no declaration of Vault carries that method',
            ],
            $this->claimsFor($root, '<pre><code>Vault::secret(); Vault::guard();</code></pre>'),
        );
    }

    public function testAnImportedSugarcraftNameIsCheckedEvenWhenAnotherSnippetDeclaresTheShortName(): void
    {
        $root = $this->makeFixture();

        // Snippet 1 imports alpha's Widget; snippet 2 declares its own Widget. The
        // claim in snippet 3 means the imported class, so vanish() is not carried.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names SugarCraft\Alpha\Widget::vanish(), but no declaration of SugarCraft\Alpha\Widget carries that method'],
            $this->claimsFor($root,
                '<pre><code>use SugarCraft\\Alpha\\Widget;</code></pre>'
                . '<pre><code>final class Widget {}</code></pre>'
                . '<pre><code>Widget::vanish();</code></pre>',
            ),
        );
        $this->assertSame([], $this->claimsFor($root,
            '<pre><code>use SugarCraft\\Alpha\\Widget;</code></pre>'
            . '<pre><code>final class Widget {}</code></pre>'
            . '<pre><code>Widget::make();</code></pre>',
        ));
    }

    public function testABareUnknownClassNameInACodeSpanIsNamed(): void
    {
        $root = $this->makeFixture();

        // A PascalCase name alone in <code> is a class claim, as a Name::method() is.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class PhantomWidget, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>Pass a <code>PhantomWidget</code> to the loop.</p>'),
        );
    }

    public function testAFullyQualifiedSugarcraftNameAloneInACodeSpanIsChecked(): void
    {
        $root = $this->makeFixture();

        // A qualified SugarCraft name written alone is a class claim too: a wrong
        // one is named, with or without a leading backslash, and in any case.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class SugarCraft\Alpha\Nope, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>Use <code>SugarCraft\\Alpha\\Nope</code>.</p>'),
        );
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class sugarcraft\Alpha\Nope, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>Use <code>\\sugarcraft\\Alpha\\Nope</code>.</p>'),
        );
        $this->assertSame([], $this->claimsFor($root, '<p>Use <code>\\SugarCraft\\Alpha\\Widget</code>.</p>'));
        // A namespace the monorepo declares classes under is a place, not a class.
        $this->assertSame([], $this->claimsFor($root, '<p>Contracts live under <code>SugarCraft\\Alpha\\Concerns</code>.</p>'));
    }

    public function testARelativeQualifiedNameAloneInACodeSpanIsNotChecked(): void
    {
        $root = $this->makeFixture();

        // A relative span is resolved against the body's own namespace, which the
        // guard does not do; it is left unchecked rather than checked wrongly.
        $this->assertSame([], $this->claimsFor($root, '<p>See <code>Util\\Nope</code>.</p>'));
    }

    public function testABareKnownClassNameInACodeSpanIsAccepted(): void
    {
        $root = $this->makeFixture();
        $this->put("$root/alpha/src/SpinnerWidget.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            final class SpinnerWidget
            {
            }

            PHP);

        $this->assertSame([], $this->claimsFor($root, '<p>Pass a <code>SpinnerWidget</code> to the loop.</p>'));
    }

    public function testANonClassNameInACodeSpanIsNotChecked(): void
    {
        $root = $this->makeFixture();

        // Test classes, key names and environment variables are not library classes.
        $this->assertSame([], $this->claimsFor($root,
            '<p>Press <code>PageUp</code>, set <code>ComSpec</code>, see <code>AlphaWidgetTest</code>, '
            . 'and read <code>SUGAR_DEBUG_LOG</code>.</p>',
        ));
    }

    public function testABareHostOnlyExtensionClassNameIsNamedWhetherOrNotTheHostLoadsIt(): void
    {
        $root = $this->makeFixture();

        // Imagick and Redis come from PECL extensions. A bare span must not pass or
        // fail on whether the machine running the guard has them loaded, so the
        // verdict is the same on every host: neither is an allowlisted core name.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class Imagick, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>Load the <code>Imagick</code> image.</p>'),
        );
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class Redis, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>Cache with <code>Redis</code>.</p>'),
        );
    }

    public function testABareAllowlistedPhpCoreClassNameIsAccepted(): void
    {
        $root = $this->makeFixture();

        // PHP core and SPL names a body may write alone, with no SugarCraft declaration.
        $this->assertSame([], $this->claimsFor($root,
            '<p>Throws a <code>LogicException</code>, a <code>ValueError</code> or a '
            . '<code>JsonException</code>; <code>DateTimeImmutable</code> and <code>Countable</code> too.</p>',
        ));
    }

    public function testAnEnumKeywordInProseDoesNotMakeAClassAnEnum(): void
    {
        $root = $this->makeFixture();
        // Plain is a final class; its docblock prose reads "the enum plain: string",
        // which must not pass as a backed enum declaration, so from() is named.
        $this->put("$root/alpha/src/Plain.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            /**
             * Keeps the state, where the enum plain: string mapping is documented.
             */
            final class Plain
            {
            }

            PHP);

        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names SugarCraft\Alpha\Plain::from(), but no declaration of SugarCraft\Alpha\Plain carries that method'],
            $this->claimsFor($root, '<pre><code>\\SugarCraft\\Alpha\\Plain::from(\'x\');</code></pre>'),
        );
    }

    public function testEveryCommonPhpCoreClassNameAloneIsAccepted(): void
    {
        $root = $this->makeFixture();

        // Names a body writes bare that PHP always defines; a host-loaded class
        // must never turn a bare span into an undeclared-class finding.
        $names = [
            'RuntimeException', 'InvalidArgumentException', 'Exception', 'Closure', 'DateTime',
            'stdClass', 'ArrayIterator', 'Generator', 'Traversable', 'ArrayAccess', 'Attribute',
            'UnitEnum', 'Fiber', 'DomainException', 'LengthException', 'JsonSerializable',
        ];
        $spans = implode(' ', array_map(static fn (string $n): string => "<code>$n</code>", $names));

        $this->assertSame([], $this->claimsFor($root, "<p>$spans</p>"));
    }

    public function testAThirdPartyOrUpstreamNameInACodeSpanIsNotChecked(): void
    {
        $root = $this->makeFixture();

        // Upstream Go identifiers, PHP package interfaces and a TypeScript component
        // are named as what they are, not as classes this monorepo should declare.
        $this->assertSame([], $this->claimsFor($root,
            '<p>Use <code>LoggerInterface</code>, <code>StreamSelectLoop</code> and <code>RecordOnly</code>; '
            . 'the <code>DiffView</code> component, a <code>HIcd</code> row and <code>JetBrainsMono</code>.</p>',
        ));
    }

    public function testAQuotedWordInSourceDoesNotExcuseAPhantomClassName(): void
    {
        $root = $this->makeFixture();
        // A quoted word is a string in a source file, not a registered name: the
        // phantom class in the body must still be named.
        $this->put("$root/alpha/src/Labels.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            final class Labels
            {
                public function title(): string
                {
                    return 'ResizeMsg';
                }
            }

            PHP);

        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class ResizeMsg, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>Re-solve on every <code>ResizeMsg</code>.</p>'),
        );
    }

    public function testARegisteredToolNameInSourceIsNotAClassClaim(): void
    {
        $root = $this->makeFixture();
        // A built-in tool registers its name as NAME and as the attribute name; a
        // body naming the tool in a code span is naming a registered name.
        $this->put("$root/alpha/src/AskUserTool.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            #[BuiltInTool(name: 'PlanExit')]
            final class AskUserTool
            {
                public const NAME = 'AskUser';
            }

            PHP);

        $this->assertSame([], $this->claimsFor($root,
            '<p>The agent calls <code>AskUser</code> and <code>PlanExit</code> to ask.</p>',
        ));
    }

    public function testAPackageNameInACodeSpanIsNotAClassClaim(): void
    {
        $root = $this->makeFixture();
        $this->put("$root/alpha/composer.json", '{"name": "sugarcraft/alpha"}');
        $this->put("$root/sugar-spring/composer.json", '{"name": "sugarcraft/sugar-spring"}');

        // SugarSpring names the sugar-spring package, which is not a class here.
        $this->assertSame([], $this->claimsFor($root, '<p>Built on <code>SugarSpring</code>.</p>'));
    }

    public function testAMethodDeclaredOnlyInAnAnonymousClassDoesNotSatisfyAClaim(): void
    {
        $root = $this->makeFixture();
        $this->put("$root/alpha/src/Holder.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            final class Holder
            {
                public function make(): object
                {
                    return new class () {
                        public function ghost(): void
                        {
                        }
                    };
                }
            }

            PHP);

        // ghost() exists only on the anonymous class the method returns, not on Holder.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names Holder::ghost(), but no declaration of Holder carries that method'],
            $this->claimsFor($root, '<pre><code>Holder::ghost();</code></pre>'),
        );
        $this->assertSame([], $this->claimsFor($root, '<pre><code>Holder::make();</code></pre>'));
    }

    public function testAMethodNameIsMatchedCaseInsensitively(): void
    {
        $root = $this->makeFixture();
        $this->put("$root/alpha/src/Foo.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            final class Foo
            {
                public static function makeThing(): void
                {
                }
            }

            PHP);

        // PHP method names are case-insensitive, so the claim in any case holds.
        $this->assertSame([], $this->claimsFor($root, '<pre><code>Foo::MakeThing();</code></pre>'));
        $this->assertSame([], $this->claimsFor($root, '<pre><code>Foo::makething();</code></pre>'));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names Foo::unmakeThing(), but no declaration of Foo carries that method'],
            $this->claimsFor($root, '<pre><code>Foo::unmakeThing();</code></pre>'),
        );
    }

    public function testAClassAliasNameIsDeclaredAndItsMethodsForwardToTheTarget(): void
    {
        $root = $this->makeFixture();
        $this->put("$root/alpha/src/FacadeWidget.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            class_alias(Widget::class, 'SugarCraft\Alpha\FacadeWidget');
            PHP);

        // FacadeWidget is bound by class_alias() to Widget, so it names a real
        // class and carries Widget's methods, but not methods Widget lacks.
        $this->assertSame([], $this->claimsFor($root, '<pre><code>FacadeWidget::make();</code></pre><p>Use <code>FacadeWidget</code>.</p>'));
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names FacadeWidget::nope(), but no declaration of FacadeWidget carries that method'],
            $this->claimsFor($root, '<pre><code>FacadeWidget::nope();</code></pre>'),
        );
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
        // Plain imports Widget at top level but does not extend it, so it carries none of Widget's methods.
        $this->put("$root/alpha/src/Plain.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            use SugarCraft\Alpha\Widget;

            final class Plain
            {
                public function __construct(private Widget $widget)
                {
                }
            }

            PHP);
        $this->put("$root/alpha/src/Concerns/Vanishing.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha\Concerns;

            trait Vanishing
            {
                public static function vanish(): void
                {
                }
            }

            PHP);
        $this->put("$root/alpha/src/Both.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            final class Both
            {
                use \SugarCraft\Alpha\Concerns\Stamp, \SugarCraft\Alpha\Concerns\Vanishing;
            }

            PHP);
        $this->put("$root/alpha/src/Err.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            final class Err extends \RuntimeException
            {
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

    public function testASingleWordPascalCaseCodeSpanIsChecked(): void
    {
        $root = $this->makeFixture();

        // A one-hump name is a class claim too: an unknown one is named, and the
        // upstream Go mode and terminfo capability the bodies write are excluded.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class Gizmo, which no <slug>/src declares'],
            $this->claimsFor($root, '<p>Pass a <code>Gizmo</code> to the loop.</p>'),
        );
        $this->assertSame([], $this->claimsFor($root,
            '<p>The <code>Passthrough</code> mode and the <code>Tc</code> capability, '
            . 'with <code>Countable</code>, <code>TAB</code> and <code>RGB</code> left alone.</p>',
        ));
    }

    public function testABodyDeclaredClassDoesNotExemptItsProseSpan(): void
    {
        $root = $this->makeFixture();

        // The snippet declares Gizmo as its own example, but the prose span Gizmo may
        // be a SugarCraft claim, so only a resolvable class settles it.
        $this->assertSame(
            ['claims: docs/_data/alpha.body.html names class Gizmo, which no <slug>/src declares'],
            $this->claimsFor($root,
                '<pre><code>final class Gizmo {}</code></pre>'
                . '<p>Build a <code>Gizmo</code> for the loop.</p>',
            ),
        );
    }

    public function testAnExplicitImportFromOutsideSugarcraftStillExemptsItsBareSpan(): void
    {
        $root = $this->makeFixture();

        // The use statement says which Gizmo is meant: a dependency's, not a claim.
        $this->assertSame([], $this->claimsFor($root,
            '<pre><code>use Vendor\Gizmo;</code></pre>'
            . '<p>Pass a <code>Gizmo</code> to the loop.</p>',
        ));
    }

    public function testANameArgumentOutsideAnAttributeOrInACommentDoesNotRegisterAName(): void
    {
        $root = $this->makeFixture();
        // A constructor's name: argument and a docblock's attribute example are not
        // registered tool names; a real attribute in code still is.
        $this->put("$root/alpha/src/Labels.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Alpha;

            #[BuiltInTool(name: 'RealTool')]
            final class Labels
            {
                public function make(): object
                {
                    return new Gizmo(name: 'PhantomWidget');
                }

                /** Register it as #[BuiltInTool(name: 'DocOnlyWidget')] in code. */
                public function doc(): void
                {
                }
            }

            PHP);

        $this->assertSame([
            'claims: docs/_data/alpha.body.html names class PhantomWidget, which no <slug>/src declares',
            'claims: docs/_data/alpha.body.html names class DocOnlyWidget, which no <slug>/src declares',
        ], $this->claimsFor($root,
            '<p>Use <code>PhantomWidget</code>, <code>DocOnlyWidget</code> and <code>RealTool</code>.</p>',
        ));
    }

    /** A second package declaring its own Widget, with the method alpha's lacks. */
    private function putBetaWidget(string $root): void
    {
        $this->put("$root/beta/src/Widget.php", <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SugarCraft\Beta;

            final class Widget
            {
                public static function vanish(): void
                {
                }
            }

            PHP);
    }

    private function put(string $path, string $contents): void
    {
        if (!is_dir(\dirname($path)) && !mkdir(\dirname($path), 0777, true) && !is_dir(\dirname($path))) {
            throw new \RuntimeException("could not create directory for $path");
        }
        file_put_contents($path, $contents);
    }
}
