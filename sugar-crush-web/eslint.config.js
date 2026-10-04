import { defineConfig } from 'eslint/config'
import pluginVue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'

export default defineConfig(
  {
    name: 'sugar-crush-web/ignores',
    ignores: ['dist/**', 'node_modules/**', 'vendor/**', 'coverage/**', 'playwright-report/**', 'test-results/**'],
  },
  tseslint.configs.recommended,
  pluginVue.configs['flat/essential'],
  {
    name: 'sugar-crush-web/vue-ts',
    files: ['**/*.vue'],
    languageOptions: { parserOptions: { parser: tseslint.parser } },
    rules: {
      // Appendix O §7.2 names these two; they are app components, never
      // custom elements, so a clash with a future HTML element cannot happen.
      'vue/multi-word-component-names': ['error', { ignores: ['Composer', 'Transcript'] }],
    },
  },
)
