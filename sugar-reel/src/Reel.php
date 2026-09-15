<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Reel\Render\AutoMode;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Render\RendererFactory;
use SugarCraft\Reel\Source\HttpHeaders;
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
     * @param array<array-key, string> $headers  HTTP request headers for a remote source
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
        private readonly array $headers = [],
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
     * @param array<array-key, string> $headers HTTP request headers to present to the
     *               server — e.g. `['Authorization' => 'Bearer …']` for a signed
     *               stream, or any name=>value set. A positional LIST is rejected here
     *               (it would be the pre-headers allowedHosts argument; hosts like
     *               'cdn.example:8443' would parse as a legal junk header and the
     *               allowlist would vanish silently). Validated at this boundary (a
     *               CR/LF/NUL in a name or value is request smuggling and throws);
     *               re-presented on every decoder rebuild so a resize or seek
     *               never drops the credentials mid-playback. Passed to ffmpeg via
     *               `-headers`/`-user_agent`; the audio companion does NOT receive
     *               them (see README "Known limitations"). A non-network source
     *               drops them with a logged notice.
     * @param list<string>|null $allowedHosts Case-insensitive host allowlist, or
     *               null to allow any host (logs an SSRF-surface warning).
     * @throws \InvalidArgumentException When $url is not an http(s) URL, when a
     *               header name/value is malformed (CR/LF injection), or when
     *               $allowedHosts is set and the URL's host is not in it.
     */
    public static function openUrl(string $url, array $headers = [], ?array $allowedHosts = null): self
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new \InvalidArgumentException("Not an http(s) URL: {$url}");
        }

        // Shape guard BEFORE parsing: an old call `openUrl($u, ['cdn.example'])`
        // passed the ALLOWLIST positionally. A bare host has no colon so
        // parse() would reject it — but `['cdn.example:8443']` parses as a legal
        // `Name: value` pair, which would silently swap the SSRF allowlist for a
        // junk header and let the fetch proceed unrestricted. A positional list
        // at this boundary is therefore never treated as headers: fail loud with
        // the migration note instead. (Field-line lists remain valid for every
        // other headers entry point; only openUrl's positional slot has history.)
        if ($headers !== [] && array_is_list($headers)) {
            throw new \InvalidArgumentException(Lang::t('header.openurl_positional'));
        }

        // Validate at the boundary before anything is stored or handed downstream,
        // so a smuggling attempt is rejected here rather than surfacing (or being
        // silently dropped) deep inside the decoder. The return is intentionally
        // discarded — the raw array is stored on the Reel and re-parsed by the
        // decoder, which is idempotent now that these pairs are known safe.
        HttpHeaders::parse($headers);

        if ($allowedHosts !== null) {
            self::assertHostAllowed($url, $allowedHosts);
        } else {
            self::warnRemoteSsrf($url);
        }

        return new self($url, null, 80, 24, null, false, 'standard', null, $allowedHosts, $headers);
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
     * The configured HTTP request headers for a remote source (empty otherwise).
     *
     * @return array<array-key, string>
     */
    public function headers(): array
    {
        return $this->headers;
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
     * Set the HTTP request headers presented to a remote (http(s)) source.
     *
     * Validated here at the boundary exactly as {@see openUrl()} does — a CR/LF in
     * a name or value is request smuggling and throws immediately — and re-presented
     * on every decoder rebuild for the lifetime of playback. Returns a new Reel
     * (immutable).
     *
     * @param array<array-key, string> $headers Name => value, or a list of "Name: value" lines.
     * @throws \InvalidArgumentException When a header name/value is malformed.
     */
    public function withHeaders(array $headers): self
    {
        // Side-effecting parse: throws on any unsafe pair, result discarded because
        // the raw array is what the decoder re-parses downstream (idempotent once safe).
        HttpHeaders::parse($headers);

        return $this->with(headers: $headers);
    }

    /**
     * Build the playback Model from the configured options WITHOUT running it.
     *
     * This is the embed-without-owning-the-loop seam. A host that already drives
     * its own candy-core Program — a dashboard, a kiosk shell, a multi-pane TUI —
     * calls `toPlayer()` to get the fully configured {@see Player} Model and mounts
     * it inside ITS program (as a sub-Model, or by forwarding messages to it),
     * instead of handing the terminal to {@see play()}'s blocking `Program::run()`.
     * The returned Player is paused and its decoder subprocess is ALREADY SPAWNED
     * (building the Model opens the source); the host starts it (play key / an
     * explicit play transition), ticks it in its own loop, and calls
     * {@see Player::stop()} when leaving to release that child.
     *
     * HOST CONTRACT (what the embedding program is responsible for):
     *  - RESIZE: forward a `WindowSizeMsg` on SIGWINCH. The Player clamps columns
     *    to 10..200 and rows to 5..80 and no-ops when unchanged; it then
     *    rebuilds the decoder at the new cell grid. That rebuild re-opens ffmpeg,
     *    so keep resize off the per-frame hot path — react to the size change, do
     *    not poll it.
     *  - QUIT: the Player handles 'q'/Esc/Space/seek keys itself; the host decides
     *    whether a quit key tears down its whole program or just unmounts the
     *    player. Call {@see Player::stop()} when leaving so no audio/video
     *    subprocess leaks across mount → unmount.
     *  - TICK: forward {@see \SugarCraft\Reel\Msg\TickMsg} on the interval the
     *    Player's `init()`/`update()` command requests — that pacing IS the frame
     *    clock, so drive it from the host's timer, not a blocking loop.
     *
     * @throws \InvalidArgumentException When no source is configured.
     */
    public function toPlayer(): Player
    {
        // The source is required: unlike play(), which falls back to the synthetic
        // test pattern so `Reel::new()->play()` shows something, an embedding host
        // asking for a Model has an explicit real source in mind and a silent test
        // pattern would mask a misconfiguration deep in the host.
        if ($this->path === '') {
            throw new \InvalidArgumentException(Lang::t('player.no_source_for_embedding'));
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

        return Player::open(
            $this->path,
            $this->cols,
            $this->rows,
            $this->fps,
            $resolvedMode,
            $this->loop,
            $this->ramp,
            10,
            20,
            $subtitles,
            $this->headers,
        );
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

        // Create the Player with the configured dimensions, fps, render mode, loop flag and ramp,
        // and the remote-source request headers (empty for a local file).
        $player = Player::open($path, $this->cols, $this->rows, $this->fps, $resolvedMode, $loop, $this->ramp, 10, 20, $subtitles, $this->headers);

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
     * @param array<array-key, string>|null $headers  HTTP request headers, leave null to keep current
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
        ?array $headers = null,
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
            $headers ?? $this->headers,
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
     * Deliberately fires for EVERY allowlist-less openUrl(), loopback included:
     * 127.0.0.1 is itself a classic SSRF target (admin ports, metadata
     * proxies), so there is no local-host exemption to grant.
     *
     * Uses error_log() (not trigger_error()) so it never surfaces as a PHP
     * warning that a strict test harness would fail on.
     */
    private static function warnRemoteSsrf(string $url): void
    {
        $host = self::hostOf($url);
        error_log(Lang::t('ssrf.no_allowlist', [
            'host' => $host === '' ? '(unknown)' : $host,
        ]));
    }
}
