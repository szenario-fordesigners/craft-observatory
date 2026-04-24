import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';
import type { UserConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import vueDevTools from 'vite-plugin-vue-devtools';

const nodeEnv = process.env.NODE_ENV ?? 'production';

const getBaseConfig = (): UserConfig => ({
  plugins: [vue(), vueDevTools(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  define: {
    'process.env.NODE_ENV': JSON.stringify(nodeEnv),
    'process.env': JSON.stringify({ NODE_ENV: nodeEnv }),
    'process': JSON.stringify({ env: { NODE_ENV: nodeEnv } }),
    __VUE_PROD_DEVTOOLS__: true,
  },
});

const target = process.env.BUILD_TARGET || 'widget';

// https://vite.dev/config/
export default defineConfig({
  ...getBaseConfig(),
  build: {
    lib: {
      entry: fileURLToPath(new URL(target === 'widget' ? './src/widget.ts' : './src/cp.ts', import.meta.url)),
      name: target === 'widget' ? 'UmamiIsApp' : 'UmamiIsCpApp',
      formats: ['iife'],
      fileName: () => target === 'widget' ? 'widget.js' : 'cp.js',
    },
    rollupOptions: {
      output: {
        codeSplitting: false,
        assetFileNames: '[name][extname]',
      },
    },
    outDir: target === 'widget' ? '../src/assetbundles/craftumamiiswidget/dist' : '../src/assetbundles/craftumamiiscp/dist',
    emptyOutDir: true,
    sourcemap: true,
    minify: false,
  },
});
