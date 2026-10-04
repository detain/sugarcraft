/// <reference types="vitest/config" />
import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import vue from '@vitejs/plugin-vue'
import { defineConfig, type Plugin } from 'vite'

// The server port `sugarcrush serve` binds by default (user decision, Appendix O).
const SERVER = 'http://127.0.0.1:7420'

const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8')) as {
  name: string
  version: string
}

// `SugarCraft\CrushWeb\Assets::version()` reads this file. package.json is
// excluded from the Packagist archive, so the version has to travel inside
// dist/. No timestamp: a rebuild of unchanged source must be byte-identical,
// or web.yml's `git diff --exit-code dist/` gate could never be green.
function buildInfo(): Plugin {
  return {
    name: 'sugar-crush-web:build-info',
    apply: 'build',
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'build-info.json',
        source: JSON.stringify({ name: pkg.name, version: pkg.version }, null, 2) + '\n',
      })
    },
  }
}

export default defineConfig({
  // Relative asset URLs so the bundle works under any mount point.
  base: './',
  plugins: [vue(), buildInfo()],
  define: {
    __APP_VERSION__: JSON.stringify(pkg.version),
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src-web', import.meta.url)),
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: 'manifest.json',
    sourcemap: false,
  },
  server: {
    proxy: {
      '/ws': { target: SERVER, ws: true },
      '/api': { target: SERVER },
    },
  },
  test: {
    environment: 'happy-dom',
    include: ['src-web/**/*.spec.ts'],
  },
})
