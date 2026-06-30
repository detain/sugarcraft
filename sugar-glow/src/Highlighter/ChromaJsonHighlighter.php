<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Highlighter;

use function preg_match_all, file_get_contents, json_decode;

use const null;
use SugarCraft\Core\Util\Ansi;

/**
 * Chroma-inspired JSON theme highlighter.
 *
 * Uses a JSON theme file (array of token-type => SGR color mappings) and
 * regex-based tokenization to apply syntax highlighting. This is a simplified
 * proof-of-concept; real tokenization requires a proper lexer (Pygments/Scrivener).
 *
 * @see https://github.com/alecthomas/chroma for the upstream theme format
 */
final class ChromaJsonHighlighter implements HighlighterInterface
{
    /** @param array<string, string> token-type => SGR color */
    private array $theme;

    /**
     * Combined pattern for single-pass tokenization.
     * Each alternative captures token text in group 1 and identifies type via array key.
     *
     * @var string
     */
    private string $combinedPattern;

    public function __construct(array $theme)
    {
        $this->theme = $theme;
        $this->combinedPattern = $this->buildCombinedPattern();
    }

    private function buildCombinedPattern(): string
    {
        // Order matters: more specific patterns first.
        // Patterns are stored as BARE bodies (no surrounding /.../ delimiters).
        // The `m` flag is applied once to the final combined pattern.
        //
        // Split into categories to reduce NFA backtracking: a 70+ word alternation
        // creates pathological backtrack risk. Constants (async|await|void|null|true|false|mixed)
        // are separated into their own group.
        $orderedTypes = [
            'comment'     => '(\/\*[\s\S]*?\*\/|\/\/[^\n]*|#.*$)',
            'string'      => '"[^"]*"|\'[^\']*\'',
            'keyword'     => '\b(abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield|yield from)\b',
            'constant'    => '\b(async|await|void|null|true|false|mixed)\b',
            'number'      => '\b\d+\.?\d*\b',
            // Use lookahead so the `(` is not part of the captured function name.
            'function'   => '\b[a-zA-Z_]\w*(?=\s*\()',
            'operator'   => '[+\-*\/%=<>!&|^~]+',
            'punctuation' => '[{}()\[\];,\.]',
        ];

        $alternations = [];
        foreach ($orderedTypes as $type => $body) {
            $alternations[] = '(?<' . $type . '>' . $body . ')';
        }

        return '/(' . implode('|', $alternations) . ')/m';
    }

    /**
     * @param array<string, string> $theme token-type => SGR color mapping
     */
    public static function fromTheme(array $theme): self
    {
        return new self($theme);
    }

    /**
     * Load highlighter from a JSON theme file.
     */
    public static function fromJsonFile(string $path): self
    {
        $json = json_decode((string) file_get_contents($path), true);
        return new self($json ?? []);
    }

    public function highlight(string $code, string $language): string
    {
        if ($code === '') {
            return '';
        }

        $theme = $this->theme;
        $pattern = $this->combinedPattern;

        // NOTE: static closure is intentional.
        // The $theme is captured via use(), not $this->theme, to ensure stateless
        // operation and prevent accidental $this capture. If refactoring, preserve
        // static + use() pattern to maintain thread-safety.
        $result = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($theme): string {
                // Find the named group whose captured value equals the full match.
                // PCRE fills ALL declared named groups; exactly one alternation matched,
                // so exactly one named group equals $matches[0].
                // Use array_filter + array_search (O(n)) instead of iterating all groups.
                $fullMatch = $matches[0];
                $namedGroups = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $matchedType = array_search($fullMatch, $namedGroups, true);
                if ($matchedType !== false) {
                    // Strip any embedded ESC bytes from the source to prevent
                    // terminal control-code injection (the CLI path uses CandyShine's
                    // Renderer which sanitizes; this guards standalone consumers).
                    $value = str_replace("\x1b", '', $fullMatch);
                    $color = $theme[$matchedType] ?? null;
                    if ($color !== null) {
                        return Ansi::CSI . $color . 'm' . $value . Ansi::reset();
                    }
                    return $value;
                }
                return $fullMatch;
            },
            $code
        );

        // If a backtrack/recursion limit was hit, preg_replace returns null/empty.
        // Degrade gracefully to raw code rather than returning a partial highlight.
        if ($result === null || preg_last_error() !== PREG_NO_ERROR) {
            return $code;
        }

        return $result;
    }

    private const SUPPORTED_LANGUAGES = [
        'php',
        'javascript', 'js',
        'typescript', 'ts',
        'html', 'css',
        'c', 'cpp',
        'java', 'go', 'rust', 'ruby', 'python',
    ];

    public function supports(string $language): bool
    {
        // Only return true for languages whose syntax this highlighter can handle.
        // The regex patterns are PHP-focused but work for most C-style languages.
        if ($language === '' || $language === 'text') {
            return false;
        }
        return in_array(strtolower($language), self::SUPPORTED_LANGUAGES, true);
    }
}
