<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Tee target for {@see Program} input bytes, output bytes, and lifecycle
 * events. Wired via {@see Program::withRecorder()}.
 *
 * candy-core defines the interface so the runtime can record without
 * depending on a concrete recorder. The canonical implementation is
 * `SugarCraft\Vcr\Recorder` in the `sugarcraft/candy-vcr` package
 * (cassette files); other consumers — debug tap, network mirror, crash
 * bundle — can implement it to slot into the same hook.
 *
 * Method-call order over a session:
 *   1. {@see recordResize()} — once at startup with the initial TTY size,
 *      then again on every SIGWINCH.
 *   2. {@see recordInputBytes()} — every chunk read from the input stream,
 *      before {@see InputReader::parse()}.
 *   3. {@see recordOutput()} — every chunk written to the output stream by
 *      the renderer, RawMsg / PrintMsg, and the per-frame cursor / title /
 *      mode emitters in Program.
 *   4. {@see recordQuit()} — once when QuitMsg is dispatched.
 *   5. {@see close()} — once after recordQuit, idempotent.
 */
interface Recorder
{
    public function recordResize(int $cols, int $rows): void;

    public function recordInputBytes(string $bytes): void;

    public function recordOutput(string $bytes): void;

    public function recordQuit(): void;

    public function close(): void;
}
