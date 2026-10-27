### C-SES-6.a — A phar renamed without `.phar` boots
- item: C-SES-6 (sources: DST-1-P1)
- kind: fix · MINOR · effort M
- approach:
  - **Root cause.** The stub derives the archive identity only from `Phar::running(false)`, which is `''` in stub context, or from a `.phar` suffix on `__FILE__` (`candy-code/bin/candy-code:32-37`). The usual `~/bin/candy-code` install falls through to the filesystem candidates and exits 2.
  - **Fix.** When `defined('__COMPILER_HALT_OFFSET__')` holds and no identity was found:
    1. run `Phar::mapPhar('candy-code.phar')` inside `try` (the build already sets alias `candy-code.phar`, `tools/build-phar.php:265`);
    2. use `phar://candy-code.phar/vendor/autoload.php`.
    
    Composer checkouts have no `__COMPILER_HALT_OFFSET__`, so their three candidates stay byte-identical.
  - **README.** Document that renaming and symlinking both work.
- files.create: none
- files.modify: `candy-code/bin/candy-code` (the autoload guard), `candy-code/README.md` (the Install paragraph), `candy-code/tests/Integration/BinCandyCodeAutoloadGuardTest.php` (restate the guard), `candy-code/tests/Integration/PharCompatTest.php` (a suffix-less arm)
- tests: modify:
  - `candy-code/tests/Integration/PharCompatTest.php`: in the heavy `CANDY_CODE_PHAR`-gated arm, copy the archive to a suffix-less name and run `--version`; add a miniature scratch-phar arm in the default suite;
  - `candy-code/tests/Integration/BinCandyCodeAutoloadGuardTest.php`.
- docs/guards: README Install
- serialized: [headless] [docs:README.md]
- deps: none
- phase: normal
- ruling: none
- risks: UNVERIFIED: whether `Phar::running(false)` answers the real path under an alias mapping. `SelfUpdate` uses it to locate the file it replaces, so add a test arm asserting it.

