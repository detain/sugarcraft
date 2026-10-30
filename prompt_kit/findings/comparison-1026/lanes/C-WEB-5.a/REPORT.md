# C-WEB-5.a — serve --tls-cert/--tls-key built-in TLS (lane report, incremental)

## M1 skeleton commit 20137004a (cat-file: commit) — 2026-10-29
Landed: ParsedArgs+Serve ACTION_FLAGS tables, ServerConfig withTls()/hasTls()/urlScheme()
(flag-only, both-or-neither, file readability, key perms (0o077 refuse, fail-closed),
openssl pair load-check at startup), Server::start() SecureServer wrap of the PUBLIC
listener only (+url()/loginUrls() scheme), ClientAddress::isSecure() direct-TLS leg
(react/http HTTPS=on serverParams) + docblock truth-flip, Serve::announce() cleartext
warning gated on !hasTls(), lang/en.php help page + cli.serve.usage + 7 cli.serve.tls.*
keys. ServeArgsTest 38T/179A green at this commit (help-screen flag guard + action-table
lockstep guard both auto-cover the new flags).

DISCLOSED out-of-list file edit: src/Server/Http/ClientAddress.php — not in the design
block's files.modify, but its own Effects claim ("Secure cookie flag already follows
ClientAddress::isSecure()") is FALSE for direct TLS without it (isSecure answered only
via trusted-proxy X-Forwarded-Proto). Root-cause law + the brief's cookie pin require it.
The old docblock literally said "the listener itself never speaks TLS in v1".

## M2 7569546be (docs truth) + 28bed9aa6 (tests + local_pk fix) — 2026-10-29
DISCOVERED DEFECT (why the e2e matters): the design sketch's react context
['local_cert'=>cert,'local_key'=>key] is SILENTLY INERT — PHP's stream-ssl
option for a split key file is `local_pk`; unknown options are dropped, and
PHP then looks for the key INSIDE the cert file ("Unable to set private key
file <cert>" only at first-connection, never at startup). Fixed in Server.php:
context now carries local_pk (canonical) + local_key (harmless, forward-compat
comment says so). M2 mutation (drop local_pk line) reddens exactly the 3
TLS-consumer tests; restored md5-clean.

GATES (all cwd=candy-code, plain pipe):
- tests/Server/TlsListenerTest.php 4T/47A green x2 (handshake+GET, plaintext
  refusal incl. endpoint-survives-probe, cookie Secure over TLS vs plain
  contrast in-process, https url()/loginUrl()/tcp bindUri)
- tests/Cli/ServeArgsTest.php 41T/204A green (3 new TLS tests)
- combined ServeArgs+TlsListener+RequestIngress 49T/319A
- guards batch 287T/71255A: LangParity, ReadmeRosterDrift,
  ServerProtocolDocumentationDrift, DocFigureProseDrift, GlobDialect (corpus
  un-drifted), DuplicatedTestHelperDrift, StderrEmitterCensus, EnvRosterDrift,
  TrustKeyDrift, ConfigWriteProducerDrift — green (2 pre-existing deprecations
  identical at main-tree base)
- HelpTest:240 + KeyHelpTest x5 reds = FOREIGN pre-existing (identical set and
  line positions in main tree at base) — NOT ours, NOT fixed.
- Server-adjacent batch 56T/488A green (ClientAddress/Cookie/Interface/
  ServeStatusStop/RequestIngress/Preflight/WsUpgrade).

MUTATIONS 4/4: M1 flip wrap condition -> 3 reds; M2 drop local_pk -> 3 reds;
M3 neuter key-perm gate (& 0o000) -> exactly 1 red (loose-key); M4 break
direct-TLS isSecure leg -> exactly 1 red (cookie Secure pin; trusted-proxy
guard suite stays green = both legs pinned separately). All cp/git-restore
md5-clean.

DISCLOSES:
1. Out-of-list src edit: ClientAddress.php isSecure direct-TLS leg (required by
   the design's own Effects claim + cookie-Secure pin; see M1 note).
2. Out-of-list comment-only truth fixes: ApiController.php cookieHeader comment,
   AuthMiddleware.php cookieName docblock ("does not terminate TLS" became a lie
   this step; __Host- rename stays a deliberate follow-up, NOT taken — renaming
   the cookie signs out every session and is out of this step).
3. Key-permission law read fail-closed: ANY group/world bit (0o077) refuses;
   BRIEF said "not group/world-readable" — stricter reading chosen, disclosed.
4. Help.php NOT edited (serve docs live in lang/en.php cli.help.screen; the
   screen-flag guard passes). cli.serve.usage updated.
5. Serve::announce cleartext-warning gate (!hasTls) has NO direct pin: the
   method is private with zero pre-existing test surface; behavior is 2 lines
   gated on config->hasTls(). Reviewer may judge a detached-start test owed.
6. No new stream_socket_server/proc_open sites — Listener unchanged;
   no child-lifetime roster row owed. Protocol trio + suite-figure + durations
   + dist untouched (verified via diff --name-only).
7. DUR-CARRY for welder: NEW candy-code/tests/Server/TlsListenerTest.php needs
   a scripts/parallel-tests-durations.tsv row; suite-figure re-pin carries
   +7T (TlsListener 4 + ServeArgs 3) and +100A target delta (204+47+... vs
   base 179A ServeArgs; welder re-pins from green K=24 run).
