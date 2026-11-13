<?php

declare(strict_types=1);

namespace SugarCraft\Files;

/**
 * Sort orders for the pane listing. Directories always group at
 * the top regardless of order; the comparator below applies the
 * order as the secondary key.
 *
 * No upstream — house pattern (finding 051). superfile tracks sort state
 * as loose strings; this enum-plus-comparator is the library's own.
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
    case ExtAsc    = 'ext-asc';
    case ExtDesc   = 'ext-desc';

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
            // CF-46 (closes 082): the file-suffix family. Name-only arms
            // have no secondary key at all, so a directory of same-named
            // extensions (or a bulk touch) leaves the order at the mercy
            // of scandir; the extension arms therefore carry a total
            // order - suffix, then mtime, then name - so a re-sort of an
            // unchanged listing is byte-identical to the last one.
            self::ExtAsc    => static fn(Entry $a, Entry $b) => $dirsFirst($a, $b, static fn(Entry $a, Entry $b) =>
                (self::extension($a->name) <=> self::extension($b->name))
                ?: ($a->mtime <=> $b->mtime)
                ?: strcasecmp($a->name, $b->name)),
            self::ExtDesc   => static fn(Entry $a, Entry $b) => $dirsFirst($a, $b, static fn(Entry $a, Entry $b) =>
                (self::extension($b->name) <=> self::extension($a->name))
                ?: ($b->mtime <=> $a->mtime)
                ?: strcasecmp($b->name, $a->name)),
        };
    }

    /**
     * CF-46: the sort suffix of a file name — the run after the LAST
     * dot, lower-cased so 'Photo.JPG' groups with 'notes.jpg'. A name
     * with no dot, or whose only dot leads it (.bashrc — a dot-file is
     * a NAME, not an extension), has the empty suffix and so groups at
     * the front of an ascending run.
     */
    public static function extension(string $name): string
    {
        $dot = strrpos($name, '.');
        return $dot === false || $dot === 0 ? '' : strtolower(substr($name, $dot + 1));
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
            self::ExtAsc, self::ExtDesc    => Lang::t('sort.ext'),
        };
    }

    /** Direction of the order; rendered as the language-neutral ↑/↓. */
    public function isAscending(): bool
    {
        return match ($this) {
            self::NameAsc, self::MtimeAsc, self::SizeAsc, self::ExtAsc => true,
            self::NameDesc, self::MtimeDesc, self::SizeDesc, self::ExtDesc => false,
        };
    }

    /**
     * Cycle the active sort: every tap of `s` alternates the
     * direction, and the field advances on the following tap —
     * from NameAsc the order is NameDesc, MtimeAsc, MtimeDesc,
     * SizeAsc, SizeDesc, ExtAsc, ExtDesc, back to NameAsc.
     * `e` is the CF-46 direct selection of the extension field.
     */
    public function cycle(): self
    {
        return match ($this) {
            self::NameAsc   => self::NameDesc,
            self::NameDesc  => self::MtimeAsc,
            self::MtimeAsc  => self::MtimeDesc,
            self::MtimeDesc => self::SizeAsc,
            self::SizeAsc   => self::SizeDesc,
            self::SizeDesc  => self::ExtAsc,
            self::ExtAsc    => self::ExtDesc,
            self::ExtDesc   => self::NameAsc,
        };
    }
}
