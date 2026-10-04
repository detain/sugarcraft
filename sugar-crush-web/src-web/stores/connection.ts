import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

export type ConnectionStatus = 'disconnected' | 'connecting' | 'connected'

const LABELS: Record<ConnectionStatus, string> = {
  disconnected: 'not connected',
  connecting: 'connecting…',
  connected: 'connected',
}

// Scaffold only: the protocol client (O-5b) owns the transitions. Until the
// server speaks `sugarcrush.v1` this store stays `disconnected`.
export const useConnectionStore = defineStore('connection', () => {
  const status = ref<ConnectionStatus>('disconnected')
  const statusLabel = computed(() => LABELS[status.value])

  function setStatus(next: ConnectionStatus): void {
    status.value = next
  }

  return { status, statusLabel, setStatus }
})
