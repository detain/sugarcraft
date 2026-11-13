<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Snapshot;

use SugarCraft\Testing\Concerns\FilesystemErrorCapture;
use SugarCraft\Testing\Lang;

/**
 * Load/save helper for golden ANSI snapshot files.
 *
 * Golden files store expected output bytes for regression testing.
 * They use the `.golden` extension convention and live under
 * a `tests/fixtures/` directory relative to the test file.
 *
 * Write semantics are split deliberately:
 *  - {@see create()} is the first-baseline path: atomic and no-clobber,
 *    so concurrent runs cannot tear a baseline or overwrite each other's;
 *  - {@see update()} is the deliberate re-baseline path: atomic and
 *    replacing;
 *  - {@see save()} keeps its historical "write this fixture now" meaning
 *    (tests seeding their own temporaries) but is atomic underneath.
 *
 * Reads and writes refuse symbolic links at the final component: a
 * planted `evil.golden` must not become a channel for reading or
 * rewriting a victim file outside the fixtures tree.
 *
 * Upstream-antecedented (charmbracelet/x/exp/golden) but first-party
 * hardened — see docs/MATCHUPS.md.
 */
final class GoldenFile
{
    use FilesystemErrorCapture;

    /**
     * Load a golden file's contents.
     *
     * @param string $path Absolute or relative path to the golden file
     * @return string|null The file contents, or null ONLY when the file
     *                     genuinely does not exist
     *
     * @throws \RuntimeException when the path is a symlink, or the file
     *                           exists but cannot be read (an unreadable
     *                           golden must not masquerade as "missing" —
     *                           that hole let UPDATE_GOLDENS overwrite the
     *                           baseline nobody could read)
     */
    public static function load(string $path): ?string
    {
        if (is_link($path)) {
            throw new \RuntimeException(Lang::t('golden.untrusted_link', ['path' => $path]));
        }

        if (!is_file($path)) {
            return null;
        }

        [$contents, $diagnostic] = self::captureFilesystemError(
            static fn () => @\file_get_contents($path),
        );

        if ($contents === false) {
            throw new \RuntimeException(Lang::t('golden.read_failed', [
                'path'   => $path,
                'reason' => $diagnostic ?? 'unknown error',
            ]));
        }

        return $contents;
    }

    /**
     * Write content to a golden file (atomic; replaces an existing file).
     *
     * Creates parent directories if they don't exist. Empty content is a
     * legal golden (a renderer that outputs nothing must be able to pin
     * that) — success is measured as a full write of strlen($content)
     * bytes, so a 0-byte payload passes and a short write to a failing
     * device still throws.
     *
     * The payload lands via temp-file + rename, so a reader (or a
     * crashed writer) can never observe a half-written baseline.
     *
     * @param string $path    Absolute or relative path to the golden file
     * @param string $content The bytes to write
     * @return void
     */
    public static function save(string $path, string $content): void
    {
        self::guardLink($path);
        self::writeAtomic($path, $content, clobber: true);
    }

    /**
     * Create a golden file only if it does not already exist (atomic).
     *
     * @throws GoldenFileExistsException when the target exists at write
     *         time — including a target that appeared between the caller's
     *         missing-check and this call (the no-clobber is enforced by
     *         link semantics, not a check-then-act race)
     */
    public static function create(string $path, string $content): void
    {
        self::guardLink($path);
        self::writeAtomic($path, $content, clobber: false);
    }

    /**
     * Deliberately replace a golden file (the re-baseline path).
     *
     * Identical mechanics to {@see save()}; a distinct name so test code
     * can state intent — "this run is re-pinning the baseline" — instead
     * of relying on an un-flagged overwrite.
     */
    public static function update(string $path, string $content): void
    {
        self::guardLink($path);
        self::writeAtomic($path, $content, clobber: true);
    }

    /**
     * Resolve a fixture-relative path to an absolute path.
     *
     * A leading slash on $relative is treated as fixtures-relative (stripped),
     * NOT as an absolute filesystem path. Any $relative that would escape the
     * `<baseDir>/fixtures` base via `..` traversal is rejected — golden files
     * must live under the base dir, so a test (or a poisoned relative path)
     * cannot read or overwrite files elsewhere on disk.
     *
     * When the fixtures dir already exists, containment is also verified on
     * the REAL path: a planted directory symlink inside fixtures (e.g.
     * `fixtures/sub -> /etc`) is caught here instead of at write time.
     *
     * @param string $baseDir  Directory the test file lives in
     * @param string $relative Relative path within fixtures/
     * @return string Resolved absolute path
     * @throws \RuntimeException if $relative escapes the fixtures base dir
     */
    public static function resolve(string $baseDir, string $relative): string
    {
        $base = \rtrim($baseDir, '/') . '/fixtures';
        $full = $base . '/' . \ltrim($relative, '/');

        // realpath() can't be used unconditionally here: golden files may not
        // exist yet (they are resolved for both reads and first-time writes),
        // so normalize the '..'/'.' segments lexically and confirm the result
        // stays under base.
        $normBase = self::normalizePath($base);
        $normFull = self::normalizePath($full);

        if ($normFull !== $normBase && !\str_starts_with($normFull, $normBase . '/')) {
            throw new \RuntimeException(Lang::t('golden.path_traversal', ['path' => $relative]));
        }

        // Real-path leg once the base exists: guards against a symlinked
        // directory inside the walk (the lexical check cannot see those).
        $realBase = \realpath($base);
        if ($realBase !== false) {
            $parentReal = \realpath(\dirname($full));
            if ($parentReal !== false && $parentReal !== $realBase && !\str_starts_with($parentReal, $realBase . '/')) {
                throw new \RuntimeException(Lang::t('golden.path_traversal', ['path' => $relative]));
            }
        }

        return $full;
    }

    /**
     * Refuse to touch a final component that is a symbolic link.
     */
    private static function guardLink(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException(Lang::t('golden.untrusted_link', ['path' => $path]));
        }
    }

    /**
     * tmp-file + (link | rename) write, shared by save/create/update.
     *
     * @param bool $clobber when false, an existing target raises
     *                      {@see GoldenFileExistsException} instead of being replaced
     */
    private static function writeAtomic(string $path, string $content, bool $clobber): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            // Scoped capture + is_dir re-check: concurrent first-baseline
            // runs race here, and an unchecked mkdir EWARNING would trip
            // failOnWarning; the diagnostic belongs to THIS call, not to
            // whatever last failed process-wide.
            [, $mkdirError] = self::captureFilesystemError(
                static fn () => @\mkdir($dir, 0755, true),
            );
            if (!\is_dir($dir)) {
                throw new \RuntimeException(Lang::t('golden.write_failed', [
                    'path'   => $path,
                    'reason' => 'could not create parent directory ' . $dir
                        . ($mkdirError !== null ? ': ' . $mkdirError : ''),
                ]));
            }
        }

        $tmp = $dir . '/.golden-tmp-' . \bin2hex(\random_bytes(6)) . '-' . \getmypid();
        $expected = \strlen($content);

        // The scoped handler consumes the E_WARNING a failed write raises
        // (failOnWarning would otherwise trip) before we can raise the
        // typed error; the reason is captured from this call only.
        [$written, $writeError] = self::captureFilesystemError(
            static fn () => @\file_put_contents($tmp, $content),
        );

        if ($written !== $expected) {
            @\unlink($tmp);

            throw new \RuntimeException(Lang::t('golden.write_failed', [
                'path'   => $path,
                'reason' => self::writeFailureReason($written, $writeError, $expected),
            ]));
        }

        if (!$clobber) {
            // link() fails with EEXIST if the target exists at all: the
            // no-clobber guarantee is enforced by the filesystem, not by a
            // check-then-act window.
            [$linked] = self::captureFilesystemError(
                static fn () => @\link($tmp, $path),
            );
            if ($linked === true) {
                @\unlink($tmp);

                return;
            }

            @\unlink($tmp);

            if (\file_exists($path) || is_link($path)) {
                throw new GoldenFileExistsException(Lang::t('golden.exists', ['path' => $path]));
            }

            throw new \RuntimeException(Lang::t('golden.write_failed', [
                'path'   => $path,
                'reason' => 'linking the temp file failed',
            ]));
        }

        [$renamed, $renameError] = self::captureFilesystemError(
            static fn () => @\rename($tmp, $path),
        );

        if ($renamed === false || $renameError !== null) {
            @\unlink($tmp);

            throw new \RuntimeException(Lang::t('golden.write_failed', [
                'path'   => $path,
                'reason' => $renameError ?? 'rename failed',
            ]));
        }
    }

    /**
     * Classify a failed write for the error message: an int return is a
     * short write (the count says how short — the old is_string() test made
     * that branch dead code), false is a hard failure whose reason comes
     * from the scoped capture. Public so the shape roster is directly
     * testable without failing devices.
     *
     * @param int|false   $written    return of the write call
     * @param string|null $diagnostic message captured for this call, if any
     * @param int         $expected   bytes the payload should have filled
     */
    public static function writeFailureReason(int|false $written, ?string $diagnostic, int $expected): string
    {
        if (\is_int($written)) {
            return \sprintf('short write (%d of %d bytes)', $written, $expected);
        }

        return $diagnostic ?? 'unknown error';
    }

    /**
     * Lexically collapse `.`/`..`/empty segments in a path.
     *
     * Absolute inputs stay anchored at `/` (Windows drive-letter roots are
     * recognized too) and a `..` at the root is dropped (you cannot climb
     * above it). Both `/` and `\` act as separators so a Windows-style
     * relative path cannot smuggle `..` past the lexical jail. Purely
     * lexical — the path need not exist.
     *
     * @param string $path
     * @return string
     */
    private static function normalizePath(string $path): string
    {
        $isAbsolute = \str_starts_with($path, '/') || \preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
        $out = [];

        foreach (\preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($out !== [] && \end($out) !== '..') {
                    \array_pop($out);
                } elseif ($isAbsolute === false) {
                    $out[] = '..';
                }
                continue;
            }

            $out[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . \implode('/', $out);
    }
}
