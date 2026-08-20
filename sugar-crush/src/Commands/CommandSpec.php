<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use SugarCraft\Crush\Palette\PaletteAction;

/**
 * Metadata for one command, used by BOTH command surfaces - the "/" popup
 * ({@see \SugarCraft\Crush\Renderer::renderSlashMenu()}) and the Ctrl+P
 * palette ({@see \SugarCraft\Crush\Renderer::renderPalette()}).
 *
 * Display data for a BUILT-IN row - it does not affect
 * {@see \SugarCraft\Crush\Chat::submit()}'s own dispatch chain, which stays the
 * single source of truth for what a built-in command does. A FILE-BASED row is
 * the other half of that sentence and no longer only display data: it carries
 * the prompt itself, and {@see expandTemplate()} is what `submit()` sends when
 * one is typed.
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
     * The prompt this file-based command sends, with its argument placeholders
     * filled in. Returns null for a built-in row, which has no template and
     * whose behaviour is PHP in {@see \SugarCraft\Crush\Chat::dispatchCommand()}.
     *
     * TWO PLACEHOLDER FORMS, and they are fed from two DIFFERENT readings of the
     * same keystrokes, which is why both parameters exist rather than one:
     *
     *  - `$ARGUMENTS` is everything typed after the command name, VERBATIM —
     *    quotes, doubled spaces and all, apart from the SURROUNDING whitespace
     *    that {@see \SugarCraft\Crush\Chat::expandCustomCommand()} trims off
     *    before calling this (the draft as a whole was already trimmed by
     *    `submit()`, so trimming here only removes the run of spaces between the
     *    name and the first argument). A template that says "Fix: $ARGUMENTS"
     *    wants the sentence back the way it was written, so re-joining split
     *    tokens with single spaces would be a quiet rewrite of the user's prose.
     *  - `$1` … `$9` are the tokens {@see \SugarCraft\Crush\CommandParser::parse()}
     *    produced, i.e. shell-quote split and UNQUOTED, so `/deploy "us east" prod`
     *    puts `us east` in `$1` and `prod` in `$2`. Only nine, matching every
     *    other tool that spells positional arguments this way; `$10` is `$1`
     *    followed by a literal `0`, exactly as in `sh`.
     *
     * A MISSING POSITIONAL EXPANDS TO THE EMPTY STRING rather than staying
     * literal. Both answers are defensible and this one is chosen because the
     * output is a PROMPT: a leftover `$2` reaching the model is an implementation
     * token leaking into the conversation, where it reads as a filename or a
     * variable the model is expected to know. An empty slot reads as an omission,
     * which is what it is. The same rule already applies to `$ARGUMENTS` with no
     * arguments at all, so the two cannot disagree.
     *
     * `$$` IS THE ESCAPE, producing one literal `$`. A doubling rule rather than
     * a backslash because the body is markdown headed for a model — backslashes
     * there already mean something to both markdown and to whatever the prompt
     * is quoting, while `$$` collides with nothing this class emits. A literal
     * `$1` is therefore written `$$1`, and a `$` not followed by a placeholder
     * (`$PATH`, `$(date)`, a bare `$`) is left ALONE, so an ordinary shell
     * snippet inside a template survives untouched.
     *
     * ONE PASS OVER THE WHOLE BODY, not a pass per placeholder and not a pass per
     * line, and that is the substantive decision here:
     *
     *  - Per placeholder (`$1` first, then `$ARGUMENTS`, or the reverse) means
     *    the text a pass SUBSTITUTES is visible to the next pass. An argument
     *    containing the characters `$ARGUMENTS` would then be re-expanded — user
     *    input becoming template syntax, which is the injection shape this
     *    whole class fails closed against elsewhere. A single alternation makes
     *    replaced text unreachable to the matcher by construction, so the
     *    "which order?" question has no answer BECAUSE it has no meaning here.
     *  - Per line would make the result depend on where the author happened to
     *    break lines, and nothing in the syntax spans or respects a newline.
     *
     * ARGUMENTS ARE NOT APPENDED when the template names no placeholder: the
     * body is sent unchanged. Silently tacking them on the end would land them
     * after whatever closing instruction the author wrote, changing what that
     * instruction applies to — a template that wants arguments says where they go.
     *
     * @param string       $arguments  everything after the command name, as typed
     * @param list<string> $positional the parsed tokens; index 0 is `$1`
     */
    public function expandTemplate(string $arguments, array $positional = []): ?string
    {
        if ($this->template === null) {
            return null;
        }

        return preg_replace_callback(
            '/\$(\$|ARGUMENTS|[1-9])/',
            static function (array $m) use ($arguments, $positional): string {
                if ($m[1] === '$') {
                    return '$';
                }
                if ($m[1] === 'ARGUMENTS') {
                    return $arguments;
                }

                return $positional[(int) $m[1] - 1] ?? '';
            },
            $this->template,
        ) ?? $this->template;
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
