<script setup lang="ts">
import { useBackgroundStore } from '../../stores/agents/background'
import { useTodosStore } from '../../stores/agents/todos'
import { useWorkflowsStore } from '../../stores/agents/workflows'
import CommandPalette from './CommandPalette.vue'
import SidePanels from './SidePanels.vue'

/**
 * Everything roadmap O-6c adds around a session, mounted once by the shell:
 * the side panels and the command palette. Both are overlays, so the page
 * layout around them is unchanged. The stores that follow server-wide
 * events are created here, up front, so they hear the first handshake.
 */
defineProps<{ activeId: string | null }>()

useBackgroundStore()
useWorkflowsStore()
useTodosStore()
</script>

<template>
  <SidePanels :session-id="activeId" />
  <CommandPalette :active-id="activeId" />
</template>
