<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

use SugarCraft\Core\Recorder as RecorderInterface;
use SugarCraft\Vcr\Hook\HookRegistry;

/**
 * Streaming JSONL cassette writer. Each event is encoded and flushed
 * immediately so a crash mid-recording doesn't lose the cassette.
 *
 * Implements {@see \SugarCraft\Core\Recorder} so it can be attached via
 * {@see \SugarCraft\Core\Program::withRecorder()}.
 *
 * Usage:
 * ```php
 * $recorder = Recorder::open('/tmp/session.cas');
 * (new Program($model))->withRecorder($recorder)->run();
 * // recorder is closed automatically when the loop ends
 * ```
 */
final class Recorder implements RecorderInterface
{
    /** @var resource|null */
    private $fh;
    private readonly float $startTime;
    private bool $closed = false;
    private HookRegistry $hooks;

    /**
     * @param resource $fh  Open writable stream — typically a file opened
     *                      via {@see open()}, but any wb-mode stream works
     *                      (php://memory, php://temp, network sockets).
     */
    public function __construct(
        $fh,
        CassetteHeader $header,
        ?float $startTime = null,
        ?HookRegistry $hooks = null,
    ) {
        if (!is_resource($fh)) {
            throw new \InvalidArgumentException('Recorder requires an open stream resource');
        }
        $this->fh = $fh;
        $this->startTime = $startTime ?? microtime(true);
        $this->hooks = $hooks ?? new HookRegistry();
        $headerLine = [
            'v' => $header->version,
            'created' => $header->createdAt,
            'cols' => $header->cols,
            'rows' => $header->rows,
            'runtime' => $header->runtime,
        ];
        if ($header->timestampMode !== CassetteHeader::TIMESTAMP_MODE_ABSOLUTE) {
            $headerLine['timestampMode'] = $header->timestampMode;
        }
        if ($header->env !== []) {
            $headerLine['env'] = $header->env;
        }
        $this->writeLine($headerLine);
    }

    /**
     * Open a cassette file at the given path for writing. Convenience
     * factory — fills in a sensible CassetteHeader (cols=80, rows=24,
     * current UTC time, candy-vcr runtime tag) when omitted; an initial
     * `recordResize` event will overwrite the placeholder dimensions.
     */
    public static function open(
        string $path,
        ?CassetteHeader $header = null,
    ): self {
        $fh = @fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("candy-vcr: cannot open recorder file {$path}");
        }
        return new self($fh, $header ?? self::defaultHeader());
    }

    public static function defaultHeader(int $cols = 80, int $rows = 24, string $runtime = 'sugarcraft/candy-vcr@dev'): CassetteHeader
    {
        return new CassetteHeader(
            version: CassetteHeader::CURRENT_VERSION,
            createdAt: gmdate('Y-m-d\TH:i:s\Z'),
            cols: $cols,
            rows: $rows,
            runtime: $runtime,
        );
    }

    /**
     * Add a hook to be called during recording.
     *
     * @return $this
     */
    public function withHook(\SugarCraft\Vcr\Hook\Hook $hook): self
    {
        $this->hooks->addHook($hook);
        return $this;
    }

    /**
     * Get the hook registry for direct manipulation.
     */
    public function hooks(): HookRegistry
    {
        return $this->hooks;
    }

    public function recordResize(int $cols, int $rows): void
    {
        if ($this->closed) {
            return;
        }
        $this->writeEvent('resize', ['cols' => $cols, 'rows' => $rows]);
    }

    public function recordInputBytes(string $bytes): void
    {
        if ($this->closed || $bytes === '') {
            return;
        }
        $this->writeEvent('input', ['b' => $bytes]);
    }

    public function recordOutput(string $bytes): void
    {
        if ($this->closed || $bytes === '') {
            return;
        }
        $this->writeEvent('output', ['b' => $bytes]);
    }

    public function recordQuit(): void
    {
        if ($this->closed) {
            return;
        }
        $this->writeEvent('quit', []);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (is_resource($this->fh)) {
            @fclose($this->fh);
        }
        $this->fh = null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeEvent(string $kind, array $payload): void
    {
        $event = new Event(
            t: round(microtime(true) - $this->startTime, 3),
            kind: EventKind::from($kind),
            payload: $payload,
        );

        // Run beforeSave hooks
        $event = $this->hooks->beforeSave($event);
        if ($event === null) {
            // Event was suppressed by a hook
            return;
        }

        $line = ['t' => $event->t, 'k' => $event->kind->value, ...$event->payload];
        $this->writeLine($line);

        // Run afterCapture hooks (fire-and-forget)
        $this->hooks->afterCapture($event);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeLine(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('candy-vcr: json_encode failed: ' . json_last_error_msg());
        }
        if (!is_resource($this->fh)) {
            throw new \LogicException('candy-vcr: recorder stream is not open');
        }
        fwrite($this->fh, $json . "\n");
        @fflush($this->fh);
    }
}
