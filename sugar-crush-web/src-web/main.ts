import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { SIGN_IN_CODE } from './keys'
import { codeFromHash } from './protocol/auth'
import { createAppRouter } from './router'
import './styles/tokens.css'

// The sign-in URL carries a one-time code in its fragment. Take it before the
// hash router sees it, and drop it from the address bar and the history.
const code = codeFromHash(window.location.hash)
if (code !== null) {
  window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#/`)
}

const app = createApp(App)
app.provide(SIGN_IN_CODE, code)
app.use(createPinia())
app.use(createAppRouter())
app.mount('#app')
