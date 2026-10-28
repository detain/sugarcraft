# C-TUI-2.a — Highlighter language breadth + path→language

VERDICT: DONE. sugar-core RegexHighlighter gains rust/java/c/cpp/ruby/yaml/html/css
keyword sets + rs/rb/yml/cc/h/hpp/htm/jsx/tsx/mjs/cjs aliases, per-language comment
& string RULES (rust/java/c/cpp: // + /* */ only; ruby/yaml: #; html: <!-- -->;
css: /* */; ruby backtick), and pure static languageForPath() extension map
(pathinfo+lowercase, '' and dotfiles → null). Existing 7 languages keep
DEFAULT_RULES so their compiled pattern is byte-identical (legacy spans/json pins
stay green; candy-code consumer smoke unchanged). sugar-markdown docblock + README
language lists extended (roster prose unpinned by any guard — verified) and 6
delegate tests added.

Tests (all modified files, no new files → no durations duty):
- sugar-core: --filter 'RegexHighlighter|Highlight' OK 80T/164A (79T at base,
  +1 method; red-before 38E/20F); full suite OK 1492T/29232A/29S.
- sugar-markdown: --filter 'SyntaxHighlight' OK 81T/134A; full OK 1030T/2740A.
- candy-code smoke --filter 'Highlight|Syntax': 62T/605A, 2 PHPUnit deprecations
  IDENTICAL to unmodified master main-tree run → zero drift.

Mutations: M1 neuter rust line rule → initially 0 reds (gap); cured same commit
with testDoubleSlashLanguagesAllProduceLineComments (rust/java/c/cpp positive //
pin) → M1 then reds exactly 1. M2 drop closing quote from string fragment →
6+ reds incl. legacy php/json pins (assembly load-bearing). Restores md5-verified.

Deviations: none from brief. cpp keyword list includes 'int' (first cut dropped
it, caught by cc-alias alias test).
