<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests\Reader;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Reader\ScanRowKind;

/**
 * sugar-diff-017: the README promises content rows are EXACTLY
 * Context/Insert/Delete and nothing else. Full truth table, one case per
 * enum member — a future roster addition (or a widened isContentRow) is a
 * red arm here, not a silently changed pairing contract.
 */
final class ScanRowKindTest extends TestCase
{
    /** @return array<string, array{0:ScanRowKind, 1:bool}> */
    public static function contentRowTruthTable(): array
    {
        return [
            'Context' => [ScanRowKind::Context, true],
            'Insert' => [ScanRowKind::Insert, true],
            'Delete' => [ScanRowKind::Delete, true],
            'HunkHeader' => [ScanRowKind::HunkHeader, false],
            'FileHeader' => [ScanRowKind::FileHeader, false],
            'NoNewlineAnnotation' => [ScanRowKind::NoNewlineAnnotation, false],
            'OutsideHunk' => [ScanRowKind::OutsideHunk, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contentRowTruthTable')]
    public function test_isContentRow_answers_the_pinned_truth_table(ScanRowKind $kind, bool $expected): void
    {
        $this->assertSame($expected, $kind->isContentRow(), $kind->name);
    }

    public function test_the_roster_has_exactly_the_seven_documented_members(): void
    {
        $names = array_map(static fn (ScanRowKind $k): string => $k->name, ScanRowKind::cases());
        sort($names);

        $this->assertSame([
            'Context',
            'Delete',
            'FileHeader',
            'HunkHeader',
            'Insert',
            'NoNewlineAnnotation',
            'OutsideHunk',
        ], $names, 'the README API table lists these seven; additions must land in both places');
    }
}
