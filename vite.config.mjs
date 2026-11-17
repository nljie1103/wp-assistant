import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'node:path'

// Prebuilt, self-contained classic script for WordPress admin_enqueue_scripts.
// Vue and Reka are bundled; no runtime CDN, npm install, or build requirement.
export default defineConfig({
  plugins: [vue()],
  // Vite library mode otherwise leaves Vue's process.env.NODE_ENV check
  // in the classic IIFE, causing ReferenceError in normal browsers.
  define: { 'process.env.NODE_ENV': JSON.stringify('production') },
  base: './',
  build: {
    target: 'es2020',
    outDir: 'assets/vue',
    emptyOutDir: true,
    sourcemap: false,
    cssCodeSplit: false,
    lib: {
      entry: resolve(import.meta.dirname, 'src/admin/main.ts'),
      name: 'JLWAVueAdmin',
      formats: ['iife'],
      fileName: () => 'admin.js',
      cssFileName: 'admin'
    }
  }
})
