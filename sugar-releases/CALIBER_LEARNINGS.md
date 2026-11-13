# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:extracted-from-candy-code]** `SelfUpdate`, `UpdateCheck`, `WindowsSwap`, `ReleaseSelector`, `Version` and `Signature` are byte-compatible extractions of candy-code's `Cli/SelfUpdate`, `Cli/UpdateCheck`, `Cli/WindowsSwap` and `Support/Version`. App-specific literals (repo, `candy-code/` tag prefix, asset family, pinned key, Composer package, user agent) are inputs on `ReleaseSource`; the `WindowsSwap::HELPER` literal and every refusal wording key (`selfupdate.*`, `update.available`) are unchanged so an adopter maps them one-to-one through `withTranslator()`.
- **[gotcha:phpunit-final-run]** `PHPUnit\Framework\TestCase::run()` is final: a test helper that drives the engine must not be called `run()` (the suite's is `runVerb()`).
- **[gotcha:two-link-readers]** `SelfUpdate::nextPageUrl()` and `UpdateCheck::nextPageUrl()` deliberately keep their two different regexes (each is pinned by its own tests); unifying them is a behaviour change, not a cleanup.
- **[gotcha:child-pin-autoload]** The memory-limit child pins load `vendor/autoload.php` of this lib; set `SUGAR_RELEASES_TEST_AUTOLOAD` to point them at another autoload when the lib has no vendor tree of its own.
