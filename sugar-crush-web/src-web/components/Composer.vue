<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { CommandInfo, Delivery } from '../stores/session'

const props = defineProps<{
  modelValue: string
  busy: boolean
  disabled?: boolean
  commands: CommandInfo[]
  /** The delivery offered first while a turn runs — the server's `queueMode` (`steer` unless set). */
  defaultDelivery?: Delivery
}>()
const emit = defineEmits<{
  'update:modelValue': [value: string]
  send: [text: string, delivery: Delivery]
  stop: []
}>()

const initialDelivery = (): Delivery => props.defaultDelivery ?? 'steer'
const delivery = ref<Delivery>(initialDelivery())
const selected = ref(0)
const input = ref<HTMLTextAreaElement | null>(null)
let lastEscape = 0

// While a turn runs the default is the server's `queueMode` — what the TUI's
// Enter does mid-turn (`steer` unless set); idle, a prompt just starts a turn.
watch(() => props.busy, (busy) => {
  if (!busy) delivery.value = initialDelivery()
})
watch(() => props.defaultDelivery, () => {
  if (!props.busy) delivery.value = initialDelivery()
})

/** `/` completion: the commands that can run here (server built-ins and command files). */
const suggestions = computed(() => {
  const match = /^\/([^\s]*)$/.exec(props.modelValue)
  if (!match) return []
  const prefix = (match[1] ?? '').toLowerCase()
  return props.commands
    .filter((c) => c.runsIn === 'server' || c.source === 'file')
    .filter((c) => c.name.replace(/^\//, '').toLowerCase().startsWith(prefix))
    .slice(0, 8)
})

watch(suggestions, () => {
  selected.value = 0
})

function complete(command: CommandInfo): void {
  emit('update:modelValue', `/${command.name.replace(/^\//, '')} `)
  input.value?.focus()
}

function submit(): void {
  const text = props.modelValue.trim()
  if (text === '' || props.disabled) return
  emit('send', text, props.busy ? delivery.value : initialDelivery())
}

function onKeydown(event: KeyboardEvent): void {
  if (suggestions.value.length > 0 && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
    event.preventDefault()
    const n = suggestions.value.length
    selected.value = (selected.value + (event.key === 'ArrowDown' ? 1 : n - 1)) % n
    return
  }
  if (suggestions.value.length > 0 && event.key === 'Tab') {
    event.preventDefault()
    const pick = suggestions.value[selected.value]
    if (pick) complete(pick)
    return
  }
  if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
    event.preventDefault()
    submit()
    return
  }
  if (event.key === 'Escape') {
    // Esc Esc stops the running turn, as in the TUI.
    const now = Date.now()
    if (props.busy && now - lastEscape < 600) {
      lastEscape = 0
      emit('stop')
    } else {
      lastEscape = now
    }
  }
}

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <div class="composer">
    <ul v-if="suggestions.length > 0" class="suggest" role="listbox" data-testid="command-suggestions">
      <li
        v-for="(command, i) in suggestions"
        :key="command.name"
        role="option"
        :aria-selected="i === selected"
        :class="{ on: i === selected }"
        @mousedown.prevent="complete(command)"
      >
        <span class="cmd">/{{ command.name.replace(/^\//, '') }}</span>
        <span v-if="command.argumentHint" class="hint">{{ command.argumentHint }}</span>
        <span class="desc">{{ command.description }}</span>
      </li>
    </ul>
    <textarea
      ref="input"
      :value="modelValue"
      rows="3"
      :disabled="disabled"
      :placeholder="busy ? 'Type to queue, steer or interrupt…' : 'Message SugarCrush… (Enter sends, Shift+Enter for a new line, / for commands)'"
      aria-label="Message"
      data-testid="composer-input"
      @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
      @keydown="onKeydown"
    />
    <div class="controls">
      <select v-if="busy" v-model="delivery" aria-label="Delivery" data-testid="composer-delivery">
        <option value="queue">queue</option>
        <option value="steer">steer</option>
        <option value="interrupt">interrupt</option>
      </select>
      <button type="button" class="primary" :disabled="disabled || modelValue.trim() === ''" data-testid="composer-send" @click="submit">
        {{ busy ? (delivery === 'queue' ? 'Queue' : delivery === 'steer' ? 'Steer' : 'Interrupt') : 'Send' }}
      </button>
      <button v-if="busy" type="button" class="danger" data-testid="composer-stop" title="Stop the turn (Esc Esc)" @click="emit('stop')">Stop</button>
    </div>
  </div>
</template>

<style scoped>
.composer {
  position: relative;
  display: flex;
  gap: 0.5rem;
  align-items: flex-end;
}
textarea {
  flex: 1;
  min-width: 0;
  resize: vertical;
  min-height: 3.2rem;
  max-height: 40vh;
  padding: 0.5rem 0.6rem;
  font: inherit;
  color: var(--text);
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 6px;
}
textarea:focus {
  outline: 2px solid var(--accent);
  outline-offset: -1px;
}
.controls {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.suggest {
  position: absolute;
  left: 0;
  bottom: 100%;
  margin: 0 0 0.25rem;
  padding: 0.25rem 0;
  list-style: none;
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 6px;
  max-width: 100%;
  z-index: 5;
}
.suggest li {
  display: flex;
  gap: 0.6rem;
  padding: 0.25rem 0.6rem;
  cursor: pointer;
  white-space: nowrap;
  overflow: hidden;
}
.suggest li.on {
  background: var(--line);
}
.cmd {
  font-family: var(--mono);
}
.hint,
.desc {
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
}
@media (max-width: 600px) {
  .composer {
    flex-direction: column;
    align-items: stretch;
  }
  .controls {
    flex-direction: row;
    justify-content: flex-end;
  }
}
</style>
