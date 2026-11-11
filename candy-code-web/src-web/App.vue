<script setup lang="ts">
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import ApprovalsButton from './components/approvals/ApprovalsButton.vue'
import ApprovalsDrawer from './components/approvals/ApprovalsDrawer.vue'
import { useAttention } from './components/approvals/attention'
import AboutModal from './components/AboutModal.vue'
import ConnectionBanner from './components/ConnectionBanner.vue'
import DirectoryPicker from './components/DirectoryPicker.vue'
import HelpOverlay from './components/HelpOverlay.vue'
import SessionTabs from './components/grid/SessionTabs.vue'
import SessionSidebar from './components/SessionSidebar.vue'
import { SIGN_IN_CODE } from './keys'
import { AuthError } from './protocol/auth'
import { usePanelsStore } from './stores/agents/panels'
import { useConnectionStore } from './stores/connection'
import { useLayoutStore } from './stores/layout'
import { useSessionStore } from './stores/session'
import { useSessionsStore } from './stores/sessions'
import { useLocaleStore } from './stores/locale'
import { NOTIFICATION_PROBLEM_TEXT, useSettingsStore } from './stores/settings'
import { usePushStore } from './stores/push'
import { useQuestionAnnouncements } from './lib/questions'
import { t } from './lib/i18n'
import { bindingFor, captureActive, matchesBinding } from './lib/keymap'
import { onPasteNotice } from './lib/paste'
import SessionTools from './components/panels/SessionTools.vue'

const connection = useConnectionStore()
// Created up front so they hear the first handshake and every event after it.
useSessionsStore()
// C-TUI-6.f: the locale store arms on the hello frame and fetches the chrome
// strings once — created up front like the sessions store so the very first
// handshake is heard.
useLocaleStore()
// CW-DBA-8: created up front so a persisted push consent is re-validated on
// boot even when the settings screen is never opened (never prompts; push.ts).
const push = usePushStore()
const layout = useLayoutStore()
const panels = usePanelsStore()
const settings = useSettingsStore()
const route = useRoute()
const router = useRouter()
const code = inject(SIGN_IN_CODE, null)

const activeId = computed(() => (route.name === 'session' ? String(route.params.id) : null))
const onLogin = computed(() => route.name === 'login')
// G5-G: the token panel is owner chrome — admin scope, and a viewer-kind
// principal never sees it even if a mis-scoped hello ever granted admin.
const tokensVisible = computed(() => connection.scopes.includes('admin') && connection.hello?.principal.kind !== 'viewer')
/** WEB-1c: the About overlay (`server.info` + `server.health`). */
const aboutOpen = ref(false)
/** C-WEB-4.b: the help overlay — `?` on an empty composer, or outside any field. */
const helpOpen = ref(false)
const helpCommands = computed(() => (activeId.value === null ? [] : useSessionStore(activeId.value).commands))
const rtt = computed(() => (connection.connected && connection.rttMs !== null ? `${connection.rttMs} ms` : ''))

watch(() => connection.status, (status) => {
  if (status === 'signed-out' && !onLogin.value) void router.push({ name: 'login' })
})

// The attention model: the tab title, and desktop notifications for questions
// and finished turns out of sight.
const stopAttention = useAttention()

// WEB-7a: questions announced in-page for screen readers (see lib/questions).
const announcedQuestion = useQuestionAnnouncements()

// NIT web58-1: a paste the reader had to skip says so, visibly (see lib/paste).
const pasteNotice = ref('')
const stopPasteNotice = onPasteNotice((notice) => {
  pasteNotice.value = notice
})

function onPageHide(): void {
  connection.stop()
}

/**
 * C-WEB-4.b: the help chord, heard in the capture phase so it answers from
 * anywhere. Inside a field it belongs to the typing — except the empty
 * composer, which is exactly where a lone `?` reads as "show me the keys"
 * (the TUI's rule). The rebind editor raises `captureActive()` while it
 * waits for a chord, and then this is deaf on purpose.
 */
function onHelpKey(event: KeyboardEvent): void {
  if (captureActive()) return
  if (helpOpen.value) {
    if (event.key === 'Escape' || matchesBinding(event, bindingFor('help'))) {
      event.preventDefault()
      helpOpen.value = false
    }
    return
  }
  if (!matchesBinding(event, bindingFor('help'))) return
  const node = event.target
  if (node instanceof HTMLElement) {
    const field = node.closest('input, textarea, select, [contenteditable="true"]')
    if (field !== null) {
      if (!(field instanceof HTMLTextAreaElement) || field.dataset.testid !== 'composer-input' || field.value !== '') return
    }
  }
  event.preventDefault()
  helpOpen.value = true
}

function onPageShow(event: PageTransitionEvent): void {
  if (event.persisted) connection.start()
}

/**
 * UX-A F4: at ≤760px the sidebar rides over the content as a drawer, and it
 * used to answer only the ☰ button. Escape now closes it the way the approvals
 * drawer answers the key — a plain window listener, because focus may sit
 * anywhere while the drawer is open. Two precedence guards keep it honest: an
 * event already preventDefault-ed belongs to whatever overlay answered first
 * (help/About on the capture leg, the useDialog spine on their panels), and a
 * key pressed inside a [role=dialog]/[role=menu] is the sidebar's own inner
 * surface closing itself — taking the drawer down with it would be a
 * double-close. The scrim under the drawer carries the click-away half.
 */
function onDrawerKey(event: KeyboardEvent): void {
  if (event.key !== 'Escape' || event.defaultPrevented) return
  if (!layout.sidebarOpen) return
  const node = event.target
  if (node instanceof Element && node.closest('[role="dialog"], [role="menu"]') !== null) return
  layout.toggleSidebar(false)
}

/**
 * CW-6: a notification click that found this window already open arrives as
 * a client message from the service worker (see sw.ts `notificationclick`).
 * The href was validated once in the worker; it is re-validated here because
 * a message is data, not authority — anything on the page can post one, and
 * only the session-route shape earns a navigation.
 */
function onPushNavigate(event: MessageEvent): void {
  const data = event.data as { type?: unknown; href?: unknown } | null
  if (data === null || typeof data !== 'object' || data.type !== 'navigate') return
  if (typeof data.href !== 'string' || !/^#\/s\/[A-Za-z0-9_-]{1,64}$/.test(data.href)) return
  void router.push(data.href.slice(1))
}

onMounted(async () => {
  window.addEventListener('pagehide', onPageHide)
  window.addEventListener('pageshow', onPageShow)
  window.addEventListener('keydown', onHelpKey, true)
  window.addEventListener('keydown', onDrawerKey)
  window.addEventListener('message', onPushNavigate)
  // CW-DBA-8: a stored ON is a promise, not a live subscription.
  if (push.consent) void push.reconcile()
  if (code !== null) {
    try {
      await connection.signIn({ code })
      return
    } catch (failure) {
      if (!(failure instanceof AuthError) || !failure.signedOut) throw failure
      // A spent or expired code: fall through to whatever cookie this browser holds.
    }
  }
  connection.start()
})

onBeforeUnmount(() => {
  window.removeEventListener('pagehide', onPageHide)
  window.removeEventListener('pageshow', onPageShow)
  window.removeEventListener('keydown', onHelpKey, true)
  window.removeEventListener('keydown', onDrawerKey)
  window.removeEventListener('message', onPushNavigate)
  stopAttention()
  stopPasteNotice()
})

async function signOut(): Promise<void> {
  await connection.signOut()
  void router.push({ name: 'login' })
}
</script>

<template>
  <div class="shell" :class="{ 'drawer-open': layout.sidebarOpen }">
    <header class="topbar">
      <button v-if="!onLogin" type="button" class="link menu" :aria-label="t('web.chrome.app.aria_sessions')" @click="layout.toggleSidebar()">☰</button>
      <span class="brand">&#9670; CandyCode</span>
      <span v-if="connection.hello?.server.root" class="root" :title="connection.hello.server.root">{{ connection.hello.server.root }}</span>
      <span class="spacer" />
      <ApprovalsButton v-if="!onLogin" />
      <span class="status" :data-status="connection.status" data-testid="connection-status">{{ connection.statusLabel }}<template v-if="rtt"> · {{ rtt }}</template></span>
      <RouterLink v-if="!onLogin" :to="{ name: 'settings' }" class="nav" data-testid="settings-link">{{ t('web.chrome.app.settings') }}</RouterLink>
      <RouterLink v-if="!onLogin && tokensVisible" :to="{ name: 'tokens' }" class="nav" data-testid="tokens-link">{{ t('web.chrome.app.tokens') }}</RouterLink>
      <button v-if="!onLogin" type="button" class="link" data-testid="about-button" @click="aboutOpen = true">{{ t('web.chrome.app.about') }}</button>
      <button v-if="connection.canGenerateImage || connection.canGenerateVideo" type="button" class="link" data-testid="generate-button" @click="panels.show('generate')">{{ t('web.chrome.app.generate') }}</button>
      <button type="button" class="link opt" :title="t('web.chrome.app.theme_title', { theme: settings.theme })" @click="settings.cycleTheme()">{{ t('web.chrome.app.theme', { theme: settings.theme }) }}</button>
      <button v-if="!settings.notify" type="button" class="link opt" :title="settings.notifyProblem ? NOTIFICATION_PROBLEM_TEXT[settings.notifyProblem] : t('web.chrome.app.notify_title')" @click="settings.enableNotifications()">{{ t('web.chrome.app.notify') }}</button>
      <span v-if="settings.notifyProblem" class="notify-problem" role="status" data-testid="notify-problem">{{ NOTIFICATION_PROBLEM_TEXT[settings.notifyProblem] }}</span>
      <button v-if="connection.status !== 'signed-out'" type="button" class="link" @click="signOut">{{ t('web.chrome.app.sign_out') }}</button>
    </header>
    <ConnectionBanner />
    <p class="sr-only" role="status" aria-live="polite" data-testid="question-announcement">{{ announcedQuestion }}</p>
    <p v-if="pasteNotice !== ''" class="paste-notice" role="status" aria-live="polite" data-testid="paste-notice">{{ pasteNotice }}</p>
    <SessionTools v-if="!onLogin" :active-id="activeId" />
    <div class="body">
      <SessionSidebar v-if="!onLogin" class="side" :active-id="activeId" />
      <div
        v-if="!onLogin && layout.sidebarOpen"
        class="drawer-scrim"
        aria-hidden="true"
        data-testid="drawer-scrim"
        @click="layout.toggleSidebar(false)"
      />
      <main class="content">
        <SessionTabs v-if="!onLogin" />
        <RouterView />
      </main>
    </div>
    <ApprovalsDrawer v-if="!onLogin" />
    <DirectoryPicker v-if="!onLogin" />
    <AboutModal :open="aboutOpen" @close="aboutOpen = false" />
    <HelpOverlay :open="helpOpen" :commands="helpCommands" @close="helpOpen = false" />
  </div>
</template>

<style scoped>
/* UX-12: the shared breakpoint scale for this app. CSS cannot interpolate
   custom properties inside @media, so the scale lives as a documented
   convention and every media query in src-web cites it:
     width axis  — narrow 600px · medium 760px · wide 1100px
     height axis — short 480px (modal family only, CW-UX-08 caps)
   A touched breakpoint MUST carry a `UX-12: <name>` comment. PaneGrid.vue's
   760 predates this lane's ownership and already matches medium. */
.shell {
  display: flex;
  flex-direction: column;
  height: 100vh;
  height: 100dvh;
}
.topbar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem 1rem;
  border-bottom: 1px solid var(--line);
  background: var(--panel);
  min-width: 0;
}
.brand {
  font-weight: 700;
  color: var(--accent);
  white-space: nowrap;
}
.root {
  font-family: var(--mono);
  font-size: 0.8125rem;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}
.spacer {
  flex: 1;
}
.notify-problem {
  color: var(--warn);
  font-size: var(--fs-small);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.paste-notice {
  margin: 0.25rem 1rem 0;
  color: var(--warn);
  font-size: var(--fs-small);
}

.status {
  font-size: 0.875rem;
  color: var(--muted);
  white-space: nowrap;
}
.status[data-status='connected'] {
  color: var(--ok);
}
.status[data-status='reconnecting'],
.status[data-status='signed-out'] {
  color: var(--warn);
}
.nav {
  font-size: 0.875rem;
  color: var(--accent);
  text-decoration: none;
  white-space: nowrap;
}
.nav.router-link-active {
  text-decoration: underline;
}
.menu {
  display: none;
}
.body {
  flex: 1;
  display: grid;
  grid-template-columns: 17rem 1fr;
  min-height: 0;
}
.side {
  border-right: 1px solid var(--line);
  background: var(--panel);
}
/* UX-A F4: the drawer's click-away catcher. Hidden at desktop widths where
   the sidebar is a static column — same base-then-media shape as .menu above.
   The scrim itself only rides the ≤760 media rule below. */
.drawer-scrim {
  display: none;
}
.content {
  min-width: 0;
  min-height: 0;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}
.content > :deep(*:not(.tabs-strip)) {
  flex: 1;
}
@media (max-width: 600px) {
  /* UX-12: narrow — was a second 480px step; the scale collapses the two
     sub-760 widths into one so topbar wrap and composer slimming switch
     together. ISS-105: phone widths. The spacer becomes a deliberate line
     break, so ☰+brand ride the first row and every action the second,
     instead of the bar pushing the page into horizontal scroll. */
  .topbar {
    flex-wrap: wrap;
    row-gap: 0.35rem;
  }
  .spacer {
    flex: 1 1 100%;
  }
  .status {
    white-space: normal;
    text-align: right;
  }
  .notify-problem {
    flex-basis: 100%;
    white-space: normal;
  }
}

@media (max-width: 760px) {
  /* UX-12: medium */
  .topbar {
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
  }
  .menu {
    display: inline-block;
  }
  .root,
  .opt {
    display: none;
  }
  .body {
    grid-template-columns: 1fr;
    position: relative;
  }
  .side {
    position: absolute;
    inset: 0 auto 0 0;
    width: min(20rem, 85vw);
    z-index: 10;
    transform: translateX(-100%);
    transition: transform 0.15s ease;
  }
  .drawer-open .side {
    transform: none;
    box-shadow: 0 0 1rem rgb(0 0 0 / 0.4);
  }
  /* UX-A F4: one rung under the drawer (its 10) in the same .body stacking
     context — the same tone the approvals drawer carries its shadow in. */
  .drawer-open .drawer-scrim {
    display: block;
    position: absolute;
    inset: 0;
    z-index: 9;
    background: rgb(0 0 0 / 0.35);
  }
}
</style>
