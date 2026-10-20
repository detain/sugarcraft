<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useConnectionStore } from '../../stores/connection'
import { useGenerateStore } from '../../stores/generate'
import MediaCard from '../MediaCard.vue'

/**
 * WEB-1d: the Generate panel — the client half of media generation the
 * closeout once credited but never built. A lean form over the parameters
 * `generate.submit` actually accepts (prompt, its negative, images per
 * request), and a live job surface over `generate.status`: state, progress,
 * ETA, the failure line, and finished artifacts rendered through MediaCard
 * (the one sanctioned `media.fetch` path).
 *
 * Gating follows the hello-capability law: no media feature, no affordance —
 * `canGenerateImage` is the server's own yes, and submit is an image method
 * (`kind` labels returned MediaRefs, it is not a dial to send). Server-side
 * consent, permissions and the ledger doors are MediaMethods' doing; this
 * panel never implies a render started that the server refused.
 *
 * SECURITY POSTURE (the ChangesPanel rule): every server string — job error,
 * artifact labels — renders through plain `{{ }}` spans; bytes arrive only
 * via MediaCard's vetted object URLs.
 */
const props = defineProps<{ sessionId: string }>()

const connection = useConnectionStore()
const generate = useGenerateStore()

const prompt = ref('')
const negative = ref('')
const count = ref(1)

const offered = computed(() => connection.capabilitiesMedia !== null && connection.canGenerateImage)
const reason = computed(() =>
  connection.capabilitiesMedia === null
    ? 'This server does not report media capability, so generation is not offered.'
    : 'Image generation is not enabled on this server.',
)

const job = computed(() => (generate.job?.sessionId === props.sessionId ? generate.job : null))
const running = computed(() => job.value !== null && !generate.isTerminal(job.value.state))
const percent = computed(() => Math.max(0, Math.min(100, Math.round((job.value?.progress01 ?? 0) * 100))))
const canSubmit = computed(() => offered.value && !generate.busy && prompt.value.trim() !== '')

async function send(): Promise<void> {
  if (!canSubmit.value) return
  const trimmed = prompt.value.trim()
  await generate.submit({
    sessionId: props.sessionId,
    prompt: trimmed,
    ...(negative.value.trim() !== '' ? { negative_prompt: negative.value.trim() } : {}),
    ...(count.value > 1 ? { batch_size: count.value } : {}),
  })
}

// The watch belongs to the session on screen: navigating drops it (latch law).
watch(() => props.sessionId, (id) => generate.follow(id), { immediate: true })
</script>

<template>
  <div class="generate-panel" data-testid="generate-panel">
    <div class="head">
      <span class="count" data-testid="generate-title">generate</span>
      <button v-if="running" type="button" class="link" data-testid="generate-reload" @click="generate.retry()">reload</button>
      <button v-else-if="job !== null" type="button" class="link" data-testid="generate-clear" @click="generate.forget()">clear</button>
    </div>

    <p v-if="!offered" class="empty" data-testid="generate-unoffered">{{ reason }}</p>

    <template v-else>
      <form class="form" data-testid="generate-form" @submit.prevent="send">
        <label>
          <span>Prompt</span>
          <textarea v-model="prompt" rows="3" data-testid="generate-prompt" placeholder="What to render, self-contained: style, subject, framing." />
        </label>
        <label>
          <span>Negative</span>
          <input v-model="negative" type="text" data-testid="generate-negative" placeholder="What to steer away from (optional)" />
        </label>
        <label>
          <span>Images</span>
          <input v-model.number="count" type="number" min="1" max="8" data-testid="generate-count" />
        </label>
        <button type="submit" data-testid="generate-submit" :disabled="!canSubmit">{{ generate.busy ? 'Sending…' : 'Generate' }}</button>
      </form>

      <p v-if="generate.error" class="error" role="alert" data-testid="generate-error">{{ generate.error }}</p>

      <section v-if="job !== null" class="job" data-testid="generate-job">
        <p class="state">
          <span :data-state="job.state" data-testid="generate-state">{{ job.state }}</span>
          <span class="job-id">{{ job.jobId }}</span>
          <span v-if="running" class="pct" data-testid="generate-percent">{{ percent }}%</span>
          <span v-if="running && job.etaSeconds !== undefined" class="eta">~{{ Math.round(job.etaSeconds) }} s left</span>
        </p>
        <div v-if="running" class="bar" data-testid="generate-bar" :style="{ width: `${percent}%` }" />
        <p v-if="job.error" class="error" role="alert" data-testid="generate-job-error">{{ job.error }}</p>
        <div v-if="job.artifacts && job.artifacts.length > 0" class="artifacts" data-testid="generate-artifacts">
          <MediaCard v-for="artifact in job.artifacts" :key="artifact.artifactId" :media="artifact" :session-id="sessionId" data-testid="generate-artifact" />
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.generate-panel {
  display: grid;
  gap: 0.6rem;
  min-width: 0;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.form {
  display: grid;
  gap: 0.4rem;
}
.form label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.75rem;
  color: var(--muted);
}
.form textarea,
.form input {
  font: inherit;
  padding: 0.3rem 0.4rem;
  background: var(--panel);
  color: var(--ink);
  border: 1px solid var(--line);
  border-radius: 6px;
}
.form input[type='number'] {
  width: 5rem;
}
.state {
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
  margin: 0;
  font-size: 0.8125rem;
}
.state [data-state='done'] {
  color: var(--accent);
  font-weight: 600;
}
.state [data-state='failed'],
.state [data-state='cancelled'] {
  color: var(--error);
  font-weight: 600;
}
.job-id {
  color: var(--muted);
  font-family: var(--mono, monospace);
  font-size: 0.75rem;
}
.bar {
  height: 4px;
  border-radius: 2px;
  background: var(--accent);
  transition: width 0.4s;
}
.artifacts {
  display: grid;
  gap: 0.5rem;
}
.empty {
  color: var(--muted);
  font-size: 0.8125rem;
  margin: 0;
}
.error {
  margin: 0;
  color: var(--error);
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}
</style>
