#!/usr/bin/env node
// The official WordPress ZIP ships a built IIFE and CSS; npm is not required
// at runtime. These files must be present in every immutable release.
import assert from 'node:assert/strict'
import { readFileSync, statSync } from 'node:fs'
import { resolve } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const pkg = JSON.parse(readFileSync(resolve(root, 'package.json'), 'utf8'))
const plugin = readFileSync(resolve(root, 'jiuliu-wp-assistant.php'), 'utf8')
const phpShell = readFileSync(resolve(root, 'includes/class-jlwa-admin.php'), 'utf8')
const script = readFileSync(resolve(root, 'assets/vue/admin.js'), 'utf8')
const style = readFileSync(resolve(root, 'assets/vue/admin.css'), 'utf8')
const entry = readFileSync(resolve(root, 'src/admin/main.ts'), 'utf8')

assert.ok(plugin.includes(' * Version: ' + pkg.version))
assert.ok(plugin.includes("define( 'JLWA_VERSION', '" + pkg.version + "' );"))
assert.ok(phpShell.includes("wp_enqueue_style( 'jlwa-vue-admin'"))
assert.ok(phpShell.includes("wp_enqueue_script( 'jlwa-vue-admin'"))
assert.ok(phpShell.includes("wp_localize_script( 'jlwa-vue-admin', 'JLWA_V3'"))
assert.ok(phpShell.includes('JLWA_Feature_Registry::render_admin( $key )'))
assert.ok(entry.includes('createApp(Sidebar'))
assert.ok(entry.includes('createApp(Toolbar'))
assert.ok(entry.includes('createApp(Dashboard'))
assert.ok(!script.includes('import.meta'), 'WordPress classic scripts cannot require ESM')
assert.ok(!script.includes('process.env.NODE_ENV'), 'Vue IIFE must not use Node process env at runtime')
assert.ok(script.includes('jlwa-vue-sidebar'))
assert.ok(script.includes('jlwa-vue-toolbar'))
assert.ok(style.includes('.jlwa-v3 .jlwa-ui-sidebar'))
assert.ok(style.includes('.jlwa-ui-dialog'))
assert.ok(style.includes('jlwa-v3[data-jlwa-theme'))
assert.ok(statSync(resolve(root, 'assets/vue/admin.js')).size > 20000)
assert.ok(statSync(resolve(root, 'assets/vue/admin.css')).size > 8000)
console.log('Vue 3 assets: version, WordPress enqueues, IIFE bundle, scoped CSS and legacy PHP forms OK')
