VERDICT: CLEAN (0 CRITICAL / 0 MAJOR; NITs below; review is disk-static — sandbox denies phpunit/php execution)

Verified against BRIEF + plan §1/§6:
- Scope: 9 files = brief's list + test; zero Chat.php; no suite-figure.json/durations/dist in diff; base 5f740e62a is ancestor of master tip.
- Jail: ContextHostCommand.php:157/217 uses PathJail::resolveForCreate (root) then home-shelf prefix `~/.candy-code` — structurally identical to ExportCommand.php:266-303 (`../`, abs-outside, dangling-symlink, token-smuggling all refuse; refusal writes nothing, no shelf — pinned).
- 0600 ordering: AtomicFileWriter publish chmods the temp BEFORE payload and rename (:274-286, house AtomicJsonFile law); encode-before-resolveBase means a JSON-false refusal writes nothing.
- Byte identity: Runtime.php:4369-4386 — buildSystemPrompt now delegates to public assembledSystemPrompt, the same assembleSections fold as the send path (:1441); dumpSystemPrompt mirrors promptSectionSizes' app/guard build line-for-line; strlen==sum(rows) holds by construction, pinned non-vacuously (seeded Tool; rows partition the prompt).
- Capability: instanceof gate at call site only (:86); roster pin scans src/Backend; docs/PROMPT_ENGINEERING.md:610-633 wording matches code ("would carry", refusal-by-name, no body echo; reply = paths+sizes only — test :86 pins absence).
- §1.8 pair: argumentHint + cmd.context.hint + 7 raw.* keys + COMMANDS.md row all in 9a6ebd207 (disclosed amend, green range); lang en-only honored.
- Author law: e34dd4938+9a6ebd207 %ae/%ce = detain@interserver.net, full %B shows zero trailers.
- Test file real: 7T, jail/mode/byte-identity/name-tools-shape/refusal legs all read; new test file listed in REPORT §NEW for durations gate duty.

NITs (step-local, fix-now-if-welded-else-forget):
1. NIT ContextHostCommand.php:86-96 — capability refusal precedes count($args)>1; `/context --raw a b` on a passthrough backend says "unsupported" not "at most one file name". Swap order to check args first.
2. NIT ContextHostCommand.php:112-113 — .txt write failure after .json success leaves an orphan .json with an error reply; promise is per-file atomic only, but naming the residue in the failure note would close it.
3. NIT ContextHostCommand.php:68 — `/context --raw --raw` dumps a literal `--raw.json`; harmless quirk ExportCommand shares.

Gate note: builder's 35T filter OK / gen-command-docs rc0 / TrustKey|DocFigure 109T OK / five-guard pair-only (25,835 live vs 25,537 pin) unrunnable here — weld must re-run that filter set plus the red-before claim (report says 4-src-revert → 7/7 FAIL; all seven tests mechanically depend on the new symbols, so it is credible).

--- lead note: persisted verbatim (writes denied in reviewer sandbox). stage=clean; NIT-1+NIT-2 offered to welder as optional cure commit; NIT-3 left (parity with ExportCommand).
