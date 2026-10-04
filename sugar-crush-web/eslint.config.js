import { defineConfig } from 'eslint/config'
import pluginVue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'

export default defineConfig(
  {
    name: 'sugar-crush-web/ignores',
    ignores: ['dist/**', 'node_modules/**', 'vendor/**', 'coverage/**'],
  },
  tseslint.configs.recommended,
  pluginVue.configs['flat/essential'],
  {
    name: 'sugar-crush-web/vue-ts',
    files: ['**/*.vue'],
    languageOptions: { parserOptions: { parser: tseslint.parser } },
  },
)
