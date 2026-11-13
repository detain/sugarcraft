<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

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
final class ProfileWriter
{
    /**
     * The underlying stream resource.
     *
     * @var resource
     */
    private $stream;

    /** The color profile to degrade to. */
    private ColorProfile $profile;

    /**
     * Hermetic env map used to build the degrade palette: every documented
     * input is PINNED (absent or empty = unset) so palette construction is a
     * pure function of the configured profile — ambient NO_COLOR or
     * CLICOLOR_FORCE can never hijack what a withProfile() caller chose.
     */
    private const HERMETIC_ENV = [
        'CLICOLOR_FORCE' => null,
        'FORCE_COLOR' => '',
        'NO_COLOR' => '',
        'CLICOLOR' => null,
        'TERM' => 'dumb',
        'COLORTERM' => null,
        'TERM_PROGRAM' => null,
        'WT_SESSION' => null,
        'GOOGLE_CLOUD_SHELL' => null,
        'TMUX' => null,
        'STY' => null,
    ];

    /**
     * Degradation engine for the CURRENT profile, built lazily and reused by
     * every write(). Constructing one per call re-ran the whole detection
     * chain for identical output — and re-read ambient env each time.
     */
    private ?Palette $degrade = null;

    /**
     * @param resource $stream
     * @param array<string, string|null> $env
     */
    public static function wrap($stream, array $env = []): self
    {
        // Shape is checked HERE, not three frames down inside the palette
        // detection (-057/DX-16).
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException(Lang::t('stream.invalid_resource', ['label' => 'ProfileWriter::wrap()']));
        }

        // A non-empty $env is a COMPLETE hermetic replacement (see Env::resolve);
        // an empty one reads the ambient snapshot. TERM, COLORTERM, NO_COLOR,
        // FORCE_COLOR, TERM_PROGRAM and the rest of the documented ladder
        // inputs all apply through DetectionChain.
        return new self($stream, Palette::detect($stream, $env));
    }

    /**
     * Create a new ProfileWriter wrapping $stream.
     *
     * @param resource $stream The output stream to wrap (e.g. STDOUT, STDERR)
     */
    public function __construct($stream, ColorProfile $profile)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException(Lang::t('stream.invalid_resource', ['label' => 'ProfileWriter']));
        }

        $this->stream = $stream;
        $this->profile = $profile;
    }

    /**
     * The bytes written so far, read back — for consumers that wrap a
     * memory stream (php://memory / php://temp) in tests and pipelines and
     * want the accumulated output without the ftell/rewind dance themselves.
     *
     * Returns null for non-seekable streams (a real STDOUT, a pipe): there
     * is no rewindable buffer to offer there, and draining the pipe would
     * STEAL bytes from the caller's reader. For seekable files opened
     * mid-stream the answer is the whole buffer from byte 0, not just what
     * this writer appended — the memory-stream use is the honest contract.
     * The stream's own write position is restored before returning.
     */
    public function contents(): ?string
    {
        $meta = @stream_get_meta_data($this->stream);
        if (empty($meta['seekable'])) {
            return null;
        }

        $position = (int) @ftell($this->stream);
        if ($position === 0) {
            return '';
        }
        if (@fseek($this->stream, 0) !== 0) {
            return null;
        }

        $bytes = (string) @fread($this->stream, $position);
        @fseek($this->stream, $position, SEEK_SET);

        return $bytes;
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
        // The degrade engine is profile-bound; the clone rebuilds it lazily.
        $clone->degrade = null;
        return $clone;
    }

    /**
     * Write data to the stream, degrading color codes to match the target profile.
     *
     * This is the main entry point — writing raw bytes to the unwrapped stream
     * bypasses degradation entirely; use $writer->write($data) instead.
     *
     * @param string $data  Raw bytes (potentially containing ANSI sequences)
     * @return int|false    Number of bytes actually written (empty payload ⇒
     *                      0), or false on a write error. A single fwrite can
     *                      SHORT-WRITE on pipes and non-blocking destinations,
     *                      and the remainder would silently vanish under a
     *                      success-shaped count; the loop below chases partial
     *                      progress until the payload is delivered. Zero
     *                      progress (a full non-blocking buffer) stops the
     *                      chase and returns the bytes delivered so far —
     *                      callers on non-blocking streams check the count.
     *                      The writer never flips the caller's stream mode:
     *                      the handle belongs to the caller.
     */
    public function write(string $data): int|false
    {
        if ($this->profile === ColorProfile::NoTTY) {
            $data = Palette::stripAnsi($data);
        } elseif ($this->profile !== ColorProfile::TrueColor) {
            $data = $this->degradePalette()->degrade($data);
        }

        // PHP's own stream layer chases a cooperative short write inside a
        // single fwrite(); what it CANNOT promise is the stream that refuses
        // one call (buffer momentarily full) and would take more later —
        // the non-blocking-pipe shape this method's contract is written
        // against (finding -073/PROC-006). One retry rung per call of ours,
        // stopping at the first zero-progress answer: the partial count is
        // the honest end of the line, spinning is not, and flipping the
        // caller's blocking mode would be rude.
        $total = 0;
        $length = strlen($data);
        while ($total < $length) {
            $written = \fwrite($this->stream, substr($data, $total));
            if ($written === false) {
                return false;
            }
            if ($written === 0) {
                break;
            }
            $total += $written;
        }

        return $total;
    }

    /**
     * Alias of write() for printf-style calls.
     */
    public function printf(string $format, ...$args): int|false
    {
        return $this->write(\sprintf($format, ...$args));
    }

    /**
     * Lazily build the profile-bound degradation palette.
     */
    private function degradePalette(): Palette
    {
        return $this->degrade ??= (new Palette(null, self::HERMETIC_ENV))->withProfile($this->profile);
    }
}
