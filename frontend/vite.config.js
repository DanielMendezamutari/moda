import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import vue from '@vitejs/plugin-vue'
import vueJsx from '@vitejs/plugin-vue-jsx'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { VueRouterAutoImports, getPascalCaseRouteName } from 'unplugin-vue-router'
import VueRouter from 'unplugin-vue-router/vite'
import { defineConfig, loadEnv } from 'vite'
import VueDevTools from 'vite-plugin-vue-devtools'
import Layouts from 'vite-plugin-vue-layouts'
import vuetify from 'vite-plugin-vuetify'
import svgLoader from 'vite-svg-loader'

/** Base pública del SPA (`/` en la raíz del dominio, `/carpeta/` en subcarpeta). */
function normalizeAppBase(val) {
  const s = (val && String(val).trim()) || ''
  if (!s || s === '/')
    return '/'
  let b = s.startsWith('/') ? s : `/${s}`
  if (!b.endsWith('/'))
    b += '/'
  return b
}

// https://vitejs.dev/config/
// `--mode hosting` → salida en `compilacion-para-hosting/` y variables desde `.env.hosting` (ver `package.json` → build:hosting).
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const isHosting = mode === 'hosting'
  const base = isHosting ? normalizeAppBase(env.VITE_APP_BASE) : '/'

  return {
  base,
  server: {
    // Con `pnpm dev`, si `VITE_API_BASE_URL` no está definida se usa `/api` y aquí se reenvía a Laravel (moda.test).
    proxy: {
      '/api': {
        target: 'http://moda.test',
        changeOrigin: true,
        secure: false,
      },
    },
  },
  plugins: [
    // Docs: https://github.com/posva/unplugin-vue-router
    // ℹ️ This plugin should be placed before vue plugin
    VueRouter({
      getRouteName: routeNode => {
        // Convert pascal case to kebab case
        return getPascalCaseRouteName(routeNode)
          .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
          .toLowerCase()
      },
    }),
    vue({
      template: {
        compilerOptions: {
          isCustomElement: tag => tag === 'swiper-container' || tag === 'swiper-slide',
        },
      },
    }),
    VueDevTools(),
    vueJsx(),

    // Docs: https://github.com/vuetifyjs/vuetify-loader/tree/master/packages/vite-plugin
    vuetify({
      styles: {
        configFile: 'src/assets/styles/variables/_vuetify.scss',
      },
    }),

    // Docs: https://github.com/johncampionjr/vite-plugin-vue-layouts#vite-plugin-vue-layouts
    Layouts({
      layoutsDirs: './src/layouts/',
    }),

    // Docs: https://github.com/antfu/unplugin-vue-components#unplugin-vue-components
    Components({
      dirs: ['src/@core/components', 'src/views/demos', 'src/components'],
      dts: true,
      resolvers: [
        componentName => {
          // Auto import `VueApexCharts`
          if (componentName === 'VueApexCharts')
            return { name: 'default', from: 'vue3-apexcharts', as: 'VueApexCharts' }
        },
      ],
    }),

    // Docs: https://github.com/antfu/unplugin-auto-import#unplugin-auto-import
    AutoImport({
      imports: ['vue', VueRouterAutoImports, '@vueuse/core', '@vueuse/math', 'vue-i18n', 'pinia'],
      dirs: [
        './src/@core/utils',
        './src/@core/composable/',
        './src/composables/',
        './src/utils/',
        './src/plugins/*/composables/*',
      ],
      vueTemplate: true,

      // ℹ️ Disabled to avoid confusion & accidental usage
      ignore: ['useCookies', 'useStorage'],
      eslintrc: {
        enabled: true,
        filepath: './.eslintrc-auto-import.json',
      },
    }),
    svgLoader(),
    isHosting && {
      name: 'hosting-spa-htaccess',
      closeBundle() {
        const outDir = path.resolve(process.cwd(), 'compilacion-para-hosting')
        const rewriteBase = base === '/' ? '/' : base
        const lastRule = base === '/' ? 'RewriteRule . /index.html [L]' : 'RewriteRule . index.html [L]'
        const body = [
          '# Generado en build:hosting (SPA en Apache).',
          'DirectoryIndex index.php index.html',
          '',
          '<IfModule mod_rewrite.c>',
          '  RewriteEngine On',
          `  RewriteBase ${rewriteBase}`,
          '  RewriteRule ^index\\.html$ - [L]',
          '  RewriteCond %{REQUEST_FILENAME} !-f',
          '  RewriteCond %{REQUEST_FILENAME} !-d',
          `  ${lastRule}`,
          '</IfModule>',
          '',
        ].join('\n')
        fs.writeFileSync(path.join(outDir, '.htaccess'), body, 'utf8')

        const indexPhp = `<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
$index = __DIR__ . DIRECTORY_SEPARATOR . 'index.html';
if (! is_readable($index)) {
    http_response_code(500);
    echo 'No se encuentra index.html.';
    exit(1);
}
readfile($index);
`
        fs.writeFileSync(path.join(outDir, 'index.php'), indexPhp, 'utf8')
      },
    },
  ].filter(Boolean),
  define: { 'process.env': {} },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      '@themeConfig': fileURLToPath(new URL('./themeConfig.js', import.meta.url)),
      '@core': fileURLToPath(new URL('./src/@core', import.meta.url)),
      '@layouts': fileURLToPath(new URL('./src/@layouts', import.meta.url)),
      '@images': fileURLToPath(new URL('./src/assets/images/', import.meta.url)),
      '@styles': fileURLToPath(new URL('./src/assets/styles/', import.meta.url)),
      '@configured-variables': fileURLToPath(new URL('./src/assets/styles/variables/_template.scss', import.meta.url)),
      '@db': fileURLToPath(new URL('./src/plugins/fake-api/handlers/', import.meta.url)),
      '@api-utils': fileURLToPath(new URL('./src/plugins/fake-api/utils/', import.meta.url)),
    },
  },
  build: {
    outDir: mode === 'hosting' ? 'compilacion-para-hosting' : 'dist',
    chunkSizeWarningLimit: 5000,
  },
  optimizeDeps: {
    exclude: ['vuetify'],
    entries: [
      './src/**/*.vue',
    ],
  },
  }
})
