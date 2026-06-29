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
        $orderedTypes = [
            'comment'     => '(\/\*[\s\S]*?\*\/|\/\/[^\n]*|#.*$)',
            'string'      => '"[^"]*"|\'[^\']*\'',
            'keyword'     => '\b(abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|die|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|eval|exit|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield|yield from|async|await|void|null|true|false|mixed)\b',
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

        return (string) preg_replace_callback(
            $pattern,
            static function (array $matches) use ($theme): string {
                // Find the named group whose captured value equals the full match.
                // PCRE fills ALL declared named groups; exactly one alternation matched,
                // so exactly one named group equals $matches[0].
                $fullMatch = $matches[0];
                foreach ($matches as $type => $value) {
                    if (!is_string($type) || $value === '' || $value !== $fullMatch) {
                        continue;
                    }
                    $color = $theme[$type] ?? null;
                    if ($color !== null) {
                        return Ansi::CSI . $color . 'm' . $value . Ansi::reset();
                    }
                    return $value;
                }
                return $fullMatch;
            },
            $code
        ) ?: $code;
    }

    public function supports(string $language): bool
    {
        // Supports any language except empty-string (no language specified)
        // and the generic 'text' sentinel (plain text, not to be highlighted).
        return $language !== '' && $language !== 'text';
    }
}
