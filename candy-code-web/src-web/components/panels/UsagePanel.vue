<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { spendLabel, tokens } from '../../lib/format'
import { useConnectionStore } from '../../stores/connection'
import { useServerSettingsStore } from '../../stores/settings/serverSettings'
import type { MethodResult } from '../../protocol/generated'

/**
 * C-SES-8.a: the durable spend ledger on the web — the same fold `/usage`
 * prints, over `usage.rollup`. Totals, the day×model grid and the per-model
 * table ride ONE answer, so the panel cannot print a grid and totals from
 * two reads that straddled a settle.
 *
 * THE `≥` LAW (SES-1): every dollar figure renders through `spendLabel`,
 * which prefixes `≥` when the row (or, for the totals, the answer) folded an
 * unpriced call — an unknown rate is a LOWER BOUND, never a bare "$".
 *
 * RETENTION IS READ-ONLY HERE: the answer carries the launch's effective
 * `sessionRetentionDays`; the panel states it and, when the existing
 * settings schema marks the key remotely writable, links to the Settings
 * route where the user changes it. This panel never writes a setting — the
 * same law the RPC carries (§1.x: no new write path).
 *
 * SECURITY POSTURE: every server string (reason, model name) lands in a
 * plain `{{ }}` span — no MessageMarkdown near untrusted content (the
 * ChangesPanel rule).
 */
const props = defineProps<{ sessionId: string }>()

type UsageRollup = MethodResult<'usage.rollup'>
type DayRow = UsageRollup['days'][number]
type ModelRow = UsageRollup['models'][number]

const connection = useConnectionStore()
const settings = useServerSettingsStore()

const scope = ref<'session' | 'all'>('session')
/** 0 means "no window" — the whole ledger; 7 and 30 are the two windows. */
const windowDays = ref<0 | 7 | 30>(0)
const answer = ref<UsageRollup | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

/**
 * The ordering latch in component form (the WEB-3a MINOR-2 lesson): a late
 * answer for the previous session or a superseded scope must not paint over
 * the fresh request's result — answers AND errors, because a refusal for the
 * old request is not news to the new one.
 */
let generation = 0

async function load(): Promise<void> {
  const mine = ++generation
  loading.value = true
  error.value = null
  const params: { sessionId: string; scope: 'session' | 'all'; days?: 7 | 30 } = {
    sessionId: props.sessionId,
    scope: scope.value,
  }
  if (windowDays.value !== 0) params.days = windowDays.value
  try {
    const result = await connection.request('usage.rollup', params)
    if (mine !== generation) return
    answer.value = result
  } catch (failure) {
    if (mine !== generation) return
    error.value = failure instanceof Error ? failure.message : String(failure)
  } finally {
    if (mine === generation) loading.value = false
  }
}

/**
 * The Settings link is offered exactly when the schema already on the client
 * says the user may change retention over the wire — and the client asks for
 * that schema once (it is the same store the Settings form loads, so opening
 * Settings later re-uses it). When the answer says the key is locked or not
 * remote-writable, the panel states the number and offers no button.
 */
const retentionWritable = computed(() => settings.editabilityOf('sessionRetentionDays').editable)
if (!settings.loaded && !settings.loading) void settings.load()

function chooseScope(next: 'session' | 'all'): void {
  if (scope.value === next) return
  scope.value = next
  void load()
}

function chooseWindow(next: 0 | 7 | 30): void {
  if (windowDays.value === next) return
  windowDays.value = next
  void load()
}

onMounted(() => void load())
watch(() => props.sessionId, () => void load())

function dayKey(row: DayRow): string {
  return `${row.day}|${row.model ?? ''}`
}

function modelKey(row: ModelRow): string {
  return row.model ?? ''
}

const windowLabel = computed(() => (windowDays.value === 0 ? 'whole ledger' : `last ${windowDays.value} days`))
</script>

<template>
  <div class="usage-panel" data-testid="usage-panel">
    <div class="head">
      <span class="count">usage · {{ windowLabel }}</span>
      <span class="scopes" role="group" aria-label="scope">
        <button type="button" class="link" :class="{ on: scope === 'session' }" data-testid="usage-scope-session" @click="chooseScope('session')">session</button>
        <button type="button" class="link" :class="{ on: scope === 'all' }" data-testid="usage-scope-all" @click="chooseScope('all')">all</button>
      </span>
      <span class="windows" role="group" aria-label="window">
        <button type="button" class="link" :class="{ on: windowDays === 0 }" data-testid="usage-window-full" @click="chooseWindow(0)">full</button>
        <button type="button" class="link" :class="{ on: windowDays === 7 }" data-testid="usage-window-7" @click="chooseWindow(7)">7d</button>
        <button type="button" class="link" :class="{ on: windowDays === 30 }" data-testid="usage-window-30" @click="chooseWindow(30)">30d</button>
      </span>
      <button type="button" class="link" data-testid="usage-reload" :disabled="loading" @click="load()">reload</button>
    </div>

    <p v-if="error" class="error" role="alert" data-testid="usage-error">{{ error }}</p>
    <p v-else-if="loading && answer === null" class="empty">Reading the ledger…</p>

    <template v-else-if="answer">
      <p class="totals" data-testid="usage-totals">
        <span>{{ spendLabel(answer.totals.costUsd, answer.unpriced) }}</span>
        <span class="meta">· {{ answer.totals.calls }} calls · {{ tokens(answer.totals.totalTokens) }} tokens</span>
      </p>

      <p v-if="answer.reason" class="meta" data-testid="usage-reason">{{ answer.reason }}</p>

      <p class="retention" data-testid="usage-retention">
        Sessions kept {{ answer.retentionDays }} day{{ answer.retentionDays === 1 ? '' : 's' }}
        <a v-if="retentionWritable" class="link" href="#/settings" data-testid="usage-settings-link">adjust in Settings</a>
      </p>

      <section v-if="answer.days.length" class="grid" data-testid="usage-day-grid">
        <table>
          <thead>
            <tr><th>day</th><th>model</th><th>calls</th><th>tokens</th><th>spend</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in answer.days" :key="dayKey(row)">
              <td>{{ row.day }}</td>
              <td>{{ row.model ?? '—' }}</td>
              <td>{{ row.calls }}</td>
              <td>{{ tokens(row.totalTokens) }}</td>
              <td>{{ spendLabel(row.costUsd, row.unpriced) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section v-if="answer.models.length" class="models" data-testid="usage-model-table">
        <table>
          <thead>
            <tr><th>model</th><th>calls</th><th>tokens</th><th>spend</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in answer.models" :key="modelKey(row)">
              <td>{{ row.model ?? '—' }}</td>
              <td>{{ row.calls }}</td>
              <td>{{ tokens(row.totalTokens) }}</td>
              <td>{{ spendLabel(row.costUsd, row.unpriced) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <p v-if="!answer.days.length && !answer.models.length && !answer.reason" class="empty" data-testid="usage-empty">No calls on this ledger yet.</p>
    </template>
  </div>
</template>

<style scoped>
.usage-panel { display: flex; flex-direction: column; gap: 8px; font-size: 12px; }
.usage-panel .head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.usage-panel .head .count { font-weight: 600; }
.usage-panel .scopes, .usage-panel .windows { display: inline-flex; gap: 4px; }
.usage-panel .link { background: none; border: none; color: var(--accent, #7aa2f7); cursor: pointer; padding: 0; font: inherit; }
.usage-panel .link[disabled] { opacity: 0.5; cursor: default; }
.usage-panel .link.on { text-decoration: underline; }
.usage-panel .totals { margin: 0; }
.usage-panel .totals .meta { color: var(--muted, #888); }
.usage-panel .meta { color: var(--muted, #888); }
.usage-panel .empty { color: var(--muted, #888); font-style: italic; margin: 0; }
.usage-panel .error { color: var(--danger, #f7768e); margin: 0; }
.usage-panel table { width: 100%; border-collapse: collapse; }
.usage-panel th, .usage-panel td { text-align: left; padding: 2px 6px; border-bottom: 1px solid var(--line, #33393f); }
</style>
