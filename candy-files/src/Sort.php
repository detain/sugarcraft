<?php

declare(strict_types=1);

namespace SugarCraft\Files;

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
     * CF-16 (closes 060): the localized field label the pane header
     * shows in place of the raw enum notation ('name-asc' is a wire
     * value, not user chrome). Every arm spells its literal key —
     * tests/LangCoverageTest greps literals, so a built key would
     * look used to the forward scan and unused to the reverse one.
     */
    public function label(): string
    {
        return match ($this) {
            self::NameAsc, self::NameDesc  => Lang::t('sort.name'),
            self::MtimeAsc, self::MtimeDesc => Lang::t('sort.mtime'),
            self::SizeAsc, self::SizeDesc  => Lang::t('sort.size'),
        };
    }

    /** Direction of the order; rendered as the language-neutral ↑/↓. */
    public function isAscending(): bool
    {
        return match ($this) {
            self::NameAsc, self::MtimeAsc, self::SizeAsc => true,
            self::NameDesc, self::MtimeDesc, self::SizeDesc => false,
        };
    }

    /**
     * Cycle the active sort: every tap of `s` alternates the
     * direction, and the field advances on the following tap —
     * from NameAsc the order is NameDesc, MtimeAsc, MtimeDesc,
     * SizeAsc, SizeDesc, back to NameAsc.
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
