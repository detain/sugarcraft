<?php

declare(strict_types=1);

namespace SugarCraft\Code\Media\Voice;

use RuntimeException;

/**
 * Why one transcription did not happen (C-TUI-6.l).
 *
 * THE KEY NEVER RIDES ALONG. Every message here is built from a status
 * code, a reason phrase, or a sanitized excerpt of the endpoint's own
 * body — never from the request, its headers, or the config. A transport
 * exception's message can echo request metadata on some handlers, so the
 * transport failure is translated (factory `transport()`) into a sentence
 * naming only the exception class, exactly like the Sd client's refusal
 * to forward provider noise. Whatever surfaces this in the TUI is safe to
 * print because nothing printable was ever put in.
 */
final class TranscriptionException extends RuntimeException
{
    public static function noAudio(): self
    {
        return new self('the take captured no audio');
    }

    public static function off(): self
    {
        return new self('voice input is off: no transcription endpoint is configured');
    }

    public static function missingFile(string $path): self
    {
        return new self('the take file is no longer on disk at ' . $path);
    }

    public static function unreadable(string $path): self
    {
        return new self('the take file could not be read at ' . $path);
    }

    public static function status(int $status, string $reason, string $excerpt): self
    {
        return new self('the transcription endpoint answered ' . $status . ($reason === '' ? '' : ' ' . $reason)
            . ($excerpt === '' ? '' : ': ' . $excerpt));
    }

    public static function unparseable(string $excerpt): self
    {
        return new self('the transcription response carried no text field' . ($excerpt === '' ? '' : ': ' . $excerpt));
    }

    public static function transport(string $exceptionClass): self
    {
        return new self('the transcription request did not complete (' . $exceptionClass . ')');
    }
}
