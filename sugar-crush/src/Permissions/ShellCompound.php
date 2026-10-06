<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

/**
 * The compound-statement structure of a {@see ShellWords} line: which simple
 * commands are the body of a `for` or `while read` loop, or the branches of
 * an `if`, so a caller can judge a loop as ONE thing whose body must qualify
 * (user decision 2026-10-11) instead of refusing every line that holds a
 * reserved word.
 *
 * {@see ShellWords} splits on operators and does not interpret reserved
 * words: `for f in a b; do wc -l "$f"; done` comes back as the commands
 * `for f in a b`, `do wc -l $f` and `done`. This class re-reads that list
 * against a deliberately SMALL grammar and returns null for anything outside
 * it, so a caller that grants on a parse fails closed:
 *
 *     list    := item (terminator item)*
 *     item    := simple | for | while | if
 *     for     := `for` NAME `in` WORD* (`;`|newline) `do` list `done`
 *     while   := `while` [IFS=WORD] `read` [-r] NAME+ (`;`|newline) `do` list `done`
 *     if      := `if` list `then` list (`elif` list `then` list)* [`else` list] `fi`
 *
 * Everything else that would make a reserved word structural — `until`,
 * `case`, `select`, `function`, `coproc`, an arithmetic `for ((…))`, a
 * `while` whose condition is not a bare `read`, a subshell or `;;` anywhere
 * in a line that holds a keyword — is null. `{`, `}`, `!`, `[[` stay what the
 * tokeniser makes of them, simple commands, which no allow-list names.
 *
 * A word is a keyword only where bash would read one: at the start of a
 * command, unexpanded, and written LITERALLY (`"for"` is a command named
 * `for`, not a loop). `do`, `then`, `else`, `if`, `elif` and `while` open the
 * command they start — `do wc -l "$f"` is the keyword `do` and then the
 * simple command `wc -l "$f"`, with that command's own source text and the
 * quote-removed words after the keyword.
 *
 * Nodes are arrays (one shape per `kind`):
 *
 * - `simple`: `words`, `flags` ({@see ShellWords::$expandable}), `dollars`
 *   ({@see ShellWords::$dollars}), `index` (the {@see ShellWords} command it
 *   came from — its redirections are keyed by it), `source`, `terminator`;
 * - `for`: `var`, `words` / `flags` / `dollars` (the list after `in`),
 *   `body`, `terminator`, `structural`;
 * - `while`: `vars`, `read` (the condition, a `simple` node), `body`,
 *   `terminator`, `structural`;
 * - `if`: `branches` (list of `[condition list, body list]`), `else` (a list
 *   or null), `terminator`, `structural`.
 *
 * `terminator` is the operator that ends the item at its own level; for a
 * compound it is the one after `done` / `fi`. `structural` lists the
 * {@see ShellWords} command indices that are pure syntax (`done`, `fi`, a
 * `for` header, a lone `do`) — redirections keyed to those belong to the
 * whole statement (`done < file`), not to any command of its body.
 */
final class ShellCompound
{
    /** Keywords that open the command they start (`do wc -l x`). */
    private const PREFIX_KEYWORDS = ['do', 'then', 'else', 'if', 'elif', 'while'];

    /** Keywords that are a whole command of their own. */
    private const WHOLE_KEYWORDS = ['for', 'done', 'fi'];

    /** Keywords that make a line structural in a way this grammar does not read. */
    private const UNSUPPORTED_KEYWORDS = ['until', 'case', 'esac', 'select', 'function', 'coproc', 'in'];

    /** What may end the last command before `do`, `then`, `done`, `fi` …. */
    private const LIST_ENDERS = [';', "\n"];

    /** @var list<array<string, mixed>> */
    private array $virtual = [];

    private int $pos = 0;

    /**
     * @param list<array<string, mixed>> $virtual
     */
    private function __construct(array $virtual)
    {
        $this->virtual = $virtual;
    }

    /**
     * The line's items, or null when its structure is outside the grammar.
     * A line with no keyword at all is a flat list of `simple` items — the
     * same commands, in the same order, as {@see ShellWords::$commands}.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function parse(ShellWords $parsed): ?array
    {
        if (\count($parsed->terminators) !== \count($parsed->commands)) {
            return null;
        }

        $virtual = [];
        $keywords = false;
        foreach ($parsed->commands as $index => $words) {
            $flags = $parsed->expandable[$index] ?? [];
            $dollars = $parsed->dollars[$index] ?? [];
            $source = ltrim($parsed->sources[$index] ?? '');
            $terminator = $parsed->terminators[$index];
            while ($words !== [] && in_array($words[0], self::PREFIX_KEYWORDS, true)
                && self::isLiteralKeyword($words[0], $flags[0] ?? true, $source)) {
                $keywords = true;
                $alone = \count($words) === 1;
                $virtual[] = ['kw' => $words[0], 'index' => $index, 'terminator' => $alone ? $terminator : '', 'alone' => $alone];
                if ($alone) {
                    continue 2;
                }
                $source = ltrim(substr($source, \strlen($words[0])));
                array_shift($words);
                array_shift($flags);
                array_shift($dollars);
            }
            $kw = null;
            if ($words !== [] && self::isLiteralKeyword($words[0], $flags[0] ?? true, $source)
                && (in_array($words[0], self::WHOLE_KEYWORDS, true) || in_array($words[0], self::UNSUPPORTED_KEYWORDS, true))) {
                $kw = $words[0];
                $keywords = true;
            }
            $virtual[] = [
                'kw' => $kw,
                'index' => $index,
                'terminator' => $terminator,
                'words' => array_values($words),
                'flags' => array_values($flags),
                'dollars' => array_values($dollars),
                'source' => $source,
            ];
        }

        if ($keywords) {
            foreach ($parsed->operators as $operator) {
                if (in_array($operator, ['(', ')', ';;'], true)) {
                    return null;
                }
            }
        }

        $parser = new self($virtual);
        $items = $parser->parseList([]);

        return $items === null || $parser->pos !== \count($virtual) ? null : $items;
    }

    /**
     * Does the line hold a compound statement (any item that is not `simple`)?
     *
     * @param list<array<string, mixed>> $items
     */
    public static function hasCompound(array $items): bool
    {
        foreach ($items as $item) {
            if ($item['kind'] !== 'simple') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `simple` node of $items, depth first, in source order — loop
     * bodies, `if` conditions and branches included; a `while`'s `read`
     * condition is structure and is not one of them.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    public static function simpleCommands(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            switch ($item['kind']) {
                case 'simple':
                    $out[] = $item;
                    break;
                case 'for':
                case 'while':
                    array_push($out, ...self::simpleCommands($item['body']));
                    break;
                case 'if':
                    foreach ($item['branches'] as [$condition, $body]) {
                        array_push($out, ...self::simpleCommands($condition), ...self::simpleCommands($body));
                    }
                    if ($item['else'] !== null) {
                        array_push($out, ...self::simpleCommands($item['else']));
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * The {@see ShellWords} command indices of $items that are pure syntax —
     * `done`, `fi`, a `for` header, a lone `do` / `then` / `else`, a `while`'s
     * `read` — nested statements included.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<int>
     */
    public static function structuralIndices(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'simple') {
                continue;
            }
            array_push($out, ...$item['structural']);
            $nested = match ($item['kind']) {
                'for', 'while' => [$item['body']],
                default => [...array_merge(...array_map(static fn (array $b): array => [$b[0], $b[1]], $item['branches'])), ...($item['else'] === null ? [] : [$item['else']])],
            };
            foreach ($nested as $list) {
                array_push($out, ...self::structuralIndices($list));
            }
        }

        return $out;
    }

    /**
     * A reserved word counts only unexpanded and written bare at the start
     * of the command's source: `"do"` and `d\o` are words, not keywords.
     */
    private static function isLiteralKeyword(string $word, bool $expandable, string $source): bool
    {
        return !$expandable && preg_match('/^' . preg_quote($word, '/') . '(?=\s|$)/', $source) === 1;
    }

    /**
     * Items until a virtual command whose keyword is in $stop (not consumed)
     * or the end.
     *
     * @param list<string> $stop
     *
     * @return list<array<string, mixed>>|null
     */
    private function parseList(array $stop): ?array
    {
        $items = [];
        while ($this->pos < \count($this->virtual)) {
            $kw = $this->virtual[$this->pos]['kw'];
            if ($kw !== null && in_array($kw, $stop, true)) {
                break;
            }
            $item = match (true) {
                $kw === null => $this->simple(),
                $kw === 'for' => $this->parseFor(),
                $kw === 'while' => $this->parseWhile(),
                $kw === 'if' => $this->parseIf(),
                default => null, // a closer out of place, or an unsupported keyword
            };
            if ($item === null) {
                return null;
            }
            $items[] = $item;
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function simple(): array
    {
        $v = $this->virtual[$this->pos++];

        return [
            'kind' => 'simple',
            'words' => $v['words'],
            'flags' => $v['flags'],
            'dollars' => $v['dollars'],
            'index' => $v['index'],
            'source' => $v['source'],
            'terminator' => $v['terminator'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function parseFor(): ?array
    {
        $header = $this->virtual[$this->pos++];
        $words = $header['words'];
        $flags = $header['flags'];
        if (\count($words) < 3 || ($flags[1] ?? true) || ($flags[2] ?? true) || $words[2] !== 'in'
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $words[1]) !== 1
            || !in_array($header['terminator'], self::LIST_ENDERS, true)) {
            return null;
        }
        $body = $this->doneBody();
        if ($body === null) {
            return null;
        }

        return [
            'kind' => 'for',
            'var' => $words[1],
            'words' => array_slice($words, 3),
            'flags' => array_slice($flags, 3),
            'dollars' => array_slice($header['dollars'], 3),
            'body' => $body['items'],
            'terminator' => $body['terminator'],
            'structural' => [$header['index'], ...$body['structural']],
        ];
    }

    /** @return array<string, mixed>|null */
    private function parseWhile(): ?array
    {
        $marker = $this->virtual[$this->pos++];
        if (!$this->opensList($marker)) {
            return null;
        }
        $condition = $this->parseList(['do']);
        if ($condition === null || \count($condition) !== 1 || $condition[0]['kind'] !== 'simple') {
            return null;
        }
        $read = $condition[0];
        $vars = self::readVariables($read);
        if ($vars === null || !in_array($read['terminator'], self::LIST_ENDERS, true)) {
            return null;
        }
        $body = $this->doneBody();
        if ($body === null) {
            return null;
        }

        return [
            'kind' => 'while',
            'vars' => $vars,
            'read' => $read,
            'body' => $body['items'],
            'terminator' => $body['terminator'],
            'structural' => [$marker['index'], $read['index'], ...$body['structural']],
        ];
    }

    /**
     * The names a `while` condition reads into, or null when it is not
     * `[IFS=WORD] read [-r] NAME…` — every word literal, every NAME an
     * identifier (a NAME like `a[$(id)]` would evaluate its subscript).
     *
     * @param array<string, mixed> $read
     *
     * @return list<string>|null
     */
    private static function readVariables(array $read): ?array
    {
        $words = $read['words'];
        if (in_array(true, $read['flags'], true)) {
            return null;
        }
        if ($words !== [] && str_starts_with($words[0], 'IFS=')) {
            array_shift($words);
        }
        if (($words[0] ?? null) !== 'read') {
            return null;
        }
        array_shift($words);
        if (($words[0] ?? null) === '-r') {
            array_shift($words);
        }
        if ($words === []) {
            return null;
        }
        foreach ($words as $name) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                return null;
            }
        }

        return array_values(array_unique($words));
    }

    /** @return array<string, mixed>|null */
    private function parseIf(): ?array
    {
        $structural = [];
        $branches = [];
        $else = null;
        $marker = $this->virtual[$this->pos++];
        while (true) {
            // $marker is `if` or `elif`: a condition list, then `then` and a body.
            if (!$this->opensList($marker)) {
                return null;
            }
            if ($marker['alone']) {
                $structural[] = $marker['index'];
            }
            $condition = $this->closedList(['then']);
            $then = $this->expect('then');
            if ($condition === null || $then === null || !$this->opensList($then)) {
                return null;
            }
            if ($then['alone']) {
                $structural[] = $then['index'];
            }
            $body = $this->closedList(['elif', 'else', 'fi']);
            if ($body === null) {
                return null;
            }
            $branches[] = [$condition, $body];
            $next = $this->virtual[$this->pos] ?? null;
            if ($next !== null && $next['kw'] === 'elif') {
                $marker = $this->virtual[$this->pos++];
                continue;
            }
            break;
        }
        $next = $this->virtual[$this->pos] ?? null;
        if ($next !== null && $next['kw'] === 'else') {
            ++$this->pos;
            if (!$this->opensList($next)) {
                return null;
            }
            if ($next['alone']) {
                $structural[] = $next['index'];
            }
            $else = $this->closedList(['fi']);
            if ($else === null) {
                return null;
            }
        }
        $fi = $this->expect('fi');
        if ($fi === null || $fi['words'] !== ['fi']) {
            return null;
        }
        $structural[] = $fi['index'];

        return [
            'kind' => 'if',
            'branches' => $branches,
            'else' => $else,
            'terminator' => $fi['terminator'],
            'structural' => $structural,
        ];
    }

    /**
     * `do` LIST `done`, consumed: the body items, the terminator after
     * `done`, and the structural indices — or null.
     *
     * @return array{items: list<array<string, mixed>>, terminator: ?string, structural: list<int>}|null
     */
    private function doneBody(): ?array
    {
        $do = $this->expect('do');
        if ($do === null || !$this->opensList($do)) {
            return null;
        }
        $items = $this->closedList(['done']);
        $done = $this->expect('done');
        if ($items === null || $done === null || $done['words'] !== ['done']) {
            return null;
        }
        $structural = [$done['index']];
        if ($do['alone']) {
            $structural[] = $do['index'];
        }

        return ['items' => $items, 'terminator' => $done['terminator'], 'structural' => $structural];
    }

    /**
     * A non-empty list up to one of $stop whose last item ends in `;` or a
     * newline — what bash requires before `do`, `then`, `done`, `fi` ….
     *
     * @param list<string> $stop
     *
     * @return list<array<string, mixed>>|null
     */
    private function closedList(array $stop): ?array
    {
        $items = $this->parseList($stop);
        if ($items === null || $items === []) {
            return null;
        }

        return in_array($items[\count($items) - 1]['terminator'], self::LIST_ENDERS, true) ? $items : null;
    }

    /**
     * A keyword that opens a list (`do`, `then`, `else`, `if`, `elif`,
     * `while`) is followed by the list's first command on the same line, or
     * stands alone ended by a newline — `do;` is a syntax error.
     *
     * @param array<string, mixed> $marker
     */
    private function opensList(array $marker): bool
    {
        return !$marker['alone'] || $marker['terminator'] === "\n";
    }

    /**
     * Consume the next virtual command if its keyword is $kw.
     *
     * @return array<string, mixed>|null
     */
    private function expect(string $kw): ?array
    {
        $next = $this->virtual[$this->pos] ?? null;
        if ($next === null || $next['kw'] !== $kw) {
            return null;
        }
        ++$this->pos;

        return $next;
    }
}
