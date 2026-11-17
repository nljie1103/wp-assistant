'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const source = fs.readFileSync('features/page-effects/bootstrap.php', 'utf8');
const adminJS = fs.readFileSync('features/page-effects/assets/js/admin.js', 'utf8');

test('page effects shows exactly one save action inside the original settings form', () => {
  const start = source.indexOf('<form method="post"');
  const end = source.indexOf('</form>', start);
  assert.ok(start > 0 && end > start, 'original WordPress form exists');
  const form = source.slice(start, end + '</form>'.length);

  const primarySaveButtons = form.match(/<button\s+type="submit"[^>]*>保存美化配置<\/button>/g) || [];
  assert.equal(primarySaveButtons.length, 1, 'only the top primary save button is rendered');
  assert.equal((form.match(/submit_button\s*\(/g) || []).length, 0, 'no extra WordPress bottom save');
  assert.equal((form.match(/type="submit"/g) || []).length, 1, 'form has exactly one submit trigger');
  assert.match(form, /id="xjpe-settings-form"/);
  assert.match(form, /name="xjpe_direct_save"/);
  assert.match(form, /name="action"\s+value="xjpe_save_options"/);
  assert.match(form, /wp_nonce_field\( 'xjpe_save_options', 'xjpe_nonce' \)/);
  assert.match(form, /class="xjpe-savebar"/);
  assert.match(form, /不保存，直接测试前台特效/);
});

test('single save button retains submit feedback and all existing settings panels', () => {
  assert.match(adminJS, /getElementById\('xjpe-settings-form'\)/);
  assert.match(adminJS, /querySelectorAll\('button\[type="submit"\]'\)/);
  assert.match(adminJS, /正在保存/);
  for (const tab of ['basic', 'effects', 'custom-code', 'diagnostics']) {
    assert.ok(source.includes("'"+tab+"'"), 'retained tab '+tab);
  }
  assert.match(source, /xjpe-effect-filters/);
  assert.match(source, /xjpe-effects-grid/);
  assert.match(source, /name="xjpe-effect-config"/);
  assert.match(source, /wp_safe_redirect\( \$redirect \)/);
});
