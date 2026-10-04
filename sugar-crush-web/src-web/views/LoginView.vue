<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { AuthError } from '../protocol/auth'
import { useConnectionStore } from '../stores/connection'

const connection = useConnectionStore()
const router = useRouter()
const secret = ref('')
const busy = ref(false)
const error = ref<string | null>(null)

/**
 * A sign-in code (from `sugarcrush serve url`) or the owner token
 * (`sugarcrush serve token`). The two cannot be told apart by shape — a
 * container's SUGARCRUSH_SERVER_TOKEN is any string of 32+ characters — so
 * the code is tried first and the token second.
 */
async function submit(): Promise<void> {
  const value = secret.value.trim().replace(/^.*#code=/, '')
  if (value === '') return
  busy.value = true
  error.value = null
  try {
    try {
      await connection.signIn({ code: value })
    } catch (failure) {
      if (!(failure instanceof AuthError) || failure.status !== 401) throw failure
      await connection.signIn({ token: value })
    }
    secret.value = ''
    void router.push({ name: 'dashboard' })
  } catch (failure) {
    error.value = failure instanceof AuthError && failure.status === 401
      ? 'That code or token was not accepted. Codes are single-use and expire after 120 s.'
      : failure instanceof Error ? failure.message : String(failure)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="login" data-testid="login">
    <h1>Sign in</h1>
    <p>
      Open the sign-in link <code>sugarcrush serve</code> printed, or paste a code from
      <code>sugarcrush serve url</code> — or the owner token from <code>sugarcrush serve token</code>.
    </p>
    <form @submit.prevent="submit">
      <label for="secret">Sign-in code or token</label>
      <input id="secret" v-model="secret" type="password" autocomplete="off" spellcheck="false" data-testid="login-secret">
      <button type="submit" class="primary" :disabled="busy || secret.trim() === ''" data-testid="login-submit">Sign in</button>
    </form>
    <p v-if="error" class="error" role="alert">{{ error }}</p>
  </section>
</template>

<style scoped>
.login {
  max-width: 32rem;
  margin: 3rem auto;
  padding: 0 1rem;
}
h1 {
  font-size: 1.4rem;
  margin: 0 0 0.75rem;
}
p {
  line-height: 1.5;
}
form {
  display: grid;
  gap: 0.5rem;
}
input {
  font: inherit;
  padding: 0.45rem 0.6rem;
  color: var(--text);
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 6px;
}
button {
  justify-self: start;
}
.error {
  color: var(--error);
}
code {
  font-family: var(--mono);
  background: var(--panel);
  padding: 0.1rem 0.3rem;
  border-radius: 4px;
}
</style>
