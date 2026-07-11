<?php

declare(strict_types=1);

namespace SugarCraft\Core\Syntax;

/**
 * Lightweight regex-based {@see Highlighter}.
 *
 * Tokenises into four lexical classes (comment / string / number / keyword)
 * with everything else emitted as {@see TokenKind::Plain}. The recognised
 * language set is intentionally small — PHP, JS, TS, JSON, Python, Go, Bash,
 * SQL — so the combined regex stays maintainable and linear (O(n)).
 *
 * This is the pure tokenisation half extracted from candy-shine's
 * SyntaxHighlighter: it carries no colour/ANSI/theme dependency, so any
 * consumer (candy-shine's Markdown renderer, candy-freeze, ...) can map the
 * span classes onto its own presentation while sharing one lexer.
 */
final class RegexHighlighter implements Highlighter
{
    /**
     * Upper bound on the byte length we will run the tokeniser regex over.
     * The combined pattern is linear O(n), but a multi-megabyte block still
     * burns CPU proportionally — an unbounded input is a cheap denial-of
     * service surface. Mirrors the 1 MB caps enforced by the sibling loaders;
     * oversized input degrades to a single {@see TokenKind::Plain} span so the
     * caller renders it plainly.
     */
    private const MAX_TOKENIZE_BYTES = 1_000_000;

    /**
     * Named alternatives, checked in this order per match. Comment/string
     * come before keyword/number so the latter can't match inside the former.
     *
     * @var list<string>
     */
    private const TOKEN_CLASSES = ['comment', 'string', 'keyword', 'number'];

    /** @var array<string, list<string>> language → keyword list */
    private const KEYWORDS = [
        'php' => [
            'abstract','and','array','as','break','case','catch','class','clone','const',
            'continue','declare','default','die','do','echo','else','elseif','empty',
            'enddeclare','endfor','endforeach','endif','endswitch','endwhile','enum',
            'extends','final','finally','fn','for','foreach','function','global','goto',
            'if','implements','include','include_once','instanceof','insteadof','interface',
            'isset','list','match','namespace','new','null','or','print','private','protected',
            'public','readonly','require','require_once','return','static','switch','throw',
            'trait','try','unset','use','var','while','xor','yield',
            'true','false',
        ],
        'js' => [
            'await','break','case','catch','class','const','continue','debugger','default',
            'delete','do','else','export','extends','false','finally','for','function','if',
            'import','in','instanceof','let','new','null','of','return','super','switch',
            'this','throw','true','try','typeof','undefined','var','void','while','with','yield',
            'async',
        ],
        'ts' => [
            'await','break','case','catch','class','const','continue','debugger','default',
            'delete','do','else','enum','export','extends','false','finally','for','function',
            'if','implements','import','in','instanceof','interface','let','new','null','of',
            'private','protected','public','readonly','return','super','switch','this',
            'throw','true','try','type','typeof','undefined','var','void','while','with',
            'yield','async','as',
        ],
        'python' => [
            'False','None','True','and','as','assert','async','await','break','class',
            'continue','def','del','elif','else','except','finally','for','from','global',
            'if','import','in','is','lambda','nonlocal','not','or','pass','raise','return',
            'try','while','with','yield',
        ],
        'go' => [
            'break','case','chan','const','continue','default','defer','else','fallthrough',
            'for','func','go','goto','if','import','interface','map','package','range',
            'return','select','struct','switch','type','var','nil','true','false',
        ],
        'bash' => [
            'if','then','else','elif','fi','case','esac','for','while','until','do','done',
            'in','select','function','time','export','local','readonly','declare','return',
            'true','false',
        ],
        'sql' => [
            'select','from','where','and','or','not','null','is','in','as','on','join',
            'inner','outer','left','right','full','cross','group','by','order','having',
            'limit','offset','insert','into','values','update','set','delete','create',
            'alter','drop','table','index','view','primary','key','foreign','references',
            'unique','default','distinct','union','all','case','when','then','else','end',
        ],
    ];

    /** @var array<string, string> alias → canonical language id. */
    private const ALIASES = [
        'javascript' => 'js',
        'typescript' => 'ts',
        'py'         => 'python',
        'sh'         => 'bash',
        'zsh'        => 'bash',
        'shell'      => 'bash',
        'golang'     => 'go',
        'jsonc'      => 'json',
    ];

    /** @var array<string, string> cached compiled regex patterns, keyed by keyword list */
    private static array $patternCache = [];

    /**
     * @return list<TokenSpan>
     */
    public function tokenize(string $code, string $language): array
    {
        // DoS guard: skip tokenisation for oversized input and emit a single
        // Plain span so the caller renders it plainly. Keeps tokenisation a
        // bounded-cost operation for every consumer.
        if (strlen($code) > self::MAX_TOKENIZE_BYTES) {
            return [new TokenSpan(TokenKind::Plain, $code)];
        }

        $lang = strtolower(trim($language));
        $lang = self::ALIASES[$lang] ?? $lang;

        // JSON has no keywords (everything is data); only the literals
        // true/false/null are treated as keyword-class tokens.
        if ($lang === 'json') {
            $keywords = ['true', 'false', 'null'];
        } elseif (!isset(self::KEYWORDS[$lang])) {
            // Unknown/empty language: one Plain span over the whole input.
            return [new TokenSpan(TokenKind::Plain, $code)];
        } else {
            $keywords = self::KEYWORDS[$lang];
        }

        return self::scan($code, $keywords);
    }

    /**
     * @param list<string> $keywords
     *
     * @return list<TokenSpan>
     */
    private static function scan(string $code, array $keywords): array
    {
        $pattern = self::pattern($keywords);

        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            // Regex engine bailed (e.g. backtrack limit): degrade to plain.
            return [new TokenSpan(TokenKind::Plain, $code)];
        }

        $spans = [];
        $pos = 0;
        foreach ($matches as $m) {
            // Find which named class actually matched (order matters).
            foreach (self::TOKEN_CLASSES as $cls) {
                if (!isset($m[$cls]) || $m[$cls][1] === -1) {
                    continue;
                }
                [$txt, $offset] = $m[$cls];
                if ($txt === '') {
                    continue 2;
                }
                if ($offset > $pos) {
                    $spans[] = new TokenSpan(TokenKind::Plain, substr($code, $pos, $offset - $pos), $pos);
                }
                $kind = match ($cls) {
                    'comment' => TokenKind::Comment,
                    'string'  => TokenKind::StringToken,
                    'keyword' => TokenKind::Keyword,
                    'number'  => TokenKind::Number,
                };
                $spans[] = new TokenSpan($kind, $txt, $offset);
                $pos = $offset + strlen($txt);
                continue 2;
            }
        }
        if ($pos < strlen($code)) {
            $spans[] = new TokenSpan(TokenKind::Plain, substr($code, $pos), $pos);
        }

        return $spans;
    }

    /**
     * @param list<string> $keywords
     */
    private static function pattern(array $keywords): string
    {
        $key = implode("\x00", $keywords);
        return self::$patternCache[$key] ??= self::buildPattern($keywords);
    }

    /**
     * @param list<string> $keywords
     */
    private static function buildPattern(array $keywords): string
    {
        $kw = implode('|', array_map(static fn(string $k): string => preg_quote($k, '/'), $keywords));
        return '/'
            . '(?P<comment>\/\/[^\n]*|\#[^\n]*|\/\*[^*]*(?:\*(?!\/)[^*]*)*\*\/|<!--(?:[^-]|-(?!->))*-->)'
            . '|(?P<string>"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`)'
            . '|(?P<keyword>\b(?:' . $kw . ')\b)'
            . '|(?P<number>\b\d+(?:\.\d+)?\b)'
            . '/su';
    }
}
