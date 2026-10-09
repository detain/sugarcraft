<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Media\MediaArtifact;
use SugarCraft\Crush\Media\MediaKind;

/**
 * The artifact home: generated media lands at
 * `~/.sugar-crush/media/<session>/` — 0700 directories, 0600 files, and a
 * publish that is atomic in the `.partial` + rename shape this repo already
 * trusts in {@see PrivateDir} and Server/StateDir (plan W1.9, Appendix C-3:
 * HOME store for now, a Wave-7 revisit for workspace placement).
 *
 * WHY A PATH AND NEVER ONLY MEMORY (mystage §6.5): ToolOutputSpill and the
 * clipboard temp dirs sweep at seven days; a generated image must outlive
 * them, so every save flows through this class and hands back an absolute
 * path plus a stable artifact id (the basename minus its extension) that the
 * ToolResult image channel carries.
 *
 * NAMING — the `sd.savePattern` token vocabulary, minus its prompt arms.
 * The thirteen tokens below are the A1111 §3.4/§5.7 subset the plan rules
 * into v1. PROMPT-DERIVED TOKENS ARE EXCLUDED BY RULING, not by oversight:
 * embedding prompt text in a filename is both a privacy leak (filenames ride
 * transcripts, listings and any future share of a session directory) and an
 * injection surface (a prompt is model-adjacent content; a filename built
 * from it would feed shell quoting and path parsing downstream). A bracketed
 * token the palette does not know is therefore never filled — it stays
 * literal in the name and is reported in `unknown_tokens`, so a stale
 * `sd.savePattern` degrades visibly instead of silently.
 *
 * PERMISSIONS ride the temp inode, settled by chmod BEFORE a payload byte is
 * written and long before the publish rename (the candy-core
 * AtomicJsonFile ordering law, mirrored structurally — rename carries the
 * temp's mode onto the target, so no published path ever exists with looser
 * bits and the tests' structural pin reddens a chmod-after-rename
 * restructure that functional mode checks would pass).
 *
 * NO LOCK, DELIBERATELY. AtomicJsonFile flocks because it REPLACES a stable
 * name; every write here publishes a FRESH name chosen by an existence loop
 * with an exclusive `xb` create, so two racing writers can only ever collide
 * onto the next `[number]`, never tear or clobber each other's bytes.
 *
 * NO SWEEP. Retention is a Wave-7 ruling (Appendix C); nothing here deletes
 * on a timer.
 */
final class MediaStore
{
    /** Mirror of the `sd.savePattern` default in Config/Settings/Definitions/MediaSettings.php — byte-identical on purpose. */
    public const DEFAULT_PATTERN = '[date]-[time]-[seed]-[number]';

    /** The `--filenames-max-length 128` analog: full filename INCLUDING its extension. */
    public const FILENAME_MAX_LENGTH = 128;

    /** Permission bits every artifact carries for its whole life. */
    public const FILE_MODE = 0o600;

    /** In-flight name suffix; a published file never wears it, and list() hides it. */
    public const PARTIAL_SUFFIX = '.partial';

    /** Ceiling for consecutive taken names before a put is refused loudly. */
    public const MAX_COLLISION_ATTEMPTS = 100;

    /** The thirteen bracketed names a save pattern may use; the ruling excludes every prompt arm. */
    public const TOKENS = [
        'date', 'time', 'seed', 'steps', 'cfg', 'sampler', 'model_name',
        'width', 'height', 'number', 'batch_number', 'generation_number', 'job_timestamp',
    ];

    /** Integer arms: refused unless the value is a plain (optionally signed) integer. */
    private const INTEGER_TOKENS = ['seed', 'steps', 'width', 'height', 'batch_number', 'generation_number'];

    /** The one float arm, accepted as integer or decimal. */
    private const FLOAT_TOKENS = ['cfg'];

    /** Filled from the clock when a caller does not supply them (tests do, for determinism). */
    private const GENERATED_TOKENS = ['date', 'time', 'job_timestamp'];

    /** Free-text arms: sanitized to the name alphabet, and the first to collapse under the 128 cap. */
    private const TEXT_TOKENS = ['sampler', 'model_name'];

    private const SESSION_ID_MAX_LENGTH = 64;

    /** Bytes a session id or a filled token value may keep; everything else becomes a dash. */
    private const NAME_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-';

    /** Names additionally keep square brackets so an UNKNOWN pattern token stays literal — separators never do. */
    private const FILE_ALPHABET = self::NAME_ALPHABET . '[]';

    /** @var list<array{0: string, 1: string}> parsed pattern: text plus kind (literal | token | unknown) */
    private readonly array $segments;

    /** @var list<string> palette names a pattern references that no value could fill */
    private readonly array $patternTokens;

    /** @var list<string> bracketed names a pattern references that the palette does not know */
    private readonly array $unknownTokens;

    /** @param ?\Closure(resource, string): void $writer payload seam — tests inject a throwing one to prove the cleanup */
    private function __construct(
        private readonly string $sessionId,
        private readonly string $directory,
        private readonly string $resolvedDirectory,
        private readonly string $pattern,
        private readonly ?\Closure $writer = null,
    ) {
        [$segments, $used, $unknown] = self::parsePattern($pattern);
        $this->segments = $segments;
        $this->patternTokens = $used;
        $this->unknownTokens = $unknown;
    }

    /**
     * Open (creating when absent) the store for one session under $root —
     * by default `~/.sugar-crush/media`, rooted at {@see HomeDirectory::owned()}
     * so an unestablishable home throws instead of silently storing under a
     * world-writable stand-in.
     *
     * The session directory is created through {@see PrivateDir::ensure} —
     * exactly 0700, real directory, this uid, planted symlinks refused — and
     * its realpath becomes the containment anchor every later operation is
     * checked against.
     *
     * @param string|null $pattern save-name override; null takes {@see DEFAULT_PATTERN}.
     * @param (callable(resource, string): void)|null $writer test seam for mid-write failure injection.
     *
     * @throws \RuntimeException when the home cannot be established, the id
     *         carries a traversal shape, or the session directory is not private.
     */
    public static function forSession(
        string $sessionId,
        ?string $root = null,
        ?string $pattern = null,
        ?callable $writer = null,
    ): self {
        $root ??= self::defaultRoot();
        $root = rtrim($root, '/\\');
        $safe = self::sanitizeSessionId($sessionId);
        $directory = $root . '/' . $safe;
        PrivateDir::ensure($directory, 'media store');
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new \RuntimeException("MediaStore: cannot resolve the session directory {$directory}");
        }

        return new self($safe, $directory, $resolved, $pattern ?? self::DEFAULT_PATTERN, $writer === null ? null : \Closure::fromCallable($writer));
    }

    /** Absolute path of this store's 0700 session directory. */
    public function directoryPath(): string
    {
        return $this->directory;
    }

    /** The sanitized session directory name this store writes into. */
    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Publish one artifact under the pattern-shaped name and return the
     * media reference whose path is absolute and whose tokenValues carry the
     * filled map plus the `missing_tokens` / `unknown_tokens` notes.
     *
     * @param array<string, mixed> $tokens palette values (see {@see TOKENS}); generated arms self-fill from the clock.
     *
     * @throws \InvalidArgumentException on a junk extension, an off-palette key, or a non-numeric numeric arm.
     * @throws \RuntimeException when every name in the collision window is taken or the publish fails.
     */
    public function put(string $bytes, string $ext, array $tokens = [], MediaKind $kind = MediaKind::Image): MediaArtifact
    {
        $extension = self::normalizeExtension($ext);
        [$values, $startNumber] = $this->tokenValues($tokens);
        [$target, $partial, $attempt] = $this->resolveTarget($values, $extension, $startNumber);
        $this->publish($target, $partial, $bytes);

        $meta = $values;
        $meta['number'] = (string) $attempt;
        $meta['missing_tokens'] = $this->missingTokens($values);
        $meta['unknown_tokens'] = $this->unknownTokens;

        return MediaArtifact::new($kind)->withPath($target)->withTokenValues($meta);
    }

    /**
     * Every published artifact of this session, name-sorted. In-flight
     * `.partial` files and non-regular entries are not listed.
     *
     * @return list<array{id: string, filename: string, path: string, bytes: int, mtime: int}>
     */
    public function list(): array
    {
        $rows = [];
        foreach ($this->entries() as $name) {
            $path = $this->directory . '/' . $name;
            $stat = @lstat($path);
            if ($stat === false) {
                continue;
            }
            $rows[] = [
                'id' => self::idOf($name),
                'filename' => $name,
                'path' => $path,
                'bytes' => (int) $stat['size'],
                'mtime' => (int) $stat['mtime'],
            ];
        }

        return $rows;
    }

    /**
     * Absolute path of the artifact whose id is $artifactId, or null when the
     * session holds no such file. An id carrying a separator or control byte
     * is refused, not searched — same early-exit law as the session id.
     * (The path itself is always rebuilt from a scandir entry, so a matching
     * id can never steer it out of the session directory; the containment
     * re-check on the found entry is what bounds a planted symlink.)
     */
    public function pathFor(string $artifactId): ?string
    {
        if ($artifactId === '' || str_contains($artifactId, '/') || str_contains($artifactId, '\\')
            || strpbrk($artifactId, "\0\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x1b\x7f") !== false) {
            throw new \RuntimeException('MediaStore: artifact id carries a separator or control character');
        }
        foreach ($this->entries() as $name) {
            if (self::idOf($name) !== $artifactId) {
                continue;
            }
            $path = $this->directory . '/' . $name;
            if (!self::contained(realpath($path), $this->resolvedDirectory)) {
                throw new \RuntimeException("MediaStore: {$path} no longer resolves inside its session directory");
            }

            return $path;
        }

        return null;
    }

    /** Bytes of a stored artifact, or null when the id names nothing. */
    public function read(string $artifactId): ?string
    {
        $path = $this->pathFor($artifactId);
        if ($path === null) {
            return null;
        }
        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    /** Remove one artifact; false when the id names nothing. Retention sweeps are NOT this class (Wave-7). */
    public function delete(string $artifactId): bool
    {
        $path = $this->pathFor($artifactId);
        if ($path === null) {
            return false;
        }
        $stat = @lstat($path);
        if ($stat === false || (($stat['mode'] & 0o170000) !== 0o100000)) {
            return false;
        }

        return @unlink($path);
    }

    /** The HOME-rooted store directory every default-constructed session shares one level above. */
    private static function defaultRoot(): string
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            throw new \RuntimeException('MediaStore: this process cannot establish an owned home for the media store');
        }

        return $home . '/.sugar-crush/media';
    }

    /**
     * Refuse (never repair) a session id carrying a traversal shape, then map
     * every remaining odd byte to a dash, clamp, and fall back to `default`.
     * The refused set is checked on the RAW id so a separator can never be
     * "stripped and continued" past the early exit the plan demands.
     */
    private static function sanitizeSessionId(string $sessionId): string
    {
        $id = trim($sessionId);
        if ($id === '') {
            return 'default';
        }
        if (str_contains($id, '/') || str_contains($id, '\\') || str_contains($id, '..')
            || strpbrk($id, "\0\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x1b\x7f") !== false) {
            throw new \RuntimeException("MediaStore: session id '{$sessionId}' carries a separator or control character");
        }

        $safe = self::sanitizeToNameAlphabet($id);
        if (\strlen($safe) > self::SESSION_ID_MAX_LENGTH) {
            $safe = substr($safe, 0, self::SESSION_ID_MAX_LENGTH);
        }
        if ($safe === '' || $safe === '.') {
            return 'default';
        }

        return $safe;
    }

    /** Byte loop, deliberately not a character-class regex (see the corpus-census hazard in the test notes). */
    private static function sanitizeToNameAlphabet(string $value, ?string $alphabet = null): string
    {
        $alphabet ??= self::NAME_ALPHABET;
        $out = '';
        $length = \strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = $value[$i];
            $out .= strpos($alphabet, $byte) === false ? '-' : $byte;
        }

        return $out;
    }

    /** `png`, `.png`, `PNG` all land as `png`; anything else is a caller bug we refuse at the door. */
    private static function normalizeExtension(string $ext): string
    {
        $clean = strtolower(ltrim($ext, '.'));
        if ($clean === '' || \strlen($clean) > 8 || !ctype_alnum($clean)) {
            throw new \InvalidArgumentException("MediaStore: extension '{$ext}' is not 1..8 alphanumeric characters");
        }

        return $clean;
    }

    /**
     * Validate + canonicalize every palette value. Off-palette keys are
     * refused (a `prompt` key arriving here would be the exact leak the
     * exclusion ruling closes, one layer earlier than the filename).
     *
     * @return array{0: array<string, string>, 1: int} filled values and the collision start number
     */
    private function tokenValues(array $tokens): array
    {
        foreach (array_keys($tokens) as $key) {
            if (!\is_string($key) || !\in_array($key, self::TOKENS, true)) {
                throw new \InvalidArgumentException(
                    'MediaStore: token \'' . (string) $key . '\' is not in the save-name palette',
                );
            }
        }

        $values = [];
        foreach (self::TOKENS as $token) {
            $raw = $tokens[$token] ?? null;
            if ($raw === null && \in_array($token, self::GENERATED_TOKENS, true)) {
                $raw = match ($token) {
                    'date' => \date('Y-m-d'),
                    'time' => \date('H-i-s'),
                    default => \date('YmdHis'),
                };
            }
            if ($raw === null) {
                $values[$token] = '';
                continue;
            }
            $values[$token] = match (true) {
                \in_array($token, self::INTEGER_TOKENS, true) => self::integerValue($token, $raw),
                \in_array($token, self::FLOAT_TOKENS, true) => self::floatValue($token, $raw),
                default => self::sanitizeToNameAlphabet(self::stringValue($token, $raw)),
            };
        }

        $startNumber = 0;
        if (($tokens['number'] ?? null) !== null) {
            $startNumber = (int) $values['number'];
            if ($startNumber < 0) {
                throw new \InvalidArgumentException('MediaStore: the number token must be non-negative');
            }
        }

        return [$values, $startNumber];
    }

    private static function integerValue(string $token, mixed $raw): string
    {
        if (\is_int($raw)) {
            return (string) $raw;
        }
        if (\is_string($raw) && preg_match('/^-?[0-9]+$/', $raw) === 1) {
            return (string) (int) $raw;
        }

        throw new \InvalidArgumentException("MediaStore: token '{$token}' requires an integer, got " . get_debug_type($raw));
    }

    private static function floatValue(string $token, mixed $raw): string
    {
        if (\is_int($raw) || \is_float($raw)) {
            return (string) $raw;
        }
        if (\is_string($raw) && preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $raw) === 1) {
            return $raw;
        }

        throw new \InvalidArgumentException("MediaStore: token '{$token}' requires a number, got " . get_debug_type($raw));
    }

    private static function stringValue(string $token, mixed $raw): string
    {
        if (\is_string($raw)) {
            return $raw;
        }
        if (\is_int($raw) || \is_float($raw)) {
            return (string) $raw;
        }

        throw new \InvalidArgumentException("MediaStore: token '{$token}' requires text, got " . get_debug_type($raw));
    }

    /**
     * Split a pattern into literal / palette-token / unknown-token segments.
     * A bracket only opens a token when its contents are name characters and
     * it closes; anything else stays literal text (and is sanitized anyway).
     *
     * @return array{0: list<array{0: string, 1: string}>, 1: list<string>, 2: list<string>}
     */
    private static function parsePattern(string $pattern): array
    {
        $segments = [];
        $used = [];
        $unknown = [];
        $literal = '';
        $length = \strlen($pattern);
        $nameAlphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

        for ($i = 0; $i < $length; $i++) {
            if ($pattern[$i] !== '[') {
                $literal .= $pattern[$i];
                continue;
            }
            $name = '';
            $j = $i + 1;
            while ($j < $length && strpos($nameAlphabet, $pattern[$j]) !== false) {
                $name .= $pattern[$j];
                $j++;
            }
            if ($name === '' || $j >= $length || $pattern[$j] !== ']') {
                $literal .= '[';
                continue;
            }
            if ($literal !== '') {
                $segments[] = [$literal, 'literal'];
                $literal = '';
            }
            if (\in_array($name, self::TOKENS, true)) {
                $segments[] = [$name, 'token'];
                $used[$name] = true;
            } else {
                $segments[] = [$name, 'unknown'];
                $unknown[$name] = true;
            }
            $i = $j;
        }
        if ($literal !== '') {
            $segments[] = [$literal, 'literal'];
        }

        return [$segments, array_keys($used), array_keys($unknown)];
    }

    /** Render segments against values and sanitize the whole — the name can never carry a separator regardless of pattern. */
    private function renderBody(array $values): string
    {
        $out = '';
        foreach ($this->segments as [$text, $kind]) {
            $out .= match ($kind) {
                'token' => $values[$text],
                'unknown' => '[' . $text . ']',
                default => $text,
            };
        }

        return self::sanitizeToNameAlphabet($out, self::FILE_ALPHABET);
    }

    /**
     * Choose the first free name: the `[number]` arm ticks with each taken
     * candidate; when the pattern itself cannot express the tick (no
     * `[number]` arm, or a cap that truncated it away) the attempt number is
     * appended AFTER the collapse, which is what keeps long-name collisions
     * unique under the 128-char ceiling.
     *
     * @param array<string, string> $values
     *
     * @return array{0: string, 1: string, 2: int} target, partial, chosen attempt
     */
    private function resolveTarget(array $values, string $extension, int $startNumber): array
    {
        $suffix = '.' . $extension;
        $previousBody = null;
        for ($i = 0; $i < self::MAX_COLLISION_ATTEMPTS; $i++) {
            $attempt = $startNumber + $i;
            $candidate = $values;
            $candidate['number'] = (string) $attempt;
            $body = $this->renderBody($candidate);
            $forceTail = $previousBody !== null && $body === $previousBody;
            $tail = $forceTail ? '-' . $attempt : '';
            $room = max(1, self::FILENAME_MAX_LENGTH - \strlen($suffix) - \strlen($tail));
            $name = $this->collapseToFit($candidate, $body, $room) . $tail . $suffix;
            $target = $this->directory . '/' . $name;
            $partial = $target . self::PARTIAL_SUFFIX;
            if (!file_exists($target) && !file_exists($partial)) {
                return [$target, $partial, $attempt];
            }
            $previousBody = $body;
        }

        throw new \RuntimeException(
            'MediaStore: no free name within ' . self::MAX_COLLISION_ATTEMPTS . ' attempts in ' . $this->directory,
        );
    }

    /**
     * Fit the body under $room the A1111 way: free-text token values lose
     * their tails first (longest arm last standing), only then does the
     * assembled name take a hard cut. Pure-ASCII by construction — the
     * sanitizer ran before this — so byte surgery cannot split a character.
     *
     * @param array<string, string> $candidate
     */
    private function collapseToFit(array $candidate, string $body, int $room): string
    {
        foreach (array_reverse(self::TEXT_TOKENS) as $token) {
            $excess = \strlen($body) - $room;
            if ($excess <= 0) {
                break;
            }
            $current = $candidate[$token];
            if ($current === '') {
                continue;
            }
            $candidate[$token] = substr($current, 0, max(0, \strlen($current) - $excess));
            $body = $this->renderBody($candidate);
        }
        if (\strlen($body) > $room) {
            $body = substr($body, 0, $room);
        }

        return $body === '' ? 'media' : $body;
    }

    /**
     * The atomic publish: pre-refuse a planted partial, create the temp
     * exclusively under a narrowed umask, settle 0600 on the temp inode,
     * write through the (injectable) payload seam, rename, then re-verify
     * containment on the published path. Every failure unlinks the partial —
     * `.partial` residue is a bug this method owns.
     */
    private function publish(string $target, string $partial, string $bytes): void
    {
        if (@lstat($partial) !== false) {
            throw new \RuntimeException("MediaStore: a partial file already sits at {$partial}");
        }

        $previous = umask(0o077);
        try {
            $handle = @fopen($partial, 'xb');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            throw new \RuntimeException("MediaStore: cannot create the partial file {$partial}");
        }

        try {
            $this->applyPermissions($partial);
            $write = $this->writer ?? \Closure::fromCallable([$this, 'writePayload']);
            $write($handle, $bytes);
        } catch (\Throwable $failure) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            @unlink($partial);

            throw $failure;
        }
        if (\is_resource($handle)) {
            fclose($handle);
        }

        if (!@rename($partial, $target)) {
            @unlink($partial);

            throw new \RuntimeException("MediaStore: cannot publish {$target}");
        }
        if (!self::contained(realpath($target), $this->resolvedDirectory)) {
            @unlink($target);

            throw new \RuntimeException("MediaStore: {$target} resolved outside its session directory");
        }
    }

    /** Settle the artifact bits on the temp inode BEFORE any payload and long before the rename. */
    private function applyPermissions(string $partial): void
    {
        if (!@chmod($partial, self::FILE_MODE)) {
            throw new \RuntimeException(
                'Cannot set mode ' . \sprintf('%04o', self::FILE_MODE) . " on partial media file: {$partial}",
            );
        }
    }

    /** @param resource $handle */
    private function writePayload($handle, string $bytes): void
    {
        if (fwrite($handle, $bytes) === false) {
            throw new \RuntimeException('MediaStore: payload write to the partial file failed');
        }
        if (!fflush($handle)) {
            throw new \RuntimeException('MediaStore: payload flush of the partial file failed');
        }
        if (\function_exists('fsync')) {
            @fsync($handle);
        }
        fclose($handle);
    }

    /** Directory listing minus dots, partials and non-regular entries — name-sorted, the ONE scandir. */
    private function entries(): array
    {
        $names = @scandir($this->directory);
        if ($names === false) {
            return [];
        }
        $kept = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || str_ends_with($name, self::PARTIAL_SUFFIX)) {
                continue;
            }
            if (!is_file($this->directory . '/' . $name)) {
                continue;
            }
            $kept[] = $name;
        }

        return $kept;
    }

    /** Stable artifact id: the stored name minus its final extension. */
    private static function idOf(string $filename): string
    {
        $dot = strrpos($filename, '.');

        return $dot === false ? $filename : substr($filename, 0, $dot);
    }

    /** Palette arms the pattern actually referenced but nothing could fill. */
    private function missingTokens(array $values): array
    {
        $missing = [];
        foreach ($this->patternTokens as $token) {
            if ($token === 'number') {
                continue;
            }
            if ($values[$token] === '') {
                $missing[] = $token;
            }
        }

        return $missing;
    }

    /** Containment: $real must be strictly inside $dir (which is itself a realpath). */
    private static function contained(string|false $real, string $dir): bool
    {
        return $real !== false && $real !== $dir && str_starts_with($real, $dir . '/');
    }
}
