<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Repo;
use SugarCraft\Serve\Visibility;

/**
 * Visibility enum + Repo BC accessors — plan item 7.8.
 *
 * @covers \SugarCraft\Serve\Visibility
 * @covers \SugarCraft\Serve\Repo
 */
final class VisibilityTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Enum round-trip
    // -------------------------------------------------------------------------

    public function testFromBoolsMapsAllStates(): void
    {
        $this->assertSame(Visibility::Public, Visibility::fromBools(true, false));
        $this->assertSame(Visibility::CollaboratorOnly, Visibility::fromBools(false, false));
        $this->assertSame(Visibility::Private, Visibility::fromBools(false, true));
        // Private always wins, matching the old isPrivate() accessor.
        $this->assertSame(Visibility::Private, Visibility::fromBools(true, true));
    }

    public function testBackedValuesRoundTrip(): void
    {
        foreach (Visibility::cases() as $case) {
            $this->assertSame($case, Visibility::from($case->value));
        }
        $this->assertSame('public', Visibility::Public->value);
        $this->assertSame('private', Visibility::Private->value);
        $this->assertSame('collaborator-only', Visibility::CollaboratorOnly->value);
    }

    public function testEnumPredicates(): void
    {
        $this->assertTrue(Visibility::Public->isPublic());
        $this->assertFalse(Visibility::Public->isPrivate());
        $this->assertTrue(Visibility::Private->isPrivate());
        $this->assertFalse(Visibility::Private->isPublic());
        $this->assertFalse(Visibility::CollaboratorOnly->isPublic());
        $this->assertFalse(Visibility::CollaboratorOnly->isPrivate());
    }

    // -------------------------------------------------------------------------
    // Repo integration + BC accessors
    // -------------------------------------------------------------------------

    public function testNewRepoIsPublic(): void
    {
        $r = Repo::new('t', '/tmp/t');

        $this->assertSame(Visibility::Public, $r->visibility);
        $this->assertTrue($r->isPublic);
        $this->assertFalse($r->isPrivate());
        $this->assertTrue($r->isVisiblePublic());
    }

    public function testWithVisibilityReturnsNewInstance(): void
    {
        $a = Repo::new('t', '/tmp/t');
        $b = $a->withVisibility(Visibility::Private);

        $this->assertNotSame($a, $b);
        $this->assertSame(Visibility::Public, $a->visibility);
        $this->assertSame(Visibility::Private, $b->visibility);
        $this->assertFalse($b->isPublic);
        $this->assertTrue($b->isPrivate());
    }

    public function testBcWithPublicFalseDemotesToCollaboratorOnly(): void
    {
        $r = Repo::new('t', '/tmp/t')->withPublic(false);

        $this->assertSame(Visibility::CollaboratorOnly, $r->visibility);
        $this->assertFalse($r->isPublic);
        $this->assertFalse($r->isPrivate());
        $this->assertFalse($r->isVisiblePublic());
    }

    public function testBcWithPrivateTrueWinsOverPublic(): void
    {
        $r = Repo::new('t', '/tmp/t')->withPublic(true)->withPrivate(true);

        $this->assertSame(Visibility::Private, $r->visibility);
        $this->assertFalse($r->isPublic);
        $this->assertTrue($r->isPrivate());
        $this->assertFalse($r->isVisiblePublic());
    }

    public function testBcWithPublicFalseKeepsPrivate(): void
    {
        $r = Repo::new('t', '/tmp/t')->withPrivate(true)->withPublic(false);

        $this->assertSame(Visibility::Private, $r->visibility);
    }

    public function testBcWithPrivateFalseOnPrivateResolvesCollaboratorOnly(): void
    {
        $r = Repo::new('t', '/tmp/t')->withPrivate(true)->withPrivate(false);

        $this->assertSame(Visibility::CollaboratorOnly, $r->visibility);
    }

    public function testBcWithPrivateFalseOnPublicIsNoOp(): void
    {
        $r = Repo::new('t', '/tmp/t')->withPrivate(false);

        $this->assertSame(Visibility::Public, $r->visibility);
        $this->assertTrue($r->isPublic);
    }

    public function testWithPublicTruePromotesFromAnyState(): void
    {
        $this->assertSame(
            Visibility::Public,
            Repo::new('t', '/tmp/t')->withPrivate(true)->withPublic(true)->visibility,
        );
        $this->assertSame(
            Visibility::Public,
            Repo::new('t', '/tmp/t')->withPublic(false)->withPublic(true)->visibility,
        );
    }

    public function testVisibilitySurvivesOtherBuilders(): void
    {
        $r = Repo::new('t', '/tmp/t')
            ->withVisibility(Visibility::CollaboratorOnly)
            ->withDescription('d')
            ->withAllowPush(true)
            ->withMirrorFrom('https://example.com/up.git')
            ->withHighlightLanguage('php')
            ->addCollaborator('alice');

        $this->assertSame(Visibility::CollaboratorOnly, $r->visibility);
        $this->assertFalse($r->isPublic);
    }

    public function testIsMirror(): void
    {
        $plain = Repo::new('t', '/tmp/t');
        $mirror = $plain->withMirrorFrom('https://example.com/up.git');

        $this->assertFalse($plain->isMirror());
        $this->assertTrue($mirror->isMirror());
        $this->assertFalse($mirror->withMirrorFrom(null)->isMirror());
    }
}
