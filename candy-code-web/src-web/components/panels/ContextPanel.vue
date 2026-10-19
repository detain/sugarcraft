<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useConnectionStore } from '../../stores/connection'
import { useContextSourcesStore } from '../../stores/contextSources'

/**
 * WEB-3a: the session's context panel over `context.sources` — what the next
 * request is built from, as structure instead of `/context`'s text rows.
 *
 * SECURITY POSTURE: every server path, reason and detail renders through a
 * plain `{{ }}` span — no MessageMarkdown anywhere near it (the ChangesPanel
 * rule from WEB-1b: server-supplied strings are data, never markup). MCP
 * rows carry name and transport only; the server never sends the URL.
 *
 * WEB-3b: the trust prompt rides the same answer (`sources.trust`). Its
 * verdict buttons call `context.trust` on the Approve scope; the root and
 * reason stay plain spans under the same rule.
 */
const props = defineProps<{ sessionId: string }>()

const context = useContextSourcesStore()
const connection = useConnectionStore()

const canAnswer = computed(() => connection.scopes.includes('approve'))
const busy = ref(false)
const trustError = ref<string | null>(null)

async function answer(decision: 'grant' | 'reject', remember: 'session' | 'user'): Promise<void> {
  if (busy.value) return
  busy.value = true
  trustError.value = null
  try {
    const settled = await context.respondTrust(decision, remember)
    if (!settled) trustError.value = 'The prompt was settled elsewhere — reload to re-measure.'
  } catch (failure) {
    trustError.value = failure instanceof Error ? failure.message : String(failure)
  } finally {
    busy.value = false
  }
}

const bytes = ref(0)
watch(
  () => context.sources,
  (sources) => {
    bytes.value = sources?.instructionFiles?.reduce((total, file) => total + file.bytes, 0) ?? 0
  },
)

const share = computed(() => {
  const breakdown = context.sources?.breakdown
  if (!breakdown || breakdown.window <= 0) return null
  return Math.min(100, Math.round((breakdown.historyTokens / breakdown.window) * 100))
})

const loading = computed(() => context.loading && context.sessionId !== props.sessionId)

onMounted(() => void context.load(props.sessionId))
</script>

<template>
  <div class="context-panel" data-testid="context-panel">
    <div class="head">
      <span v-if="context.loaded && context.sessionId === sessionId" class="count" data-testid="context-count">context</span>
      <button type="button" class="link" data-testid="context-reload" :disabled="context.loading" @click="context.load(sessionId)">reload</button>
    </div>
    <p v-if="context.error" class="error" role="alert" data-testid="context-error">{{ context.error }}</p>
    <p v-else-if="loading" class="empty">Measuring the context…</p>
    <template v-else-if="context.loaded && context.sessionId === sessionId && context.sources">
      <section v-if="context.sources.breakdown" data-testid="context-breakdown">
        <p class="share">
          ~{{ context.sources.breakdown.historyTokens.toLocaleString() }} / {{ context.sources.breakdown.window.toLocaleString() }} context
          <template v-if="share !== null"> ({{ share }}%)</template>
        </p>
        <ul v-if="context.sources.breakdown.sections" class="layers" data-testid="context-layers">
          <li v-for="layer in context.sources.breakdown.sections" :key="layer.label">
            <span class="label">{{ layer.label }}</span>
            <span class="tokens">~{{ layer.tokens.toLocaleString() }}</span>
          </li>
        </ul>
        <p class="meta">
          tools: <template v-if="context.sources.breakdown.toolCount !== null">{{ context.sources.breakdown.toolCount }} (~{{ context.sources.breakdown.toolTokens?.toLocaleString() }} tokens)</template>
          <template v-else>not measured</template>
          · history: {{ context.sources.breakdown.historyMessages }} sent, {{ context.sources.breakdown.uiOnlyRows }} UI-only
          <template v-if="context.sources.breakdown.pruning"> · pruned ~{{ context.sources.breakdown.pruning.outputTokens.toLocaleString() }} tokens ({{ context.sources.breakdown.pruning.mode }})</template>
        </p>
      </section>
      <p v-else class="empty" data-testid="context-no-breakdown">The breakdown is not measured for this session.</p>

      <section data-testid="context-instruction-files">
        <h3>Instruction files</h3>
        <p v-if="context.sources.instructionFiles === null" class="empty">not measured</p>
        <p v-else-if="context.sources.instructionFiles.length === 0" class="empty">none</p>
        <ul v-else>
          <li v-for="file in context.sources.instructionFiles" :key="file.path">
            <span class="path" :data-testid="`context-file-${file.path}`">{{ file.path }}</span>
            <span class="bytes">{{ file.bytes.toLocaleString() }} B</span>
            <span v-if="file.tier === 'personal'" class="badge">personal</span>
            <span class="badge">{{ file.tier }}</span>
          </li>
        </ul>
        <p v-if="context.sources.instructionFiles && bytes > 0" class="meta">{{ bytes.toLocaleString() }} B spliced total</p>
      </section>

      <section v-if="context.sources.untrusted.length > 0" data-testid="context-untrusted">
        <h3>Not read</h3>
        <ul>
          <li v-for="entry in context.sources.untrusted" :key="entry.path">
            <span class="path">{{ entry.path }}</span> — <span class="reason">{{ entry.reason }}</span>
          </li>
        </ul>
      </section>

      <section data-testid="context-memory">
        <h3>Memory ({{ context.sources.memorySections.length }})</h3>
        <p v-if="context.sources.memorySections.length === 0" class="empty">none</p>
        <ul v-else>
          <li v-for="note in context.sources.memorySections" :key="`${note.source}-${note.id}`">
            <span class="content">{{ note.content }}</span>
            <span class="badge">{{ note.scope }}</span>
            <span class="badge" :data-testid="`context-memory-source-${note.source}`">{{ note.source }}</span>
          </li>
        </ul>
      </section>

      <section data-testid="context-skills">
        <h3>Skills ({{ context.sources.skills.length }})</h3>
        <p v-if="context.sources.skills.length === 0" class="empty">none</p>
        <ul v-else>
          <li v-for="skill in context.sources.skills" :key="skill.name">
            <span class="name">{{ skill.name }}</span>
            <span class="badge">{{ skill.tier }}</span>
            <span v-if="!skill.enabled" class="badge off">disabled</span>
          </li>
        </ul>
      </section>

      <section data-testid="context-mcp">
        <h3>MCP</h3>
        <p class="meta">
          <span data-testid="context-mcp-status">{{ context.sources.mcp.status }}</span>
          <span v-if="context.sources.mcp.error"> — {{ context.sources.mcp.error }}</span>
        </p>
        <ul v-if="context.sources.mcp.servers.length > 0">
          <li v-for="server in context.sources.mcp.servers" :key="server.name">
            <span class="name">{{ server.name }}</span>
            <span class="badge">{{ server.transport }}</span>
            <span class="badge">{{ server.trust }}</span>
          </li>
        </ul>
        <p v-if="context.sources.mcp.skipped.length > 0" class="meta">Skipped entries: <span class="reason">{{ context.sources.mcp.skipped.join('; ') }}</span></p>
      </section>

      <section v-if="context.sources.trust || trustError" data-testid="context-trust">
        <h3>Project trust</h3>
        <template v-if="context.sources.trust">
          <p class="reason" data-testid="context-trust-reason">{{ context.sources.trust.reason }}</p>
          <p class="meta">root: <span class="path" data-testid="context-trust-root">{{ context.sources.trust.root }}</span></p>
        </template>
        <p v-if="trustError" class="error" role="alert" data-testid="context-trust-error">{{ trustError }}</p>
        <div v-if="context.sources.trust?.status === 'asked'" class="actions">
          <button type="button" data-testid="context-trust-session" :disabled="busy || !canAnswer" @click="answer('grant', 'session')">Trust (this run)</button>
          <button type="button" data-testid="context-trust-user" :disabled="busy || !canAnswer" @click="answer('grant', 'user')">Always trust</button>
          <button type="button" data-testid="context-trust-reject" :disabled="busy || !canAnswer" @click="answer('reject', 'session')">Leave untrusted</button>
        </div>
        <p v-else-if="context.sources.trust" class="meta" data-testid="context-trust-settled">
          <template v-if="context.sources.trust.status === 'granted'">
            {{ context.sources.trust.persisted ? 'Trusted and written to your user config — the servers start at the next launch.' : 'Trusted for this server run — nothing was written.' }}
          </template>
          <template v-else>Left untrusted — nothing of this project starts.</template>
        </p>
      </section>
    </template>
  </div>
</template>

<style scoped>
.context-panel {
  display: grid;
  gap: 0.75rem;
  min-width: 0;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
h3 {
  margin: 0 0 0.25rem;
  font-size: 0.8125rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
section {
  min-width: 0;
}
ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.3rem;
}
li {
  display: flex;
  gap: 0.4rem;
  align-items: baseline;
  min-width: 0;
}
.path,
.content,
.reason,
.name {
  overflow-wrap: anywhere;
  font-size: 0.8125rem;
}
.content {
  flex: 1;
}
.tokens,
.bytes {
  color: var(--muted);
  font-size: 0.75rem;
  white-space: nowrap;
}
.share {
  margin: 0 0 0.25rem;
  font-weight: 600;
}
.badge {
  display: inline-block;
  padding: 0 0.35rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  font-size: 0.75rem;
  color: var(--muted);
}
.badge.off {
  color: var(--error);
}
.meta,
.empty {
  margin: 0;
  color: var(--muted);
  font-size: 0.8125rem;
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
}
.actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
</style>
