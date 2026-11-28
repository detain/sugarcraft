<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;

/**
 * A TEA log viewer: the records of a log file a {@see LogFilter} keeps,
 * following the file as it grows.
 *
 * ```php
 * $viewer = LogViewer::new(LogReader::new('/var/log/app.log', LogFormat::Json))
 *     ->withFilter(LogFilter::new()->withMinLevel(Level::Warn))
 *     ->withHeight(20);
 * ```
 *
 * Reading the file is a side effect, so it only happens inside a Cmd:
 * {@see init()} reads at once and starts the viewer's one follow
 * chain. The chain runs for the viewer's whole life: every
 * {@see tick()} interval it reads if the viewer follows and only
 * waits if it does not, so a viewer resumed or pointed at another file with
 * a setter picks the change up at the next tick without any Cmd from the
 * caller, as long as it follows: a paused viewer keeps the records it holds
 * until it resumes or a {@see refresh()} reads. {@see refresh()} is a
 * one-off read beside the chain, never a second chain. {@see update()} only folds the result in, and {@see view()} only
 * renders the records it already holds, with the last read's error, if
 * any, on its bottom row.
 *
 * Reads go through the filter. The first read takes the last
 * {@see withTailSize()} records the filter keeps, scanning back through the
 * file as far as it takes to find them, so matches far from the end show
 * too. Every later read resumes from a {@see LogCursor} where the one before
 * stopped and appends every matching record written since: a burst within
 * one interval, or the lines written while the viewer was paused, all land
 * below the older ones, none skipped. The viewer holds at most
 * {@see withMaxRecords()} records, dropping the oldest first. A line still
 * missing its newline waits for it. A truncated or replaced file starts over
 * from its tail, as does the next read after {@see withReader()},
 * {@see withFilter()} or a failed read.
 *
 * The filter is also applied at render time, so a stricter filter narrows
 * the records already held at once; the next read, from the tail again,
 * brings back the records a looser filter now keeps.
 *
 * Keys: `f` toggles follow. Every other message is ignored.
 *
 * Immutable: every with*() returns a new viewer through mutate(). The read
 * side of the display that sugar-dashboard's `Components\System\LogViewer`
 * renders (severity-colored rows, optional timestamps and level prefix,
 * bottom-anchored scrolling, padded and clipped rows).
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final class LogViewer implements Model
{
    use Mutable;

    /** How long, in seconds, a following viewer waits between reads unless told otherwise. */
    public const DEFAULT_INTERVAL = 1.0;

    /** How many matching records the first read takes from the end of the file unless told otherwise. */
    public const DEFAULT_TAIL_SIZE = 500;

    /** How many records the viewer holds, at most, unless told otherwise. */
    public const DEFAULT_MAX_RECORDS = 10_000;

    /** Rows {@see view()} renders unless told otherwise. */
    public const DEFAULT_HEIGHT = 10;

    private static int $nextId = 0;

    /**
     * @param list<LogRecord>   $records     The records read so far that the filter kept then, oldest first, at most $maxRecords.
     * @param ?LogCursor        $cursor      Where the next read resumes; null reads from the tail.
     * @param ?int              $width       Columns per row; null leaves rows unpadded and unclipped.
     * @param array<int, Style> $levelStyles Row style per Level->value.
     * @param ?string           $error       Why the last read failed; null after a good read.
     * @param int               $id          Routes this viewer's {@see LogTailMsg}s back to it.
     * @param int               $generation  Bumped when follow toggles or the reader or filter changes, so a read issued before has its records dropped.
     */
    private function __construct(
        public readonly LogReader $reader,
        public readonly LogFilter $filter,
        public readonly array $records,
        public readonly bool $follow,
        public readonly float $interval,
        public readonly int $tailSize,
        public readonly int $maxRecords,
        public readonly ?LogCursor $cursor,
        public readonly int $height,
        public readonly ?int $width,
        public readonly bool $showTimestamps,
        public readonly bool $showLevel,
        public readonly bool $scrollToBottom,
        public readonly bool $color,
        public readonly array $levelStyles,
        public readonly ?string $error,
        public readonly int $id,
        public readonly int $generation,
    ) {
    }

    /**
     * A following viewer over $reader. With no $filter every record shows.
     * Nothing is read until the Cmd from {@see init()} runs.
     */
    public static function new(LogReader $reader, ?LogFilter $filter = null): self
    {
        return new self(
            reader: $reader,
            filter: $filter ?? LogFilter::new(),
            records: [],
            follow: true,
            interval: self::DEFAULT_INTERVAL,
            tailSize: self::DEFAULT_TAIL_SIZE,
            maxRecords: self::DEFAULT_MAX_RECORDS,
            cursor: null,
            height: self::DEFAULT_HEIGHT,
            width: null,
            showTimestamps: false,
            showLevel: true,
            scrollToBottom: true,
            color: true,
            levelStyles: (new Styles())->levels,
            error: null,
            id: ++self::$nextId,
            generation: 0,
        );
    }

    /**
     * The first read, whether or not the viewer follows (a viewer that never
     * read has nothing to show), which also starts the follow chain.
     */
    public function init(): ?\Closure
    {
        return $this->read(chained: true);
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof LogTickMsg) {
            if ($msg->viewerId !== $this->id) {
                return [$this, null];
            }

            // A paused viewer keeps its chain alive without reading, so a
            // later withFollow(true) needs no Cmd to start reading again.
            return [$this, $this->follow ? $this->read(chained: true) : $this->tick()];
        }

        if ($msg instanceof LogTailMsg) {
            if ($msg->viewerId !== $this->id) {
                return [$this, null];
            }
            $next = $msg->generation === $this->generation ? $this->taking($msg) : $this;

            // Only the chain's own read schedules the next tick, so the chain
            // outlives a dropped read and a refresh() never forks a second
            // one. A failed read keeps the chain too: the file may come back.
            return [$next, $msg->chained ? $next->tick() : null];
        }

        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'f'
            && !$msg->ctrl && !$msg->alt) {
            $next = $this->withFollow(!$this->follow);

            // Catch up at once on resume instead of waiting a whole interval.
            return [$next, $next->follow ? $next->refresh() : null];
        }

        return [$this, null];
    }

    /**
     * The filtered records that fit, one row each, exactly {@see $height}
     * rows. Blank rows fill the space the records leave, after them: a view
     * holding fewer records than its height shows them from the top row, so
     * the newest sits on the bottom row only once the records fill the view.
     *
     * After a failed read the bottom row is {@see $error}, styled as an
     * error, and the records take the rows above it: the records it shows
     * are no longer live, and a view that looked the same would hide that.
     * The next good read clears it.
     */
    public function view(): string
    {
        $space = $this->error === null ? $this->height : $this->height - 1;
        $visible = $this->visibleRecords();
        $shown = $this->scrollToBottom
            ? \array_slice($visible, \max(0, \count($visible) - $space))
            : \array_slice($visible, 0, $space);

        $rows = \array_map($this->row(...), $shown);
        $blank = \str_repeat(' ', $this->width ?? 0);
        while (\count($rows) < $space) {
            $rows[] = $blank;
        }
        if ($this->error !== null) {
            $rows[] = $this->styled($this->error, Level::Error);
        }

        return \implode("\n", $rows);
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /**
     * A Cmd that reads now and hands the result back as a {@see LogTailMsg},
     * without waiting for the next tick: after {@see withReader()} or
     * {@see withFollow()} it catches up at once instead of an interval later.
     * It is a one-off read and never starts a second follow chain.
     */
    public function refresh(): \Closure
    {
        return $this->read(chained: false);
    }

    /**
     * A Cmd that hands back a {@see LogTickMsg} after {@see $interval}
     * seconds: one beat of the follow chain {@see init()} starts. The viewer
     * decides on taking the tick whether to read, so nothing is read here.
     * The chain reschedules itself; issuing this yourself starts a second one.
     */
    public function tick(): \Closure
    {
        $id = $this->id;

        return Cmd::tick($this->interval, static fn (): Msg => new LogTickMsg($id));
    }

    /**
     * The held records the filter keeps, oldest first — every record the
     * viewer could show, before the height cut.
     *
     * @return list<LogRecord>
     */
    public function visibleRecords(): array
    {
        return $this->filter->apply($this->records);
    }

    /**
     * Read another file, from its tail. Reads of the old one in flight are
     * dropped; a following viewer's chain reads the new one at its next tick, or issue
     * {@see refresh()} to read it at once. A paused viewer reads nothing on a
     * tick, so it keeps showing the old file's records until it resumes or a
     * {@see refresh()} reads the new one.
     */
    public function withReader(LogReader $reader): self
    {
        return $this->mutate(['reader' => $reader, 'cursor' => null, 'generation' => $this->generation + 1]);
    }

    /**
     * Keep only the records $filter matches. The held records are narrowed
     * at once; reads in flight are dropped, and the next read starts over
     * from the tail through $filter, so a looser filter brings back the
     * records the old one left out. A paused viewer reads nothing on a tick,
     * so issue {@see refresh()} for that read while paused.
     */
    public function withFilter(LogFilter $filter): self
    {
        return $this->mutate(['filter' => $filter, 'cursor' => null, 'generation' => $this->generation + 1]);
    }

    /**
     * Follow the file or freeze on what is held. A change drops the reads in
     * flight, so a paused viewer takes no late read. The follow chain keeps
     * running while paused without reading, so a resumed viewer reads again
     * at the next tick; issue {@see refresh()} to read at once (the `f` key
     * does). Setting the current value changes nothing.
     */
    public function withFollow(bool $follow): self
    {
        if ($follow === $this->follow) {
            return $this->mutate([]);
        }

        return $this->mutate(['follow' => $follow, 'generation' => $this->generation + 1]);
    }

    /** @throws \InvalidArgumentException When $seconds is not greater than zero. */
    public function withInterval(float $seconds): self
    {
        if (\is_nan($seconds) || $seconds <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('viewer.not_positive', ['name' => 'interval', 'value' => (string) $seconds]));
        }

        return $this->mutate(['interval' => $seconds]);
    }

    /**
     * How many matching records a read from the tail takes: the first read,
     * and the one after a reader or filter change, a failed read or a
     * truncated file. Later reads append every matching record written since,
     * however many.
     *
     * @throws \InvalidArgumentException When $records is less than one.
     */
    public function withTailSize(int $records): self
    {
        if ($records < 1) {
            throw new \InvalidArgumentException(Lang::t('viewer.not_positive', ['name' => 'tailSize', 'value' => (string) $records]));
        }

        return $this->mutate(['tailSize' => $records]);
    }

    /**
     * The most records the viewer holds; past it the oldest are dropped.
     *
     * @throws \InvalidArgumentException When $records is less than one.
     */
    public function withMaxRecords(int $records): self
    {
        if ($records < 1) {
            throw new \InvalidArgumentException(Lang::t('viewer.not_positive', ['name' => 'maxRecords', 'value' => (string) $records]));
        }

        return $this->mutate(['maxRecords' => $records, 'records' => \array_slice($this->records, -$records)]);
    }

    /** @throws \InvalidArgumentException When $rows is less than one. */
    public function withHeight(int $rows): self
    {
        if ($rows < 1) {
            throw new \InvalidArgumentException(Lang::t('viewer.not_positive', ['name' => 'height', 'value' => (string) $rows]));
        }

        return $this->mutate(['height' => $rows]);
    }

    /**
     * Pad and clip every row to $columns cells; null renders rows at their
     * own width. A clipped row ends in `…`.
     *
     * @throws \InvalidArgumentException When $columns is less than one.
     */
    public function withWidth(?int $columns): self
    {
        if ($columns !== null && $columns < 1) {
            throw new \InvalidArgumentException(Lang::t('viewer.not_positive', ['name' => 'width', 'value' => (string) $columns]));
        }

        return $this->mutate(['width' => $columns]);
    }

    /** Show a structured record's time as `[time]`. A text line shows as written either way. */
    public function withShowTimestamps(bool $show): self
    {
        return $this->mutate(['showTimestamps' => $show]);
    }

    /** Show a structured record's level as `[INF]`. A text line shows as written either way. */
    public function withShowLevel(bool $show): self
    {
        return $this->mutate(['showLevel' => $show]);
    }

    /** Show the newest records (true) or the oldest of those held (false) when they overflow the height. */
    public function withScrollToBottom(bool $bottom): self
    {
        return $this->mutate(['scrollToBottom' => $bottom]);
    }

    /** Color each row by its level; false renders plain text. */
    public function withColor(bool $color): self
    {
        return $this->mutate(['color' => $color]);
    }

    /**
     * Take the level palette from $styles. The styles are read once here, so
     * editing $styles afterwards does not reach this viewer.
     */
    public function withStyles(Styles $styles): self
    {
        return $this->mutate(['levelStyles' => $styles->levels]);
    }

    /** A Cmd that reads on from the cursor when it runs, not when it is built. */
    private function read(bool $chained): \Closure
    {
        $reader = $this->reader;
        $filter = $this->filter;
        $from = $this->cursor;
        $tailSize = $this->tailSize;
        $maxRecords = $this->maxRecords;
        $id = $this->id;
        $generation = $this->generation;

        return static function () use ($reader, $filter, $from, $tailSize, $maxRecords, $id, $generation, $chained): Msg {
            try {
                $chunk = $reader->follow($from, $tailSize, $filter, $maxRecords);
            } catch (\RuntimeException $e) {
                return new LogTailMsg($id, $generation, [], $e->getMessage(), $chained, $from);
            }

            return new LogTailMsg($id, $generation, $chunk->records, null, $chained, $from, $chunk->cursor, $chunk->fresh);
        };
    }

    /** Folds in a read of the current generation. */
    private function taking(LogTailMsg $msg): self
    {
        if ($msg->error !== null) {
            // The file may come back as another file: start over from its tail.
            return $this->mutate(['error' => $msg->error, 'cursor' => null]);
        }
        if ($msg->fresh) {
            $records = $msg->records;
        } elseif ($this->cursor !== null && $this->cursor->equals($msg->from)) {
            $records = [...$this->records, ...$msg->records];
        } else {
            // Resumed from a cursor already read past (a refresh beside a
            // tick): its lines are held already.
            return $this;
        }

        return $this->mutate([
            'records' => \array_slice($records, -$this->maxRecords),
            'cursor' => $msg->cursor,
            'error' => null,
        ]);
    }

    private function row(LogRecord $record): string
    {
        return $this->styled($this->rowText($record), $record->level);
    }

    private function styled(string $text, ?Level $level): string
    {
        // Log bytes come from outside the process: one row each, no escapes.
        $text = \str_replace(["\n", "\t"], ' ', Sanitize::untrustedForDisplay($text));
        if ($this->width !== null) {
            $text = Width::padRight(Width::clipCells($text, $this->width), $this->width);
        }

        $style = $level === null ? null : ($this->levelStyles[$level->value] ?? null);
        if (!$this->color || $style === null) {
            return $text;
        }

        return $style->render($text);
    }

    private function rowText(LogRecord $record): string
    {
        // A text line (or one the format could not parse) is its own message.
        if ($record->message === $record->line) {
            return $record->line;
        }

        $parts = [];
        if ($this->showTimestamps && $record->time !== null) {
            $parts[] = '[' . $record->time . ']';
        }
        if ($this->showLevel && $record->level !== null) {
            $parts[] = '[' . $record->level->shortLabel() . ']';
        }
        if ($record->prefix !== null && $record->prefix !== '') {
            $parts[] = $record->prefix;
        }
        if ($record->message !== '') {
            $parts[] = $record->message;
        }
        foreach ($record->fields as $key => $value) {
            $parts[] = $key . '=' . self::fieldValue($value);
        }

        return \implode(' ', $parts);
    }

    private static function fieldValue(mixed $value): string
    {
        $written = match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            default => \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '?',
        };

        // Quote what would not read back as one value, the way logfmt does.
        // An array or object is judged by its JSON text: a space inside one
        // of its strings would split the row there just the same.
        if ((\is_string($value) && ($value === '' || \preg_match('/[\s"=]/', $value) === 1))
            || (!\is_scalar($value) && $value !== null && \preg_match('/\s/', $written) === 1)) {
            return \json_encode(\is_string($value) ? $value : $written, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE) ?: '""';
        }

        return $written;
    }
}
