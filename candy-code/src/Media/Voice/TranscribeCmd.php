<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Voice;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\App\VoiceFailedMsg;
use SugarCraft\Code\App\VoiceTranscribedMsg;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Support\ToolIpcFiles;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use Throwable;

/**
 * Runs one transcription off the TUI thread (C-TUI-6.l).
 *
 * MIRRORS {@see \SugarCraft\Code\Config\StatusLineCommand::cmd()} exactly:
 * a forked child does the HTTP work, the parent loop polls it with
 * `pcntl_waitpid(WNOHANG)` on a periodic timer, and the answer rides back
 * through a {@see ToolIpcFiles} one-shot JSON file. Where pcntl is missing
 * (Windows, or a stripped build) the same call runs synchronously on the
 * caller thread instead — the StatusLine fallback, for the same reason: a
 * fallback that refuses to exist is a feature that silently dies.
 *
 * THE LATCH, NOT THE TIMEOUT, bounds concurrency: one in-flight transcription
 * at a time, a second cmd() while the first runs returns null and the caller
 * keeps waiting. There is deliberately no wall-clock kill on the request
 * (E646): the Transcriber's connect bound plus its low-speed stall abort
 * already bound dead pipes; a slow-but-live whisper endpoint is allowed to
 * finish. The take itself is capped by the recorder's own `-d`/`trim`.
 *
 * NOTHING PRINTABLE EVER CARRIES THE KEY. The child's error sentence is a
 * {@see TranscriptionException} message — status codes and sanitized
 * endpoint text only, by that class's own law — and the parent wraps it in
 * the generic `media.voice.failed` sentence before anything sees it.
 */
final class TranscribeCmd
{
    /** {@see \SugarCraft\Code\Config\StatusLineCommand::POLL_SECONDS}. */
    private const POLL_SECONDS = 0.05;

    private static bool $inFlight = false;

    /**
     * Launch the upload. Returns the command closure the fold runs (same
     * shape as StatusLineCommand::cmd()), or null when one is already
     * in flight. An empty take never reaches the child: it is deleted and
     * answered with the visible `media.voice.no_audio` refusal — a take
     * that captured nothing is said so, never sent and never shrugged off.
     */
    public static function cmd(AudioRecording $recording, ?VoiceConfig $config = null): ?\Closure
    {
        if (self::$inFlight) {
            return null;
        }

        $config = $config ?? VoiceConfig::fromSettings();

        if (!AudioRecorder::hasAudio($recording->path)) {
            AudioRecorder::cancel($recording);

            return static fn(): Msg => new VoiceFailedMsg(Lang::t('media.voice.no_audio'));
        }

        self::$inFlight = true;

        return Cmd::promise(static function () use ($recording, $config): PromiseInterface {
            $deferred = new Deferred();

            if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
                $deferred->resolve(self::settle(self::run($recording, $config)));

                return $deferred->promise();
            }

            $file = ToolIpcFiles::reserve(ToolIpcFiles::CHAT_PREFIX, 'json');
            $pid = pcntl_fork();

            if ($pid === -1) {
                ToolIpcFiles::discard($file);
                $deferred->resolve(self::settle(self::run($recording, $config)));

                return $deferred->promise();
            }

            if ($pid === 0) {
                ForkedChild::closeInheritedServerFds();
                ToolIpcFiles::write($file, json_encode(self::run($recording, $config), JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
                ForkedChild::exitNow(0);
            }

            $loop = Loop::get();
            $timer = null;
            $timer = $loop->addPeriodicTimer(
                self::POLL_SECONDS,
                static function () use ($pid, $file, $loop, &$timer, $deferred): void {
                    $status = 0;

                    if (pcntl_waitpid($pid, $status, WNOHANG) !== $pid) {
                        return;
                    }

                    $loop->cancelTimer($timer);
                    $deferred->resolve(self::settle(self::collect($file)));
                },
            );

            return $deferred->promise();
        });
    }

    /**
     * Turn a finished child run into the Msg that re-enters the model —
     * the settle mirror: it releases the in-flight latch on every path, so
     * a child that died with no file (OOM, kill -9) frees the next take
     * instead of latching the session shut.
     *
     * @param array{ok: bool, text: string, error: string} $result
     */
    public static function settle(array $result): Msg
    {
        self::$inFlight = false;

        if ($result['ok']) {
            return new VoiceTranscribedMsg($result['text']);
        }

        return new VoiceFailedMsg(Lang::t('media.voice.failed', ['reason' => $result['error']]));
    }

    public static function inFlight(): bool
    {
        return self::$inFlight;
    }

    /** Test seam: drop the latch a previous test set (the StatusLineCommand resetForTesting law). */
    public static function resetForTesting(): void
    {
        self::$inFlight = false;
    }

    /**
     * The work itself, in whichever process runs it: transcribe, and flatten
     * the outcome into the JSON the parent reads back. The exception class
     * is the sanitizer here — every TranscriptionException sentence is
     * printable by its own law; anything unanticipated is reduced to its
     * type name and never re-thrown across the IPC boundary.
     *
     * @return array{ok: bool, text: string, error: string}
     */
    private static function run(AudioRecording $recording, VoiceConfig $config): array
    {
        try {
            return ['ok' => true, 'text' => (new Transcriber())->transcribe($recording, $config), 'error' => ''];
        } catch (TranscriptionException $failure) {
            return ['ok' => false, 'text' => '', 'error' => $failure->getMessage()];
        } catch (Throwable $other) {
            return ['ok' => false, 'text' => '', 'error' => 'unexpected ' . \get_debug_type($other)];
        }
    }

    /**
     * @return array{ok: bool, text: string, error: string}
     */
    private static function collect(string $file): array
    {
        $raw = \is_file($file) ? @\file_get_contents($file) : false;
        ToolIpcFiles::discard($file);

        if (!\is_string($raw) || $raw === '') {
            return ['ok' => false, 'text' => '', 'error' => 'the transcription child returned nothing'];
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded) || !isset($decoded['ok'])) {
            return ['ok' => false, 'text' => '', 'error' => 'the transcription child returned a malformed answer'];
        }

        return [
            'ok' => (bool) $decoded['ok'],
            'text' => isset($decoded['text']) && \is_string($decoded['text']) ? $decoded['text'] : '',
            'error' => isset($decoded['error']) && \is_string($decoded['error']) ? $decoded['error'] : '',
        ];
    }
}
