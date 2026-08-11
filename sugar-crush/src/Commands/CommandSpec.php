<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use SugarCraft\Crush\Palette\PaletteAction;

/**
 * Metadata for one command, used by BOTH command surfaces - the "/" popup
 * ({@see \SugarCraft\Crush\Renderer::renderSlashMenu()}) and the Ctrl+P
 * palette ({@see \SugarCraft\Crush\Renderer::renderPalette()}). Pure display
 * data - it does not affect {@see \SugarCraft\Crush\Chat::submit()}'s own
 * dispatch chain, which stays the single source of truth for what a command
 * actually does.
 *
 * A row is visible in the "/" popup unless $slashVisible is false, and
 * visible in the Ctrl+P palette exactly when it carries a $paletteAction -
 * the two flags are what let one registry feed two surfaces that legitimately
 * do not list identical sets (e.g. "New session" has no slash form).
 *
 * A row can also come from disk instead of {@see CommandRegistry::all()} -
 * {@see fromFile()} builds one from a user-authored `*.md` file (see
 * {@see CommandLoader}). Those rows carry a $template, which is what marks
 * them as file-based: a built-in's behaviour lives in `Chat::submit()`, a
 * file-based one's lives in its template body.
 */
final class CommandSpec
{
    private const FRONTMATTER_PATTERN = '/^---\s*\n(.*?)\n---\s*\n/s';

    /**
     * A command name is typed after "/" and used as an array key, so it is
     * restricted to path-safe characters. "/" itself is allowed as the
     * subdirectory namespace separator ("deploy/staging.md" -> "deploy/staging")
     * but a leading/trailing/doubled separator is not, which is what keeps a
     * traversal-shaped filename ("../../etc/passwd.md") from ever becoming a
     * command name.
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*(\/[A-Za-z0-9][A-Za-z0-9_-]*)*$/';

    public function __construct(
        /** Command name without the leading "/", e.g. "compact". */
        public readonly string $name,
        /** One-line human-readable description shown in the popup/palette. */
        public readonly string $description,
        /** Grouping label shown in the Ctrl+P palette, e.g. "Session". */
        public readonly string $category,
        /**
         * The palette action {@see \SugarCraft\Crush\Chat} dispatches when
         * this row is picked in Ctrl+P; null for commands the palette does
         * not list (they are reachable by typing "/name" instead).
         */
        public readonly ?PaletteAction $paletteAction = null,
        /**
         * Palette row text when it should read differently from the bare
         * command name ("Switch session" vs "sessions"). Null falls back to
         * {@see label()}'s default, the name itself.
         */
        public readonly ?string $paletteLabel = null,
        /** Argument placeholder shown after the name, e.g. "<name>". */
        public readonly ?string $argumentHint = null,
        /** Keybind that also triggers this command, e.g. "Ctrl+C". */
        public readonly ?string $shortcut = null,
        /** Whether the "/" popup lists this row (false = palette-only). */
        public readonly bool $slashVisible = true,
        /**
         * Prompt-template body of a file-based command, i.e. everything after
         * the YAML frontmatter. Null for built-in rows, whose behaviour is
         * PHP in `Chat::submit()` rather than text.
         */
        public readonly ?string $template = null,
        /** Frontmatter `model:` - pins this command to one model. */
        public readonly ?string $model = null,
        /** Frontmatter `subtask: true` - run in an isolated subagent. */
        public readonly bool $subtask = false,
    ) {}

    public static function new(
        string $name,
        string $description,
        string $category,
        ?PaletteAction $paletteAction = null,
        ?string $paletteLabel = null,
        ?string $argumentHint = null,
        ?string $shortcut = null,
        bool $slashVisible = true,
        ?string $template = null,
        ?string $model = null,
        bool $subtask = false,
    ): self {
        return new self(
            $name,
            $description,
            $category,
            $paletteAction,
            $paletteLabel,
            $argumentHint,
            $shortcut,
            $slashVisible,
            $template,
            $model,
            $subtask,
        );
    }

    /**
     * Build a row from a user-authored command file: YAML frontmatter
     * (`description`, `argument-hint`, `model`, `subtask`) plus a template
     * body. Frontmatter is optional - a bare markdown file is a valid command
     * whose body is the whole prompt.
     *
     * Everything here is user-controlled input, so it fails closed: any
     * unreadable file, unparseable YAML, wrongly-typed frontmatter value,
     * unsafe command name, or empty template raises instead of yielding a
     * half-built row. {@see CommandLoader::loadFromDirectory()} catches and
     * skips, so one bad file cannot take the whole directory down with it.
     *
     * @param string $path Absolute path to the `.md` file.
     * @param string $name Command name (without the leading "/"), derived by
     *                     the caller from the file's path.
     */
    public static function fromFile(string $path, string $name): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException("Unsafe command name: $name");
        }

        // Check first so a missing path throws cleanly instead of emitting a
        // PHP warning from file_get_contents before we throw.
        if (!is_file($path)) {
            throw new \RuntimeException("Command file not found: $path");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read command file: $path");
        }

        if (preg_match(self::FRONTMATTER_PATTERN, $content, $matches) === 1) {
            try {
                $meta = Yaml::parse($matches[1]);
            } catch (ParseException $e) {
                throw new \InvalidArgumentException("Malformed frontmatter in $path: {$e->getMessage()}", 0, $e);
            }
            $body = substr($content, strlen($matches[0]));
        } else {
            $meta = [];
            $body = $content;
        }

        // `--- \n null \n ---` parses to a scalar, not a map.
        if (!is_array($meta)) {
            throw new \InvalidArgumentException("Frontmatter must be a YAML mapping in $path");
        }

        $template = trim($body);
        if ($template === '') {
            throw new \InvalidArgumentException("Command file has an empty template body: $path");
        }

        return new self(
            name: $name,
            description: self::stringField($meta, 'description', $path) ?? "Custom command: $name",
            category: 'Custom',
            argumentHint: self::stringField($meta, 'argument-hint', $path),
            template: $template,
            model: self::stringField($meta, 'model', $path),
            subtask: self::boolField($meta, 'subtask', $path),
        );
    }

    /**
     * The row's display text in the Ctrl+P palette.
     */
    public function label(): string
    {
        return $this->paletteLabel ?? $this->name;
    }

    /**
     * Whether this row came from a command file rather than
     * {@see CommandRegistry::all()}.
     */
    public function isFileBased(): bool
    {
        return $this->template !== null;
    }

    /**
     * A frontmatter value that must be a string when present. A YAML list or
     * map here means the author mis-wrote the file; coercing it with (string)
     * would silently produce "Array", so reject it instead.
     *
     * @param array<mixed> $meta
     */
    private static function stringField(array $meta, string $key, string $path): ?string
    {
        $value = $meta[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new \InvalidArgumentException("Frontmatter '$key' must be a string in $path");
        }

        return (string)$value;
    }

    /** @param array<mixed> $meta */
    private static function boolField(array $meta, string $key, string $path): bool
    {
        $value = $meta[$key] ?? false;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Frontmatter '$key' must be a boolean in $path");
        }

        return $value;
    }
}
