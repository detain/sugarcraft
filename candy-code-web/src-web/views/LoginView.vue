<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { t } from '../lib/i18n'
import { useSlowPending } from '../lib/slowPending'
import { AuthError } from '../protocol/auth'
import { useConnectionStore } from '../stores/connection'

const connection = useConnectionStore()
const router = useRouter()
const secret = ref('')
const busy = ref(false)
const error = ref<string | null>(null)
// MA-36: "Asking the server…" appears only if the round-trip takes long enough
// to be worth seeing — a fast sign-in never flashes the verb.
const slowBusy = useSlowPending(() => busy.value)

/**
 * A sign-in code (from `candy-code serve url`) or the owner token
 * (`candy-code serve token`). The two cannot be told apart by shape — a
 * container's CANDY_CODE_SERVER_TOKEN is any string of 32+ characters — so
 * the code is tried first and the token second.
 */
async function submit(): Promise<void> {
  if (busy.value) return // MA-36: Enter in the field submits even while the button is greyed
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
      ? t('web.views.login.rejected')
      : failure instanceof Error ? failure.message : String(failure)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="login" data-testid="login">
    <h1>{{ t('web.views.login.title') }}</h1>
    <p>
      {{ t('web.views.login.intro_link') }} <code>candy-code serve</code> {{ t('web.views.login.intro_paste') }} <code>candy-code serve url</code> {{ t('web.views.login.intro_token') }} <code>candy-code serve token</code>.
    </p>
    <form @submit.prevent="submit">
      <label for="secret">{{ t('web.views.login.code_label') }}</label>
      <input id="secret" v-model="secret" type="password" autocomplete="off" spellcheck="false" data-testid="login-secret">
      <button type="submit" class="primary" :disabled="busy || secret.trim() === ''" data-testid="login-submit">{{ slowBusy ? t('web.chrome.about.loading') : t('web.views.login.submit') }}</button>
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
