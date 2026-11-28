<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Log\Formatter\TextFormatter;

/**
 * Read side of a log file: `LogReader::new($path, LogFormat::Json)->tail(50)`.
 *
 * {@see tail()} seeks to the end and reads backwards in fixed chunks until it
 * has the lines it needs, so the cost follows the lines asked for, not the
 * file size — tailing a multi-gigabyte log reads a few kilobytes.
 *
 * {@see follow()} reads on from a {@see LogCursor}, so a follower takes
 * every line written since its last read, not just the newest few.
 *
 * Immutable: every with*() returns a new reader through mutate(). Rotation
 * (following a log across a rename to `.1`) is not handled here; it waits on
 * plan decision D6. {@see follow()} only notices that the file under the
 * path was replaced, and starts over from the new file's tail.
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogReader
{
    /** Bytes read per backwards step; one line longer than this still parses (the steps join). */
    public const CHUNK_BYTES = 8192;

    private function __construct(
        public string $path,
        public LogFormat $format,
        public PartsOrder $partsOrder,
    ) {
    }

    /**
     * A reader over $path that parses lines as $format. $partsOrder is the
     * writer's text layout ({@see TextFormatter::withPartsOrder()}); it only
     * steers where a text line's level is looked for, and defaults to
     * PartsOrder::new(), the layout a TextFormatter writes unless told otherwise.
     */
    public static function new(string $path, LogFormat $format = LogFormat::Text, ?PartsOrder $partsOrder = null): self
    {
        return new self($path, $format, $partsOrder ?? PartsOrder::new());
    }

    public function withPath(string $path): self
    {
        return $this->mutate(['path' => $path]);
    }

    public function withFormat(LogFormat $format): self
    {
        return $this->mutate(['format' => $format]);
    }

    /** The PartsOrder the text lines were written with; JSON and logfmt ignore it. */
    public function withPartsOrder(PartsOrder $partsOrder): self
    {
        return $this->mutate(['partsOrder' => $partsOrder]);
    }

    /**
     * The last $n records of the file, oldest first. With a $filter, the last
     * $n records the filter keeps: the scan goes on back through the file
     * until it has them, so matches far from the end are found too, at the
     * cost of reading back to them.
     *
     * Blank lines are not records and do not count toward $n. A final line
     * without its newline (a write in flight) is returned like any other —
     * the same answer `tail(1)` gives. A file that does not exist yet has no
     * records: a log nobody has written to is an answer, not an error.
     *
     * @return list<LogRecord>
     *
     * @throws \InvalidArgumentException When $n is negative.
     * @throws \RuntimeException         When the path exists but is not a readable regular file.
     */
    public function tail(int $n, ?LogFilter $filter = null): array
    {
        if ($n < 0) {
            throw new \InvalidArgumentException(Lang::t('reader.negative_count', ['count' => (string) $n]));
        }
        if ($n === 0) {
            return [];
        }
        $handle = $this->open();
        if ($handle === null) {
            return [];
        }

        try {
            return $this->backwards($handle, (int) (\fstat($handle)['size'] ?? 0), $n, $filter);
        } finally {
            \fclose($handle);
        }
    }

    /**
     * Follow the file from $from: the records written since that cursor, or,
     * with no cursor or when the file was truncated or replaced since it was
     * taken, the last $n records as {@see tail()} finds them ($fresh then
     * says to replace rather than extend). Either way only records $filter
     * keeps come back, and the returned cursor resumes the next read.
     *
     * Only complete lines are read: a final line still missing its newline
     * is left for the read after it lands, so it is never taken twice or
     * torn in two. $limit caps the records a resumed read returns, keeping
     * the newest, so a reader that fell far behind does not hold them all;
     * null takes every one.
     *
     * @throws \InvalidArgumentException When $n or $limit is negative.
     * @throws \RuntimeException         When the path exists but is not a readable regular file.
     */
    public function follow(?LogCursor $from, int $n, ?LogFilter $filter = null, ?int $limit = null): LogChunk
    {
        foreach ([$n, $limit ?? 0] as $count) {
            if ($count < 0) {
                throw new \InvalidArgumentException(Lang::t('reader.negative_count', ['count' => (string) $count]));
            }
        }
        $handle = $this->open();
        if ($handle === null) {
            return new LogChunk([], null, true);
        }

        try {
            $stat = \fstat($handle);
            $inode = (int) ($stat['ino'] ?? 0);
            $end = self::lineEnd($handle, (int) ($stat['size'] ?? 0));
            $cursor = new LogCursor($end, $inode);

            // A cursor holds only while the bytes before it are still the
            // ones it was taken past: same file, not shorter, and a line
            // still ending there. Anything else starts over from the tail.
            if ($from !== null && $from->inode === $inode && $from->offset <= $end
                && self::endsALine($handle, $from->offset)) {
                return new LogChunk($this->forwards($handle, $from->offset, $end, $filter, $limit), $cursor, false);
            }

            return new LogChunk($n === 0 ? [] : $this->backwards($handle, $end, $n, $filter), $cursor, true);
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @return resource|null null when nothing exists at the path
     *
     * @throws \RuntimeException When the path exists but is not a readable regular file.
     */
    private function open(): mixed
    {
        \clearstatcache(true, $this->path);
        if (!\file_exists($this->path)) {
            return null;
        }
        $handle = \is_file($this->path) ? @\fopen($this->path, 'rb') : false;
        if ($handle === false) {
            throw new \RuntimeException(Lang::t('reader.unreadable', ['path' => $this->path]));
        }

        return $handle;
    }

    /**
     * The last $n kept records of the bytes before $position, oldest first.
     *
     * @param resource $handle
     * @return list<LogRecord>
     */
    private function backwards(mixed $handle, int $position, int $n, ?LogFilter $filter): array
    {
        // The unfinished line, as the chunks read so far, newest first.
        // Only each new chunk is searched for newlines, and the pieces are
        // joined once the line is complete, so a line many chunks long
        // costs its length, not its length squared.
        /** @var list<string> $carry */
        $carry = [];
        /** @var list<LogRecord> $newestFirst */
        $newestFirst = [];

        while ($position > 0 && \count($newestFirst) < $n) {
            $step = \min(self::CHUNK_BYTES, $position);
            $position -= $step;
            \fseek($handle, $position);
            $pieces = \explode("\n", self::readExactly($handle, $step));
            if (\count($pieces) === 1) {
                $carry[] = $pieces[0];
                continue;
            }

            $carry[] = \array_pop($pieces);
            $this->keep(\implode('', \array_reverse($carry)), $newestFirst, $filter);
            // The first piece may continue into bytes not read yet, so it
            // waits for the next step (or for the start of the file).
            $carry = [\array_shift($pieces)];
            for ($i = \count($pieces) - 1; $i >= 0 && \count($newestFirst) < $n; --$i) {
                $this->keep($pieces[$i], $newestFirst, $filter);
            }
        }

        if ($position === 0 && \count($newestFirst) < $n) {
            $this->keep(\implode('', \array_reverse($carry)), $newestFirst, $filter);
        }

        return \array_slice(\array_reverse($newestFirst), -$n);
    }

    /**
     * The kept records of the bytes from $position to $end, oldest first,
     * at most the newest $limit of them.
     *
     * @param resource $handle
     * @return list<LogRecord>
     */
    private function forwards(mixed $handle, int $position, int $end, ?LogFilter $filter, ?int $limit): array
    {
        if ($limit === 0) {
            return [];
        }
        \fseek($handle, $position);
        /** @var list<string> $carry */
        $carry = [];
        /** @var list<LogRecord> $records */
        $records = [];

        while ($position < $end) {
            $bytes = self::readExactly($handle, \min(self::CHUNK_BYTES, $end - $position));
            if ($bytes === '') {
                break; // truncated under us: keep what was read
            }
            $position += \strlen($bytes);
            $pieces = \explode("\n", $bytes);
            if (\count($pieces) === 1) {
                $carry[] = $pieces[0];
                continue;
            }

            $carry[] = \array_shift($pieces);
            $this->keep(\implode('', $carry), $records, $filter);
            $carry = [\array_pop($pieces)];
            foreach ($pieces as $piece) {
                $this->keep($piece, $records, $filter);
            }
            if ($limit !== null && \count($records) > 2 * $limit) {
                $records = \array_slice($records, -$limit);
            }
        }
        // $end sits just past a newline, so a carry left over is a line the
        // file lost under the read: it is not taken.

        return $limit === null ? $records : \array_slice($records, -$limit);
    }

    /**
     * The offset just past the last newline before $size: where the complete
     * lines end. 0 when there is none.
     *
     * @param resource $handle
     */
    private static function lineEnd(mixed $handle, int $size): int
    {
        $position = $size;
        while ($position > 0) {
            $step = \min(self::CHUNK_BYTES, $position);
            $position -= $step;
            \fseek($handle, $position);
            $at = \strrpos(self::readExactly($handle, $step), "\n");
            if ($at !== false) {
                return $position + $at + 1;
            }
        }

        return 0;
    }

    /**
     * True when $offset is the start of the file or follows a newline.
     *
     * @param resource $handle
     */
    private static function endsALine(mixed $handle, int $offset): bool
    {
        if ($offset === 0) {
            return true;
        }
        \fseek($handle, $offset - 1);

        return \fread($handle, 1) === "\n";
    }

    /**
     * Parses $piece onto $into when it is a line and $filter keeps it.
     *
     * @param list<LogRecord> $into
     */
    private function keep(string $piece, array &$into, ?LogFilter $filter): void
    {
        $line = \rtrim($piece, "\r");
        if (\trim($line) === '') {
            return;
        }
        $record = $this->parse($line);
        if ($filter === null || $filter->matches($record)) {
            $into[] = $record;
        }
    }

    /**
     * @param resource $handle
     */
    private static function readExactly(mixed $handle, int $length): string
    {
        $bytes = '';
        while (\strlen($bytes) < $length) {
            $chunk = \fread($handle, $length - \strlen($bytes));
            if ($chunk === false || $chunk === '') {
                break; // truncated under us: parse what we have
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function parse(string $line): LogRecord
    {
        return match ($this->format) {
            LogFormat::Json => self::parseJson($line),
            LogFormat::Logfmt => self::parseLogfmt($line),
            LogFormat::Text => $this->parseText($line),
        };
    }

    private static function parseJson(string $line): LogRecord
    {
        try {
            $decoded = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new LogRecord($line, null, $line);
        }
        if (!\is_array($decoded) || \array_is_list($decoded)) {
            return new LogRecord($line, null, $line);
        }

        return self::fromMembers($line, $decoded);
    }

    private static function parseLogfmt(string $line): LogRecord
    {
        $pairs = self::logfmtPairs($line);
        if ($pairs === null) {
            return new LogRecord($line, null, $line);
        }

        return self::fromMembers($line, $pairs);
    }

    /**
     * Splits the envelope members the formatters write from the context.
     *
     * @param array<array-key, mixed> $members
     */
    private static function fromMembers(string $line, array $members): LogRecord
    {
        $level = \is_string($members['level'] ?? null) ? Level::fromLabel($members['level']) : null;
        $message = $members['msg'] ?? null;
        $fields = [];
        foreach ($members as $key => $value) {
            if (!\in_array((string) $key, ['level', 'msg', 'time', 'prefix', 'caller'], true)) {
                $fields[(string) $key] = $value;
            }
        }

        return new LogRecord(
            $line,
            $level,
            \is_string($message) ? $message : '',
            self::stringOrNull($members['time'] ?? null),
            self::stringOrNull($members['prefix'] ?? null),
            self::stringOrNull($members['caller'] ?? null),
            $fields,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /**
     * Inverse of LogfmtFormatter::escape(): bare values end at whitespace;
     * quoted values take `\\`, `\"`, `\n`, `\r` and `\t` escapes.
     *
     * @return array<string, string>|null null when the line is not logfmt
     */
    private static function logfmtPairs(string $line): ?array
    {
        $pairs = [];
        $length = \strlen($line);
        $i = 0;
        while ($i < $length) {
            while ($i < $length && \ctype_space($line[$i])) {
                ++$i;
            }
            if ($i >= $length) {
                break;
            }

            $keyStart = $i;
            while ($i < $length && $line[$i] !== '=' && !\ctype_space($line[$i])) {
                ++$i;
            }
            if ($i >= $length || $line[$i] !== '=' || $i === $keyStart) {
                return null;
            }
            $key = \substr($line, $keyStart, $i - $keyStart);
            ++$i; // past '='

            if ($i < $length && $line[$i] === '"') {
                ++$i;
                $value = '';
                $closed = false;
                while ($i < $length) {
                    $char = $line[$i];
                    if ($char === '\\' && $i + 1 < $length) {
                        $value .= match ($line[$i + 1]) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            default => $line[$i + 1],
                        };
                        $i += 2;
                        continue;
                    }
                    if ($char === '"') {
                        $closed = true;
                        ++$i;
                        break;
                    }
                    $value .= $char;
                    ++$i;
                }
                if (!$closed) {
                    return null;
                }
            } else {
                $valueStart = $i;
                while ($i < $length && !\ctype_space($line[$i])) {
                    ++$i;
                }
                $value = \substr($line, $valueStart, $i - $valueStart);
            }

            $pairs[$key] = $value;
        }

        return $pairs === [] ? null : $pairs;
    }

    /**
     * Text lines keep their bytes as the message; only the level is lifted.
     *
     * The text formatter writes the level as its short label (`WRN`), so only
     * those count: a message word such as `ERROR`, `info` or `Warn` never
     * passes for the level. Styling is stripped before the search, since a
     * colored file (`CLICOLOR_FORCE`) wraps the label in SGR sequences, while
     * the record keeps the line exactly as written.
     *
     * Where the label sits follows the writer's PartsOrder ({@see
     * withPartsOrder()}): no level part means no level, a level written
     * before the message is the first label on the line, and one written
     * after it is the last, so a label-shaped word in the message is passed
     * over. What stays ambiguous is a context value containing a spaced label
     * word on a line whose level follows the message.
     */
    private function parseText(string $line): LogRecord
    {
        if (!$this->partsOrder->has(PartsOrder::PART_LEVEL)) {
            return new LogRecord($line, null, $line);
        }

        $labels = [];
        foreach (Level::cases() as $case) {
            $labels[$case->shortLabel()] = $case;
        }
        $level = null;
        $tokens = \preg_split('/\s+/', \trim(self::stripAnsi($line))) ?: [];
        foreach (self::levelFollowsMessage($this->partsOrder) ? \array_reverse($tokens) : $tokens as $token) {
            if (isset($labels[$token])) {
                $level = $labels[$token];
                break;
            }
        }

        return new LogRecord($line, $level, $line);
    }

    private static function levelFollowsMessage(PartsOrder $order): bool
    {
        $message = \array_search(PartsOrder::PART_MESSAGE, $order->parts, true);

        return $message !== false && $message < \array_search(PartsOrder::PART_LEVEL, $order->parts, true);
    }

    /** Drops CSI (SGR and friends), OSC and two-byte ESC sequences: the escapes styling writes. */
    private static function stripAnsi(string $line): string
    {
        return \preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)?|\x1b[@-Z\\\\-_]/', '', $line) ?? $line;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...\array_merge(\get_object_vars($this), $changes));
    }
}
