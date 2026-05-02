<?php

declare(strict_types=1);

namespace CandyCore\SuperCandy;

/**
 * Sort orders for the pane listing. Directories always group at
 * the top regardless of order; the comparator below applies the
 * order as the secondary key.
 *
 * The "..  parent" sentinel is excluded from sorting and always
 * sits at the top of any pane that has one.
 */
enum Sort: string
{
    case NameAsc   = 'name-asc';
    case NameDesc  = 'name-desc';
    case MtimeAsc  = 'mtime-asc';
    case MtimeDesc = 'mtime-desc';
    case SizeAsc   = 'size-asc';
    case SizeDesc  = 'size-desc';

    /**
     * Sort `$entries` in place (returns a new list — pure).
     * `..` parent sentinel stays at index 0 if present.
     *
     * @param list<Entry> $entries
     * @return list<Entry>
     */
    public function apply(array $entries): array
    {
        $parent = null;
        $rest = [];
        foreach ($entries as $e) {
            if ($e->isParentSentinel()) {
                $parent = $e;
                continue;
            }
            $rest[] = $e;
        }
        $cmp = $this->comparator();
        usort($rest, $cmp);
        return $parent !== null ? array_merge([$parent], $rest) : $rest;
    }

    /**
     * @return \Closure(Entry,Entry): int
     */
    private function comparator(): \Closure
    {
        // Directories always come before files.
        $dirsFirst = static function (Entry $a, Entry $b, \Closure $tiebreak): int {
            if ($a->isDir !== $b->isDir) {
                return $a->isDir ? -1 : 1;
            }
            return $tiebreak($a, $b);
        };

        return match ($this) {
            self::NameAsc   => static fn(Entry $a, Entry $b) => $dirsFirst($a, $b, static fn(Entry $a, Entry $b) =>
                strcasecmp($a->name, $b->name)),
            self::NameDesc  => static fn(Entry $a, Entry $b) => $dirsFirst($a, $b, static fn(Entry $a, Entry $b) =>
                strcasecmp($b->name, $a->name)),
            self::MtimeAsc  => static fn(Entry $a, Entry $b) => $dirsFirst($a, $b, static fn(Entry $a, Entry $b) =>
                $a->mtime <=> $b->mtime),
            self::MtimeDesc => static fn(Entry $a, Entry $b) => $dirsFirst($a, $b, static fn(Entry $a, Entry $b) =>
                $b->mtime <=> $a->mtime),
            self::SizeAsc   => static fn(Entry $a, Entry $b) => $dirsFirst($a, $b, static fn(Entry $a, Entry $b) =>
                $a->size <=> $b->size),
            self::SizeDesc  => static fn(Entry $a, Entry $b) => $dirsFirst($a, $b, static fn(Entry $a, Entry $b) =>
                $b->size <=> $a->size),
        };
    }

    /**
     * Cycle through name → mtime → size → back to name. Tapping
     * `s` once moves through asc orders; tapping it on the same
     * field flips to desc.
     */
    public function cycle(): self
    {
        return match ($this) {
            self::NameAsc   => self::NameDesc,
            self::NameDesc  => self::MtimeAsc,
            self::MtimeAsc  => self::MtimeDesc,
            self::MtimeDesc => self::SizeAsc,
            self::SizeAsc   => self::SizeDesc,
            self::SizeDesc  => self::NameAsc,
        };
    }
}
