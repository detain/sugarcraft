<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Skills;

/**
 * Every read of a skill's files off disk, bounded (audit 15d-27).
 *
 * The prompt budgets {@see \SugarCraft\Crush\Context\CompactorConfig} holds
 * enabled skill bodies to cap only what is SPLICED into a request; until this
 * class, what was READ had no cap at all. A repository's multi-megabyte
 * `SKILL.md` was read whole into memory at launch — the manifest stage read the
 * entire file to find a frontmatter block a few hundred bytes long — and again
 * for its body, then scrubbed and measured before the budget threw it away.
 * Skill files are chosen by whoever wrote the tree, and the project and foreign
 * trees are written by whatever repository was cloned.
 *
 * Modelled on {@see \SugarCraft\Crush\Context\InstructionFileLoader}'s read-side
 * ceiling: stat first, so a file that is already over the ceiling is never
 * loaded to be measured; then read bounded at the ceiling plus one byte, so a
 * file that grows between the stat and the read still cannot deliver more than
 * that — the extra byte is what proves it overran.
 *
 * A refusal is a {@see \RuntimeException}, the exception every caller already
 * turns into a recorded skip ({@see SkillLoader::recordSkip()}), a launch
 * notice (`Bootstrap`'s `enabledSkills` resolution) or an error tool result
 * (the `Skill` tool) — so an oversized file is reported where an unreadable one
 * already was, never silently dropped and never a launch crash.
 *
 * Not a port — charmbracelet/crush has no skill loader to mirror.
 */
final class SkillFileReader
{
    /**
     * The most bytes one skill file — a `SKILL.md`, or an asset under its
     * `scripts/`, `references/` or `assets/` — is read in full.
     *
     * A memory guard, not a prompt budget — the per-skill prompt budget is a
     * few thousand tokens and is applied later, to what is spliced. One MiB is
     * far above any skill written for a model to read, and far below the size
     * that costs a launch anything.
     */
    public const MAX_FILE_BYTES = 1_048_576;

    /**
     * How much of a `SKILL.md` the Stage-1 manifest read takes to find its
     * frontmatter ({@see SkillLoader::loadSkillManifest()}). The frontmatter
     * is a handful of keys; a block that does not close within this many bytes
     * is refused rather than read further.
     */
    public const MAX_FRONTMATTER_BYTES = 65_536;

    /**
     * The whole file, or a {@see \RuntimeException} when it is over
     * {@see MAX_FILE_BYTES} or cannot be read.
     *
     * @param string $what what the file is, for the refusal's wording
     *        (`SKILL.md`, `skill asset`)
     */
    public static function read(string $path, string $what): string
    {
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(self::overCeiling($what, $size, $path));
        }

        $content = @file_get_contents($path, false, null, 0, self::MAX_FILE_BYTES + 1);
        if ($content === false) {
            throw new \RuntimeException("Failed to read {$what}: {$path}");
        }

        if (strlen($content) > self::MAX_FILE_BYTES) {
            // Grew past the ceiling after the stat said it was under it.
            throw new \RuntimeException(self::overCeiling($what, max((int) $size, strlen($content)), $path));
        }

        return $content;
    }

    /**
     * The first {@see MAX_FRONTMATTER_BYTES} of a `SKILL.md`, after the same
     * whole-file ceiling {@see read()} enforces — a file whose body can never
     * be read is refused at the manifest stage rather than listed to the model
     * as a skill it cannot load.
     *
     * @return array{0: string, 1: bool} the bytes read, and whether the file
     *         continues past them
     */
    public static function head(string $path, string $what): array
    {
        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(self::overCeiling($what, $size, $path));
        }

        $head = @file_get_contents($path, false, null, 0, self::MAX_FRONTMATTER_BYTES + 1);
        if ($head === false) {
            throw new \RuntimeException("Failed to read {$what}: {$path}");
        }

        if (strlen($head) > self::MAX_FRONTMATTER_BYTES) {
            return [substr($head, 0, self::MAX_FRONTMATTER_BYTES), true];
        }

        return [$head, false];
    }

    private static function overCeiling(string $what, int $bytes, string $path): string
    {
        return sprintf(
            '%s is %s bytes, over the %s-byte skill-file ceiling; not read: %s',
            $what,
            number_format($bytes),
            number_format(self::MAX_FILE_BYTES),
            $path,
        );
    }
}
