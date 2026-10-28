<?php

declare(strict_types=1);

namespace SugarCraft\Core\Syntax;

/**
 * Lightweight regex-based {@see Highlighter}.
 *
 * Tokenises into four lexical classes (comment / string / number / keyword)
 * with everything else emitted as {@see TokenKind::Plain}. The recognised
 * language set covers PHP, JS, TS, JSON, Python, Go, Bash, SQL plus Rust,
 * Java, C, C++, Ruby, YAML, HTML and CSS, reachable through aliases (rs, rb,
 * yml, cc/h/hpp, …) and inferred from a file path via
 * {@see languageForPath()}. Comment and string markers are per-language
 * (rust has no `#` comments, CSS has no line comments, HTML only `<!-- -->`),
 * so the combined regex stays maintainable and linear (O(n)) — every branch
 * is an unrolled loop or a disjoint-character repetition, never a nested
 * quantifier.
 *
 * This is the pure tokenisation half extracted from sugar-markdown's
 * SyntaxHighlighter: it carries no colour/ANSI/theme dependency, so any
 * consumer (sugar-markdown's Markdown renderer, candy-codeshot, ...) can map the
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
        'rust' => [
            'as','async','await','break','const','continue','crate','dyn','else','enum',
            'extern','false','fn','for','if','impl','in','let','loop','match','mod','move',
            'mut','pub','ref','return','self','static','struct','super','trait','true',
            'type','unsafe','use','where','while','yield',
        ],
        'java' => [
            'abstract','assert','boolean','break','byte','case','catch','char','class',
            'const','continue','default','do','double','else','enum','extends','false',
            'final','finally','float','for','goto','if','implements','import','instanceof',
            'int','interface','long','native','new','null','package','private','protected',
            'public','return','short','static','strictfp','super','switch','synchronized',
            'this','throw','throws','transient','true','try','void','volatile','while',
        ],
        'c' => [
            'auto','bool','break','case','char','const','continue','default','do','double',
            'else','enum','extern','false','float','for','goto','if','inline','int','long',
            'NULL','register','restrict','return','short','signed','sizeof','static','struct',
            'switch','true','typedef','union','unsigned','void','volatile','while',
        ],
        'cpp' => [
            'alignas','alignof','and','asm','auto','bool','break','case','catch','char',
            'class','const','constexpr','const_cast','continue','decltype','default','delete',
            'do','double','dynamic_cast','else','enum','explicit','export','extern','false',
            'float','for','friend','goto','if','inline','int','long','mutable','namespace','new',
            'noexcept','nullptr','operator','private','protected','public','register',
            'reinterpret_cast','return','short','signed','sizeof','static','static_assert',
            'static_cast','struct','switch','template','this','throw','true','try','typedef',
            'typeid','typename','union','unsigned','using','virtual','void','volatile',
            'wchar_t','while','xor',
        ],
        'ruby' => [
            'alias','and','attr_accessor','attr_reader','attr_writer','begin','break','case',
            'class','def','defined','do','else','elsif','end','ensure','false','for','if','in',
            'lambda','module','next','nil','not','or','proc','redo','require',
            'require_relative','rescue','retry','return','self','super','then','true','undef',
            'unless','until','when','while','yield',
        ],
        // YAML has no reserved words in the language sense; its scalar literal
        // booleans/nulls are the only tokens worth classifying.
        'yaml' => ['true','false','null','yes','no','on','off'],
        // Markup: element and attribute names play the keyword role. Single-letter
        // tags (a/b/i/p/q/s/u) stay out of the list — their \b legs fire on
        // ordinary prose inside the document.
        'html' => [
            'abbr','address','area','article','aside','audio','bdi','bdo','blockquote','body',
            'br','button','canvas','caption','cite','code','colgroup','data','datalist','dd',
            'del','details','dfn','dialog','div','dl','dt','em','embed','fieldset',
            'figcaption','figure','footer','form','head','header','hgroup','hr','html','iframe',
            'img','input','ins','kbd','label','legend','li','link','main','map','mark','menu',
            'meta','meter','nav','noscript','object','ol','optgroup','option','output','param',
            'picture','pre','progress','script','section','select','slot','source','span',
            'strong','style','sub','summary','sup','svg','table','tbody','td','template',
            'textarea','tfoot','th','thead','time','title','tr','track','ul','var','video',
            'aria','checked','class','disabled','draggable','enctype','href','id','placeholder',
            'readonly','rel','required','role','src','target','value','alt','for','height',
            'width','type','name','doctype',
        ],
        // Style sheets: property names (hyphenated legs match via word boundaries).
        'css' => [
            'accent-color','align-items','align-content','align-self','all','animation',
            'appearance','aspect-ratio','backface-visibility','background','border','bottom',
            'box-shadow','box-sizing','break-after','break-before','break-inside','caret',
            'clear','clip','clip-path','color','column-count','column-gap','columns',
            'content','cursor','direction','display','empty-cells','filter','flex','float',
            'font','font-family','font-size','font-style','font-weight','gap','grid','height',
            'hyphens','isolation','justify-content','left','letter-spacing','line-height',
            'list-style','margin','mask','max-height','max-width','min-height','min-width',
            'opacity','order','orphans','outline','overflow','padding','page-break',
            'paint-order','perspective','pointer-events','position','quotes','resize','right',
            'rotate','row-gap','scale','scroll-behavior','shape-outside','tab-size',
            'table-layout','text-align','text-decoration','text-indent','text-overflow',
            'text-shadow','text-transform','top','touch-action','transform','transition',
            'translate','unicode-bidi','user-select','vertical-align','visibility',
            'white-space','widows','width','will-change','word-break','word-spacing',
            'writing-mode','z-index','important',
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
        'rs'         => 'rust',
        'rb'         => 'ruby',
        'yml'        => 'yaml',
        'cc'         => 'cpp',
        'h'          => 'c',
        'hpp'        => 'cpp',
        'htm'        => 'html',
        'jsx'        => 'js',
        'tsx'        => 'ts',
        'mjs'        => 'js',
        'cjs'        => 'js',
    ];

    /**
     * Per-language comment/string rules.
     *
     *  - line:     line-comment markers, each running to end of line;
     *  - block:    C-style /* ... *&#47; block comments (unrolled-loop, linear);
     *  - html:     &lt;!-- ... --&gt; comments (the HTML/XML form);
     *  - backtick: backtick-delimited string literals.
     *
     * Languages absent from this table (the original php/js/ts/python/go/bash/
     * sql set plus json) use {@see DEFAULT_RULES} exactly, so their combined
     * pattern — and every existing consumer's byte output — is unchanged.
     *
     * @var array<string, array{line: list<string>, block: bool, html: bool, backtick: bool}>
     */
    private const RULES = [
        'rust'   => ['line' => ['//'], 'block' => true,  'html' => false, 'backtick' => false],
        'java'   => ['line' => ['//'], 'block' => true,  'html' => false, 'backtick' => false],
        'c'      => ['line' => ['//'], 'block' => true,  'html' => false, 'backtick' => false],
        'cpp'    => ['line' => ['//'], 'block' => true,  'html' => false, 'backtick' => false],
        'ruby'   => ['line' => ['#'],  'block' => false, 'html' => false, 'backtick' => true],
        'yaml'   => ['line' => ['#'],  'block' => false, 'html' => false, 'backtick' => false],
        'html'   => ['line' => [],     'block' => false, 'html' => true,  'backtick' => false],
        'css'    => ['line' => [],     'block' => true,  'html' => false, 'backtick' => false],
    ];

    /**
     * The historical combined alternation: both line markers, block and HTML
     * comments, and backtick strings. Double-quoted and single-quoted strings
     * are universal and never gated.
     *
     * @var array{line: list<string>, block: bool, html: bool, backtick: bool}
     */
    private const DEFAULT_RULES = ['line' => ['//', '#'], 'block' => true, 'html' => true, 'backtick' => true];

    /**
     * File extension (lower-cased, final dot-segment) → canonical language id.
     * Values are guaranteed keys of {@see KEYWORDS} or 'json'.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'php'   => 'php',
        'js'    => 'js',
        'mjs'   => 'js',
        'cjs'   => 'js',
        'jsx'   => 'js',
        'ts'    => 'ts',
        'tsx'   => 'ts',
        'json'  => 'json',
        'jsonc' => 'json',
        'py'    => 'python',
        'pyw'   => 'python',
        'go'    => 'go',
        'sh'    => 'bash',
        'bash'  => 'bash',
        'zsh'   => 'bash',
        'sql'   => 'sql',
        'rs'    => 'rust',
        'java'  => 'java',
        'c'     => 'c',
        'h'     => 'c',
        'cpp'   => 'cpp',
        'cc'    => 'cpp',
        'cxx'   => 'cpp',
        'hpp'   => 'cpp',
        'hh'    => 'cpp',
        'hxx'   => 'cpp',
        'rb'    => 'ruby',
        'yaml'  => 'yaml',
        'yml'   => 'yaml',
        'html'  => 'html',
        'htm'   => 'html',
        'css'   => 'css',
    ];

    /** @var array<string, string> cached compiled regex patterns, keyed by keyword list + rules */
    private static array $patternCache = [];

    /**
     * Infer the highlighter language from a file path by its final extension.
     *
     * Pure and total: any path (absolute, relative, dotfile, extensionless)
     * maps to a recognised language id or null. Consumers that only know a
     * filename — diff views, file panes — use this to pick a fence language
     * for arbitrary sources.
     */
    public static function languageForPath(string $path): ?string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext === '' ? null : (self::EXTENSIONS[$ext] ?? null);
    }

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

        return self::scan($code, $keywords, self::RULES[$lang] ?? self::DEFAULT_RULES);
    }

    /**
     * @param list<string> $keywords
     * @param array{line: list<string>, block: bool, html: bool, backtick: bool} $rules
     *
     * @return list<TokenSpan>
     */
    private static function scan(string $code, array $keywords, array $rules): array
    {
        $pattern = self::pattern($keywords, $rules);

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
     * @param array{line: list<string>, block: bool, html: bool, backtick: bool} $rules
     */
    private static function pattern(array $keywords, array $rules): string
    {
        $key = implode("\x00", $keywords) . '|' . implode(',', $rules['line'])
            . ($rules['block'] ? 'B' : '') . ($rules['html'] ? 'H' : '') . ($rules['backtick'] ? 'T' : '');
        return self::$patternCache[$key] ??= self::buildPattern($keywords, $rules);
    }

    /**
     * Assemble the combined tokeniser regex from the language's keyword list
     * and lexical rules. Every repetition inside is either an unrolled loop
     * (block/HTML comments) or a disjoint-character class (line comments,
     * strings), so matching stays linear on adversarial input — no nested
     * quantifier anywhere.
     *
     * @param list<string> $keywords
     * @param array{line: list<string>, block: bool, html: bool, backtick: bool} $rules
     */
    private static function buildPattern(array $keywords, array $rules): string
    {
        $kw = implode('|', array_map(static fn(string $k): string => preg_quote($k, '/'), $keywords));

        $commentParts = [];
        foreach ($rules['line'] as $marker) {
            $commentParts[] = preg_quote($marker, '/') . '[^\n]*';
        }
        if ($rules['block']) {
            $commentParts[] = '\/\*[^*]*(?:\*(?!\/)[^*]*)*\*\/';
        }
        if ($rules['html']) {
            $commentParts[] = '<!--(?:[^-]|-(?!->))*-->';
        }

        // Double- and single-quoted strings are universal; backticks are a
        // per-language rule (JS-family shells, ruby command literals).
        $quoteRules = ['"', "'"];
        if ($rules['backtick']) {
            $quoteRules[] = '`';
        }
        $stringParts = array_map(
            static fn(string $q): string => $q . '(?:\\\\.|[^' . $q . '\\\\])*' . $q,
            $quoteRules,
        );

        $alternatives = [];
        if ($commentParts !== []) {
            $alternatives[] = '(?P<comment>' . implode('|', $commentParts) . ')';
        }
        $alternatives[] = '(?P<string>' . implode('|', $stringParts) . ')';
        $alternatives[] = '(?P<keyword>\b(?:' . $kw . ')\b)';
        $alternatives[] = '(?P<number>\b\d+(?:\.\d+)?\b)';

        return '/' . implode('|', $alternatives) . '/su';
    }
}
