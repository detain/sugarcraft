<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\PatchAction;
use SugarCraft\Code\Tools\Edit\PatchHunk;
use SugarCraft\Code\Tools\Edit\PatchOperation;

final class PatchOperationTest extends TestCase
{
    public function testPathsIsJustThePathWithoutMove(): void
    {
        self::assertSame(['a.php'], (new PatchOperation(PatchAction::Update, 'a.php'))->paths());
    }

    public function testPathsIncludesMoveTarget(): void
    {
        $op = new PatchOperation(PatchAction::Update, 'a.php', moveTo: 'b.php');

        self::assertSame(['a.php', 'b.php'], $op->paths());
    }

    public function testDefaultsAndCarriedState(): void
    {
        $op = new PatchOperation(PatchAction::Add, 'n.txt', "body\n");
        self::assertSame("body\n", $op->content);
        self::assertSame([], $op->hunks);
        self::assertNull($op->moveTo);

        $hunk = new PatchHunk([], ['x'], ['y']);
        $upd = new PatchOperation(PatchAction::Update, 'u', hunks: [$hunk]);
        self::assertSame([$hunk], $upd->hunks);
    }

    public function testActionBackingValues(): void
    {
        self::assertSame('add', PatchAction::Add->value);
        self::assertSame(PatchAction::Delete, PatchAction::from('delete'));
        self::assertNull(PatchAction::tryFrom('rename'));
    }
}
