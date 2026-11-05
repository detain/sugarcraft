<script setup lang="ts">
/**
 * Viewer-token management — the web plane of the G4-G store (X-DESIGN1 §D-2).
 *
 * Grants are minted read-only (owner ruling §7 item 2: viewer grants are a
 * read-only allowlist in v1 — the scopes field is fixed server-side shape,
 * never pickable here). The raw token crosses the wire EXACTLY ONCE, inside
 * the token.grant answer; token.list rows never carry it and this component
 * drops it from state the moment the mint dialog closes. Revoking is
 * confirm-gated, mirroring the sidebar's close-confirm pattern, and the
 * answer is uniform for unknown ids — so "already gone" is honest copy, never
 * a re-query. A viewer-kind hello (principal.kind === 'viewer') and a
 * uniform-401 answer each hide every management affordance.
 *
 * State is component-local on purpose: no store, no socket reducer — the
 * shared regions belong to other lanes (CW-DBA-1 owns the connection/store
 * files; this lane's contract is src-web/components/tokens/** plus the
 * minimal router/menu insertion).
 *
 * Types are the generated ones (G4-E → G4-G/addendum → G5-G weld order law
 * satisfied): the token.* defs live in protocol/generated.ts's Methods map,
 * so all three calls are plain typed connection.request — the lane's wire.ts
 * cast seam was deleted at the weld. All copy renders through t() — the
 * web.chrome.tokens.* keys (plus web.chrome.app.tokens) were minted across
 * the twelve candy-code locales in the reviews-1103 lang window; the panel's
 * rawCopyCensus rows were deleted in the same commit as this swap.
 */
import { computed, onMounted, ref } from 'vue'

import type { MethodResult } from '../../protocol/generated'
import { t } from '../../lib/i18n'
import { useDialog } from '../../lib/useDialog'
import { useConnectionStore } from '../../stores/connection'
import { describe } from '../../stores/session'

/** One token.list row; the hash and raw token are NEVER in list rows. */
type TokenGrantRow = MethodResult<'token.list'>['tokens'][number]
/** The grant answer — the ONLY moment the raw cv1_ token crosses the wire. */
type TokenGrantResult = MethodResult<'token.grant'>

const connection = useConnectionStore()

const grants = ref<TokenGrantRow[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
/** The mint answer — the ONLY holder of the raw token anywhere in the app. */
const minted = ref<TokenGrantResult | null>(null)
const copied = ref(false)
/** True once any call answers the uniform 401 — management is dead over this connection. */
const locked = ref(false)

/** Grant form. The scope is NOT a field: read-only in v1, fixed at submit. */
const label = ref('')
const days = ref('30')
const pinSessions = ref(false)
const pinIds = ref('')

/** A viewer-kind principal never manages tokens; the panel states its peace. */
const isViewer = computed(() => connection.hello?.principal.kind === 'viewer')
const canManage = computed(() => connection.scopes.includes('admin') && !isViewer.value)

onMounted(() => {
  if (canManage.value) void reload()
})

/**
 * The realized server stamps ISO-8601 (`date(ATOM)`), not epoch seconds —
 * parse the string and render UTC, matching the 'Expires {when} UTC'
 * catalogue line. (G4-G-ADDENDUM review MAJOR-1.)
 */
function expiryLabel(expiresAt: string): string {
  return new Date(expiresAt).toISOString().slice(0, 16).replace('T', ' ')
}

/**
 * The list answer OMITS `sessionIds` for an unpinned grant — omitted means
 * no session restriction, the same law the old explicit null carried.
 */
function sessionsLabel(row: TokenGrantRow): string {
  const ids = row.sessionIds ?? null
  return ids === null ? t('web.chrome.tokens.all_sessions') : t('web.chrome.tokens.pinned_sessions', { count: String(ids.length) })
}

/** The uniform-401 discriminator: kind travels in the error envelope (HANDOFFS). */
function isUnauthorized(failure: unknown): boolean {
  return (failure as { kind?: string } | null)?.kind === 'unauthorized'
}

function refuse(failure: unknown): void {
  if (isUnauthorized(failure)) {
    locked.value = true
    error.value = t('web.chrome.tokens.denied')
    return
  }
  error.value = describe(failure)
}

async function reload(): Promise<void> {
  if (!canManage.value || loading.value) return
  loading.value = true
  error.value = ''
  try {
    const result = await connection.request('token.list', {})
    // Newest-first over the ISO stamps: Date.parse honours each stamp's own
    // offset (the server formats with date(ATOM), so summer and winter rows
    // on a DST server can legitimately carry different offsets — a raw
    // string compare would misorder those).
    grants.value = [...result.tokens].sort((a, b) => Date.parse(b.createdAt) - Date.parse(a.createdAt))
  } catch (failure) {
    refuse(failure)
  } finally {
    loading.value = false
  }
}

async function submitGrant(): Promise<void> {
  const name = label.value.trim().slice(0, 64)
  if (name === '' || !canManage.value || loading.value) return
  loading.value = true
  error.value = ''
  notice.value = ''
  try {
    const daysAsked = Number(days.value)
    const bounded = Number.isFinite(daysAsked) ? Math.min(365, Math.max(1, Math.round(daysAsked))) : 30
    const ids = pinSessions.value
      ? pinIds.value.split(/\s+/u).map((id) => id.trim()).filter((id) => id !== '')
      : undefined
    const result = await connection.request('token.grant', {
      label: name,
      scopes: ['read'],
      days: bounded,
      ...(ids === undefined || ids.length === 0 ? {} : { sessionIds: ids }),
    })
    minted.value = result
    copied.value = false
    label.value = ''
    pinIds.value = ''
    pinSessions.value = false
  } catch (failure) {
    refuse(failure)
  } finally {
    loading.value = false
  }
}

/** Closing the mint dialog drops the raw token from state — nothing re-displays it. */
function closeMint(): void {
  minted.value = null
  copied.value = false
  void reload()
}

/**
 * The shown-once mint dialog joins the shared dialog spine (CW-UX-07):
 * focus enters on open, Tab stays trapped, Escape dismisses exactly like
 * the close button — which is the same drop-the-token path, never a
 * second exit.
 */
const dialogEl = ref<HTMLElement | null>(null)
const dialog = useDialog({
  panel: dialogEl,
  open: () => minted.value !== null,
  onClose: closeMint,
})

async function copyMinted(): Promise<void> {
  const token = minted.value?.token
  if (!token || !navigator.clipboard) return
  try {
    await navigator.clipboard.writeText(token)
    copied.value = true
  } catch {
    // Clipboard refusal keeps the text selectable — the token itself remains visible.
  }
}

async function revokeGrant(row: TokenGrantRow): Promise<void> {
  if (!canManage.value || loading.value) return
  if (!window.confirm(t('web.chrome.tokens.revoke_confirm', { label: row.label }))) return
  loading.value = true
  error.value = ''
  notice.value = ''
  try {
    await connection.request('token.revoke', { id: row.id })
    grants.value = grants.value.filter((grant) => grant.id !== row.id)
    notice.value = t('web.chrome.tokens.revoked')
  } catch (failure) {
    refuse(failure)
    void reload()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="tokens" aria-labelledby="tokens-heading">
    <header class="head">
      <h2 id="tokens-heading" class="heading">{{ t('web.chrome.tokens.title') }}</h2>
      <button v-if="canManage && !locked" class="reload" type="button" data-testid="tokens-reload" :disabled="loading" @click="reload()">{{ t('web.chrome.tokens.refresh') }}</button>
    </header>

    <p v-if="isViewer" class="banner" role="status" data-testid="tokens-viewer-banner">
      {{ t('web.chrome.tokens.viewer_only') }}
    </p>
    <p v-if="error" class="error" role="alert" data-testid="tokens-error">{{ error }}</p>
    <p v-if="notice" class="notice" role="status" data-testid="tokens-notice">{{ notice }}</p>

    <div v-if="minted" ref="dialogEl" class="mint" role="dialog" aria-modal="true" tabindex="-1" aria-labelledby="mint-heading" data-testid="tokens-mint-dialog" @keydown="dialog.onKeydown">
      <h3 id="mint-heading" class="mint-heading">{{ t('web.chrome.tokens.grant_created') }}</h3>
      <p class="mint-copy">{{ t('web.chrome.tokens.mint_shown_once') }}</p>
      <code class="mint-token" data-testid="tokens-mint-token">{{ minted.token }}</code>
      <p class="mint-meta">{{ t('web.chrome.tokens.expires', { when: expiryLabel(minted.expiresAt) }) }}</p>
      <p class="mint-meta">{{ t('web.chrome.tokens.rotation_warning') }}</p>
      <div class="mint-actions">
        <button class="reload" type="button" data-testid="tokens-mint-copy" @click="copyMinted()">{{ copied ? t('web.chrome.sidebar.revoked') : t('web.chrome.tokens.copy') }}</button>
        <button class="reload" type="button" data-testid="tokens-mint-close" @click="closeMint()">{{ t('web.chrome.sidebar.close') }}</button>
      </div>
    </div>

    <form v-if="canManage && !locked" class="grant" data-testid="tokens-grant-form" @submit.prevent="submitGrant">
      <label class="field">
        <span class="field-label">{{ t('web.chrome.tokens.label') }}</span>
        <input v-model="label" type="text" maxlength="64" required data-testid="tokens-label" />
      </label>
      <label class="field">
        <span class="field-label">{{ t('web.chrome.tokens.days') }}</span>
        <input v-model="days" type="number" min="1" max="365" data-testid="tokens-days" />
      </label>
      <label class="field check">
        <input v-model="pinSessions" type="checkbox" data-testid="tokens-pin" />
        <span class="field-label">{{ t('web.chrome.tokens.pin') }}</span>
      </label>
      <label v-if="pinSessions" class="field">
        <span class="field-label">{{ t('web.chrome.tokens.sessions') }}</span>
        <input v-model="pinIds" type="text" :placeholder="t('web.chrome.tokens.pin_ids_placeholder')" data-testid="tokens-pin-ids" />
      </label>
      <p class="field-note" data-testid="tokens-scopes-note">{{ t('web.chrome.tokens.readonly_note') }}</p>
      <button class="grant-submit" type="submit" data-testid="tokens-grant-submit" :disabled="loading || label.trim() === ''">{{ t('web.chrome.tokens.grant') }}</button>
    </form>

    <ul v-if="canManage && !locked" class="list" data-testid="tokens-list">
      <li v-if="grants.length === 0 && !loading" class="empty" data-testid="tokens-empty">{{ t('web.chrome.tokens.empty') }}</li>
      <li v-for="grant in grants" :key="grant.id" class="row" :data-testid="`tokens-row-${grant.id}`">
        <span class="row-label">{{ grant.label }}</span>
        <span class="row-meta" data-testid="tokens-meta">{{ grant.scopes.join(' ') }} · {{ sessionsLabel(grant) }} · {{ t('web.chrome.tokens.expires', { when: expiryLabel(grant.expiresAt) }) }}</span>
        <button class="revoke" type="button" :data-testid="`tokens-revoke-${grant.id}`" :disabled="loading" @click="revokeGrant(grant)">{{ t('web.chrome.tokens.revoke') }}</button>
      </li>
    </ul>
    <p v-if="canManage && !locked" class="cap">{{ t('web.chrome.tokens.cap') }}</p>
  </section>
</template>

<style scoped>
.tokens {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  border: 1px solid var(--line, #333);
  border-radius: 10px;
  background: var(--bg, #101014);
}
.head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;
}
.heading {
  margin: 0;
  font-size: 15px;
}
.reload {
  border: 1px solid var(--line, #333);
  border-radius: 7px;
  background: transparent;
  color: var(--accent, #9fc7ff);
  padding: 2px 10px;
  cursor: pointer;
  font: inherit;
}
.banner {
  margin: 0;
  color: var(--muted, #9a9aa2);
}
.error {
  margin: 0;
  color: var(--error, #ff7a7a);
}
.notice {
  margin: 0;
  color: var(--accent, #9fc7ff);
}
.mint {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 10px;
  border: 1px solid var(--warn, #d7b45a);
  border-radius: 9px;
}
.mint-heading {
  margin: 0;
  font-size: 14px;
}
.mint-copy {
  margin: 0;
  color: var(--warn, #d7b45a);
}
.mint-token {
  display: block;
  padding: 8px;
  border-radius: 7px;
  background: rgba(255, 255, 255, 0.06);
  overflow-wrap: anywhere;
  user-select: all;
}
.mint-meta {
  margin: 0;
  color: var(--muted, #9a9aa2);
  font-size: 12px;
}
.mint-actions {
  display: flex;
  gap: 8px;
}
.grant {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 10px;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.field.check {
  flex-direction: row;
  align-items: center;
  gap: 6px;
}
.field-label {
  color: var(--muted, #9a9aa2);
  font-size: 12px;
}
.field input[type='text'],
.field input[type='number'] {
  border: 1px solid var(--line, #333);
  border-radius: 7px;
  background: transparent;
  color: inherit;
  padding: 4px 8px;
  font: inherit;
}
.field-note {
  margin: 0;
  flex-basis: 100%;
  color: var(--muted, #9a9aa2);
  font-size: 12px;
}
.grant-submit {
  border: 1px solid var(--accent, #9fc7ff);
  border-radius: 7px;
  background: transparent;
  color: var(--accent, #9fc7ff);
  padding: 4px 14px;
  cursor: pointer;
  font: inherit;
}
.grant-submit[disabled] {
  opacity: 0.5;
  cursor: default;
}
.list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px;
  border: 1px solid var(--line, #333);
  border-radius: 8px;
}
.row-label {
  flex: 1;
}
.row-meta {
  color: var(--muted, #9a9aa2);
  font-size: 12px;
}
.revoke {
  border: 1px solid var(--error, #ff7a7a);
  border-radius: 7px;
  background: transparent;
  color: var(--error, #ff7a7a);
  padding: 2px 10px;
  cursor: pointer;
  font: inherit;
}
.empty {
  color: var(--muted, #9a9aa2);
}
.cap {
  margin: 0;
  color: var(--muted, #9a9aa2);
  font-size: 11px;
}
</style>
