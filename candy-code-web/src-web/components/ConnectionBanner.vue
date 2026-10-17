<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useConnectionStore } from '../stores/connection'

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
  <div v-if="connection.serverChanged" class="banner info" role="status" data-testid="banner-updated">
    The server was updated.
    <button type="button" @click="reload">Reload</button>
  </div>
  <div v-else-if="connection.status === 'reconnecting'" class="banner warn" role="status" data-testid="banner-reconnecting">
    Connection lost<template v-if="connection.lastError"> ({{ connection.lastError }})</template>.
    <template v-if="retryIn !== null">Retrying in {{ retryIn }} s.</template>
    <button type="button" @click="connection.retryNow()">Retry now</button>
  </div>
  <div v-else-if="connection.status === 'stopped' && connection.lastError" class="banner error" role="alert">
    {{ connection.lastError }}
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
