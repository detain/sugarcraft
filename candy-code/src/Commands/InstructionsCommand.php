<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;

/**
 * `/instructions`: the instruction files this session reads, one numbered
 * row each — tier and bytes, the same rows the `context.sources` wire and
 * the `/context` panel answer from (C-CTX-3.a).
 *
 * The rows arrive as {@see \SugarCraft\Code\Host\ContextSources::instructionRows()}
 * output, and the display vocabulary is `/context`'s own (its header, its
 * `none`, its `(personal)` badge, its refused row) — one listing of one list,
 * so `/instructions 2` and the panel's second file are the same file by
 * construction. The numbers ARE the row order: {@see report()} prints them
 * 1-based and the editor arm resolves `open <n>` against the same list.
 *
 * Pure renderer: it reads no files and prices nothing. A null $rows is the
 * loader's failure passed through, answered by the same honest
 * "not measured" row `/context` carries, never by an empty list that reads
 * as "nothing is loaded".
 */
final class InstructionsCommand
{
    /**
     * @param ?list<array{path: string, bytes: int, tier: string}> $rows the priced instruction files, loader order, null when not measured
     * @param list<array{path: string, reason: string}> $refused what the loader refused and why (empty when unmeasured)
     */
    public function __construct(
        private readonly ?array $rows,
        private readonly array $refused = [],
    ) {
    }

    /** The report body, one screen row per listed or refused file. */
    public function report(): string
    {
        return implode("\n", $this->lines());
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        if ($this->rows === null) {
            return [Lang::t('cmd.context.instructions.unmeasured')];
        }

        $lines = [Lang::t('cmd.context.instructions')];
        if ($this->rows === [] && $this->refused === []) {
            $lines[] = '  - ' . Lang::t('cmd.context.none');
        }
        $index = 0;
        foreach ($this->rows as $row) {
            $index++;
            $lines[] = sprintf(
                '  %2d) %-8s %s%s',
                $index,
                self::bytes($row['bytes']),
                Chat::reportField($row['path']),
                $row['tier'] === 'personal' ? ' ' . Lang::t('cmd.context.instructions.personal') : '',
            );
        }
        foreach ($this->refused as $refusal) {
            $lines[] = '  ' . Lang::t('cmd.context.instructions.refused', [
                'path' => Chat::reportField($refusal['path']),
                'reason' => Chat::reportField($refusal['reason']),
            ]);
        }

        return $lines;
    }

    /** The size column, formatted exactly as {@see ContextCommand}'s panel formats it. */
    private static function bytes(int $bytes): string
    {
        return $bytes < 1024 ? $bytes . ' B' : rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.') . ' KB';
    }
}
