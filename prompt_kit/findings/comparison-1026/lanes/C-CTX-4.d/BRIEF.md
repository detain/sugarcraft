### C-CTX-4.d — URL instruction sources and a shared egress guard
- item: C-CTX-4 (sources: GAP-INS-11 (URL half))
- kind: gap · effort M
- approach:
  - User-tier `instructions` entries that start with `https://` are fetched once per session in the parent prime, before any fork.
  - Each fetch goes through an SSRF guard extracted from `WebFetch` into `Support\PublicUrlGuard`: `BLOCKED_HOSTNAMES`, `BLOCKED_IP_RANGES` and the mixed-answer refusal, from `WebFetch.php:38, 103-117`. WebFetch keeps behaving identically through the shared class.
  - Limits: a 15 s connect+read budget (this is not an LLM call), `MAX_DOCUMENT_BYTES`, and HTTPS only.
  - Each document is cached for the session, fenced as untrusted remote content, and failures become a launch notice.
  - Project-tier URLs are refused.
- files.create: `candy-code/src/Support/PublicUrlGuard.php`, `candy-code/tests/Support/PublicUrlGuardTest.php`, `candy-code/tests/Context/InstructionUrlSourceTest.php`
- files.modify: `candy-code/src/Tools/BuiltIn/WebFetch.php` (delegate to the guard), `candy-code/src/Context/InstructionFileLoader.php` (`loadForced()` URL arm), `candy-code/docs/MEMORY.md`, `candy-code/docs/SETTINGS.md` (`instructions` prose)
- tests: PublicUrlGuardTest (WebFetch's existing cases move here unchanged); InstructionUrlSourceTest (fenced, cached, size-capped; private IPs and project tier refused)
- docs/guards: SETTINGS/MEMORY prose; WebFetch's existing tests stay green
- serialized: [docs:MEMORY.md] [docs:SETTINGS.md] [hot:candy-code/src/Context/InstructionFileLoader.php] [hot:candy-code/src/Tools/BuiltIn/WebFetch.php]
- deps: C-CTX-4.c (the user-tier pattern split)
- phase: normal
- ruling: none
- risks: C-TOOL-2 (other area) rewrites WebFetch's body to convert HTML to Markdown. The two must be in different waves.

