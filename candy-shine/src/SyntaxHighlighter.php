<?php

declare(strict_types=1);

namespace SugarCraft\Shine;

/**
 * Lightweight regex-based syntax highlighter for fenced code blocks.
 * Tokenises into four classes (comment / string / number / keyword)
 * and styles each via the matching {@see Theme} slot. Falls back to
 * the plain code-block style for unknown / empty languages.
 *
 * The set of recognised languages is intentionally small — PHP, JS,
 * TS, JSON, Python, Go, Bash, SQL — so the regex stays maintainable.
 * For prose-heavy READMEs this is plenty; deeper highlighting can
 * grow later without changing the call site.
 */
final class SyntaxHighlighter
{
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

    /**
     * @param bool $lineNumbers When true, each line is prefixed with its 1-based
     *                          line number styled using the theme's comment slot.
     */
    public static function highlight(string $code, string $language, Theme $theme, bool $lineNumbers = false): string
    {
        $lang = strtolower(trim($language));
        $lang = self::ALIASES[$lang] ?? $lang;

        // JSON has no keywords (everything is data); just colour
        // strings + numbers + literals.
        if ($lang === 'json') {
            $highlighted = self::tokenise($code, $theme, keywords: ['true', 'false', 'null']);
        } elseif (!isset(self::KEYWORDS[$lang])) {
            $highlighted = $theme->codeBlock?->render($code) ?? $code;
        } else {
            $highlighted = self::tokenise($code, $theme, keywords: self::KEYWORDS[$lang]);
        }

        if (!$lineNumbers) {
            return $highlighted;
        }

        $commentStyle = $theme->comment;
        $lines = explode("\n", $highlighted);
        $paddedWidth = strlen((string) count($lines));

        return implode("\n", array_map(
            static function (string $line, int $i) use ($commentStyle, $paddedWidth): string {
                $padded = str_pad((string) ($i + 1), $paddedWidth, ' ', STR_PAD_LEFT);
                $styled = $commentStyle?->render($padded) ?? $padded;
                return $styled . "\t" . $line;
            },
            $lines,
            array_keys($lines),
        ));
    }

    /** @var array<string, string> cached compiled regex patterns, keyed by keyword list */
    private static array $patternCache = [];

    /**
     * @param list<string> $keywords
     */
    private static function getPattern(array $keywords): string
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

    /**
     * @param list<string> $keywords
     */
    private static function tokenise(string $code, Theme $theme, array $keywords): string
    {
        // Build a single combined regex with named alternatives. Order
        // matters — comment/string before keyword/number so the latter
        // can't match inside the former.
        $pattern = self::getPattern($keywords);

        $base = $theme->codeBlock ?? null;
        $out  = '';
        $pos  = 0;
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return $base?->render($code) ?? $code;
        }
        foreach ($matches as $m) {
            // Find which named class actually matched.
            foreach (['comment', 'string', 'keyword', 'number'] as $cls) {
                if (!isset($m[$cls]) || $m[$cls][1] === -1) {
                    continue;
                }
                [$txt, $offset] = $m[$cls];
                if ($txt === '') {
                    continue 2;
                }
                if ($offset > $pos) {
                    $gap = substr($code, $pos, $offset - $pos);
                    $out .= $base?->render($gap) ?? $gap;
                }
                $style = match ($cls) {
                    'comment' => $theme->comment,
                    'string'  => $theme->string,
                    'keyword' => $theme->keyword,
                    'number'  => $theme->number,
                };
                $out .= $style?->render($txt) ?? ($base?->render($txt) ?? $txt);
                $pos = $offset + strlen($txt);
                continue 2;
            }
        }
        if ($pos < strlen($code)) {
            $tail = substr($code, $pos);
            $out .= $base?->render($tail) ?? $tail;
        }
        return $out;
    }
}
