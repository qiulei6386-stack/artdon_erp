const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const required = [
  'ui/index.css', 'ui/tokens.css', 'ui/theme-light.css', 'ui/theme-dark.css',
  'ui/js/interaction-manager.js', 'ui/js/confirm-modal.js', 'ui/js/dropdown.js', 'ui/js/modal.js',
  'ui/js/drawer.js', 'ui/js/toast.js', 'ui/js/table.js', 'ui/js/app-shell.js',
  'ui/docs/component-gallery.php', 'power_supplies.php', 'bom_audit.php', 'system_status.php'
];
for (const file of required) {
  if (!fs.existsSync(path.join(root, file))) throw new Error(`缺少 UI 文件: ${file}`);
}
const page = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
for (const marker of ['ui/index.css', 'data-ui-table', 'data-ui-mask', 'ui/js/interaction-manager.js']) {
  if (!page.includes(marker)) throw new Error(`首页未应用: ${marker}`);
}
const manager = fs.readFileSync(path.join(root, 'ui/js/interaction-manager.js'), 'utf8');
for (const behavior of ['Escape', 'ui-scroll-locked', 'restoreFocus', 'pagehide', 'uiDirty']) {
  if (!manager.includes(behavior)) throw new Error(`交互管理器缺少: ${behavior}`);
}
console.log(`UI static test passed (${required.length} required files).`);
