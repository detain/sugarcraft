# STEP 2.1 — MySQL DSN SSL + ConnectionFactory parsing

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §C (ssl-mode), §E (factory parsing).

## Why
- `Db/ConnectionConfig.php:39-45` appends `ssl-mode=%s` to EVERY MySQL DSN — PDO mysql has no
  such DSN param (SSL is configured via `PDO::MYSQL_ATTR_SSL_*` driver options). Non-default
  `sslMode` breaks or silently no-ops.
- `Db/ConnectionFactory::fromDsn()` (`:69-87`) hand-parses with `explode('@'/':')`: breaks on
  `@`/`:` inside a password, rejects passwordless users (requires a `:`), mis-parses IPv6 hosts.

## Goal
- MySQL SSL configured correctly (driver options), no bogus DSN key.
- DSN parsing via `parse_url()` — correct for special chars, passwordless, IPv6.

## Files
- `src/Db/ConnectionConfig.php` (DSN builder; SSL fields).
- `src/Db/MysqlDatabase.php` (apply `PDO::MYSQL_ATTR_SSL_CA` / `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`
  from config at connect time).
- `src/Db/ConnectionFactory.php` (`fromDsn`).
- `tests/Db/{ConnectionConfigTest,ConnectionFactoryTest}.php`.

## Do
1. Remove `ssl-mode` from the MySQL DSN string. Pass SSL via PDO driver options in
   `MysqlDatabase::connect()` based on `ConnectionConfig` SSL fields (CA path, verify flag).
   Keep behaviour sane when no SSL is requested.
2. Rewrite `fromDsn` using `parse_url()` (scheme→driver, user/pass with `rawurldecode`, host,
   port, path→dbname, query→options like sslmode). Treat missing password as empty (don't
   throw). Handle IPv6 hosts. Keep the password out of any echoed/logged string.
3. Add tests: password with `@`/`:`, passwordless user, IPv6 host, sqlite path form.

## Acceptance criteria
- [ ] No MySQL DSN contains `ssl-mode`; SSL applied via driver options when configured.
- [ ] `fromDsn` parses `mysql://u:p%40ss@host:3306/db`, `mysql://root@localhost/db`,
      `mysql://u:p@[::1]:3306/db`, and `sqlite:///path.db` correctly.
- [ ] Full suite green.

## Out of scope / defer
- `query()` null-contract, `password()`/`prepare()` interface cleanup, Flavor seeding → STEP 2.2.
