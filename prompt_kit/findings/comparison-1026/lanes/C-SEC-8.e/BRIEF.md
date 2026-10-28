### C-SEC-8.e — `${keychain:<name>}` provider keys
- item: C-SEC-8 (sources: GAP-MOD-25)
- kind: gap · effort M
- approach:
  - Provider keys sit in plaintext in a 0600 config, and `ProviderFactory::resolveEnv()` (`src/Providers/ProviderFactory.php:450`) resolves only `${VAR}`.
  - Add `${keychain:<name>}`, resolved by a new `Keychain` that shells out to `secret-tool lookup service candy-code account <name>` on Linux or `security find-generic-password -s candy-code -a <name> -w` on macOS. It uses argv arrays only, through `ProcessContainment::spawnSpec`, and drains then reaps in-function. A short timeout is fine, since this is not an LLM call.
  - Resolution is memoised per process. A miss is a launch notice naming the entry, never a fatal.
- files.create: `candy-code/src/Support/Keychain.php`, `candy-code/tests/Support/KeychainTest.php`
- files.modify: `candy-code/src/Providers/ProviderFactory.php` (`resolveEnv`), `candy-code/docs/ENVIRONMENT.md` (interpolation section), `candy-code/lang/en.php` (`cli.keychain.*`)
- tests: the new test drives a spawn seam for hit, miss, missing binary and timeout, and checks that the argv never contains the secret. Modify `candy-code/tests/Providers/ProviderFactoryTest.php` (`${keychain:x}` resolves through the seam, `${VAR}` unchanged).
- docs/guards:
  - The child lifetime is short and synchronous. If `DescriptorInheritanceGuardTest` (`candy-code/tests/Support/DescriptorInheritanceGuardTest.php`) does not classify it `short`, add an `ACCOUNTED_FOR` row there. candy-code's sites are owned by that guard; `tools/check-child-lifetimes.php` excludes candy-code.
  - `EnvRosterDriftTest` is untouched.
- serialized: [docs:ENVIRONMENT.md] [lang:cli.keychain.]
- deps: none
- phase: normal
- ruling: none
- risks: Windows has no backend. `${keychain:}` there is a clear notice, not a silent empty key.

