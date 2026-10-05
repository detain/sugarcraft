<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PermissionCard, { type Reply } from '../PermissionCard.vue'
import { useApprovalsStore, type SessionAsk } from '../../stores/approvals'
import { useConnectionStore } from '../../stores/connection'
import { useLayoutStore } from '../../stores/layout'
import { describe } from '../../stores/session'
import { useSessionsStore } from '../../stores/sessions'
import { useSettingsStore } from '../../stores/settings'
import { sessionLabel } from './attention'

/**
 * The cross-session approvals drawer (Appendix O §7.4): every question open
 * in any session of this server, oldest first, grouped by session, each
 * answerable right here — Allow once / Always / Reject / Reject & stop, or
 * `y` / `a` / `n` on a focused card. The first answer from any client wins;
 * a question answered elsewhere leaves the drawer by itself.
 */
const approvals = useApprovalsStore()
const sessions = useSessionsStore()
const connection = useConnectionStore()
const layout = useLayoutStore()
const settings = useSettingsStore()
const router = useRouter()
const panel = ref<HTMLElement | null>(null)
const error = ref<string | null>(null)
// A failed answer remounts its card so its buttons come back.
const attempts = ref<Record<string, number>>({})

const canAnswer = computed(() => connection.scopes.includes('approve'))
const notificationsAvailable = typeof Notification !== 'undefined'

async function answer(ask: SessionAsk, reply: Reply, cascade: boolean): Promise<void> {
  error.value = null
  try {
    await approvals.respond(ask, reply, cascade ? { cascade: true } : {})
  } catch (failure) {
    error.value = describe(failure)
    attempts.value = { ...attempts.value, [ask.askId]: (attempts.value[ask.askId] ?? 0) + 1 }
  }
}

function open(sessionId: string): void {
  layout.toggleApprovals(false)
  void router.push({ name: 'session', params: { id: sessionId } })
}

function close(): void {
  layout.toggleApprovals(false)
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape' && layout.approvalsOpen) close()
}

// Opening the drawer puts the keyboard on the first question.
watch(() => layout.approvalsOpen, async (opened) => {
  if (!opened) return
  await nextTick()
  const first = panel.value?.querySelector<HTMLElement>('[data-testid="permission-card"]')
  ;(first ?? panel.value)?.focus()
})

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <aside
    v-if="layout.approvalsOpen"
    id="approvals-drawer"
    ref="panel"
    class="drawer"
    role="dialog"
    aria-label="Approvals"
    tabindex="-1"
    data-testid="approvals-drawer"
  >
    <header>
      <h2>Approvals <span class="count">{{ approvals.total }}</span></h2>
      <button
        v-if="notificationsAvailable && !settings.notify"
        type="button"
        class="link"
        title="Notify me when a question arrives or a turn finishes out of sight"
        data-testid="approvals-notify"
        @click="settings.enableNotifications()"
      >
        notify me
      </button>
      <button type="button" class="link" aria-label="Close the approvals drawer" data-testid="approvals-close" @click="close">&#10005;</button>
    </header>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-if="approvals.total === 0" class="empty" data-testid="approvals-empty">No questions are waiting.</p>
    <section v-for="group in approvals.bySession" :key="group.sessionId" class="group" data-testid="approvals-group" :data-session-id="group.sessionId">
      <h3>
        <a :href="`#/s/${group.sessionId}`" @click.prevent="open(group.sessionId)">{{ sessionLabel(sessions.byId[group.sessionId], group.sessionId) }}</a>
        <span class="n">{{ group.asks.length }}</span>
      </h3>
      <PermissionCard
        v-for="ask in group.asks"
        :key="`${ask.askId}:${attempts[ask.askId] ?? 0}`"
        :ask="ask"
        :can-answer="canAnswer"
        @answer="(reply, cascade) => answer(ask, reply, cascade)"
      />
    </section>
  </aside>
</template>

<style scoped>
.drawer {
  position: fixed;
  inset: 0 0 0 auto;
  width: min(28rem, 100vw);
  z-index: 30;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.75rem 1rem 1rem;
  overflow-y: auto;
  background: var(--bg);
  border-left: 1px solid var(--line);
  box-shadow: 0 0 1.5rem rgb(0 0 0 / 0.35);
}
.drawer:focus {
  outline: none;
}
header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
h2 {
  flex: 1;
  margin: 0;
  font-size: 1rem;
}
.count,
.n {
  display: inline-block;
  min-width: 1.4rem;
  text-align: center;
  border-radius: 999px;
  padding: 0 0.4rem;
  font-size: 0.75rem;
  background: var(--warn);
  color: var(--bg);
}
h3 {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0.5rem 0 0.4rem;
  font-size: 0.875rem;
  min-width: 0;
}
h3 a {
  color: var(--accent);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.empty {
  margin: 1rem 0;
  color: var(--muted);
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
</style>
