# Credentials: `login`, the config file, and the keychain

`candy-code login <provider>` is how a provider gets its credential. It does
one of three things, depending on the provider: it runs the vendor's own
sign-in, it takes a pasted API key and writes it into your config file, or it
takes a pasted API key and writes it into the OS keychain, leaving a reference
in the config file. It never writes a key anywhere else, and it never
mints or refreshes one.

For the environment variable each provider reads, see
[ENVIRONMENT.md](ENVIRONMENT.md#provider-credential-variables). For the provider
types and their settings, see [SETTINGS.md](SETTINGS.md).

## What `login` does per provider

`login` accepts exactly one provider name. With no name it prints the usage line
and exits `2`. With more than one word it prints `login takes exactly one
provider name, got N words.` and exits `2`. A name outside the list is refused
the same way.

| Provider | What `login` does | Where the credential ends up |
|---|---|---|
| `openai`, `azure`, `anthropic`, `gemini`, `openrouter` | Asks for the key on the terminal (typed input is not echoed) and stores it | `providers.<name>.apiKey` in `~/.candy-code/config.json`, or with `--keychain` the OS keychain |
| `claude-code` | Runs `claude auth login` and hands it the terminal | Stored by the Claude Code CLI, not by candy-code |
| `codex` | Runs `codex login` and hands it the terminal | Stored by the Codex CLI, not by candy-code |
| `sglang`, `bedrock`, `vertex`, `custom` | Stores nothing. It prints a hint about the setup these providers take (an endpoint, or the cloud SDK's ambient credentials) and exits `1` | Nothing. These providers read the environment or the cloud SDK's own chain |

If the vendor CLI for `claude-code` or `codex` is not on `PATH`, `login` says
so and exits `1`. The vendor flow inherits your terminal unchanged, so a
browser prompt or a device code is the vendor's to show.

## Storing a key in the config file

```sh
candy-code login openai
```

`login` prints the config path it will write, reads one line, and then:

- refuses an empty key, and a key with control characters in it;
- merges the key into the file's `providers` map, keeping every other field of
  that provider's block (its model, base URL, and so on);
- refuses to overwrite a block already typed as a different provider;
- writes the file, then reads the value back to confirm it landed before it
  reports success.

On success it names the environment variable that would have carried the same
key, so you can switch to an environment-based setup later if you prefer.

The file is `~/.candy-code/config.json`. `--config <path>` on the command line
points `login` at a different file, the same as every other command. The
writer settles the file to mode `0600`, owner only. The key is stored as plain
text, so anything that can read the file as you can read the key, including
backups and dotfile syncs.

The first-run wizard writes the same block when you paste a key into it.

## Storing a key in the OS keychain

```sh
candy-code login anthropic --keychain
```

`--keychain` is accepted only for `openai`, `azure`, `anthropic`, `gemini` and
`openrouter`. For any other provider it is refused.

The key goes to the platform's secret store on the helper's standard input,
never on the command line, so it does not show up in the process list. Only
the service name and the entry name appear in the argument list:

| OS | Command candy-code runs | Entry |
|---|---|---|
| Linux | `secret-tool store service candy-code account <provider>` | service `candy-code`, account = the provider name |
| macOS | `security add-generic-password -U -s candy-code -a <provider> -w` | the same service and account |

`-U` replaces an existing entry, so running `login --keychain` again rotates
the key. On Linux, `secret-tool` usually comes from the `libsecret` tools package in
your distribution. If
it is missing, `login` says which backend it looked for and stops. Windows has
no backend here. `login` says so and stores nothing.

Once the entry is written, the config file gets a reference in place of the
key:

```json
{ "providers": { "anthropic": { "type": "anthropic", "apiKey": "${keychain:anthropic}" } } }
```

A string value inside a provider block can spell `${keychain:<name>}`. MCP
`env` and `headers` values expand only `${VAR}`, not the keychain form.
candy-code reads the entry
through the same helper on that OS (`secret-tool lookup` on Linux,
`security find-generic-password -w` on macOS), each lookup has a 5-second
limit, and each name is looked up once per process. A missing entry does not
stop the launch. The value resolves to an empty string, and a notice names the
entry that was not found.

## How a credential is found at launch

1. A `providers.<name>` entry in `config.json` is merged over the built-in
   defaults for its `type`. Keys the entry sets win. Keys it leaves out come
   from the defaults. An entry without a string `type` is skipped entirely,
   not merged.
2. The built-in defaults for each type read their environment variable, for
   example `OPENAI_API_KEY` for `openai`. The full list is in
   [ENVIRONMENT.md](ENVIRONMENT.md#provider-credential-variables).
3. Any string value in the resolved config is interpolated. `${VAR}` reads the
   environment, `${VAR:-default}` falls back when the variable is unset or
   empty, and `${keychain:<name>}` reads the keychain.

So a key stored by `login` is used whenever its provider is selected, and an
exported variable is used when the config has no key for that provider.
Selecting a provider is covered in [SETTINGS.md](SETTINGS.md) and in the
`CANDY_CODE_PROVIDER` row of [ENVIRONMENT.md](ENVIRONMENT.md).

## Checking what is in use

```sh
candy-code models              # providers this install can select; * marks the selected one
candy-code config show --sources   # which layer each setting came from
candy-code doctor              # reports the spend cap in force
```

`config show --sources` names the file or variable behind each value, which is
the quickest way to find a key that is coming from somewhere you did not expect.

## Choosing between the options

- **A key on a machine you alone use:** `login <provider>` is enough. Mode `0600`
  keeps other local users out, but not backups or sync tools.
- **A key that must not sit in a file:** `login <provider> --keychain`. The
  config then holds only the `${keychain:<provider>}` reference.
- **CI or a container:** use the environment variable from your secret store
  and do not run `login` there. See [CI.md](CI.md).
- **A subscription CLI you already use:** `login claude-code` or
  `login codex` hands off to that CLI. candy-code does not see the credential.

## See also

- [ENVIRONMENT.md](ENVIRONMENT.md): every provider variable and its default
- [SECURITY.md](SECURITY.md): where each secret is stored and the trust
  boundaries around it
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): a provider that refuses to start
