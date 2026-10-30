# C-WEB-5.a — REVIEW-1 (round 1, fresh reviewer)

Anchors: branch cc/C-WEB-5.a; master 574e69f1d; chain 20137004a / 7569546be / 28bed9aa6 (each rev-parse OK, individual); tree clean.

## VERDICT: CLEAN (0 CRITICAL / 0 MAJOR / 1 MINOR step-local / 2 NIT)

## Gate results (measured this review, cwd=candy-code, plain pipe, env -u LINES -u COLUMNS)
- TlsListenerTest: Tests: 4, Assertions: 47 — OK (ephemeral binds, real handshake)
- ServeArgsTest: Tests: 41, Assertions: 204 — OK
- LangParity|ServerProtocolDocumentationDrift|ReadmeRosterDrift: 23T/3387A — OK
- GlobDialect|DuplicatedTestHelperDrift|ClientAddressTest|RequestIngressTest|ReadmeSuiteFigureDrift: 35T/659A, 2F = staleness pair ONLY (25537 pinned vs 28456 live — fleet-wide standing lag, §7 duty; lane carries +7T/+125A)
- StderrEmitterCensus|EnvRosterDrift|TrustKeyDrift|ConfigWriteProducerDrift|DocFigureProseDrift: 251T/67334A — OK
- "PHPUnit Deprecations: 2" rides EVERY run incl single-test — pre-existing config-level, not lane-caused.

## Security law — verified hard
- Both-or-neither: withTls throws cli.serve.tls.needs_both (ServerConfig.php:467-469 region); flag-only (no config/env reader — grep: tlsCert/tlsKey live only in the 5 touched files; ServeArgsTest pins server.tlsCert config keys cannot enable TLS).
- Key perms: (fileperms & 0o077)!==0 refuse, fail-closed — stricter than brief, ACCEPTED (disclosed; docs agree: "no group- or world-readable mode bits").
- Pair check: openssl_x509_check_private_key at launch (SecureServer loads lazily — startup refusal is the only loud shape); ext-openssl absence refused, not skipped.
- https round-trip + plaintext refusal (no answer, feof, tls error logged, endpoint survives probe) + Secure-cookie TLS-vs-plain contrast — all REAL e2e, no fabricated paths. local_pk fix: tests write split PEM files (key NOT in cert), so the handshake pins discriminate the silent-inert local_key defect (builder M2: drop line → 3 reds).
- url()/loginUrls() scheme via urlScheme(); bindUri stays tcp:// (pinned — Listener refuses tls://). isSecure direct leg reads serverParams HTTPS (unforgeable by clients); trusted-proxy leg untouched; mutation M4 reddens exactly the cookie pin.
- Per-hop redirect re-check: OUT of blast radius — this lane adds ZERO outbound dials (only stream_socket_client in src/Server is the pre-existing unix control socket). Law lands with C-WEB-5.c/.e (push relay). SEAM, not charged.
- E646: no blanket timeouts added; test-side connect/pump bounds only.

## Disclosures adjudicated
1. ClientAddress.php out-of-list edit: ACCEPTED — the brief's own Effects claim (Secure follows isSecure) is unsatisfiable without the direct-TLS leg; file is claimed by NO other plan row (grep plan_comparison.md: zero mentions), concurrent C-SES-8.a touches Protocol/web + SERVER.md sections disjoint from this one. Wave safety intact.
2. Key-perm stricter: ACCEPTED (above).
3. Help.php untouched: ACCEPTED — Help::screen() renders Lang('cli.help.screen') (Help.php:57-59); the ServeArgsTest lockstep guard testEveryServeFlagIsOnTheHelpScreen auto-covers both new flags (green). Plan row's Help.php modify-entry is a design erratum → closeout doc note, NIT.
4. Serve::announce warning gate !hasTls: MINOR (step-local) — behavior correct, zero pin. Cure: reflection-invoke announce on a loopback config and an !isLoopback config with/without hasTls, assert cli.serve.announce.remote row present iff !isLoopback && !hasTls (~15 lines, existing config builders).
5. __Host- cookie rename deliberately NOT taken (session-breaking); truth-fixed docblocks are honest.

## §1.8 / project law checks
- Docs/roster in-steps: SERVER.md flag table + Remote access + cookie note, README synopsis, cli.help.screen + cli.serve.usage + 7 cli.serve.tls.* keys — all in-lane; guards green (above). SERVER.md edits are hand-authored sections (no generated markers in touched windows).
- Authors: 3/3 commits %ae and %ce individually grepped = detain@interserver.net; zero trailers.
- Forbidden files: suite-figure.json / durations.tsv / dist NOT in diff (diff --stat verified). DUR row + re-pin are welder carries (builder §7 matches reviewer instruction +7T/+125A).
- Strict_types, PSR-12 shape, one type per file, immutable mutate() (variadic — new fields ride), Lang::t for user strings, comments say WHY. No glob-shaped or **Refused:**-shaped fixtures (GlobDialect green).

## NITs
- N1 ServeArgsTest.php:~420 + TlsListenerTest.php:~176: curly apostrophes (’) in assertion messages/docblock — cosmetic respell.
- N2 plan row lists Help.php modify that the design makes unnecessary — dated erratum at weld.

Findings above are below the weld bar except MINOR-4 (step loop: fix it in the fixer round, then re-review).
