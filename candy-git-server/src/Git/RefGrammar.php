<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Git;

/**
 * Refname grammar gate for client-supplied ref names.
 *
 * Push command lines carry a fully client-controlled refname straight into
 * `git update-ref`. escapeshellarg keeps it out of the SHELL, but not out of
 * git's own option parser (a leading '-' is an option to update-ref), and a
 * ref containing '..' addresses files outside the refs/ tree on disk. This
 * gate mirrors git's check-ref-format rules (the subset a server needs to
 * reject hostile names before ever calling git):
 *
 *  - no leading '-' (option-injection class), no blanks/control chars;
 *  - no '..', no '@{', no '~^:?*[' or backslash;
 *  - no component may start with '.' or end with '.lock';
 *  - no double slashes, no trailing slash, no trailing dot;
 *  - must live under refs/ (or be exactly HEAD);
 *  - bounded length.
 *
 * @see https://git-scm.com/docs/git-check-ref-format
 */
final class RefGrammar
{
    /** Hard ceiling on refname length (git caps paths at PATH_MAX; 1024 is generous). */
    public const MAX_LENGTH = 1024;

    private function __construct()
    {
    }

    public static function isValidRefName(string $ref): bool
    {
        if ($ref === '' || \strlen($ref) > self::MAX_LENGTH) {
            return false;
        }

        // Control characters, space and DEL are forbidden outright; a leading
        // '-' would be parsed as an option by git update-ref itself.
        if ($ref[0] === '-') {
            return false;
        }
        if (\preg_match('/[\x00-\x20\x7f]/', $ref) === 1) {
            return false;
        }

        // Characters git refuses in refnames.
        if (\preg_match('/[~^:?*\[\\\\]/', $ref) === 1) {
            return false;
        }

        // '..' may not appear anywhere (path traversal into the refs store),
        // and the reflog-only '@{' sequence has no place in a pushed name.
        if (\str_contains($ref, '..') || \str_contains($ref, '@{')) {
            return false;
        }

        // Structural rules.
        if (\str_ends_with($ref, '/') || \str_contains($ref, '//')) {
            return false;
        }
        if (\str_ends_with($ref, '.')) {
            return false;
        }

        $components = \explode('/', $ref);
        foreach ($components as $component) {
            if ($component === '') {
                return false;
            }
            if ($component[0] === '.') {
                return false;
            }
            if (\str_ends_with($component, '.lock')) {
                return false;
            }
        }

        // Namespace rule for pushed refs: the ref store, or exactly HEAD.
        return $ref === 'HEAD' || \str_starts_with($ref, 'refs/');
    }
}
