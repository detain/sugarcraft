<?php

declare(strict_types=1);

namespace SugarCraft\Log;

/**
 * Read side of a log file: `LogReader::new($path, LogFormat::Json)->tail(50)`.
 *
 * {@see tail()} seeks to the end and reads backwards in fixed chunks until it
 * has the lines it needs, so the cost follows the lines asked for, not the
 * file size — tailing a multi-gigabyte log reads a few kilobytes.
 *
 * Immutable: every with*() returns a new reader through mutate(). Rotation
 * (following a log across a rename to `.1`) is not handled here; it waits on
 * plan decision D6.
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
    ) {
    }

    /** A reader over $path that parses lines as $format. */
    public static function new(string $path, LogFormat $format = LogFormat::Text): self
    {
        return new self($path, $format);
    }

    public function withPath(string $path): self
    {
        return $this->mutate(['path' => $path]);
    }

    public function withFormat(LogFormat $format): self
    {
        return $this->mutate(['format' => $format]);
    }

    /**
     * The last $n records of the file, oldest first.
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
    public function tail(int $n): array
    {
        if ($n < 0) {
            throw new \InvalidArgumentException(Lang::t('reader.negative_count', ['count' => (string) $n]));
        }
        if ($n === 0) {
            return [];
        }

        return \array_map($this->parse(...), $this->lastLines($n));
    }

    /**
     * @return list<string> oldest first, blank lines skipped
     */
    private function lastLines(int $n): array
    {
        \clearstatcache(true, $this->path);
        if (!\file_exists($this->path)) {
            return [];
        }
        $handle = \is_file($this->path) ? @\fopen($this->path, 'rb') : false;
        if ($handle === false) {
            throw new \RuntimeException(Lang::t('reader.unreadable', ['path' => $this->path]));
        }

        try {
            $position = (int) (\fstat($handle)['size'] ?? 0);
            $carry = '';
            /** @var list<string> $newestFirst */
            $newestFirst = [];

            while ($position > 0 && \count($newestFirst) < $n) {
                $step = \min(self::CHUNK_BYTES, $position);
                $position -= $step;
                \fseek($handle, $position);
                $carry = self::readExactly($handle, $step) . $carry;

                $pieces = \explode("\n", $carry);
                // The first piece may continue into bytes not read yet, so it
                // waits for the next step (or for the start of the file).
                $carry = \array_shift($pieces);
                for ($i = \count($pieces) - 1; $i >= 0 && \count($newestFirst) < $n; --$i) {
                    self::collect($pieces[$i], $newestFirst);
                }
            }

            if ($position === 0 && \count($newestFirst) < $n) {
                self::collect($carry, $newestFirst);
            }
        } finally {
            \fclose($handle);
        }

        return \array_reverse($newestFirst);
    }

    /**
     * @param list<string> $into
     */
    private static function collect(string $piece, array &$into): void
    {
        $line = \rtrim($piece, "\r");
        if (\trim($line) !== '') {
            $into[] = $line;
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
            LogFormat::Text => self::parseText($line),
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
     * Text lines keep their bytes as the message; only the level is lifted,
     * from the first whitespace-separated token that is a level label (the
     * text formatter's layout moves with PartsOrder and the time format, so
     * nothing else has a fixed position to parse from). Only upper-case
     * tokens count, as the formatter writes them, so a message word such as
     * "info" or "error" never passes for the level.
     */
    private static function parseText(string $line): LogRecord
    {
        $level = null;
        foreach (\preg_split('/\s+/', \trim($line)) ?: [] as $token) {
            if ($token !== \strtoupper($token)) {
                continue;
            }
            $level = Level::fromLabel($token);
            if ($level !== null) {
                break;
            }
        }

        return new LogRecord($line, $level, $line);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...\array_merge(\get_object_vars($this), $changes));
    }
}
