# candy-msteams

Microsoft Teams channel bundle for `candy-code serve`. A Teams personal chat,
group chat or channel conversation becomes a served session: Microsoft posts
activities to your serve over a webhook, the bundle validates the Bot
Framework token itself, and replies go back through the activity's
`serviceUrl`. Policy lives in the user-tier `channels.msteams` settings;
credentials live only in the 0600 channels store.

This guide is written from the code. Anything the code cannot confirm (Azure
portal labels, tenant policy, Teams client behaviour) is marked
**verify in your tenant**.

## Contents

1. [Requirements](#requirements)
2. [Azure Bot resource: single-tenant or multi-tenant](#azure-bot-resource-single-tenant-or-multi-tenant)
3. [App registration: app id and client secret](#app-registration-app-id-and-client-secret)
4. [Messaging endpoint](#messaging-endpoint)
5. [Credentials store](#credentials-store)
6. [Enable the channel](#enable-the-channel)
7. [Teams app manifest](#teams-app-manifest)
8. [Resource-specific consent (RSC) for channels and groups](#resource-specific-consent-rsc-for-channels-and-groups)
9. [Allowed IDs: aadObjectId vs 29:… ids](#allowed-ids-aadobjectid-vs-29-ids)
10. [Access, groups and mentions](#access-groups-and-mentions)
11. [Pairing and approvers](#pairing-and-approvers)
12. [Commands](#commands)
13. [Streaming replies](#streaming-replies)
14. [Proactive notify](#proactive-notify)
15. [Local testing with a tunnel](#local-testing-with-a-tunnel)
16. [Settings reference](#settings-reference)
17. [Troubleshooting](#troubleshooting)

## Requirements

- PHP 8.3+ and a candy-code build that ships the `channels.msteams` settings
  reader. The bundle refuses to start (with a log line) on an older host.
- An Azure subscription where you can create a Bot resource and an app
  registration (verify in your tenant: the portal flow changes over time).
- A public HTTPS address that reaches your serve's `/channels/msteams/…`
  path (a reverse proxy or a tunnel, see [Local testing](#local-testing-with-a-tunnel)).

## Azure Bot resource: single-tenant or multi-tenant

Create an Azure Bot resource for the bot. Pick the **single-tenant** type and
use your own directory. Reasons, from the code:

- The bundle uses one `tenantId` for two things. It is the directory the bot
  token is minted against (`login.microsoftonline.com/<tenantId>/oauth2/v2.0/token`),
  and it is the tenant floor: an inbound activity whose conversation tenant
  differs from it is refused before anything reads it (`msteams_tenant_refused`).
  A single-tenant bot with your directory GUID makes both checks say the same thing.
- A multi-tenant bot mints its tokens against a shared authority name, not
  your directory GUID. **As written, this bundle cannot run a multi-tenant bot:**
  the authority and the tenant floor are the same field, so a shared name fails
  the floor for every conversation, and your GUID is not the shared authority.
  Use single-tenant. (Finding from the code; confirm with a test message in
  your tenant.)

Record the bot's **Microsoft App ID** and your **Directory (tenant) ID**. The
tenant ID is the `tenantId` the store needs.

## App registration: app id and client secret

The Bot resource has an app registration behind it. You need:

- **Application (client) ID**: the `appId`. The bundle requires the inbound
  JWT `aud` to equal this value, and it is also the bot's identity in
  Teams (`28:<appId>` in member lists).
- **Client secret**: the `appPassword`. The bundle sends it in a
  client-credentials exchange (`client_id` + `client_secret`, scope
  `api.botframework.com/.default`). **Certificates are not supported by this
  bundle**: there is no certificate field in the store and no certificate
  grant in the token code. Create a client secret, and rotate it by writing a
  new value into the store.

## Messaging endpoint

Set the bot's messaging endpoint to:

```
https://<your-public-host>/channels/msteams/api/messages
```

The path is `/channels/msteams` followed by `transport.webhookPath`
(default `/api/messages`). The serve's `/channels/` mount hands the whole
request to the bundle, which answers `404` for any other path and `405` for
anything but `POST`. The endpoint must be HTTPS; Microsoft will not post to plain HTTP.

## Credentials store

Credentials live in `~/.candy-code/channels.json` (the trusted config
directory). This bundle ships no command that writes the Teams triple, so
create the file yourself:

```json
{
  "version": 1,
  "platforms": {
    "msteams": {
      "accounts": {
        "default": {
          "appId": "<application (client) id>",
          "appPassword": "<client secret value>",
          "tenantId": "<directory (tenant) id>"
        }
      }
    }
  }
}
```

Then `chmod 600 ~/.candy-code/channels.json`. The bundle refuses a store that
any other user can read, and writes its own pairing data back through a
0600 rename. The three values are all-or-nothing per account: a partial triple
is treated as no account.

Environment fallback, default account only, store wins:
`MSTEAMS_APP_ID`, `MSTEAMS_APP_PASSWORD`, `MSTEAMS_TENANT_ID`. Prefer the store.

Additional accounts go under `accounts.<name>` in the same store, and may
override policy under `channels.msteams.accounts.<name>` in settings.

## Enable the channel

Policy goes in the **user** config, `~/.candy-code/config.json`, under
`channels.msteams`. The project tier cannot set it: a checked-out repository
cannot widen a channel's reach. The bundle is dark until `enabled` is `true`.

```json
{
  "channels": {
    "msteams": {
      "enabled": true,
      "access": { "dmPolicy": "pairing" },
      "approvals": { "approvers": ["<aadObjectId of an approver>"] }
    }
  }
}
```

Restart `candy-code serve` after editing (settings are read when the channel
starts). The start log names the posture
(`channels.msteams: disabled posture, nothing started` when off).

## Teams app manifest

Build a Teams app package (`manifest.json`, a 192×192 colour icon `color.png`,
a 32×32 outline icon `outline.png`, zipped together). The parts that matter here:

- `id` and `botId`: both your **application (client) id**.
- `bots`: one entry with `"scopes": ["personal", "team", "groupchat"]`. These
  are the three places the bundle serves: personal chats, group chats and
  team channels.
- `commandLists` (optional): the text commands are typed as messages, so
  this only adds autocomplete in the compose box. Example entries: `help`,
  `status`, `cost`, `new`, `stop`, `model`, `queue`, `approve`, `pair`.
- `permissions` and `validDomains`: see the next section. Use the current
  manifest schema version (verify in your tenant).

Sideload the package for yourself or publish it to your org's app catalogue
(verify in your tenant: the admin path depends on your Teams policies).

## Resource-specific consent (RSC) for channels and groups

Personal chats need no RSC. For **team channels and group chats** the bundle
expects to read conversation messages that do not mention the bot only when
the app holds the RSC permission `ChannelMessage.Read.Group` (verify in your
tenant that the permission is granted in the manifest and by an admin).

If that consent is **not** granted, set `groups.degraded` to `true`. The
bundle then forces mention-only in every group (`degraded` is the declared
posture, §8.3 in the code's comments), so it does not depend on the consent
being present. The bundle does not use any other RSC permission in the code
paths reviewed here; anything beyond that is a verify-in-your-tenant item.

Media from Microsoft Graph (SharePoint or OneDrive links) is **not**
fetched: those attachments are refused with a note in the prompt, because
they need delegated consent this bundle does not request.

## Allowed IDs: aadObjectId vs 29:… ids

`access.allowFrom`, `groups.rows.<conversationId>.allowFrom`,
`approvals.approvers` and the pairing store all compare the same thing: the
sender's id, read as `from.aadObjectId` when the activity carries it, and
`from.id` otherwise.

- **`aadObjectId`** (a GUID) is the stable Entra object id. Prefer it.
- **`29:…`** is the Bot Framework user id. It is what `from.id` carries when
  no `aadObjectId` is present. It is specific to this bot, so the same person
  has a different `29:` id for each bot.

The easiest way to find an id is the pairing list. A user who DMs the bot
with pairing on gets a code, and an approver running `/pair` in a direct
message sees `<CODE> — user <id>`. That `<id>` is exactly the value the
allowlists compare. With `dmPolicy: "allowlist"`, put it in `access.allowFrom`
to admit that user without pairing. Under `dmPolicy: "pairing"`, `access.allowFrom`
is not consulted; only paired users pass.

## Access, groups and mentions

- **Direct messages** follow `access.dmPolicy`: `pairing` (default), `allowlist`
  (pairing store plus `access.allowFrom`), `open`, or `disabled`.
- **Group chats and channels are blocked by default.** A conversation is
  served only when it has a `groups.rows.<conversationId>` row, or when
  `groups.groupPolicy` is `open`. The `conversationId` is the `conversation.id`
  on the activity (`19:…@thread.v2` style for channels and group chats).
- Per row: `policy: "disabled"` closes it; `requireMention` (default `true`)
  decides whether a plain message needs an @mention; `allowFrom` (new, see
  below) restricts who may prompt.
- `access.ignoreOtherMentions` (default `true`) drops messages that mention
  somebody other than the bot.
- `access.allowBots` (default `"mentions"`) decides whether another bot's
  messages count. The bot never answers its own messages.

Example: one channel, mention-only, and only two people may prompt there.

```json
{
  "channels": {
    "msteams": {
      "enabled": true,
      "groups": {
        "groupPolicy": "allowlist",
        "degraded": false,
        "rows": {
          "19:<channel-thread-id>@thread.v2": {
            "policy": "allowlist",
            "requireMention": true,
            "allowFrom": ["<aadObjectId A>", "<aadObjectId B>"]
          }
        }
      }
    }
  }
}
```

`allowFrom` on a group row: unset or an empty list means anyone in the
conversation may prompt, as before. A non-empty list means only those ids may
prompt there, including commands and card answers. Commands from anyone else
get no reply.

## Pairing and approvers

With `dmPolicy: "pairing"`, an unpaired person who DMs the bot gets a notice
with an 8-character code (valid one hour, at most three pending at a time,
one reminder per hour). Nothing they write reaches the model until paired.

An **approver** pairs them, in a direct message with the bot:

```
/pair            list pending codes and the user ids they belong to
/pair approve <CODE>
```

`/pair` answers only approvers, and an empty `approvals.approvers` roster answers
nobody. In a group, `/pair` names no code and tells you to use a direct message.

## Commands

Typed as messages (a leading `@bot` mention is stripped first).

| Command | What it does |
| --- | --- |
| `/help` | Lists the commands. |
| `/new` | Starts a fresh session for this conversation. |
| `/stop` | Cancels the running turn. |
| `/status` | Session id, state, queue mode, queued prompts, and approvals awaiting an answer. The model line appears only if the host reports one. |
| `/cost` | Spend the host reports for this session, or `Cost: not reported.` |
| `/queue <text>` | Queues a prompt behind the running turn. |
| `/model [name]` | Runs the session's `/model` command. |
| `/approve <askId> [once\|always\|deny]` | Answers a tool approval in chat. |
| `/pair` | Pairing, approvers only. |

In a group, commands follow the same door as prompts: the conversation must be
served, and `requireMention` applies.

## Streaming replies

`streamReplies` (default `false`) posts `working…` when a prompt is admitted,
then edits that message in place with the elapsed seconds, at most once every
two seconds, and finally replaces it with the reply. Long replies are chunked
as before; the first chunk replaces the placeholder, the rest post as new
messages. If an edit is refused, the reply posts as a new message instead. A
turn that ends without a reply edits the placeholder to `No reply was produced.`

With it off (the default), each reply is one or more posts, as before.

## Proactive notify

`MsTeamsChannel::notifyConversation(array $conversationRef, string $text)`
posts into a conversation the bot has already served. The reply address is
the one the bot stored on its last inbound there. The caller's `serviceUrl` is
ignored. An unseen conversation is refused. The text gets the same redaction
and chunking as a reply.

The host call site for background-job completion is **not** part of this
bundle. It needs a candy-code host change to call this method.

## Local testing with a tunnel

1. Run `candy-code serve` locally with `channels.msteams.enabled: true`.
2. Start a tunnel to the serve's HTTP port (any HTTPS tunnel tool). Point the
   bot's messaging endpoint at `https://<tunnel-host>/channels/msteams/api/messages`.
   The tunnel is HTTPS, so no insecure flag is needed for Microsoft's own
   traffic.
3. Keep `transport.insecureLoopbackProxy` **off** unless you are faking
   Microsoft's endpoints on loopback. That flag only lets an `http://` loopback
   proxy stand in for `transport.authBaseUrl`, `transport.jwksUrl` or a
   `serviceUrl`. It never applies to the tunnel. An offline signing-key roster
   can instead be given as a `file:///` path in `transport.jwksUrl`.

Note that a reverse proxy or tunnel makes every request arrive from the proxy's
address. The webhook limiter is per remote address (see troubleshooting).

## Settings reference

All keys live under `channels.msteams` in `~/.candy-code/config.json` (user
tier only). Defaults are what the code uses when a key is absent.

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `false` | Must be exactly `true`. |
| `access.dmPolicy` | `pairing` | `pairing`, `allowlist`, `open`, `disabled`. |
| `access.dmScope` | `per-peer` | `main`, `per-peer`, `per-channel-peer`, `per-account-channel-peer`. |
| `access.allowFrom` | `[]` | Ids allowed in DMs (see above). |
| `access.allowBots` | `mentions` | `mentions`, `true`, `false`. |
| `access.ignoreOtherMentions` | `true` | |
| `access.respondWithoutMention` | `false` | Plain DM-style prompts in a served group without a mention. |
| `access.botName` | `bot` | Name stripped from own mentions. |
| `groups.groupPolicy` | `allowlist` | `open` serves every group. |
| `groups.degraded` | `false` | Set `true` when RSC `ChannelMessage.Read.Group` is not granted. |
| `groups.rows.<conversationId>.policy` | `allowlist` | `disabled` closes the row. |
| `groups.rows.<conversationId>.requireMention` | `true` | |
| `groups.rows.<conversationId>.allowFrom` | unset | **New.** Per-user list for one group; unset or empty means anyone. |
| `streamReplies` | `false` | **New.** Progressive `working…` placeholder. |
| `outbound.typing` | `true` | |
| `outbound.textChunkLimit` | `3800` | Minimum 100. |
| `outbound.maxLinesPerMessage` | `40` | |
| `media.maxMb` | `100` | Per-attachment cap. |
| `approvals.enabled` | `true` | `false` leaves `/approve <askId>` as the only answer path. |
| `approvals.target` | `both` | `dm`, `channel`, or `both`. |
| `approvals.approvers` | `[]` | Who may answer approvals and pairings. Empty: anyone may answer approve-once or deny; `always` needs a named approver; nobody can approve a pairing. |
| `approvals.ttlMinutes` | `30` | Clamped to the core's ask expiry. |
| `approvals.sessionFilter` / `agentFilter` | empty | Regex doors on which asks are presented. |
| `queue.mode` | `steer` | `steer`, `interrupt`, `collect`, `queue`. |
| `queue.debounceMs` / `queue.maxPending` | `500` / `20` | For `collect`. |
| `transport.webhookPath` | `/api/messages` | Under `/channels/msteams`. |
| `transport.authBaseUrl` | `https://login.microsoftonline.com` | Override needs https, or loopback with the opt-in below. |
| `transport.jwksUrl` | `https://login.botframework.com/v1/.well-known/keys` | Override needs https, loopback with the opt-in, or `file:///`. |
| `transport.insecureLoopbackProxy` | `false` | Allows `http://` for a loopback host only. |
| `accounts.<name>` | none | Per-account overrides of the rows above. |

## Troubleshooting

**Microsoft shows 401 on the messaging endpoint.** The bundle answers 401 for
a missing bearer or a token it refuses (log `channels.msteams.webhook_unauthorized`
with `why` set to `no bearer` or `token refused`). For a refused token, check:

- The token's audience must equal the **application (client) id** in the
  store. A bot with a different app id, or a store written with the wrong
  value, gets a 401 on every message.
- The issuer must be `https://api.botframework.com` or `https://botframework.com`.
- Token expiry applies, with a 60-second clock leeway.
- If the signing keys cannot be fetched, the log says
  `channels.msteams.jwks_roster_unavailable`. That is a network problem on
  the serve host, not a forged token. The bundle keeps a 60-second negative
  cache after a failed fetch and refreshes known keys every 5 minutes.

**The bot receives messages but never replies.** Check, in order:

- The start log: `channels.msteams.no_usable_accounts` or
  `channels.msteams.account_without_credentials` means the store triple is
  missing or partial.
- `msteams_tenant_refused` means the conversation's tenant is not the
  configured `tenantId`. Use the same directory as the Bot resource.
- `channels.msteams.serve_down` / `serve_dial_failed`: the bundle cannot reach
  the local serve. A session is not created until it can.
- The service URL. Replies go to the activity's `serviceUrl`, which must be
  HTTPS on a Bot Framework host (`botframework.com`, `smba.trafficmanager.net`,
  `msg.teams.microsoft.com`). Other hosts are refused before any token is sent
  (`msteams serviceUrl refused`).
- Bot token problems appear as `msteams_token_failed` or `msteams_token_error`:
  the client secret is wrong, expired, or belongs to another app.
- In a group, the conversation needs a `groups.rows` entry (or `groupPolicy: open`)
  and, by default, an @mention.

**429 errors.** There are two different sources.

- **Inbound (the webhook):** more than 30 requests in a burst, refilled at 30 a
  minute, per remote address. Logged as `channels.msteams.webhook_rate_limited`
  with `Retry-After: 2`. Behind a tunnel or reverse proxy every caller shares
  the proxy's address, so one busy chat can throttle the rest.
- **Outbound (Teams throttling the bot):** a `429` from Bot Framework is retried
  twice, honouring `Retry-After` up to 30 seconds (log `msteams_429`). After
  the last retry that chunk is not delivered, and the failure is logged as
  `deliver_failed`.

**Approval cards do not appear.** Check `approvals.enabled`, `approvals.target`
(a `dm` target hides cards in channels), and `approvals.sessionFilter` /
`agentFilter`. The same-chat fallback `/approve <askId> once|always|deny`
works whenever the ask is open.

**`/cost` says not reported.** The host sent no spend figure for the session.
The bundle does not estimate one.
