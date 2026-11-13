# candy-discord

Discord channel bundle for `candy-code serve`. A Discord bot receives direct
messages and guild messages, forwards them into a served candy-code session
over the ordinary WebSocket protocol, and posts the replies back in chunks.
Pairing gates, guild allowlists, approval buttons and native slash commands
are all handled inside the bundle.

Package: `sugarcraft/candy-discord` (bundle namespace `CandyCode\Bundles\Discord\`).

## Contents

1. [Requirements](#requirements)
2. [Create the Discord application and bot](#create-the-discord-application-and-bot)
3. [Credentials store and env fallback](#credentials-store-and-env-fallback)
4. [Enable the channel](#enable-the-channel)
5. [Access, guilds and mentions](#access-guilds-and-mentions)
6. [Pairing](#pairing)
7. [Approvers and approvals](#approvers-and-approvals)
8. [Commands](#commands)
9. [Replies and streaming](#replies-and-streaming)
10. [Settings reference](#settings-reference)
11. [Log events](#log-events)
12. [Troubleshooting](#troubleshooting)

## Requirements

- PHP 8.3+ and a candy-code build that ships the `channels.discord` settings
  reader. The bundle refuses to start (with a log line) on an older host.
- A Discord account that can create an application in the Developer Portal,
  and a server (guild) where you can invite the bot.
- The serve process running on the same machine as the bundle. The bundle
  dials the serve over the local WebSocket; it does not accept inbound
  connections from Discord.
- Outbound HTTPS and WebSocket access to Discord's API and gateway.
- `ext-pdo_sqlite`: the session and pairing stores are sqlite. Without the
  extension the channel refuses to start with `channels.discord.missing_extension`.
  ReactPHP and Ratchet classes come from the host install; the bundle needs
  no other extensions of its own.

## Create the Discord application and bot

Discord's portal changes over time; the steps below describe what the bundle
needs, not a screen-by-screen walkthrough. Verify the labels in your portal.

1. In the Developer Portal, create a new application. Its **Application ID**
   is the id the bundle registers slash commands under. Verify in your portal.
2. Under **Bot**, add a bot user and copy the **bot token**. The token is a
   secret: it goes into the credentials store (below), never into settings.
3. Under **Bot**, enable the **Message Content** privileged intent. The bundle
   requests it when `intents.messageContent` is true (the default). Without it
   Discord withholds message text, and the bundle falls back to mention-only
   handling; the gateway's disallowed-intents close (4013/4014) triggers the
   same degrade. Verify the privileged-intent toggle in your portal. Whenever
   the ladder lands on mention-only, the bundle logs `discord_intents_degraded`
   — that row names the posture you are actually running.
4. The bundle identifies to the gateway with these intents: guilds, guild
   messages, direct messages, and (when enabled) message content. No other
   intents are requested.
5. Invite the bot to your server with an OAuth2 URL. The bundle relies on the
   `bot` scope for messaging and the `applications.commands` scope for slash
   commands (it deploys them under the application id). The bundle does not
   request explicit permissions in code, so the permissions you grant the bot
   (at minimum: view channels, send messages, read message history, and use
   application commands in the channels it should answer in) are whatever you
   set on the invite. Verify the permission set in your server.

Global slash commands can take a while to appear in Discord after the first
deploy. Verify in your server; the bundle deploys them on startup when
`slash.deploy` is true.

## Credentials store and env fallback

The bot token lives in the 0600 channels store at `~/.candy-code/channels.json`
— the same file the other channel credentials use — under
`platforms.discord.accounts.<name>.token`. The bundle never
reads it from settings, and it never writes it to logs: the token and the
pairing codes are registered as redaction secrets.

Canonical store shape (v1, byte-for-byte the layout `CredentialStore` fixes —
the Teams bundle rides the same file):

```json
{
  "version": 1,
  "platforms": {
    "discord": { "accounts": { "default": { "token": "<bot token>" } } }
  }
}
```

How credential mistakes surface — two families, one loud:

| Mistake | Behaviour |
| --- | --- |
| No token anywhere (no store, no `DISCORD_BOT_TOKEN`) | start warns `channels.discord.account_without_credentials` for the default account, then `channels.discord.no_usable_accounts`; nothing starts |
| A named account without its token | `channels.discord.account_without_credentials` skips it; `channels.discord.no_usable_accounts` when nothing remains |
| Store file group/world-readable, not a regular file, or owned by another user | start **refuses** with a `RuntimeException` naming the path — a half-trusted credential file is worse than none |

For the **default** account only, `DISCORD_BOT_TOKEN` is honored when the
store has no token for it. The store wins when both are set. Named accounts
(`accounts.<name>`) read only from the store.

`DISCORD_API_URL` and `DISCORD_GATEWAY_URL` override the Discord endpoints, and
so do `transport.apiBaseUrl` and `transport.gatewayUrl` (the env var wins). An
override must be https/wss. Plain http/ws is accepted only for a loopback host,
and only when `transport.insecureLoopbackProxy` is true. A refused override
fails at startup, naming the setting that would authorize it, because the bot
token rides every request.

`CANDY_CODE_SERVER_DIR` points the bundle at the serve process's discovery
directory (default `~/.candy-code/server`). The precedence law is fixed in
`DiscordChannel::start()`: the environment wins over the settings tree. To
front Discord through a local mirror or test proxy, run it on **loopback** and
set `transport.insecureLoopbackProxy` — in `Endpoint`'s own words, "a
plain-HTTP override must never silently point at a remote host", because a bot
token rides every request. These env names live in the bundle, not in
`candy-code/src`, so the host's generated env roster does not list them: this
section is their documentation.

## Enable the channel

The channel stays dark until `channels.discord` says otherwise. The user-tier
settings map carries the policy; `enabled` is the switch:

```json
{
  "channels.discord": {
    "enabled": true,
    "access": { "dmPolicy": "pairing" },
    "approvals": { "enabled": true, "approvers": ["<your Discord user id>"] }
  }
}
```

Installation: the bundle is loaded from
`~/.candy-code/bundles/candy-discord` — place the directory there, or run
`candy-code bundles install <path>` (and `bundles install <name>@<marketplace>`
once it is published to an added marketplace). The `candy-discord/` directory
in this repository is the source tree, not the install path.

Then launch candy-code once to list the bundle, add `candy-discord` to
`trustedBundleChannels`, and launch again so it loads. This follows the same
first-launch-lists, second-launch-loads rule as every bundle. The keys are
documented in the [settings reference](#settings-reference).

## Access, guilds and mentions

**Direct messages** follow `access.dmPolicy`:

- `pairing` (default): an unpaired sender gets a pairing code and nothing is
  answered until an approver approves it (see [Pairing](#pairing)).
- `allowlist`: only the users in `access.allowFrom` and the pairing store's
  approved roster are served. An empty list serves nobody.

`access.dmScope` decides how DM sessions are keyed: `per-peer` (default), `main`,
`per-channel-peer`, or `per-account-channel-peer`.

**Guild messages** follow `guilds.groupPolicy` (default `allowlist`) and the
per-guild rows under `guilds.rows`. A guild with a row whose `policy` is
`disabled` is refused. Guilds without a row are not served under `allowlist`.
`access.allowBots` (default `mentions`) and `access.ignoreOtherMentions`
(default true) control messages from other bots and messages that mention
someone else. `access.respondWithoutMention` (default false) lets the bot answer
plain messages in a served guild without a mention. Keep it false unless you
want every message in the guild to reach the model.

**Threads**: when `threads.enabled` is true, each thread is its own session
and goes idle after `threads.idleHours` (default 24).

## Pairing

A stranger who DMs the bot under `pairing` receives an 8-character code from
the unambiguous alphabet, valid for one hour, with at most three pending codes
at a time. Codes are shown only in that notice and to approvers in a direct
message, and they are redacted everywhere else.

An approver answers with either spelling:

- the slash command `/pair action:grant code:<code>` (ephemeral by default
  through `slash.ephemeral`), or
- the text spelling `/pair grant <code>` in a channel or DM (`/pair approve <code>` is a deprecated alias that still works and answers with a one-line notice).

Approval writes the sender into the 0600 store's allowlist. `/pair list` shows
pending codes **only in a direct message**; asked in a guild it answers without
listing anything. Pending codes are never listed in a channel, even to an
approver.

If `approvals.approvers` is empty, no one can run `/pair grant`, so no
stranger is ever admitted by pairing. Configure at least one approver before
you expect pairing to work.

## Approvers and approvals

`approvals.approvers` is the Discord user-id roster allowed to answer
permission prompts and to approve pairing codes.

- **Empty roster**: any user in the conversation where the ask was presented
  may answer `once` or `deny`. `always` is refused ("needs a configured
  approver") and the ask stays open. The refusal is posted in the ask's own
  conversation.
- **Configured roster**: only the listed users can answer. Anyone else is
  refused on the wire with `is not an approver`.

Every answer, from a button or from a text command, must carry the per-ask
nonce minted at presentation and must arrive in the conversation the ask was
presented in. A forged, stale or cross-conversation answer is refused and
logged; the permission request is not touched.

Approval prompts render as buttons (Approve once, Always allow, Deny) when
`approvals.enabled` is true (the default). The buttons are a courtesy filter;
the roster and nonce checks run server-side in the bundle. With approvals
disabled, or where buttons cannot render, the ask falls back to a same-chat
line: `/approve <askId> once|always|deny`. `approvals.ttlMinutes` (default 30,
floor one minute, clamped to the pending-ask ceiling) bounds how long a presented
ask waits. Verify the button layout in your client.

## Commands

The bundle registers these slash commands when `slash.deploy` is true, and
accepts the same words as text in a served conversation:

| Command | What it does |
| --- | --- |
| `/new [name]` | Start a fresh session for this conversation. |
| `/stop` | Stop the running turn. |
| `/approve <ask> [once\|always\|deny]` | Answer a pending permission prompt by id. |
| `/queue <text>` | Queue a message behind the running turn (see `queue`). |
| `/model [name]` | Show or change the model for this session. |
| `/pair action:approve\|list [code]` | Approver pairing commands (`list` is DM-only). |

Unknown slash commands are refused. Slash replies are ephemeral by default
(`slash.ephemeral`).

Worked pairing-and-approval exchange (a DM, text spellings):

```text
stranger  DM> hello                -> bot: "pairing code k7m2q9x4 …"
approver  DM> /pair grant k7m2q9x4
                                   -> admitted; the sender id is written to
                                      the allowlist in the 0600 store
bot       DM> [ask] run composer install?
approver      /approve ask_17 once -> the pending permission is answered
```

## Replies and streaming

Replies are posted as normal Discord messages, split by the outbound chunker
to fit `outbound` limits and with secrets redacted. The bundle does **not**
stream partial output; each reply arrives as finished message chunks.
`outbound.typing` (default true) shows the typing indicator while a turn runs.
`outbound.suppressEmbeds` (default true) stops link previews from cluttering
replies. Verify how your client shows typing.

Media limits are set under `media.*`; see `DiscordChannel` for the keys the
bundle reads.

## Settings reference

All keys live under `channels.discord`. Defaults are the values the code uses
when a key is absent.

| Key | Default | Meaning |
| --- | --- | --- |
| `enabled` | `false` | Turns the channel on. |
| `transport.apiBaseUrl` / `transport.gatewayUrl` | Discord's | Endpoint overrides; https/wss, or loopback http/ws with the flag below. |
| `transport.insecureLoopbackProxy` | `false` | Allows the loopback override above. |
| `intents.messageContent` | `true` | Requests the Message Content intent. |
| `access.dmPolicy` | `pairing` | `pairing`, `allowlist`, or `disabled`. |
| `access.dmScope` | `per-peer` | DM session keying (see above). |
| `access.allowFrom` | `[]` | Discord user ids (strings) allowed under `allowlist`. |
| `access.allowBots` | `mentions` | How other bots' messages are treated. |
| `access.ignoreOtherMentions` | `true` | Ignore messages that mention someone else. |
| `access.respondWithoutMention` | `false` | Answer plain guild messages without a mention. |
| `guilds.groupPolicy` | `allowlist` | Default for guilds without a row. |
| `guilds.rows` | `{}` | Per-guild rows keyed by guild id; `policy: "disabled"` refuses. |
| `threads.enabled` / `threads.idleHours` | `false` / `24` | Threaded sessions and idle timeout. |
| `queue.debounceMs` / `queue.maxPending` | `500` / `20` | Queue timing and depth. |
| `outbound.typing` / `outbound.suppressEmbeds` | `true` / `true` | Typing indicator and link embeds. |
| `media.*` | `maxMb` 100, `maxTotalMb` 50 | Attachment caps: per file, and per batch (shared across concurrent messages; rows past it are skipped with a disclosed note). |
| `approvals.enabled` | `true` | Buttons on; `false` uses the text fallback. |
| `approvals.approvers` | `[]` | Approver user ids (empty roster: no `always`, no pairing approvals). |
| `approvals.ttlMinutes` | `30` | How long a presented ask waits. |
| `slash.deploy` | `true` | Register slash commands on startup. |
| `slash.ephemeral` | `true` | Make slash replies ephemeral. |
| `accounts` | `{}` | Per-account overrides, keyed by account name. |

The full default tree (the shape the bundle sees when settings say nothing —
see the table above for every key's meaning):

```json
{
  "enabled": false,
  "intents": { "messageContent": true },
  "transport": {},
  "access": { "dmPolicy": "pairing", "dmScope": "per-peer", "allowFrom": [],
              "allowBots": "mentions", "ignoreOtherMentions": true,
              "respondWithoutMention": false },
  "guilds": { "groupPolicy": "allowlist", "rows": {} },
  "threads": { "enabled": false, "idleHours": 24 },
  "queue": { "mode": "steer", "debounceMs": 500, "maxPending": 20 },
  "outbound": { "typing": true, "suppressEmbeds": true },
  "media": { "maxMb": 100 },
  "approvals": { "enabled": true, "approvers": [], "ttlMinutes": 30 },
  "slash": { "deploy": true, "ephemeral": true },
  "accounts": {}
}
```

## Log events

Every row is one structured line in the channel log.

| Event | Meaning | First remedy |
| --- | --- | --- |
| `channels.discord.missing_extension` | `ext-pdo_sqlite` is not loaded, so the session/pairing store cannot open. | Enable the extension and restart — nothing else starts. |
| `channels.discord.account_without_credentials` | One named account booted without a token and was skipped (the `account` field names it). | Fill that account's `token` row in `channels.json`. |
| `channels.discord.no_usable_accounts` | No token survived the account scan — including the plain no-credential boot (each skipped account first logs `account_without_credentials`). | Add the token(s); or leave `enabled: false` to stay dark on purpose. |
| `channels.discord.state_dir_refused` | The serve discovery dir exists but refused validation. | Check `CANDY_CODE_SERVER_DIR` and permissions; the poll retries on a 30 s back-off. |
| `channels.discord.discovery_url_untranslatable` | The discovery record's url cannot become a ws endpoint. | Restart `candy-code serve`; the bundle keeps polling. |
| `channels.discord.serve_dial_failed` | The WebSocket connect to the serve leg threw. | Is `candy-code serve` running? The poll retries. |
| `channels.discord.serve_down` | The serve leg dropped mid-run (`why` says which shape). | None needed: re-follow resumes each session from its cursor. |
| `channels.discord.serve_giveup` | The dial ladder for a serve leg exhausted. | Back-off ends on a 30 s cadence; fix the serve side if it persists. |
| `channels.discord.serve_connected` | The serve leg is up and bindings were adopted. | — (success row) |
| `discord_intents_degraded` | The gateway closed 4013/4014 or text was withheld; this process is mention-only now — one-way. | Enable the portal intent and restart. |

Other gateway/rest rows are named for their step (`discord_reconnect`,
`discord_slash_deploy_failed`, `discord_rest_error`, …); pairing codes never
appear in any of them — they are redaction secrets.

## Troubleshooting

- **The bot is online but never answers in a guild**: the guild has no row
  under `guilds.rows` (with `groupPolicy` at its `allowlist` default), or the
  message did not mention the bot. Add the guild row, or mention the bot.
- **The bot sees messages without text**: the Message Content intent is off in
  the portal, or the gateway closed with 4013/4014 and the bundle degraded to
  mention-only (`discord_intents_degraded` names the landing in the channel
  log; it is one-way for the process). Enable the intent and restart.
- **DMs get a pairing code but nothing happens after approval**: check the
  approver roster. `/pair grant` by a user not on `approvals.approvers` is
  refused, and an empty roster admits no one.
- **`/approve` says the answer was refused**: the answer came from another
  conversation, carried a stale nonce (the card is from before a restart or
  after the ask was already answered), or came from a user not on the roster.
  Re-ask, or answer from the conversation the ask was presented in.
- **`always` says it needs a configured approver**: the roster is empty. Add
  your user id under `approvals.approvers`.
- **Slash commands do not appear**: the bot was invited without
  `applications.commands`, or the global deploy has not propagated yet. Verify
  in your server.
- **`DISCORD_BOT_TOKEN` is ignored**: the store already has a token for the
  default account, and the store wins. Remove the store token to use the env.
