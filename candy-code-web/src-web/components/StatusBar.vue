<script setup lang="ts">
import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
import { spendLabel, tokens } from '../lib/format'
import { t } from '../lib/i18n'
import type { MethodResult, PermissionMode, SessionSummary } from '../protocol/generated'
import { useConnectionStore } from '../stores/connection'
import { useContextSourcesStore } from '../stores/contextSources'
import { usePanelsStore } from '../stores/agents/panels'
import { useAgentsStore } from '../stores/agents/agents'
import type { SessionState } from '../stores/reducer'

const MODES: PermissionMode[] = ['default', 'accept-edits', 'plan', 'auto', 'dont-ask', 'bypass-permissions']

const props = defineProps<{
  state: SessionState
  summary?: SessionSummary
  contextPct: number | null
  connection: string
}>()
const emit = defineEmits<{ setMode: [mode: PermissionMode]; runCommand: [text: string] }>()

/**
 * WEB-2: the served session runs /compact and /model on the host now (the
 * runsIn marker admits them), so the status bar carries both as controls.
 * The provider list rides `server.info` — the same read-scoped inventory
 * the About overlay shows — and a failed or empty fetch simply leaves the
 * model a plain span: the select is progressive enhancement, never a
 * second source of truth about what is selected (the session summary is).
 */
const socket = useConnectionStore()

/**
 * m23: session.setMode is write-scoped server-side (Dispatcher::requireScope).
 * The switcher disappears only when the server has explicitly announced a
 * principal without the write scope (a viewer grant); before the hello
 * handshake nothing is known yet, so the chrome keeps its prior shape.
 */
const canSetMode = computed(() => !socket.hello || socket.scopes.includes('write'))
// C-SEC-4: the trust prompts can arrive before any panel measured; the shelf
// in the context store keeps them, and the badge sends the user to the panel
// that answers them. C-SEC-5.b: the shelf is a list — the badge COUNTS the
// rows still awaiting their answer (one per pending grant door).
const context = useContextSourcesStore()
const panels = usePanelsStore()
const trustAwaiting = computed(() => context.pendingTrusts.filter((row) => row.status === 'asked').length)
const providers = ref<string[]>([])

onMounted(async () => {
  try {
    const info = await socket.request('server.info', {})
    providers.value = (info as MethodResult<'server.info'>).providers ?? []
  } catch {
    providers.value = []
  }
})

const currentProvider = computed(() => props.summary?.provider || '')
const selectableProviders = computed(() => {
  if (providers.value.length === 0) return []
  const names = new Set(providers.value)
  if (currentProvider.value !== '' && !names.has(currentProvider.value)) names.add(currentProvider.value)
  return [...names].sort()
})
/** The current entry keeps its exact model; switching to another provider takes that provider's configured default. */
function modelOptionValue(provider: string): string {
  const model = props.summary?.model || ''
  return provider === currentProvider.value && model !== '' ? `${provider} ${model}` : provider
}
function modelOptionLabel(provider: string): string {
  return provider === currentProvider.value ? model.value : provider
}
function pickModel(event: Event): void {
  const select = event.target as HTMLSelectElement
  const value = select.value
  if (value !== '' && value !== modelOptionValue(currentProvider.value)) emit('runCommand', `/model ${value}`)
  // C-WEB-1.b: a pick is a REQUEST the session has not granted yet — while the
  // switch is pending, or if it is refused, the summary keeps naming the old
  // entry. The bound :value did not change, so Vue never repaints the element
  // and the select would advertise a model that is not in use. Pull the DOM
  // back to the summary's entry; the select repaints when the summary moves.
  select.value = modelOptionValue(currentProvider.value)
}

/**
 * C-AGT-4.c: the same three laws for the session's primary agent. The choice
 * is summary state (`agent`, announced by the switch itself), the offer is
 * the server's own roster filtered by declaring mode — exactly what the TUI's
 * `/agent` cycle presents — and the pick sends `/agent use <name>` through
 * the command lane, never a second source of truth. No roster and no choice:
 * the control is hidden, the default agent the session already runs staying
 * nameless; unlike the model, it has no pair to label.
 */
const agents = useAgentsStore()
onMounted(() => {
  void agents.refreshCatalogue()
})
const currentAgent = computed(() => props.summary?.agent ?? '')
const selectableAgents = computed(() => {
  const names = [...agents.primaryRoster]
  if (currentAgent.value !== '' && !names.includes(currentAgent.value)) names.push(currentAgent.value)
  return names.sort()
})
const agentLabel = computed(() =>
  currentAgent.value === '' ? t('web.chrome.status.agent_default') : t('web.chrome.status.agent_title', { agent: currentAgent.value }),
)
function pickAgent(event: Event): void {
  const select = event.target as HTMLSelectElement
  const value = select.value
  if (value !== '' && value !== currentAgent.value) emit('runCommand', `/agent use ${value}`)
  // The C-WEB-1.b pending-pick law: the element repaints only when the
  // summary actually moves.
  select.value = currentAgent.value
}

/**
 * C-WEB-4.d (GAP-UI-31): the elapsed read-out for a running turn. The
 * reducer stamps `turnStartedAt` from the `turn.started` envelope; this
 * ref advances on a 1 s interval that exists ONLY while there is something
 * to count — busy and a start observed. A snapshot-resumed mid-turn session
 * has no start, so no interval ever arms and the read-out stays hidden
 * rather than guessing. The interval is cleared on idle and on unmount.
 */
const tickNow = ref(0)
let tickTimer: ReturnType<typeof setInterval> | null = null
function stopTick(): void {
  if (tickTimer === null) return
  clearInterval(tickTimer)
  tickTimer = null
}
watch(
  () => props.state.status === 'busy' && props.state.turnStartedAt !== null,
  (counting) => {
    stopTick()
    if (!counting) return
    tickNow.value = Date.now()
    tickTimer = setInterval(() => {
      tickNow.value = Date.now()
    }, 1000)
  },
  { immediate: true },
)
onScopeDispose(stopTick)

const elapsedLabel = computed(() => {
  const start = props.state.turnStartedAt
  if (props.state.status !== 'busy' || start === null) return null
  // Server clock ahead of ours (skew): clamp at 0:00 until real time catches up; behind reads inflated by the skew.
  const seconds = Math.max(0, Math.floor((tickNow.value - start) / 1000))
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
})

const statusText = computed(() => {
  switch (props.state.status) {
    case 'busy': {
      const bits = [t('web.chrome.status.working')]
      const elapsed = elapsedLabel.value
      if (elapsed !== null) bits.push(elapsed)
      if (props.state.maxSteps > 0) bits.push(t('web.chrome.status.step', { step: props.state.step, max: props.state.maxSteps }))
      return bits.join(' · ')
    }
    case 'waiting_permission':
      return t('web.chrome.status.waiting')
    default:
      return t('web.chrome.status.idle')
  }
})
const spent = computed(() => props.state.spentUsd + (props.state.turnId !== null ? props.state.turnUsage?.costUsd ?? 0 : 0))
const model = computed(() => {
  const provider = props.summary?.provider || ''
  const name = props.summary?.model || ''
  return [provider, name].filter((part) => part !== '').join(' · ') || t('web.chrome.status.model_default')
})

/**
 * P-CTX.g (GAP-MOD-15w): the cache read-out, spelled exactly as the TUI
 * status line computes it (Renderer.php) — `read / prompt`, where prompt is
 * the three-bucket identity `cacheRead + cacheCreation + input`, or the
 * OpenAI-shaped `read + input` alone when the provider reports no
 * cache-write bucket at all. An unreported bucket is never a zero: any leg
 * null, the span vanishes rather than advertising 0%.
 */
const cachePct = computed<number | null>(() => {
  const usage = props.state.turnUsage
  const read = usage?.cacheReadTokens ?? null
  const input = usage?.inputTokens ?? null
  if (read === null || input === null) return null
  const creation = usage?.cacheCreationTokens ?? null
  const prompt = creation === null ? read + input : read + input + creation
  if (prompt <= 0) return null
  return Math.round((read / prompt) * 100)
})

/**
 * CW-SEC-03 (handoff per rv1103/g4d): the PR url is server-shaped data
 * landing on a live `href`. Only an absolute http(s) target renders as a
 * link — anything else (javascript:, data:, ftp:, a bare relative string)
 * keeps the identical chip as plain text, so no click can hand a
 * script-bearing scheme to the browser.
 */
const prLinkable = computed(() => /^https?:\/\//i.test(props.summary?.pr?.url ?? ''))
</script>

<template>
  <footer class="status" data-testid="status-bar" :data-status="state.status">
    <span class="item state" :class="state.status">{{ statusText }}</span>
    <button v-if="trustAwaiting" type="button" class="item action" data-testid="trust-badge" :title="trustAwaiting === 1 ? t('web.chrome.status.trust_title') : t('web.chrome.status.trust_title_count', { n: trustAwaiting })" @click="panels.show('context')">{{ trustAwaiting === 1 ? t('web.chrome.status.trust') : t('web.chrome.status.trust_count', { n: trustAwaiting }) }}</button>
    <button type="button" class="item action numbers" data-testid="context-meter" :title="t('web.chrome.status.context_title')" @click="panels.show('context')">
      {{ t('web.chrome.status.tokens', { tok: tokens(state.contextTokens) }) }}<template v-if="contextPct !== null"> · {{ contextPct }}%</template>
      <span v-if="contextPct !== null" class="meter" aria-hidden="true"><span :style="{ width: `${contextPct}%` }" /></span>
      <span v-if="cachePct !== null" class="cache" data-testid="status-cache">{{ t('web.chrome.status.cache', { n: cachePct }) }}</span>
    </button>
    <span class="item numbers" :title="state.unpriced ? t('web.chrome.status.spend_unpriced') : t('web.chrome.status.spend')" :data-unpriced="state.unpriced ? 'true' : 'false'" data-testid="spend">{{ spendLabel(spent, state.unpriced) }}</span>
    <label v-if="selectableProviders.length > 0" class="item" :title="model">
      <span class="sr-only">{{ t('web.chrome.status.model') }}</span>
      <select :value="modelOptionValue(currentProvider)" data-testid="model-select" @change="pickModel">
        <option v-if="currentProvider === ''" value="">{{ t('web.chrome.status.model_default') }}</option>
        <option v-for="provider in selectableProviders" :key="provider" :value="modelOptionValue(provider)">{{ modelOptionLabel(provider) }}</option>
      </select>
    </label>
    <span v-else class="item model" :title="model">{{ model }}</span>
    <label v-if="selectableAgents.length > 0" class="item" :title="agentLabel">
      <span class="sr-only">{{ t('web.chrome.status.agent') }}</span>
      <select :value="currentAgent" data-testid="agent-select" @change="pickAgent">
        <option v-if="currentAgent === ''" value="">{{ t('web.chrome.status.agent_default') }}</option>
        <option v-for="name in selectableAgents" :key="name" :value="name">{{ name }}</option>
      </select>
    </label>
    <!-- C-WEB-4.f (GAP-GIT-01): the branch the session was OPENED on — the
         summary field is read off the row, never off a live `git`, so the
         tooltip says what it is: a start-state fact an agent's `git checkout`
         cannot silently outdate. Chip only when the server named one. -->
    <span v-if="summary?.branch" class="item branch" data-testid="branch-chip" :title="t('web.chrome.status.branch_title', { branch: summary.branch })">⎇ {{ summary.branch }}</span>
    <!-- C-AGT-6.e (R13): the open PR for that branch with a CI dot, served
         from the server's cached `gh pr view` refresh path — the chip never
         fetches and appears only once the cache holds an answer.
         CW-SEC-03 (handoff per rv1103/g4d): the anchor half requires an
         absolute http(s) url; a non-web scheme renders the same chip as
         plain text so no href ever carries javascript:, data:, or kin. -->
    <a
      v-if="summary?.pr && prLinkable"
      class="item pr"
      data-testid="pr-chip"
      :href="summary.pr.url"
      target="_blank"
      rel="noopener noreferrer"
      :title="t('web.chrome.status.pr_title', { number: summary.pr.number, state: summary.pr.state, checks: summary.pr.checks })"
    >#{{ summary.pr.number }}<span class="pr-dot" :class="`is-${summary.pr.checks}`" data-testid="pr-dot" aria-hidden="true" /><span class="sr-only">{{ summary.pr.state }} · {{ summary.pr.checks }}</span></a>
    <span
      v-else-if="summary?.pr"
      class="item pr"
      data-testid="pr-chip"
      :title="t('web.chrome.status.pr_title', { number: summary.pr.number, state: summary.pr.state, checks: summary.pr.checks })"
    >#{{ summary.pr.number }}<span class="pr-dot" :class="`is-${summary.pr.checks}`" data-testid="pr-dot" aria-hidden="true" /><span class="sr-only">{{ summary.pr.state }} · {{ summary.pr.checks }}</span></span>
    <button
      type="button"
      class="item action"
      data-testid="compact-button"
      :title="t('web.chrome.status.compact_title')"
      :disabled="state.status === 'busy'"
      @click="emit('runCommand', '/compact')"
    >{{ t('web.chrome.status.compact') }}</button>
    <label v-if="canSetMode" class="item">
      <span class="sr-only">{{ t('web.chrome.status.mode_aria') }}</span>
      <select :value="state.permissionMode ?? 'default'" data-testid="mode-select" @change="emit('setMode', ($event.target as HTMLSelectElement).value as PermissionMode)">
        <option v-for="mode in MODES" :key="mode" :value="mode">{{ t('web.chrome.status.mode', { mode }) }}</option>
      </select>
    </label>
    <!-- m23: without the write scope the server honestly refuses the change, so the
         switcher is not offered — a plain read-out of the current mode stands in,
         mirroring the model/provider chrome above. -->
    <span v-else class="item mode" data-testid="mode-state">{{
      t('web.chrome.status.mode', { mode: state.permissionMode ?? 'default' })
    }}</span>
    <span class="item conn">{{ connection }}</span>
  </footer>
</template>

<style scoped>
.status {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 1rem;
  align-items: center;
  font-size: 0.8125rem;
  color: var(--muted);
  padding: 0.35rem 0;
}
.item {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  white-space: nowrap;
}
.state.busy {
  color: var(--accent);
}
.state.waiting_permission {
  color: var(--warn);
}
.model {
  max-width: 16rem;
  overflow: hidden;
  text-overflow: ellipsis;
}
a.item.pr {
  color: inherit;
  text-decoration: none;
}
a.item.pr:hover {
  text-decoration: underline;
}
.pr-dot {
  display: inline-block;
  width: 0.5em;
  height: 0.5em;
  border-radius: 50%;
}
.pr-dot.is-pass {
  background: var(--ok);
}
.pr-dot.is-fail {
  background: var(--error);
}
.pr-dot.is-pending {
  background: var(--warn);
}
.meter {
  display: inline-block;
  width: 4rem;
  height: 0.4rem;
  border: 1px solid var(--line);
  border-radius: 2px;
  overflow: hidden;
}
.meter span {
  display: block;
  height: 100%;
  background: var(--accent);
}
select {
  font: inherit;
  color: inherit;
  background: transparent;
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 0.1rem 0.25rem;
  min-height: 1.5rem; /* UX-15: keep the hit target at the 24px floor */
}
button.action {
  font: inherit;
  color: inherit;
  background: transparent;
  border: 1px solid var(--line);
  border-radius: 4px;
  padding: 0.1rem 0.4rem;
  cursor: pointer;
}
button.action:disabled {
  color: var(--muted);
  cursor: default;
  opacity: 0.55;
}
.conn {
  margin-left: auto;
}
.numbers {
  font-variant-numeric: tabular-nums;
}
</style>
