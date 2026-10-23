<?php

declare(strict_types=1);

/**
 * ProfileWriter — stream wrapper that automatically degrades ANSI color
 * sequences to match the terminal's detected color profile.
 *
 * Mirrors colorprofile.NewWriter / ProfileWriter in the Go library.
 *
 * Usage:
 *   $w = ProfileWriter::wrap(STDOUT);
 *   $w->write("\x1b[38;2;255;0;0mred\x1b[0m"); // auto-degrades if terminal is ANSI256/ANSI
 *
 * ProfileWriter is an object, not a stream resource — fwrite() on it is a
 * TypeError; all output must go through write()/printf().
 */
namespace SugarCraft\ColorProfile;

use SugarCraft\ColorProfile\Palette;

/**
 * Wraps a stream and degrades color escape sequences to match the target profile.
 *
 * Example:
 *   $w = ProfileWriter::wrap(STDOUT, ['TERM' => 'xterm-256color']);
 *   $w->write("\x1b[38;2;100;200;50mGreenish\x1b[0m\n");
 */
final class ProfileWriter
{
    /** The underlying stream resource. */
    private $stream;

    /** The color profile to degrade to. */
    private ColorProfile $profile;

    /** Detected from environment. */
    private static function buildProfile(array $env, $stream): ColorProfile
    {
        // Allow FORCE_COLOR / NO_COLOR / COLORTERM / TERM_PROGRAM / TERM to influence detection
        return Palette::detect($stream, $env);
    }

    /**
     * Create a new ProfileWriter wrapping $stream.
     *
     * @param resource $stream The output stream to wrap (e.g. STDOUT, STDERR)
     */
    public function __construct($stream, ColorProfile $profile)
    {
        $this->stream = $stream;
        $this->profile = $profile;
    }

    /**
     * Factory: detect environment and wrap a stream.
     *
     * @param resource      $stream
     * @param array<string,string|null> $env
     */
    public static function wrap($stream, array $env = []): self
    {
        $profile = self::buildProfile($env, $stream);
        return new self($stream, $profile);
    }

    /**
     * Get the current target profile.
     */
    public function profile(): ColorProfile
    {
        return $this->profile;
    }

    /**
     * Manually change the target profile (e.g. force ASCII mode).
     *
     * @return self A clone targeting $profile; the original is unchanged.
     */
    public function withProfile(ColorProfile $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;
        return $clone;
    }

    /**
     * Write data to the stream, degrading color codes to match the target profile.
     *
     * This is the main entry point — use fwrite() on the stream directly
     * is NOT recommended; instead use $writer->write($data).
     *
     * @param string $data  Raw bytes (potentially containing ANSI sequences)
     * @return int|false    Number of bytes written, or false on error
     */
    public function write(string $data): int|false
    {
        if ($this->profile === ColorProfile::NoTTY) {
            $data = Palette::stripAnsi($data);
        } elseif ($this->profile !== ColorProfile::TrueColor) {
            // Pin to the configured profile: withProfile() below discards
            // whatever the constructor detected, so the env map is belt-
            // braces only. NO_COLOR=1 is the one entry with a real effect —
            // if the override were ever dropped, the palette would default
            // to no color rather than leaking ambient env detection here.
            // The null entries are NOT blockers: Palette::envValue treats a
            // present-but-null key as unset and falls through to $_ENV/getenv;
            // blocking a key requires omitting it AND clearing the ambient
            // layers, which this call deliberately does not do.
            // NOTE (chunk boundary): degrade() operates per write() call —
            // an SGR sequence split across two calls is not reassembled.
            $palette = (new Palette($this->stream, [
                'NO_COLOR' => '1',
                'CLICOLOR_FORCE' => null,
                'FORCE_COLOR' => null,
                'COLORTERM' => null,
                'TERM_PROGRAM' => null,
                'WT_SESSION' => null,
                'GOOGLE_CLOUD_SHELL' => null,
                'TMUX' => null,
                'STY' => null,
            ]))->withProfile($this->profile);
            $data = $palette->degrade($data);
        }

        return \fwrite($this->stream, $data);
    }

    /**
     * Alias of write() for printf-style calls.
     */
    public function printf(string $format, ...$args): int|false
    {
        return $this->write(\sprintf($format, ...$args));
    }
}
