<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Reel\Render\AutoMode;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Render\RendererFactory;
use SugarCraft\Reel\Subtitle\WebVtt;

/**
 * Terminal video player facade — plays a video file by decoding frames on the
 * fly and rendering them to ASCII / ANSI / truecolor half-block / sixel / kitty
 * output, the way `mpv -vo tct`, `tplay`, `video-to-ascii`, and `glyph` do.
 *
 * No single upstream: the decode → render → pace pipeline draws on prior art in
 * maxcurzi/tplay, seatedro/glyph, and joelibaceta/video-to-ascii. The rendering
 * stack is reused from the SugarCraft ecosystem (candy-mosaic image renderers,
 * candy-flip downsampling, candy-palette color mapping, candy-core TEA runtime)
 * rather than reinvented.
 *
 * Usage:
 *   Reel::open('video.mp4')->play();
 *   Reel::open('video.mp4')->withMode(Mode::Ascii)->withSize(120, 40)->play();
 *   Reel::new()->withSize(100, 30)->withFps(30.0)->play(); // with no source (Synthetic test pattern)
 *
 * State is immutable — each `with*()` returns a new Reel instance.
 */
final class Reel
{
    /**
     * @param string      $path  Video file path ('' for synthetic/unbound)
     * @param Mode|null   $mode  Rendering mode (null = auto-detect)
     * @param int          $cols  Terminal cell width
     * @param int          $rows  Terminal cell height
     * @param float|null   $fps   FPS override (null = auto from probe)
     * @param bool         $loop  When true, playback restarts at end instead of stopping
     * @param string       $ramp  Luma ramp name: 'minimal', 'standard', or 'dense'
     * @param string|null  $subtitlePath  Path to a WebVTT/SRT subtitle file, or null
     * @param list<string>|null $allowedHosts  Host allowlist for remote (http(s)) sources,
     *               or null to disable host restriction (SSRF surface — see {@see openUrl()})
     */
    private function __construct(
        private readonly string $path,
        private readonly ?Mode $mode,
        private readonly int $cols,
        private readonly int $rows,
        private readonly ?float $fps,
        private readonly bool $loop = false,
        private readonly string $ramp = 'standard',
        private readonly ?string $subtitlePath = null,
        private readonly ?array $allowedHosts = null,
    ) {
    }

    /**
     * Construct an empty player with no source bound yet.
     *
     * Calling play() on the result will show a synthetic test pattern.
     */
    public static function new(): self
    {
        return new self('', null, 80, 24, null, false, 'standard');
    }

    /**
     * Open a video source by path. Does not probe or decode — it only records
     * the path so the instance can be configured before playback.
     */
    public static function open(string $path): self
    {
        return new self($path, null, 80, 24, null, false, 'standard');
    }

    /**
     * Open a remote video source by http(s) URL.
     *
     * ffmpeg decodes a network stream natively, so this is the entry-point a
     * media client uses to direct-play a server's stream URL (e.g. a signed
     * `/media/{id}/stream` link) without downloading or transcoding it first.
     * The decoder passes the http/https reconnect options through so a transient
     * drop does not end playback. Audio (ffplay/mpv) likewise streams the URL.
     *
     * Functionally identical to {@see open()} — the URL is just recorded — but
     * named for intent and it rejects a non-http(s) argument so a path typo
     * surfaces immediately rather than as an obscure ffmpeg failure.
     *
     * SSRF: the recorded URL is handed verbatim to ffmpeg (and ffplay/mpv for
     * audio), which resolves DNS and follows HTTP redirects itself — so a URL
     * that looks external can still reach an internal/link-local host (e.g.
     * cloud metadata at `http://169.254.169.254/…`). The scheme check alone does
     * NOT prevent that. Pass $allowedHosts to restrict playback to a set of
     * trusted hosts; a URL whose host is not allowlisted is rejected up front.
     * When $allowedHosts is null the previous unrestricted behavior is kept, but
     * a warning is logged noting the remote URL is being fed to ffmpeg.
     *
     * @param string            $url          Remote http(s) URL.
     * @param list<string>|null $allowedHosts Case-insensitive host allowlist, or
     *               null to allow any host (logs an SSRF-surface warning).
     * @throws \InvalidArgumentException When $url is not an http(s) URL, or when
     *               $allowedHosts is set and the URL's host is not in it.
     */
    public static function openUrl(string $url, ?array $allowedHosts = null): self
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new \InvalidArgumentException("Not an http(s) URL: {$url}");
        }

        if ($allowedHosts !== null) {
            self::assertHostAllowed($url, $allowedHosts);
        } else {
            self::warnRemoteSsrf($url);
        }

        return new self($url, null, 80, 24, null, false, 'standard', null, $allowedHosts);
    }

    /**
     * The source video path this player was opened with ('' when unbound).
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * The configured rendering mode (null means auto-detect).
     */
    public function mode(): ?Mode
    {
        return $this->mode;
    }

    /**
     * The configured terminal cell width.
     */
    public function cols(): int
    {
        return $this->cols;
    }

    /**
     * The configured terminal cell height.
     */
    public function rows(): int
    {
        return $this->rows;
    }

    /**
     * The configured FPS override, or null for auto-detect from probe.
     */
    public function fps(): ?float
    {
        return $this->fps;
    }

    /**
     * Whether playback loops back to the start at end-of-stream.
     */
    public function loop(): bool
    {
        return $this->loop;
    }

    /**
     * The configured luminance ramp name ('minimal', 'standard', 'dense').
     */
    public function ramp(): string
    {
        return $this->ramp;
    }

    /**
     * The configured remote-host allowlist, or null when host restriction is
     * disabled (any host is accepted — SSRF surface; see {@see openUrl()}).
     *
     * @return list<string>|null
     */
    public function allowedHosts(): ?array
    {
        return $this->allowedHosts;
    }

    /**
     * Set the rendering mode. Returns a new Reel (immutable).
     */
    public function withMode(Mode $mode): self
    {
        return $this->with(mode: $mode);
    }

    /**
     * Set the rendering mode to auto-detect (probed at play time).
     * Returns a new Reel (immutable).
     */
    public function withAutoMode(): self
    {
        return $this->with(mode: new AutoMode());
    }

    /**
     * Enable (or disable) looping: replay from the start at end-of-stream
     * instead of stopping. Returns a new Reel (immutable).
     */
    public function withLoop(bool $loop = true): self
    {
        return $this->with(loop: $loop);
    }

    /**
     * Set the luminance ramp. Returns a new Reel (immutable).
     *
     * @param string $name Ramp name: 'minimal', 'standard', 'dense'
     * @throws \InvalidArgumentException If the ramp name is unknown
     */
    public function withRamp(string $name): self
    {
        if (!\SugarCraft\Reel\Render\LumaRamp::isValidRamp($name)) {
            throw new \InvalidArgumentException("Unknown ramp name: {$name}");
        }
        return $this->with(ramp: $name);
    }

    /**
     * Set the terminal size in cells. Returns a new Reel (immutable).
     */
    public function withSize(int $cols, int $rows): self
    {
        return $this->with(cols: $cols, rows: $rows);
    }

    /**
     * Set a target FPS override. Pass null to use auto-detect from video probe.
     * Returns a new Reel (immutable).
     */
    public function withFps(?float $fps): self
    {
        return $this->with(fps: $fps);
    }

    /**
     * Attach a subtitle file (WebVTT or SRT) to the player.
     *
     * Returns a new Reel (immutable). The file is read and parsed at play()
     * time; a missing or unreadable file is silently ignored (no subtitles).
     */
    public function withSubtitles(string $path): self
    {
        return $this->with(subtitlePath: $path);
    }

    /**
     * Restrict remote (http(s)) playback to a set of trusted hosts.
     *
     * This is the config seam for the SSRF surface described on {@see openUrl()}:
     * with an allowlist set, a remote URL whose host is not listed is rejected
     * before it ever reaches ffmpeg. When a remote URL is already bound (via
     * {@see openUrl()}), its host is re-validated against the new allowlist here
     * so the restriction can be tightened after the fact. Returns a new Reel
     * (immutable).
     *
     * @param list<string> $hosts Case-insensitive host allowlist.
     * @throws \InvalidArgumentException When a remote URL is already bound and
     *               its host is not in $hosts.
     */
    public function withAllowedHosts(array $hosts): self
    {
        if ($this->path !== '' && preg_match('#^https?://#i', $this->path) === 1) {
            self::assertHostAllowed($this->path, $hosts);
        }

        return $this->with(allowedHosts: $hosts);
    }

    /**
     * Run the player: creates a Player from the configured options and
     * executes the TEA program loop via Program::run().
     *
     * If no path was set (Reel::new()), plays a built-in synthetic test pattern.
     */
    public function play(): void
    {
        $path = $this->path;

        // When unbound, generate synthetic test pattern via the single canonical
        // source.  The synthetic demo always loops — it has no natural end.
        $loop = ($path === '') ? true : $this->loop;
        if ($path === '') {
            $path = Synthetic::generate();
        }

        // Resolve auto-mode to the best available mode at runtime (F3).
        $resolvedMode = $this->mode ?? RendererFactory::autoMode();

        // Parse the subtitle track if a subtitle file was configured.
        // A missing/unreadable file is silently treated as no subtitles.
        $subtitles = null;
        if ($this->subtitlePath !== null) {
            $raw = @file_get_contents($this->subtitlePath);
            if (is_string($raw) && $raw !== '') {
                $subtitles = WebVtt::parse($raw);
            }
        }

        // Create the Player with the configured dimensions, fps, render mode, loop flag and ramp.
        $player = Player::open($path, $this->cols, $this->rows, $this->fps, $resolvedMode, $loop, $this->ramp, 10, 20, $subtitles);

        $options = new ProgramOptions(
            useAltScreen: true,
            hideCursor: true,
        );

        (new Program($player, $options))->run();
    }

    /**
     * Generic immutable-update helper: create a new Reel with changed fields.
     *
     * @param string             $path  Leave null to keep current
     * @param Mode|AutoMode|null $mode  Leave null to keep current; AutoMode sets null (auto-detect)
     * @param int                $cols  Leave null to keep current
     * @param int                $rows  Leave null to keep current
     * @param float|null         $fps   Leave null to keep current
     * @param bool|null          $loop  Leave null to keep current
     * @param string|null        $ramp  Leave null to keep current
     * @param string|null        $subtitlePath  Path to subtitle file, leave null to keep current
     * @param list<string>|null  $allowedHosts  Remote-host allowlist, leave null to keep current
     */
    private function with(
        ?string $path = null,
        Mode|AutoMode|null $mode = null,
        ?int $cols = null,
        ?int $rows = null,
        ?float $fps = null,
        ?bool $loop = null,
        ?string $ramp = null,
        ?string $subtitlePath = null,
        ?array $allowedHosts = null,
    ): self {
        // AutoMode sentinel → null (play() will resolve to auto-detected mode).
        $resolvedMode = $mode instanceof AutoMode ? null : ($mode ?? $this->mode);

        return new self(
            $path ?? $this->path,
            $resolvedMode,
            $cols ?? $this->cols,
            $rows ?? $this->rows,
            $fps ?? $this->fps,
            $loop ?? $this->loop,
            $ramp ?? $this->ramp,
            $subtitlePath ?? $this->subtitlePath,
            $allowedHosts ?? $this->allowedHosts,
        );
    }

    /**
     * Extract the lowercased host from a URL, or '' when it has none.
     */
    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Reject a remote URL whose host is not in $allowedHosts.
     *
     * @param list<string> $allowedHosts
     * @throws \InvalidArgumentException When the host is missing or not allowlisted.
     */
    private static function assertHostAllowed(string $url, array $allowedHosts): void
    {
        $host = self::hostOf($url);
        $allowed = array_map('strtolower', $allowedHosts);
        if ($host === '' || !in_array($host, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Remote host not in allowlist: ' . ($host === '' ? '(none)' : $host)
            );
        }
    }

    /**
     * Log a warning that a remote URL is being handed to ffmpeg without a host
     * allowlist. Only the host is logged — never the full URL — so a signed
     * stream token in the query string is not leaked to the error log.
     *
     * Uses error_log() (not trigger_error()) so it never surfaces as a PHP
     * warning that a strict test harness would fail on.
     */
    private static function warnRemoteSsrf(string $url): void
    {
        $host = self::hostOf($url);
        error_log(sprintf(
            'sugar-reel: openUrl() to remote host "%s" without a host allowlist — the URL is '
            . 'handed to ffmpeg, which resolves DNS and follows redirects and can reach '
            . 'internal/link-local hosts (SSRF surface). Restrict it via openUrl($url, [...]) '
            . 'or withAllowedHosts([...]).',
            $host === '' ? '(unknown)' : $host
        ));
    }
}
