### C-WEB-5.a — `serve --tls-cert/--tls-key` (built-in TLS)
- item: C-WEB-5 (sources: GAP-WEB-04, OC-new#12)
- kind: gap · M
- approach:
  - **Gap.** `--allow-remote` is cleartext; `docs/SERVER.md:53` says "there is no built-in TLS".
  - **Wrapping.** `Listener` already `implements ServerInterface` (`candy-code/src/Server/Listener.php:29`). `Server` (:249) wraps it in `React\Socket\SecureServer` when both flags are given, so FD registration and CLOEXEC are unchanged.
  - **Effects.** `url()` (:284) and the sign-in URLs (:311) become `https://`. The `Secure` cookie flag already follows `ClientAddress::isSecure()` (`ApiController.php:155`). The cleartext warning prints only without TLS.
  - **Validation.** `ServerConfig` checks that the cert and key are readable files, that the key is not group/world-readable, and that the pair loads (`openssl_x509_check_private_key`).
- files.create: `candy-code/tests/Server/TlsListenerTest.php`
- files.modify: `candy-code/src/Cli/Serve.php` (`ACTION_FLAGS['']`, validation, warning), `candy-code/src/Cli/ParsedArgs.php` (`SUBCOMMAND_FLAGS['serve']` value flags), `candy-code/src/Server/ServerConfig.php` (fields + validation), `candy-code/src/Server/Server.php` (bind/wrap, `url()`, sign-in URLs), `candy-code/src/Cli/Help.php` (serve help), `candy-code/lang/en.php` (`cli.serve.tls.*`), `candy-code/docs/SERVER.md` (flags table :51-53, Remote access), `candy-code/README.md` (serve synopsis ~:2114)
- tests: create `candy-code/tests/Server/TlsListenerTest.php`. It generates a self-signed pair in a temp dir via ext-openssl and pins:
  - an https handshake and `GET /` round trip;
  - a plain-http connect fails;
  - the login cookie carries `Secure`.

  Modify `candy-code/tests/Cli/ServeArgsTest.php`: the flags parse, a missing key or an unreadable or loose key refuses, and `Help::screen()` is pinned.
- docs/guards: `docs/SERVER.md`, `README.md`; `tests/Config/ReadmeRosterDriftTest.php` re-run; `tests/LangParityTest.php`; no env var, no child process.
- serialized: [headless] [docs:SERVER.md] [docs:README.md] [lang:cli.serve.tls.] [hot:candy-code/src/Cli/Serve.php]
- deps: none
- phase: LATE
- ruling: R13 — does the owner want built-in TLS on `serve` (and with it a supported remote/phone posture), or keep SSH-tunnel/proxy as the only remote answer?
- risks: Workspace-host gateway relays and the control socket stay plain unix/loopback. The test must prove that only the public listener is wrapped.

