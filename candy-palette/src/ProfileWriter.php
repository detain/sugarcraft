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
 *   fwrite($w, \"\\x1b[38;2;255;0;0mred\\x1b[0m\"); // auto-degrades if terminal is ANSI256/ANSI
 */
namespace SugarCraft\Palette;

use SugarCraft\Palette\Palette;
use SugarCraft\Palette\Profile;

/**
 * Wraps a stream and degrades color escape sequences to match the target profile.
 *
 * Example:
 *   $w = ProfileWriter::wrap(STDOUT, ['TERM' => 'xterm-256color']);
 *   fwrite($w, \"\\x1b[38;2;100;200;50mGreenish\\x1b[0m\\n\");
 */
final class ProfileWriter
{
    /** The underlying stream resource. */
    private $stream;

    /** The color profile to degrade to. */
    private Profile $profile;

    /** Detected from environment. */
    private static function buildProfile(array $env, $stream): Profile
    {
        // Allow FORCE_COLOR / NO_COLOR / COLORTERM / TERM_PROGRAM / TERM to influence detection
        return Palette::detect($stream, $env);
    }

    /**
     * Create a new ProfileWriter wrapping $stream.
     *
     * @param resource      $stream  The output stream to wrap (e.g. STDOUT, STDERR)
     * @param array<string,string|null> $env     Environment map; defaults to $_ENV
     */
    public function __construct($stream, Profile $profile)
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
    public function profile(): Profile
    {
        return $this->profile;
    }

    /**
     * Manually change the target profile (e.g. force ASCII mode).
     *
     * @return $this
     */
    public function withProfile(Profile $profile): self
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
        if ($this->profile === Profile::NoTTY) {
            $data = Palette::stripAnsi($data);
        } elseif ($this->profile !== Profile::TrueColor) {
            // Pin to the configured profile — do NOT re-detect from ambient env.
            // Use null values to block parent state from leaking via $_ENV/getenv.
            // NO_COLOR=1 forces the intermediate Palette to NoTTY; then
            // withProfile() overrides to the configured profile for degradation.
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
