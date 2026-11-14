<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Tty;

/**
 * Non-blocking keystroke bytes from a Windows console input handle.
 *
 * A console stdin is not a pipe: fread() on it parks until a whole line (or,
 * in raw mode, a key) arrives, and stream_select() cannot wait on it. What
 * can be read without blocking are its input RECORDS: ReadConsoleInputW after
 * a zero-timeout wait ({@see Kernel32Interface::readConsoleInput()}).
 *
 * With ENABLE_VIRTUAL_TERMINAL_INPUT set on the handle (the raw mode
 * {@see WindowsBackend::enableRawMode()} applies), the console host delivers
 * every key, arrows and function keys included, as the xterm byte sequence a
 * POSIX terminal would send, spread over key-down records one UTF-16 code
 * unit each. This class drains those records and returns the UTF-8 bytes:
 * key-down records only, each char repeated `repeat` times, surrogate pairs
 * joined even when they straddle two reads. Key-up, focus, menu, mouse and
 * buffer-size records carry no input bytes and are consumed silently.
 */
final class WindowsConsoleInput
{
    /** A high surrogate whose low half has not arrived yet. */
    private ?int $pendingHigh = null;

    public function __construct(
        private readonly int $handle,
        private readonly Kernel32Interface $kernel32,
    ) {
    }

    /**
     * A reader over this process's stdin when stdin is a console; null when
     * it is a pipe or file (read those as streams), or when kernel32 is not
     * reachable (no FFI, not Windows).
     */
    public static function forStdin(?Kernel32Interface $kernel32 = null): ?self
    {
        try {
            $kernel32 ??= Kernel32::self();
            $handle = $kernel32->stdIn();
            $mode = $kernel32->getConsoleMode($handle);
        } catch (\Throwable) {
            return null;
        }

        // GetConsoleMode fails on anything that is not a console handle.
        if ($mode === false || $mode === null) {
            return null;
        }

        return new self($handle, $kernel32);
    }

    /**
     * Drain every pending input record and return the keystroke bytes they
     * carry; '' when none are pending. Never blocks.
     */
    public function read(int $maxRecords = 64): string
    {
        $bytes = '';
        // Bounded so a key held down cannot pin the caller here forever.
        for ($batch = 0; $batch < 16; $batch++) {
            $records = $this->kernel32->readConsoleInput($this->handle, \max(1, $maxRecords));
            if (!\is_array($records) || $records === []) {
                break;
            }
            foreach ($records as $record) {
                $bytes .= $this->recordBytes($record);
            }
            if (\count($records) < $maxRecords) {
                break;
            }
        }

        return $bytes;
    }

    /**
     * The keystroke bytes carried by ONE input record; '' for records that
     * carry none (key-up, focus, menu, mouse, buffer-size, bare modifiers).
     *
     * Public from sc-02 (finding sugar-core-052) as the shared decoder seam:
     * {@see WindowsBackend::drainSignals()} forwards the non-interrupt records
     * its CONIN$ poll touched through THIS instance, so the surrogate-join
     * state is shared with the read() path instead of reimplemented beside it.
     *
     * @param array{type:int, keyDown?:bool, repeat?:int, char?:int} $record
     */
    public function recordBytes(array $record): string
    {
        if ($record['type'] !== Kernel32Interface::KEY_EVENT || ($record['keyDown'] ?? false) !== true) {
            return '';
        }
        $unit = (int) ($record['char'] ?? 0);
        if ($unit === 0) {
            // A bare modifier or a key with no character (Shift, Ctrl alone).
            return '';
        }
        $repeat = \max(1, (int) ($record['repeat'] ?? 1));

        if ($unit >= 0xD800 && $unit <= 0xDBFF) {
            $this->pendingHigh = $unit;

            return '';
        }
        if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
            if ($this->pendingHigh === null) {
                return '';
            }
            $codepoint = 0x10000 + (($this->pendingHigh - 0xD800) << 10) + ($unit - 0xDC00);
            $this->pendingHigh = null;

            return \str_repeat((string) \mb_chr($codepoint, 'UTF-8'), $repeat);
        }
        $this->pendingHigh = null;

        return \str_repeat((string) \mb_chr($unit, 'UTF-8'), $repeat);
    }
}
