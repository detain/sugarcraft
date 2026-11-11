<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useConnectionStore } from '../stores/connection'
import { t } from '../lib/i18n'

const connection = useConnectionStore()
const now = ref(Date.now())
const timer = setInterval(() => {
  now.value = Date.now()
}, 500)
onBeforeUnmount(() => clearInterval(timer))

const retryIn = computed(() => (connection.retryAt === null ? null : Math.max(0, Math.ceil((connection.retryAt - now.value) / 1000))))

function reload(): void {
  location.reload()
}
</script>

<template>
  <div v-if="connection.shutdown !== null" class="banner error" role="alert" data-testid="banner-shutdown">
    {{ t('web.chrome.banner.stopping') }}<template v-if="connection.shutdown.reason !== ''">{{ t('web.chrome.banner.reason', { reason: connection.shutdown.reason }) }}</template>.
    <template v-if="connection.shutdown.graceSeconds > 0">{{ t('web.chrome.banner.drain', { n: connection.shutdown.graceSeconds }) }}</template>
    {{ t('web.chrome.banner.reconnect_note') }}
  </div>
  <div v-else-if="connection.serverChanged" class="banner info" role="status" data-testid="banner-updated">
    {{ t('web.chrome.banner.updated') }}
    <button type="button" @click="reload">{{ t('web.chrome.banner.reload') }}</button>
  </div>
  <div v-else-if="connection.status === 'reconnecting'" class="banner warn" role="status" data-testid="banner-reconnecting">
    {{ t('web.chrome.banner.lost') }}<template v-if="connection.lastError">{{ t('web.chrome.banner.lost_error', { lastError: connection.lastError }) }}</template>.
    <!-- m50: this region is an implicit aria-live=polite queue; the countdown
         ticks every second, so its node is aria-hidden decoration (house
         idiom) — sighted users still read the seconds, AT is not re-fired. -->
    <template v-if="retryIn !== null"><span data-testid="banner-retry-countdown" aria-hidden="true">{{ t('web.chrome.banner.retrying', { n: retryIn }) }}</span></template>
    <button type="button" @click="connection.retryNow()">{{ t('web.chrome.banner.retry_now') }}</button>
  </div>
  <div v-else-if="connection.exhausted" class="banner error" role="alert" data-testid="banner-offline">
    <template v-if="connection.lastError">{{ t('web.chrome.banner.error_period', { lastError: connection.lastError }) }}</template>
    <template v-else>{{ t('web.chrome.banner.no_answer') }}</template>
    {{ t('web.chrome.banner.stopped') }}
    <button type="button" @click="connection.retryNow()">{{ t('web.chrome.banner.try_again') }}</button>
  </div>
  <div v-else-if="connection.status === 'stopped' && connection.lastError" class="banner error" role="alert" data-testid="banner-stopped-error">
    {{ t('web.chrome.banner.stopped_error', { lastError: connection.lastError }) }}
  </div>
</template>

<style scoped>
.banner {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
  padding: 0.4rem 1rem;
  font-size: 0.875rem;
}
.warn {
  background: color-mix(in srgb, var(--warn) 20%, var(--bg));
}
.info {
  background: color-mix(in srgb, var(--accent) 20%, var(--bg));
}
.error {
  background: color-mix(in srgb, var(--error) 20%, var(--bg));
}
</style>
