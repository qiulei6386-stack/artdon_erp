const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const required = [
  'assets/css/app.css', 'assets/js/app.js', 'assets/js/material-shell-data.js',
  'components/layout_top.php', 'components/layout_bottom.php', 'components/sidebar.php',
  'components/material_workspace.php', 'material/all.php', 'material/power.php',
  'material/chip.php', 'material/optical.php', 'material/profile.php',
  'material/connector.php', 'material/accessories.php', 'material/packaging.php',
  'adaptation/index.php', 'supplier/index.php', 'substitute/index.php',
  'data/index.php', 'documents/index.php', 'settings/index.php'
];
for (const file of required) {
  if (!fs.existsSync(path.join(root, file))) throw new Error(`缺少 UI 文件: ${file}`);
}
const shell = [
  fs.readFileSync(path.join(root, 'index.php'), 'utf8'),
  fs.readFileSync(path.join(root, 'components/layout_top.php'), 'utf8'),
  fs.readFileSync(path.join(root, 'components/layout_bottom.php'), 'utf8')
].join('\n');
for (const marker of ['assets/css/app.css', 'data-sidebar', 'data-overlay', 'assets/js/app.js']) {
  if (!shell.includes(marker)) throw new Error(`现行外壳未应用: ${marker}`);
}
const app = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
for (const behavior of ['Escape', 'closeLayers', 'data-shell-action', '尚未配置真实业务处理']) {
  if (!app.includes(behavior)) throw new Error(`现行交互缺少: ${behavior}`);
}
console.log(`UI static test passed (${required.length} required files).`);
