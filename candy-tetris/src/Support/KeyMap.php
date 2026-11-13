<?php

/**
 * candy-tetris/src/Support/KeyMap.php
 *
 * The user-override half of finding m27: a tiny JSON file — action id to
 * tokens — folded onto the shipped table. The file is deliberately permissive
 * about WHICH ids appear (the registry is context-free; naming an action the
 * game does not dispatch is inert, not an error) and strict about SHAPE,
 * refusing loudly rather than half-applying a malformed map: a typo in a
 * key file must not silently strand a binding mid-session.
 *
 * A missing file is the defaults — byte for byte, no notices. An unreadable
 * one costs the session its overrides, not its game: the binary catches the
 * throw, warns on stderr, and boots the shipped table. A slot occupied by
 * anything other than a plain file (symlink, socket, FIFO) counts as
 * unreadable: refused by name, never followed, never opened.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Support;

use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Tetris\Lang;

final class KeyMap
{
    /**
     * Longest binding token the loader accepts, in bytes. Named keys are
     * short words ('backspace' is the widest the decoder emits); a single
     * character rune costs at most four bytes. Anything past this ceiling
     * is not a key name, and no frame column can absorb it without lying.
     */
    private const MAX_TOKEN_BYTES = 32;

    /**
     * Fold the user's overrides (when the file exists) onto $defaults.
     *
     * @throws \RuntimeException when the file is unparseable or malformed
     */
    public static function load(?string $path, KeyBindings $defaults): KeyBindings
    {
        if ($path === null) {
            return $defaults;
        }

        // lstat, not is_file(): lstat never follows a symlink, so a planted
        // keys.json -> <anything> is refused instead of being read through
        // (and, on the save side of the same XDG slot, clobbered by the
        // publish rename). A socket or FIFO occupying the slot must never
        // reach the reader either — the gate and the open then judge the
        // exact same inode, and a pre-planted special file costs the session
        // its overrides loudly (the throw below) rather than an open() that
        // blocks startup forever waiting for a writer.
        $slot = @lstat($path);
        if ($slot === false) {
            return $defaults;
        }
        if (($slot['mode'] & 0o170000) !== 0o100000) {
            throw new \RuntimeException(self::problem($path, 'key file is not a regular file'));
        }

        $entries = AtomicJsonFile::new($path)->read();

        $bindings = $defaults;
        foreach ($entries as $id => $tokens) {
            if (!\is_string($id) || $id === '') {
                throw new \RuntimeException(self::problem($path, 'action name is not a non-empty string'));
            }
            if (!\is_array($tokens) || $tokens === [] || array_is_list($tokens) === false) {
                throw new \RuntimeException(self::problem($path, "tokens for \"$id\" are not a non-empty list"));
            }
            foreach ($tokens as $token) {
                if (!\is_string($token) || $token === '' || str_contains($token, ' ')) {
                    throw new \RuntimeException(self::problem($path, "token for \"$id\" is not a single key"));
                }
                // The help card, the versus strip and the on-ramp label all
                // print the LIVE token verbatim (Bindings::helpCard,
                // Bindings::vsStrip, Game::introBeginLabel). The stderr
                // notice channel clamps control bytes through the binary's
                // cliVisible(); the frame had no matching door, so a crafted
                // keys.json carrying ESC bytes would paint raw escape
                // sequences into the terminal every render. Control bytes
                // (\x00-\x1f, \x7f) and over-long tokens are refused at the
                // validation gate so no renderer path can receive them.
                if (preg_match('/[\x00-\x1f\x7f]/', $token) === 1
                    || \strlen($token) > self::MAX_TOKEN_BYTES
                ) {
                    throw new \RuntimeException(self::problem($path, "token for \"$id\" is not a printable key name"));
                }
            }

            $bindings = $bindings->withOverride($id, ...$tokens);
        }

        return $bindings;
    }

    private static function problem(string $path, string $detail): string
    {
        // The frame localizes (candy-tetris-028); the detail stays the
        // code's own diagnostic vocabulary — it names a structural fact
        // about the file, translatable later with the strings themselves.
        return Lang::t('config.problem', ['detail' => $detail, 'path' => $path]);
    }
}
