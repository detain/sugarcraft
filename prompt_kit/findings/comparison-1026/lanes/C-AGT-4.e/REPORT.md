# C-AGT-4.e — SessionMailbox (cross-session SendMessage) — LANE REPORT (live)

Worktree /home/sites/cc-cmp-C-AGT-4.e, branch cc/C-AGT-4.e, base 44ff31314.

## State
- [done] src/Agents/Live/SessionMailbox.php — disk mailbox ~/.candy-code/session-mail/<session>/, 0700 dirs, 0600 files, per-session key minted by receiver (AgentInbox signing scheme: HMAC-SHA256 over AgentMessage::signingPayload(recipientId)); forged/malformed lines dropped+reported once; take(limit) oldest-once; frame() = <session-message from="…" mode="…"> + PromptFence::escape + neutralise + AUTHORITY (no authority).
- [done] SendMessageTool: `session:<id>` branch (sub-agent refused, self refused, unknown/background kind refused, same-root check vs SessionStore cwd w/ projectRoot fallback, send signed as agent:<scope>); seams withSessionRoot(?root,?SessionStore) + fromCatalog binds $context->root; gloss/description/inputSchema updated.
- [done] PermissionGate::isNoAskTool carve-out: SendMessage + to starts session: => NOT no-ask (falls to mode rules => Ask).
- [done] lang/en.php C-AGT-4.e region: send.session.{sent,subagent,self,target_invalid,unknown,background,foreign_root,no_store,failed}, chat.background.mail.{one,other}, host.mail.queued. (locale-wave carry: only en.php exists in candy-code/lang.)
- [in progress] SessionHost::tick drain, Chat::pumpBackgroundSessions drain, tests, ReadPathCensus rows, docs/AGENTS.md + README gen-tool-docs.

## Gates (running log)
php -l clean on all four commit-1 files; autoload reflection probe ok.
