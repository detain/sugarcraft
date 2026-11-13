# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:extracted-from-candy-code]** `Pattern`, `Automaton` and `IgnoreRules` are the IO-free half of candy-code's `Tools/IgnoreRules` (glob tokenizer, PCRE-vs-automaton split, fail-closed undecidable rules, nested-file precedence). The app keeps the policy half: default-excluded dir names, symlink hard stop, `grepExcludeFlags()`, the `include_ignored` note text, and reading files from disk.
- **[gotcha:info-exclude-scope]** `.git/info/exclude` lives under `.git/info/` but is scoped to the ROOT and ranks below every other file; `IgnoreRules::INFO_EXCLUDE` is special-cased for exactly that.
- **[gotcha:fail-closed]** An over-budget (undecidable) hide rule HIDES and a negation does NOT re-include; both are recorded on `undecidableRules()`. Do not "fix" this toward showing.
- **[gotcha:cache-not-state]** `IgnoreRules` is not a `readonly` class: the directory-verdict and automaton memos are caches. Public fields stay readonly and `with*()` returns a new instance.
