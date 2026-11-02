import { defineConfig } from 'eslint/config'
import pluginVue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'

export default defineConfig(
  {
    name: 'candy-code-web/ignores',
    ignores: ['dist/**', 'node_modules/**', 'vendor/**', 'coverage/**', 'playwright-report/**', 'test-results/**'],
  },
  tseslint.configs.recommended,
  pluginVue.configs['flat/essential'],
  {
    name: 'candy-code-web/intentional-unused-args',
    rules: {
      // A `_`-prefixed argument is the convention for a binding that must
      // exist to carry a type (typed mock params, fixture-builder params)
      // but is never read. Everything else stays a hard error.
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
    },
  },
  {
    name: 'candy-code-web/vue-ts',
    files: ['**/*.vue'],
    languageOptions: { parserOptions: { parser: tseslint.parser } },
    rules: {
      // Appendix O §7.2 names these two; they are app components, never
      // custom elements, so a clash with a future HTML element cannot happen.
      'vue/multi-word-component-names': ['error', { ignores: ['Composer', 'Transcript'] }],
    },
  },
)
