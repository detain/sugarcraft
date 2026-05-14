<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A syntax highlighting component for code display.
 *
 * Features:
 * - Language-aware syntax highlighting
 * - Line numbers (optional)
 * - Multiple color themes
 * - Line highlighting
 * - Word wrapping support
 *
 * Mirrors syntax highlighting concepts adapted to PHP with wither-style immutable setters.
 */
final class Highlight implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * Supported languages for syntax highlighting.
     */
    public const LANG_PHP = 'php';
    public const LANG_JS = 'javascript';
    public const LANG_TS = 'typescript';
    public const LANG_PYTHON = 'python';
    public const LANG_BASH = 'bash';
    public const LANG_JSON = 'json';
    public const LANG_XML = 'xml';
    public const LANG_HTML = 'html';
    public const LANG_CSS = 'css';
    public const LANG_SQL = 'sql';
    public const LANG_YAML = 'yaml';
    public const LANG_MARKDOWN = 'markdown';
    public const LANG_TEXT = 'text';

    /**
     * Color themes.
     */
    public const THEME_DEFAULT = 'default';
    public const THEME_MONOKAI = 'monokai';
    public const THEME_GITHUB = 'github';
    public const THEME_DRACULA = 'dracula';

    public function __construct(
        private readonly string $code,
        private readonly string $language = self::LANG_TEXT,
        private readonly bool $lineNumbers = false,
        private readonly string $theme = self::THEME_DEFAULT,
        private readonly ?array $highlightedLines = null,
        private readonly int $tabWidth = 4,
    ) {}

    /**
     * Create a new highlight component with default settings.
     */
    public static function new(string $code = ''): self
    {
        return new self(
            code: $code,
            language: self::LANG_TEXT,
            lineNumbers: false,
            theme: self::THEME_DEFAULT,
            highlightedLines: null,
            tabWidth: 4,
        );
    }

    /**
     * Create a new highlight component with PHP code.
     */
    public static function php(string $code): self
    {
        return new self(
            code: $code,
            language: self::LANG_PHP,
            lineNumbers: false,
            theme: self::THEME_DEFAULT,
            highlightedLines: null,
            tabWidth: 4,
        );
    }

    /**
     * Create a new highlight component with JavaScript code.
     */
    public static function js(string $code): self
    {
        return new self(
            code: $code,
            language: self::LANG_JS,
            lineNumbers: false,
            theme: self::THEME_DEFAULT,
            highlightedLines: null,
            tabWidth: 4,
        );
    }

    /**
     * Create a new highlight component with Python code.
     */
    public static function python(string $code): self
    {
        return new self(
            code: $code,
            language: self::LANG_PYTHON,
            lineNumbers: false,
            theme: self::THEME_DEFAULT,
            highlightedLines: null,
            tabWidth: 4,
        );
    }

    /**
     * Create a new highlight component with Bash code.
     */
    public static function bash(string $code): self
    {
        return new self(
            code: $code,
            language: self::LANG_BASH,
            lineNumbers: false,
            theme: self::THEME_DEFAULT,
            highlightedLines: null,
            tabWidth: 4,
        );
    }

    /**
     * Create a new highlight component with JSON.
     */
    public static function json(string $code): self
    {
        return new self(
            code: $code,
            language: self::LANG_JSON,
            lineNumbers: false,
            theme: self::THEME_DEFAULT,
            highlightedLines: null,
            tabWidth: 4,
        );
    }

    /**
     * Create with Monokai theme.
     */
    public static function monokai(string $code, string $language = self::LANG_TEXT): self
    {
        return new self(
            code: $code,
            language: $language,
            lineNumbers: false,
            theme: self::THEME_MONOKAI,
            highlightedLines: null,
            tabWidth: 4,
        );
    }

    /**
     * Set the allocated dimensions for this highlight component.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the highlighted code as a string.
     */
    public function render(): string
    {
        $lines = $this->splitLines($this->code);
        $theme = $this->getThemeColors();

        $result = '';

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $isHighlighted = $this->highlightedLines !== null && in_array($lineNumber, $this->highlightedLines, true);

            if ($this->lineNumbers) {
                $result .= $this->renderLineNumber($lineNumber, $theme);
            }

            if ($isHighlighted) {
                $result .= $theme['highlight']->toBg(ColorProfile::TrueColor);
            }

            $result .= $this->highlightLine($line, $theme);

            if ($isHighlighted) {
                $result .= Ansi::reset();
            }

            if ($this->lineNumbers || $index < count($lines) - 1) {
                $result .= "\n";
            }
        }

        return rtrim($result, "\n");
    }

    /**
     * Split code into lines.
     *
     * @return list<string>
     */
    private function splitLines(string $code): array
    {
        $lines = explode("\n", $code);
        return array_map(fn(string $line): string => str_replace("\t", str_repeat(' ', $this->tabWidth), $line), $lines);
    }

    /**
     * Render a line number.
     */
    private function renderLineNumber(int $number, array $theme): string
    {
        $lineNumStr = sprintf('%4d ', $number);
        return $theme['line_number']->toFg(ColorProfile::TrueColor) . $lineNumStr . Ansi::reset();
    }

    /**
     * Highlight a single line of code.
     */
    private function highlightLine(string $line, array $theme): string
    {
        if ($this->language === self::LANG_TEXT) {
            return $this->escapeAndColorize($line, $theme['text']);
        }

        return match ($this->language) {
            self::LANG_PHP => $this->highlightPhp($line, $theme),
            self::LANG_JS, self::LANG_TS => $this->highlightJs($line, $theme),
            self::LANG_PYTHON => $this->highlightPython($line, $theme),
            self::LANG_BASH => $this->highlightBash($line, $theme),
            self::LANG_JSON => $this->highlightJson($line, $theme),
            self::LANG_XML, self::LANG_HTML => $this->highlightXml($line, $theme),
            self::LANG_SQL => $this->highlightSql($line, $theme),
            self::LANG_YAML => $this->highlightYaml($line, $theme),
            default => $this->escapeAndColorize($line, $theme['text']),
        };
    }

    /**
     * Highlight PHP code.
     */
    private function highlightPhp(string $line, array $theme): string
    {
        // Keywords
        $keywords = ['echo', 'return', 'if', 'else', 'elseif', 'foreach', 'for', 'while', 'do', 'switch', 'case', 'break', 'continue', 'function', 'class', 'interface', 'trait', 'extends', 'implements', 'public', 'private', 'protected', 'static', 'final', 'abstract', 'const', 'var', 'new', 'try', 'catch', 'throw', 'finally', 'use', 'namespace', 'include', 'require', 'include_once', 'require_once', 'instanceof', 'as', 'match', 'fn', 'yield', 'await', 'async'];
        $builtins = ['array', 'string', 'int', 'float', 'bool', 'void', 'null', 'true', 'false', 'self', 'parent', 'this', 'mixed', 'never'];

        return $this->highlightGeneric($line, $theme, $keywords, $builtins);
    }

    /**
     * Highlight JavaScript/TypeScript code.
     */
    private function highlightJs(string $line, array $theme): string
    {
        $keywords = ['const', 'let', 'var', 'function', 'return', 'if', 'else', 'for', 'while', 'do', 'switch', 'case', 'break', 'continue', 'class', 'extends', 'new', 'this', 'super', 'import', 'export', 'from', 'default', 'async', 'await', 'try', 'catch', 'throw', 'finally', 'throw', 'typeof', 'instanceof', 'in', 'of', 'delete', 'void', 'null', 'undefined', 'true', 'false'];
        $builtins = ['console', 'Array', 'Object', 'String', 'Number', 'Boolean', 'Function', 'Symbol', 'Map', 'Set', 'WeakMap', 'WeakSet', 'Promise', 'Proxy', 'Reflect', 'JSON', 'Math', 'Date', 'RegExp', 'Error'];

        return $this->highlightGeneric($line, $theme, $keywords, $builtins);
    }

    /**
     * Highlight Python code.
     */
    private function highlightPython(string $line, array $theme): string
    {
        $keywords = ['def', 'class', 'return', 'if', 'elif', 'else', 'for', 'while', 'try', 'except', 'finally', 'with', 'as', 'import', 'from', 'pass', 'break', 'continue', 'raise', 'lambda', 'yield', 'global', 'nonlocal', 'assert', 'async', 'await', 'True', 'False', 'None', 'and', 'or', 'not', 'in', 'is'];
        $builtins = ['print', 'len', 'range', 'str', 'int', 'float', 'list', 'dict', 'set', 'tuple', 'bool', 'bytes', 'bytearray', 'memoryview', 'type', 'isinstance', 'issubclass', 'hasattr', 'getattr', 'setattr', 'delattr', 'callable', 'super', 'property', 'staticmethod', 'classmethod', 'map', 'filter', 'zip', 'enumerate', 'sorted', 'reversed', 'open', 'input', 'repr', 'format', 'chr', 'ord', 'hex', 'oct', 'bin', 'pow', 'abs', 'divmod', 'round', 'min', 'max', 'sum', 'all', 'any'];

        return $this->highlightGeneric($line, $theme, $keywords, $builtins);
    }

    /**
     * Highlight Bash code.
     */
    private function highlightBash(string $line, array $theme): string
    {
        $keywords = ['if', 'then', 'else', 'elif', 'fi', 'case', 'esac', 'for', 'while', 'do', 'done', 'in', 'function', 'select', 'time', 'coproc', 'until', 'return', 'exit', 'break', 'continue', 'local', 'declare', 'typeset', 'readonly', 'export', 'unset', 'shift', 'set', 'source', 'alias', 'unalias'];
        $builtins = ['echo', 'printf', 'read', 'cd', 'pwd', 'ls', 'cp', 'mv', 'rm', 'mkdir', 'rmdir', 'touch', 'cat', 'grep', 'sed', 'awk', 'find', 'sort', 'uniq', 'wc', 'head', 'tail', 'cut', 'tr', 'tee', 'chmod', 'chown', 'chgrp', 'sudo', 'su', 'ssh', 'scp', 'rsync', 'curl', 'wget', 'tar', 'gzip', 'gunzip', 'zip', 'unzip', 'diff', 'patch', 'make', 'gcc', 'g++', 'python', 'python3', 'node', 'npm', 'git', 'docker', 'kubectl'];

        return $this->highlightGeneric($line, $theme, $keywords, $builtins);
    }

    /**
     * Highlight JSON.
     */
    private function highlightJson(string $line, array $theme): string
    {
        // String keys: "key":
        $line = preg_replace_callback(
            '/("(?:[^"\\\\]|\\\\.)*")\s*:/',
            fn(array $matches): string => $theme['keyword']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset() . $theme['punctuation']->toFg(ColorProfile::TrueColor) . ':' . Ansi::reset(),
            $line
        ) ?? $line;

        // String values
        $line = preg_replace_callback(
            '/:\s*("(?:[^"\\\\]|\\\\.)*")/',
            fn(array $matches): string => $theme['punctuation']->toFg(ColorProfile::TrueColor) . ': ' . Ansi::reset() . $theme['string']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset(),
            $line
        ) ?? $line;

        // Numbers
        $line = preg_replace_callback(
            '/:\s*(-?\d+\.?\d*)/',
            fn(array $matches): string => $theme['punctuation']->toFg(ColorProfile::TrueColor) . ': ' . Ansi::reset() . $theme['number']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset(),
            $line
        ) ?? $line;

        // Booleans and null
        $line = str_ireplace([': true', ': false', ': null'], [
            $theme['punctuation']->toFg(ColorProfile::TrueColor) . ': ' . Ansi::reset() . $theme['keyword']->toFg(ColorProfile::TrueColor) . 'true' . Ansi::reset(),
            $theme['punctuation']->toFg(ColorProfile::TrueColor) . ': ' . Ansi::reset() . $theme['keyword']->toFg(ColorProfile::TrueColor) . 'false' . Ansi::reset(),
            $theme['punctuation']->toFg(ColorProfile::TrueColor) . ': ' . Ansi::reset() . $theme['keyword']->toFg(ColorProfile::TrueColor) . 'null' . Ansi::reset(),
        ], $line);

        // Punctuation
        $line = str_replace(['{', '}', '[', ']', ','], [
            $theme['punctuation']->toFg(ColorProfile::TrueColor) . '{' . Ansi::reset(),
            $theme['punctuation']->toFg(ColorProfile::TrueColor) . '}' . Ansi::reset(),
            $theme['punctuation']->toFg(ColorProfile::TrueColor) . '[' . Ansi::reset(),
            $theme['punctuation']->toFg(ColorProfile::TrueColor) . ']' . Ansi::reset(),
            $theme['punctuation']->toFg(ColorProfile::TrueColor) . ',' . Ansi::reset(),
        ], $line);

        return $line;
    }

    /**
     * Highlight XML/HTML.
     */
    private function highlightXml(string $line, array $theme): string
    {
        // Tags
        $line = preg_replace_callback(
            '/<\/?[a-zA-Z][a-zA-Z0-9]*/',
            fn(array $matches): string => $theme['keyword']->toFg(ColorProfile::TrueColor) . $matches[0] . Ansi::reset(),
            $line
        ) ?? $line;

        // Attributes
        $line = preg_replace_callback(
            '/\s([a-zA-Z][a-zA-Z0-9-]*)=/',
            fn(array $matches): string => ' ' . $theme['attribute']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset() . $theme['punctuation']->toFg(ColorProfile::TrueColor) . '=' . Ansi::reset(),
            $line
        ) ?? $line;

        // String values
        $line = preg_replace_callback(
            '/="([^"]*)"/',
            fn(array $matches): string => $theme['punctuation']->toFg(ColorProfile::TrueColor) . '="' . Ansi::reset() . $theme['string']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset() . $theme['punctuation']->toFg(ColorProfile::TrueColor) . '"' . Ansi::reset(),
            $line
        ) ?? $line;

        return $line;
    }

    /**
     * Highlight SQL.
     */
    private function highlightSql(string $line, array $theme): string
    {
        $keywords = ['SELECT', 'FROM', 'WHERE', 'AND', 'OR', 'NOT', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 'CREATE', 'TABLE', 'INDEX', 'DROP', 'ALTER', 'ADD', 'COLUMN', 'PRIMARY', 'KEY', 'FOREIGN', 'REFERENCES', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'ON', 'AS', 'ORDER', 'BY', 'GROUP', 'HAVING', 'LIMIT', 'OFFSET', 'UNION', 'ALL', 'DISTINCT', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX', 'NULL', 'IS', 'LIKE', 'IN', 'BETWEEN', 'EXISTS', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END', 'ASC', 'DESC'];

        return $this->highlightGeneric($line, $theme, $keywords, []);
    }

    /**
     * Highlight YAML.
     */
    private function highlightYaml(string $line, array $theme): string
    {
        // Keys
        $line = preg_replace_callback(
            '/^(\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/m',
            fn(array $matches): string => $matches[1] . $theme['keyword']->toFg(ColorProfile::TrueColor) . $matches[2] . Ansi::reset() . ':',
            $line
        ) ?? $line;

        // String values in quotes
        $line = preg_replace_callback(
            '/:\s*["\']([^"\']*)["\']/',
            fn(array $matches): string => $theme['punctuation']->toFg(ColorProfile::TrueColor) . ': ' . Ansi::reset() . $theme['string']->toFg(ColorProfile::TrueColor) . '"' . $matches[1] . '"' . Ansi::reset(),
            $line
        ) ?? $line;

        // Booleans and null
        $line = preg_replace_callback(
            '/:\s*(true|false|null)\s*$/i',
            fn(array $matches): string => $theme['punctuation']->toFg(ColorProfile::TrueColor) . ': ' . Ansi::reset() . $theme['keyword']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset(),
            $line
        ) ?? $line;

        // Numbers
        $line = preg_replace_callback(
            '/:\s*(-?\d+\.?\d*)\s*$/',
            fn(array $matches): string => $theme['punctuation']->toFg(ColorProfile::TrueColor) . ': ' . Ansi::reset() . $theme['number']->toFg(ColorProfile::TrueColor) . $matches[1] . Ansi::reset(),
            $line
        ) ?? $line;

        return $line;
    }

    /**
     * Generic syntax highlighting for keyword-based languages.
     */
    private function highlightGeneric(string $line, array $theme, array $keywords, array $builtins): string
    {
        // Comments (# or //)
        if (preg_match('/^(\s*)(#|\/\/)/', $line)) {
            return $theme['comment']->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }

        // Strings (double quoted)
        $line = preg_replace_callback(
            '/"([^"\\\\]|\\\\.)*"/',
            fn(array $matches): string => $theme['string']->toFg(ColorProfile::TrueColor) . $matches[0] . Ansi::reset(),
            $line
        ) ?? $line;

        // Strings (single quoted)
        $line = preg_replace_callback(
            '/\'([^\'\\\\]|\\\\.)*\'/',
            fn(array $matches): string => $theme['string']->toFg(ColorProfile::TrueColor) . $matches[0] . Ansi::reset(),
            $line
        ) ?? $line;

        // Numbers
        $line = preg_replace_callback(
            '/\b(\d+\.?\d*)\b/',
            fn(array $matches): string => $theme['number']->toFg(ColorProfile::TrueColor) . $matches[0] . Ansi::reset(),
            $line
        ) ?? $line;

        // Keywords and builtins (word boundaries)
        $allWords = array_merge($keywords, $builtins);
        $line = preg_replace_callback(
            '/\b(' . implode('|', array_map('preg_quote', $allWords)) . ')\b/i',
            fn(array $matches) => in_array(mb_strtolower($matches[0]), array_map('mb_strtolower', $keywords), true)
                ? $theme['keyword']->toFg(ColorProfile::TrueColor) . $matches[0] . Ansi::reset()
                : $theme['builtin']->toFg(ColorProfile::TrueColor) . $matches[0] . Ansi::reset(),
            $line
        ) ?? $line;

        return $line;
    }

    /**
     * Escape special characters and apply text color.
     */
    private function escapeAndColorize(string $text, Color $color): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return $color->toFg(ColorProfile::TrueColor) . $escaped . Ansi::reset();
    }

    /**
     * Get the color theme.
     *
     * @return array{keyword:Color, string:Color, number:Color, comment:Color, builtin:Color, text:Color, line_number:Color, highlight:Color, punctuation:Color, attribute:Color}
     */
    private function getThemeColors(): array
    {
        return match ($this->theme) {
            self::THEME_MONOKAI => [
                'keyword' => Color::hex('#F92672'),
                'string' => Color::hex('#E6DB74'),
                'number' => Color::hex('#AE81FF'),
                'comment' => Color::hex('#75715E'),
                'builtin' => Color::hex('#66D9EF'),
                'text' => Color::hex('#F8F8F2'),
                'line_number' => Color::hex('#90908A'),
                'highlight' => Color::hex('#49483E'),
                'punctuation' => Color::hex('#F8F8F2'),
                'attribute' => Color::hex('#A6E22E'),
            ],
            self::THEME_GITHUB => [
                'keyword' => Color::hex('#D73A49'),
                'string' => Color::hex('#032F62'),
                'number' => Color::hex('#005CC5'),
                'comment' => Color::hex('#6A737D'),
                'builtin' => Color::hex('#E36209'),
                'text' => Color::hex('#24292E'),
                'line_number' => Color::hex('#959DA5'),
                'highlight' => Color::hex('#FFF5B1'),
                'punctuation' => Color::hex('#24292E'),
                'attribute' => Color::hex('#6F42C1'),
            ],
            self::THEME_DRACULA => [
                'keyword' => Color::hex('#FF79C6'),
                'string' => Color::hex('#F1FA8C'),
                'number' => Color::hex('#BD93F9'),
                'comment' => Color::hex('#6272A4'),
                'builtin' => Color::hex('#50FA7B'),
                'text' => Color::hex('#F8FAFC'),
                'line_number' => Color::hex('#6272A4'),
                'highlight' => Color::hex('#44475A'),
                'punctuation' => Color::hex('#F8FAFC'),
                'attribute' => Color::hex('#50FA7B'),
            ],
            default => [
                'keyword' => Color::hex('#CC7832'),
                'string' => Color::hex('#A5C25C'),
                'number' => Color::hex('#6897BB'),
                'comment' => Color::hex('#808080'),
                'builtin' => Color::hex('#B5B5B5'),
                'text' => Color::hex('#AAAAAA'),
                'line_number' => Color::hex('#999999'),
                'highlight' => Color::hex('#2A2A2A'),
                'punctuation' => Color::hex('#AAAAAA'),
                'attribute' => Color::hex('#6E9C28'),
            ],
        };
    }

    /**
     * Calculate the natural dimensions of this highlight component.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $lines = $this->splitLines($this->code);
        $maxWidth = 0;

        foreach ($lines as $line) {
            $lineWidth = Width::string($line);
            if ($this->lineNumbers) {
                $lineWidth += 5; // Space for line number
            }
            $maxWidth = max($maxWidth, $lineWidth);
        }

        $width = $this->width ?? $maxWidth;
        $height = count($lines);

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the code content.
     */
    public function withCode(string $code): self
    {
        return new self(
            code: $code,
            language: $this->language,
            lineNumbers: $this->lineNumbers,
            theme: $this->theme,
            highlightedLines: $this->highlightedLines,
            tabWidth: $this->tabWidth,
        );
    }

    /**
     * Set the language.
     */
    public function withLanguage(string $language): self
    {
        return new self(
            code: $this->code,
            language: $language,
            lineNumbers: $this->lineNumbers,
            theme: $this->theme,
            highlightedLines: $this->highlightedLines,
            tabWidth: $this->tabWidth,
        );
    }

    /**
     * Set line numbers visibility.
     */
    public function withLineNumbers(bool $show): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            lineNumbers: $show,
            theme: $this->theme,
            highlightedLines: $this->highlightedLines,
            tabWidth: $this->tabWidth,
        );
    }

    /**
     * Set the color theme.
     */
    public function withTheme(string $theme): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            lineNumbers: $this->lineNumbers,
            theme: $theme,
            highlightedLines: $this->highlightedLines,
            tabWidth: $this->tabWidth,
        );
    }

    /**
     * Set highlighted lines.
     *
     * @param list<int>|null $lines
     */
    public function withHighlightedLines(?array $lines): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            lineNumbers: $this->lineNumbers,
            theme: $this->theme,
            highlightedLines: $lines,
            tabWidth: $this->tabWidth,
        );
    }

    /**
     * Set the tab width.
     */
    public function withTabWidth(int $width): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            lineNumbers: $this->lineNumbers,
            theme: $this->theme,
            highlightedLines: $this->highlightedLines,
            tabWidth: max(1, $width),
        );
    }
}
