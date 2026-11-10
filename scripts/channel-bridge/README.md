# candy-code channel bridge (P0)

A single long-running PHP process that connects `candy-code serve` to chat
platforms. It is an **external consumer** of the product's own WS protocol
(`candy-code.v1`) — exactly what `candy-code attach` speaks. No candy-code
source changes; nothing here is imported by the library.

```
human ⇄ Discord (gateway WSS v10)      ┐
human ⇄ Teams (Bot Framework webhook)  ┼── Bridge ── ServeBridge ──(WS)── candy-code serve
                                       ┘
```

## Run

```sh
php candy-code/bin/candy-code serve --detach          # the server, as ever
php scripts/channel-bridge/bridge.php creds set discord default "<bot token>"
cp scripts/channel-bridge/config.sample.json scripts/channel-bridge/config.json
$EDITOR scripts/channel-bridge/config.json            # flip discord.enabled
php scripts/channel-bridge/bridge.php --config=scripts/channel-bridge/config.json
```

Verify after any edit: `php scripts/channel-bridge/selfcheck.php` (fixture +
unit lanes always; a live-serve lane when a serve is reachable).

## The three doors

| Door | File | Law |
|------|------|-----|
| config | `config.json` | enablement + policy only — **never** credentials |
| credentials | `~/.candy-code/channels.json` | 0600, temp+rename atomic write, refuses group/world bits or a foreign `version`; shape `{version:1, platforms:{discord:{accounts:{name:{token}}}, msteams:{accounts:{name:{appId,appPassword,tenantId}}}}}` (ruling D4; P1 reuses it) |
| serve | `~/.candy-code/server.json` + token | the product's discovery record: `isLive()` (pid + start-time) gates every use — a stale record is a corpse, not a door. The bridge dials `wss://…/ws` with subprotocol `candy-code.auth.<token>` and follows sessions with a durable cursor stored in `bridge.sqlite` |

## Discord bot setup

1. <https://discord.com/developers/applications> → New Application → **Bot**.
2. Reset token → `bridge.php creds set discord default "<token>"`.
3. **Privileged Gateway Intents**: enable *Message Content Intent*. Without
   it the bridge still runs but guild text is invisible (mentions only);
   it logs `discord_intents_degraded` when the gateway refuses 4013/4014
   and retries without the intent. DM text always works.
4. Invite with scopes `bot applications.commands`, permissions: View
   Channels, Send Messages, Send Messages in Threads, Embed Links, Read
   Message History, Add Reactions.
5. Commands: the bridge PUTs `/new /stop /approve /queue` on boot —
   guild-scoped (instant) when `discord.guilds` lists guild ids, global
   otherwise (Discord caches global rosters up to an hour). The `/word`
   text fallback works from the first message either way.
6. Policy in config: `dmPolicy` (`allowlist`|`open`|`disabled`) +
   `allowUsers` for DMs; `guilds.<id>.allowChannels` +
   `requireMention` per guild; `respondWithoutMention` to talk in any
   allowed channel unprompted. `dmScope` picks the session collapse ladder
   (`main` | `per-peer` | `per-channel-peer` | `per-account-channel-peer`).
   Group/threads: one thread = one session (its channel id is the key).

## Teams app registration

1. Azure portal → App registration → note **Application (client) id** and
   **Directory (tenant) id**; create a client secret.
2. `bridge.php creds set msteams default '{"appId":"…","appPassword":"…","tenantId":"…"}'`
3. Bot Framework (or Teams App Studio) → set the **messaging endpoint** to
   your tunnel URL + `/api/messages` (see Tunnel law below).
4. In Microsoft Teams admin center allow the app; test in a personal DM
   first. Group chats are **blocked by default** (`groupPolicy`); open
   specific conversations through `allowGroups`.
5. Inbound activities are JWT-validated against Microsoft's published keys
   (issuer set, audience = your appId, kid-cached 1 h). A request that
   fails validation is answered `401` and never becomes a prompt.
   `allowUnsignedEmulator: true` accepts unsigned tokens from the local
   emulator — off unless you know why.

## Tunnel law

The bridge binds `127.0.0.1:3978` by default. **Public reachability is the
operator's job** (cloudflared / frp / caddy / tailscale funnel — pick one):
point the Teams messaging endpoint at `https://<your-host>/api/messages`.
Do not expose the bridge directly; TLS and the port mapping belong to the
tunnel. Discord needs no inbound port at all (outbound WSS only).

## Token scopes

- Discord: the bot token sees everything the app is invited to — invite it
  only where you want prompts, keep `guilds`/`allowChannels` tight.
- Teams: the client secret mints Connector tokens scoped
  `api.botframework.com` only. Nothing else.
- serve token: full write authority over the candy-code server (it can
  create sessions and answer permissions). It lives in the state dir or
  `CANDY_CODE_SERVER_TOKEN`; treat both as root-equivalent. Set
  `policy.approvers` to bind who may answer approval asks over channels.

## What the bridge does with your words

Inbound text is **untrusted input**: it is neutralized with the product's
own `PromptFence::escape` before it reaches the model (fence tags become
inert text), and configured `policy.secrets` are redacted in both
directions. Outbound model text is chunked per platform (2000 chars /
17 lines on Discord) and posted with `allowed_mentions.parse=[]` so model
output can never ping humans. Approval buttons/cards resolve through
`permission.respond` — first valid answer wins, so double presses are
harmless, and answered asks are retired in-channel.

## Sessions and recovery

`bridge.sqlite` maps `<platform>:<account>:<channel-or-thread-id>` → session
id and stores the durable cursor + the last reply address per session. A
crash or serve restart resumes each followed session from its stored seq:
replayed events advance the cursor but are NOT re-posted to the channel,
while still-open permission asks come back through `pendingAsks` and are
re-presented. SIGTERM stops accepting, closes the socket, and exits —
cursors are already on disk.

## Files

```
bridge.php            daemon entrypoint (+ `creds` helper)
selfcheck.php         offline fixture + unit battery and the live-serve lane
config.sample.json    copy → config.json, edit, run
src/                  ChannelBridge\ classes (autoload.php pulls candy-code's vendor)
```
