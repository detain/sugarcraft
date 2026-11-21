# Editor setup: `candy-code acp`

`candy-code acp` runs candy-code as an agent inside an editor. The editor starts
the process, and the two talk over stdin and stdout using the Agent Client
Protocol (ACP), which is JSON-RPC 2.0 with one message per line. Editors that
speak ACP can host it this way. Their settings live outside this repo, so the
examples below show the shape and the editor's own ACP documentation is the
reference.

Nothing needs to be running beforehand. There is no port and no token, because
the editor is the only client and it starts the process as your own user.

For the method-by-method protocol table, the stop reasons, and the diff format,
see the [Editors section of SERVER.md](SERVER.md#editors-candy-code-acp). This
page is the setup and the day-to-day behavior.

## Set it up

The editor needs the command `candy-code` and the single argument `acp`. The
binary has to be on the `PATH` the editor sees, which is not always the same
`PATH` as your terminal's. A GUI editor on macOS or Linux often launches agents
with a minimal environment. If the agent fails to start, give the editor the
full path to `candy-code`.

In Zed, add the agent to `agent_servers` in your settings:

```json
{
  "agent_servers": {
    "CandyCode": { "command": "candy-code", "args": ["acp"] }
  }
}
```

JetBrains IDEs and Neovim take the same command and argument in their own ACP
agent configuration. The protocol is the same in each.

Before you rely on it, check the provider from a terminal. The editor is not
needed for this check:

```sh
candy-code models     # * marks the provider a new session will use
```

If the selected provider has no key, the agent starts on an offline echo backend:
turns answer with echo text, and the agent's stderr log shows a provider-unavailable
warning. With no provider selected at all, turns answer with echo text and no warning. See
[CREDENTIALS.md](CREDENTIALS.md) to set one up.

## What the editor gets

Each editor session runs the same engine as the TUI: the same provider, tools,
hooks, permission gate, transcript and session store. A session an editor starts
appears in `candy-code session list`, and you can reopen it in the terminal with
`--resume`.

- **One project per process.** The first session fixes the project root from its
  `cwd`. An editor that opens a second project gets a JSON-RPC invalid-params error. Start another
  `candy-code acp` process for it.
- **Your project's own configuration applies.** candy-code's tools, its
  permission gate and its checkpoints are used. The editor's own file and
  terminal capabilities are not used, so `.mcp.json` and the project's trust
  settings are the ones that apply.
- **Sessions can be reloaded.** The editor's session-load request replays the
  stored transcript into the editor before the next prompt.

## Permission modes

Each session starts in the permission mode the launch resolves: `--permission-mode`,
then `CANDY_CODE_PERMISSION_MODE`, then the `permissionMode` key in your config,
then `default`. The editor can change it for later turns with `session/set_mode`.
The six mode IDs are `default`, `accept-edits`, `plan`, `auto`, `dont-ask` and
`bypass-permissions`. Each one's exact rules, tool by tool, are in
[PERMISSIONS.md](PERMISSIONS.md#the-six-modes). The editor's mode picker shows
the same descriptions candy-code uses for them.

A question from a tool comes to the editor as a permission request with three
choices: allow once, allow always, and reject once. Allow always is offered only
when the gate itself asked, and it applies for the rest of the session.

Nothing blocks while a question is open. The editor can answer when it is ready,
and other messages keep flowing in the meantime.

## Stopping a turn

Cancelling a prompt in the editor stops the turn at once. The running command is
stopped, tool rows that were running show as interrupted, and any open permission
question is settled as cancelled, which never allows the tool call. The prompt then ends with the `cancelled` stop
reason.

## Stop reasons you will see

| Reason | Meaning |
|---|---|
| `end_turn` | The model finished its answer |
| `max_tokens` | The provider stopped at its length limit |
| `max_turn_requests` | The step budget, the spend cap or the loop guard stopped the turn |
| `cancelled` | You cancelled it |
| `refusal` | The turn was not admitted, for example because the spend cap is reached |

A turn that fails returns a JSON-RPC error with the cause.

## Limits

- The editor's `fs/*` and `terminal/*` methods are not used. candy-code reads
  and writes files through its own tools.
- Image and audio prompt blocks are not used.
- Built-in slash commands with no host binding are refused with the message `/{name}
  runs in the TUI client only; nothing was run in this session.` That includes `/theme`, `/keys` and
  `/settings`, which need a screen, and others such as `/sessions`, `/compress`
  and `/copy`. Commands with a host binding, such as `/help`, answer normally.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| The agent never starts in the editor | The editor cannot find `candy-code` on its `PATH`. Give the full path to the binary in the agent's `command` |
| `candy-code acp` exits with code `2` | It was given an operand, or `--output-format json`. The protocol is the output, so no format flag applies. Remove them from the arguments |
| The editor shows an agent log full of warnings | Anything PHP prints goes to stderr, which the editor shows as the agent's log. Those lines are not protocol errors. The protocol runs on stdout only |
| Every turn ends `refusal` | The spend cap is reached. A few other conditions also end a turn with `refusal` (a foreground-compaction block, the thrash breaker, or a turn-hook refusal). `/budget` in the TUI shows the cap. A prompt with no text is a JSON-RPC invalid-params error, not a refusal |
| Turns answer with offline echo text | No provider is selected, or the selected provider has no key (the stderr log shows a provider-unavailable warning in the second case). Run `candy-code models` and `candy-code login <provider>` in a terminal, then restart the editor's agent |
| A second project will not open | One process serves one project root. Start another `candy-code acp` for it |

## See also

- [SERVER.md](SERVER.md#editors-candy-code-acp): the full ACP method table and the
  permission and diff details
- [PERMISSIONS.md](PERMISSIONS.md): how the gate decides
- [CREDENTIALS.md](CREDENTIALS.md): giving the process a provider
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md): general error recovery
