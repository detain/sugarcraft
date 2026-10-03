<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * The RETAINED overflow file a hook's model-visible `additionalContext` spills
 * its full text into when that text is longer than the context cap.
 *
 * WHY A NEW CLASS RATHER THAN NEW METHODS ON {@see ToolIpcFiles}. `ToolIpcFiles`
 * is the fork/IPC hand-off store: every one of its files is written by a child,
 * read once by the parent, and `discard()`-ed on collection, and its hourly
 * `sweep()` is the reaper for the ones whose owner died. A hook overflow has the
 * opposite lifecycle (supervisor ruling R-2): the model is expected to READ the
 * retained file later, possibly after several provider round-trips, so it must
 * NOT be discarded-after-read and must NOT be caught by that sweep. Sharing a
 * class with `ToolIpcFiles` would mean either widening the sweep's exclusion list
 * (narrowing an existing safety construct — §1.10) or living inside a store whose
 * whole contract is "gone on read". A separate class with its own directory keeps
 * the two lifetimes provably disjoint. It borrows only the WRITE discipline:
 * `umask(0o077)` around the create so the bytes are 0600 for their whole life
 * (`chmod`-after leaves a world-readable window), and write-then-`rename` so a
 * process that dies mid-write cannot leave a truncated file the consumer reads as
 * whole.
 *
 * LIFETIME. A retained file lives until the tool-result that names it leaves the
 * session — it is never auto-deleted by this class. That is deliberate: deleting
 * it on read (R-2) would strip the model's ability to re-read it on a later turn
 * that quotes the overflow path. The trade is that these files accumulate for the
 * session's life; they are bounded by the number of overflowing hooks that fire
 * (rare — a hook must both permit a call AND emit more than the cap), not by
 * every tool call, and each is at most the size of one hook's stdout.
 *
 * SWEEP-SAFETY PROOF (pre-code mandatory check, step brief §Hard constraints 6).
 * `ToolIpcFiles::sweep()` (and therefore `sweepOnce()`, wired at
 * {@see \SugarCraft\Crush\Cli\Bootstrap::backend()} / `::backendFor()`) selects
 * files ONLY with these three literal-prefix globs inside `sys_get_temp_dir()`:
 *   - `sys_get_temp_dir() . '/sc_runtime_tool_*'`
 *   - `sys_get_temp_dir() . '/sc_chat_tool_*'`
 *   - `sys_get_temp_dir() . '/crush-hook-payload-*'`
 * PHP `glob()` treats `*` as NOT crossing a `/` boundary, so a path that contains
 * the extra directory segment `'/sc-hook-ctx-<euid>/'` can never be named by any of
 * those three patterns — the sweep cannot reach a file it does not glob,
 * irrespective of mtime/age. `ToolIpcFiles::discard()` unlinks only the exact
 * `$file` and `$file . '.partial'` it is handed, and every caller passes its own
 * `reserve()`-recorded IPC path; this class's paths never enter `reserve()`'s
 * ledger (`$reserved`), so neither `discard()` nor `strandedReservations()`'s
 * partial-suffix sweep can name them either. Therefore a `HookContextFiles`
 * overflow written here is provably NOT deleted by the hourly IPC sweep, by
 * `discard()`, or by the partial-suffix cleanup before its consumer reads it.
 * `PROCEED`, not `HALT` — a clean retained-lifetime boundary exists in-tree.
 *
 * THE DIRECTORY IS PER-USER. It sits inside a parent that every user on the box
 * may create entries in, so its name carries the effective uid
 * (`sys_get_temp_dir() . '/sc-hook-ctx-<euid>'`). Under the single fixed name it
 * used to have, the first user whose hook overflowed created it `0700` as theirs,
 * and the ownership check below then refused every other user's overflow for as
 * long as that directory stood — a denial any local user could also cause on
 * purpose with one `mkdir` (audit TMP-1). The suffix leaves nobody else's
 * ordinary use contending for this user's name.
 *
 * AND IT IS INSPECTED BEFORE ONE BYTE IS WRITTEN THROUGH IT. The suffix ends the
 * contention, not the planting: another user can still create the uid-suffixed
 * name first, and a pre-planted symlink there turns "retain the overflow
 * privately" into "write this user's hook output wherever the link points, as
 * this user". `dir()` therefore refuses — it never quietly picks another place
 * to put the bytes — unless `lstat()` on the final path component says real
 * directory, owned by this effective uid, carrying no group/other bit. The
 * refusal surfaces as the in-band marker {@see bound()} already speaks for an
 * unretainable overflow ("could not be retained"), with its own reason clause,
 * so the model sees that bytes were dropped instead of reading a path that was
 * never written. `lstat()` rather than `stat()`/`is_dir()` is the load-bearing
 * call: the latter two follow the link and would answer "yes, a directory" for
 * exactly the planted shape. The refusal degrades the overflow to a bounded
 * head; it does not shorten a retained file's life, widen the sweep, or in any
 * other way disturb R-2 above.
 *
 * ONE RETAINED FILE PER OVERFLOWING PASS, AND NO FILE WITHOUT A NAME. A hook
 * pass can hand the accumulator to `bound()` more than once inside one tool
 * call ({@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()} joins each
 * pass's context and re-binds the result), so a second overflow is normal rather
 * than exceptional. The rule pinned here is that each overflowing bind writes its
 * OWN new file and rewrites nothing: the earlier file keeps every byte it was
 * given, and the earlier marker — which names it — travels inside the newer
 * file's content verbatim, because the newer accumulator contains the older
 * bounded string. The chain is therefore readable from the single path the
 * model-visible marker quotes, and no pass can destroy bytes an earlier pass
 * retained. The growth is bounded twice over by arithmetic the callers already
 * enforce: one file per pass, and each file holds at most the cap plus one
 * bounded pass's output. Lifetime is R-2's, unchanged — every file in the chain
 * lives until the tool-result naming it leaves the session, and none is swept.
 * The corollary, and the reason a cap too small to carry a path un-writes its
 * own file: a retained file the marker cannot name is bytes no reader can find,
 * which is the same hole as losing them with a different caption.
 */
final class HookContextFiles
{
    /**
     * The stem of the dedicated sub-directory these overflow files live in,
     * under `sys_get_temp_dir()`; {@see directoryFor()} appends the effective
     * uid. Kept distinct from the bare temp directory that
     * {@see ToolIpcFiles::sweep()} globs, which is exactly why the sweep cannot
     * see a file written here (see the class docblock proof).
     */
    public const DIR_NAME = 'sc-hook-ctx';

    /**
     * The name every {@see PrivateRetainedDir} message gives this store, so a
     * refusal reads "hook overflow directory … is a symbolic link".
     */
    private const LABEL = 'hook overflow';

    /**
     * Bytes of the cap reserved for the spillover marker itself, so the string
     * {@see bound()} returns never exceeds the cap after the marker is appended.
     * Sized to out-hold the longest marker this class emits (a temp-dir path plus
     * the "N bytes retained" sentence) with room to spare.
     */
    private const MARKER_RESERVE_BYTES = 512;

    /**
     * The code on the one {@see \RuntimeException} family that names a SECURITY
     * refusal rather than a full or read-only disk.
     *
     * Public because {@see dir()}'s contract is public: an operator debugging a
     * hook that stopped retaining must be able to tell "this store said no" from
     * "there was nowhere to write", and {@see bound()} branches on it to put the
     * difference into the model-visible marker instead of flattening the two.
     * Exception codes are the only channel this class has for the distinction
     * without a new file for a second exception type.
     */
    public const REFUSAL_UNSAFE_DIRECTORY = PrivateRetainedDir::REFUSAL_UNSAFE_DIRECTORY;

    /**
     * The three reasons the marker gives for retaining nothing.
     *
     * Spelled as constants rather than inline so {@see bound()}'s branch on
     * {@see REFUSAL_UNSAFE_DIRECTORY} and the tests that read the model-visible
     * sentence cannot drift apart over the wording of a string literal. The first
     * is P7.S1's shipped text and is byte-pinned; the other two are new arms.
     */
    private const UNRETAINABLE_UNWRITABLE = 'overflow directory unwritable';

    private const UNRETAINABLE_REFUSED = 'overflow directory refused as unsafe';

    private const UNRETAINABLE_CAP_TOO_NARROW = 'the cap could not carry a retained path';

    /**
     * Absolute path of the retained-overflow directory, created `0700` on demand
     * and refused outright if what stands there is not a directory this user owns
     * exclusively.
     *
     * Exposed (rather than private) so the retention test can name the exact
     * directory the {@see ToolIpcFiles::sweep()} predicate must not reach, and so
     * a future session-teardown pass has one place to reclaim from.
     *
     * @throws \RuntimeException code {@see REFUSAL_UNSAFE_DIRECTORY} when the path
     *         is a symlink, is not a directory, belongs to another uid, or
     *         carries a group/other bit; code `0` when there is no such directory
     *         and none could be created.
     */
    public static function dir(): string
    {
        return self::verifiedDirectory(
            self::directoryFor(\function_exists('posix_geteuid') ? \posix_geteuid() : null),
        );
    }

    /**
     * {@see dir()}'s path for a given effective uid, or for a build with no
     * `posix_geteuid()`.
     *
     * The same naming as {@see \SugarCraft\Crush\Hooks\BuiltIn\AuditHook}'s
     * log directory, and a seam for the same reason: every build the suite runs
     * on has the posix lookup, so with it inline the `null` arm would be
     * reachable from no test. That arm is a shared `-noposix` scope, where
     * {@see PrivateRetainedDir::verified()} skips the uid comparison and only the
     * type and mode checks hold. The mode (`0700`), the foreign-access mask
     * (`077`) and the `.partial` intermediate are {@see PrivateRetainedDir}'s
     * constants, shared with every retained store.
     */
    private static function directoryFor(?int $uid): string
    {
        return PrivateRetainedDir::pathFor(self::DIR_NAME, $uid);
    }

    /**
     * THE INSPECTION BEHIND {@see dir()}, spelled against a full directory path.
     *
     * Public because it is the one owner-only-directory verdict in the tree:
     * {@see \SugarCraft\Crush\Agents\SuspendedDelegations} keeps resumable
     * sub-agent transcripts (which hold whatever the sub-agent read) under the
     * same rules rather than a second, weaker copy of them.
     *
     * WHY THE SEAM TAKES A PATH AND NOT A BASE. The refusals this method decides
     * are statements about a directory, and the only foreign-owned directory a
     * test can obtain without root is one that already exists on the box — so the
     * seam is the path itself, and the tests hand it that real directory rather
     * than a sandbox-shaped imitation of one.
     *
     * WHY `lstat()` AND WHY ONE CALL. `lstat()` answers for the FINAL component
     * without following it, so the symlink that is the entire threat is visible as
     * what it is; `stat()`, `is_dir()` and `fileperms()` all follow it and would
     * report a planted link as the tight, owned directory it points at. The type,
     * owner and mode arms then read from that one array instead of re-resolving
     * the path three more times, which is what keeps the verdict and the bytes
     * about to be written under the same observation.
     *
     * WHY THE STICKY BIT ON THE PARENT MAKES THE VERDICT HOLD. `/tmp` is `1777`,
     * and `S_ISVTX` there means only an entry's owner may rename or unlink it, so
     * once this method has accepted a `0700` directory of this uid, the attacker
     * who cannot delete that entry cannot put a link in its place either — and
     * cannot create an entry inside it, which is what makes the pre-flight
     * `lstat()` on the intermediate name in {@see write()} a belt rather than the
     * braces.
     *
     * @throws \RuntimeException see {@see dir()} for the two codes.
     */
    public static function verifiedDirectory(string $dir): string
    {
        // The arms themselves live in {@see PrivateRetainedDir} since roadmap
        // 2.8 gave them a second writer (the tool-output spill); the label is
        // what keeps every refusal naming THIS store.
        return PrivateRetainedDir::verified($dir, self::LABEL);
    }

    /**
     * Bound one model-visible context string to $cap BYTES, spilling the full
     * text into a retained file when it is longer.
     *
     * Returns a string whose length in BYTES never exceeds $cap:
     *   - at or under the cap: the input unchanged, nothing written;
     *   - over the cap: a `mb_strcut` byte-boundary HEAD of the text (the
     *     TruncatesOutput idiom, so the cut cannot split a UTF-8 codepoint on
     *     text that happens to be valid UTF-8, and cannot cut a multi-byte
     *     sequence in half into mojibake either), then a marker naming the
     *     retained file and the full byte count. The retained file holds the
     *     ENTIRE original text.
     *
     * $cap IS A REQUIRED ARGUMENT with no "no cap" sentinel, deliberately —
     * {@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput} records twice that
     * a `$maxBytes <= 0` "disabled" sentinel silently un-bound payloads meant to
     * be capped (Glob returned 1,091,833 bytes against a 65,536 cap). The head
     * room is `max(1, …)`-guarded for the same reason {@see
     * \SugarCraft\Crush\Tools\BuiltIn\Glob} guards its reserve: a cap smaller
     * than the marker reserve still yields at least one byte of preview rather
     * than a zero- or negative-length `mb_strcut`.
     *
     * WHY THE MARKER PUTS ITS FIGURES BEFORE ITS PATH. Both sentences end in the
     * half that can be long — a temp path, or the reason nothing was retained —
     * and the cap guarantee below is enforced by cutting from the right. The two
     * numbers a reader needs to know how much went missing are therefore always
     * the first thing in the marker, so the worst a degenerate cap can do is drop
     * the explanation, never the size of the hole.
     */
    public static function bound(string $text, int $cap): string
    {
        if (strlen($text) <= $cap) {
            return $text;
        }

        $total = strlen($text);
        $path = null;
        $why = self::UNRETAINABLE_UNWRITABLE;

        try {
            $path = self::write($text);
        } catch (\RuntimeException $failure) {
            // A retained file that cannot be written (read-only or full `/tmp`)
            // must not take the hook path down with it: a hook is OBSERVABILITY,
            // not the answer. Fall back to a preview-only marker that says the
            // remainder could not be retained rather than naming a path that
            // does not exist. The security refusal keeps its OWN clause for the
            // same reason an audit log does: "no room on the disk" and "this store
            // said no to the path it was offered" are different jobs for whoever
            // reads the transcript, and flattening them sends that person to
            // `df` instead of to `/tmp`.
            $why = $failure->getCode() === self::REFUSAL_UNSAFE_DIRECTORY
                ? self::UNRETAINABLE_REFUSED
                : self::UNRETAINABLE_UNWRITABLE;
        }

        $marker = $path === null
            ? self::unretainedMarker($total, $why)
            : self::retainedMarker($total, (string) $path);

        // The preview room is the cap minus the marker's own footprint, floored
        // at one byte (see the `$maxBytes <= 0` note above) — using the constant
        // reserve rather than this specific marker's length keeps the returned
        // string under $cap even on a pathologically long temp directory.
        $preview = mb_strcut($text, 0, max(1, $cap - self::MARKER_RESERVE_BYTES), 'UTF-8');

        // THE DISCLOSURE OUTRANKS THE PREVIEW, which is the only thing standing
        // between a small cap and the failure this class exists to avoid. Cutting
        // `preview . marker` to $cap from the right eats the END of the marker,
        // and the end of the marker is the retained path: MEASURED on the shipped
        // shape before this block existed, a 128-byte cap over 5,000 bytes of text
        // returned `… is retained at /tmp/sc-hook-ctx/ctx-0fc3c479ef8` — a name no
        // reader can open, indistinguishable in kind from never having written the
        // file. So the preview yields first, to nothing if it must. And if even a
        // bare marker will not fit, the file is un-written: a retained overflow no
        // marker names is the unnamed, unswept litter this store's whole retention
        // argument says must not exist, so the honest answer at that cap is the
        // head plus a note that nothing was retained.
        if (strlen($preview) + strlen($marker) > $cap) {
            $fit = $cap - strlen($marker);

            if ($fit < 0) {
                if ($path !== null) {
                    @unlink($path);
                }

                $marker = self::unretainedMarker($total, self::UNRETAINABLE_CAP_TOO_NARROW);
                $fit = 0;
            }

            $preview = $fit > 0 ? mb_strcut($text, 0, $fit, 'UTF-8') : '';
        }

        // Re-stamp the actual shown-byte count into the marker now that the
        // preview length is known.
        $marker = preg_replace_callback(
            '/truncated: \d+ of/',
            static fn (array $m): string => 'truncated: ' . strlen($preview) . ' of',
            $marker,
            1,
        );

        $result = $preview . $marker;

        // FINAL CAP GUARANTEE. For any $cap at or above MARKER_RESERVE_BYTES the
        // reserve already keeps `preview . marker` under $cap, so this is a no-op
        // on the only path production takes (the cap is always
        // HookResult::MAX_ADDITIONAL_CONTEXT_BYTES = 10,000). It also remains what
        // it was for a cap too small to hold even the shortest marker this class
        // emits — the last resort after the fit block above has already given the
        // preview away entirely, cutting the explanation rather than the byte
        // figures, which sit at the front of the sentence for exactly that moment.
        if (strlen($result) > $cap) {
            $result = mb_strcut($result, 0, $cap, 'UTF-8');
        }

        return $result;
    }

    /**
     * The "nothing was retained" sentence, with the reason it could not be.
     *
     * `overflow directory unwritable` is the wording this class shipped with at
     * P7.S1 and is reproduced here VERBATIM, `{@see}` comments aside: the marker
     * is model-visible text and the P6-era pins on it are byte pins.
     */
    private static function unretainedMarker(int $total, string $why): string
    {
        return sprintf(
            ' … [hook additional context truncated: %d of %d bytes shown; the full '
            . 'output could not be retained (%s)]',
            0,
            $total,
            $why,
        );
    }

    /** The sentence naming the file that holds the whole text. */
    private static function retainedMarker(int $total, string $path): string
    {
        return sprintf(
            ' … [hook additional context truncated: %d of %d bytes shown; the full '
            . 'output is retained at %s]',
            0,
            $total,
            $path,
        );
    }

    /**
     * Write the full overflow text, privately (0600) and atomically, and return
     * its absolute path. The file is RETAINED — nothing here unlinks it; that is
     * the whole point of R-2 (see the class docblock). The mechanics below are
     * {@see PrivateRetainedDir::write()}'s, shared with the tool-output spill
     * since roadmap 2.8; what this store adds is the directory and the name.
     *
     * THE INTERMEDIATE IS NEVER TRUSTED TO AN EXISTING NAME. An `lstat` on the
     * `.partial` before a single byte is addressed to it is this store's
     * exclusive-create check: the name carries 64 random bits and sits in a
     * directory {@see dir()} has just verified as owned by this uid, mode 0700 and
     * not a symlink, so anything already occupying it is either a collision or a
     * plant — and for both, the answer is to refuse rather than to truncate an
     * inode somebody else made. `file_put_contents` would otherwise open with
     * `O_TRUNC`, which follows a link at that exact name.
     *
     * WHY NOT AN `x`-MODE STREAM OPEN, which is the real `O_CREAT | O_EXCL` and one
     * character cheaper. MEASURED: that stream-open spelling is a member of the
     * read/execute sink alphabet in
     * {@see \SugarCraft\Crush\Tests\Support\ReadPathCensusTest}, which counts the
     * token wherever it appears in `src/` even though its own doc-block says the
     * census "covers READS and EXECUTES, not WRITES". Adding it here would file a
     * verdict row in a census outside this store's ceiling. The trade is a
     * check-then-write window of microseconds inside a directory that only this uid
     * can create entries in — closed by {@see dir()}, which is the door; this is
     * the latch.
     *
     * A create or write failure here carries exception code `0` — it is the
     * disk talking — while a refusal from {@see dir()} propagates untouched with
     * {@see REFUSAL_UNSAFE_DIRECTORY} on it, which is what lets {@see bound()} tell
     * the two apart in the marker without either one being logged twice.
     */
    public static function write(string $text): string
    {
        return PrivateRetainedDir::write(self::dir(), 'ctx-' . bin2hex(random_bytes(8)) . '.txt', $text, self::LABEL);
    }
}
