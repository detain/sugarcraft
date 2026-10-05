<script setup lang="ts">
import { computed, inject, onBeforeUnmount, onMounted, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import ApprovalsButton from './components/approvals/ApprovalsButton.vue'
import ApprovalsDrawer from './components/approvals/ApprovalsDrawer.vue'
import { useAttention } from './components/approvals/attention'
import ConnectionBanner from './components/ConnectionBanner.vue'
import SessionTabs from './components/grid/SessionTabs.vue'
import SessionSidebar from './components/SessionSidebar.vue'
import { SIGN_IN_CODE } from './keys'
import { AuthError } from './protocol/auth'
import { useConnectionStore } from './stores/connection'
import { useLayoutStore } from './stores/layout'
import { useSessionsStore } from './stores/sessions'
import { useSettingsStore } from './stores/settings'

const connection = useConnectionStore()
// Created up front so they hear the first handshake and every event after it.
useSessionsStore()
const layout = useLayoutStore()
const settings = useSettingsStore()
const route = useRoute()
const router = useRouter()
const code = inject(SIGN_IN_CODE, null)

const activeId = computed(() => (route.name === 'session' ? String(route.params.id) : null))
const onLogin = computed(() => route.name === 'login')
const rtt = computed(() => (connection.connected && connection.rttMs !== null ? `${connection.rttMs} ms` : ''))

watch(() => connection.status, (status) => {
  if (status === 'signed-out' && !onLogin.value) void router.push({ name: 'login' })
})

// The attention model: the tab title, and desktop notifications for questions
// and finished turns out of sight.
const stopAttention = useAttention()

function onPageHide(): void {
  connection.stop()
}

function onPageShow(event: PageTransitionEvent): void {
  if (event.persisted) connection.start()
}

onMounted(async () => {
  window.addEventListener('pagehide', onPageHide)
  window.addEventListener('pageshow', onPageShow)
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
  stopAttention()
})

async function signOut(): Promise<void> {
  await connection.signOut()
  void router.push({ name: 'login' })
}
</script>

<template>
  <div class="shell" :class="{ 'drawer-open': layout.sidebarOpen }">
    <header class="topbar">
      <button v-if="!onLogin" type="button" class="link menu" aria-label="Sessions" @click="layout.toggleSidebar()">☰</button>
      <span class="brand">&#9670; SugarCrush</span>
      <span v-if="connection.hello?.server.root" class="root" :title="connection.hello.server.root">{{ connection.hello.server.root }}</span>
      <span class="spacer" />
      <ApprovalsButton v-if="!onLogin" />
      <span class="status" :data-status="connection.status" data-testid="connection-status">{{ connection.statusLabel }}<template v-if="rtt"> · {{ rtt }}</template></span>
      <RouterLink v-if="!onLogin" :to="{ name: 'settings' }" class="nav" data-testid="settings-link">settings</RouterLink>
      <button type="button" class="link opt" :title="`Theme: ${settings.theme}`" @click="settings.cycleTheme()">theme: {{ settings.theme }}</button>
      <button v-if="!settings.notify" type="button" class="link opt" title="Desktop notifications for questions" @click="settings.enableNotifications()">notify</button>
      <button v-if="connection.status !== 'signed-out'" type="button" class="link" @click="signOut">sign out</button>
    </header>
    <ConnectionBanner />
    <div class="body">
      <SessionSidebar v-if="!onLogin" class="side" :active-id="activeId" />
      <main class="content">
        <SessionTabs v-if="!onLogin" />
        <RouterView />
      </main>
    </div>
    <ApprovalsDrawer v-if="!onLogin" />
  </div>
</template>

<style scoped>
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
@media (max-width: 760px) {
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
}
</style>
