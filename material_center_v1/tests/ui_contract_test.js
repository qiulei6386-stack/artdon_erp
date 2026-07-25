const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const combined = ['index.php','power_supplies.php','bom_audit.php','system_status.php','ui/docs/component-gallery.php'].map(read).join('\n');
const table = read('ui/js/table.js');
const dropdown = read('ui/js/dropdown.js');
const overlay = read('ui/components/overlay.css');
const form = read('ui/components/form.css');

for (const feature of ['data-ui-table','ui-btn','ui-input','ui-select','ui-state','data-ui-mask']) {
  if (!combined.includes(feature)) throw new Error(`页面缺少统一组件标记: ${feature}`);
}
for (const feature of ['data-ui-select-all','data-sort','ui-resizer','data-ui-table-settings','data-density','ui-page-jump']) {
  if (!table.includes(feature) && !combined.includes(feature)) throw new Error(`表格能力缺少: ${feature}`);
}
for (const feature of ['ui-menu-up','320px']) {
  if (!dropdown.includes(feature) && !overlay.includes(feature)) throw new Error(`菜单约束缺少: ${feature}`);
}
for (const feature of ['appearance:none','ui-check-box','ui-radio-mark','ui-switch-track']) {
  if (!form.replace(/\s/g,'').includes(feature.replace(/\s/g,'')) && !combined.includes(feature)) throw new Error(`表单统一样式缺少: ${feature}`);
}
const repository = read('app/Repositories/MaterialReadRepository.php');
if (!repository.includes("preg_match('/^\\s*SELECT")) throw new Error('只读 SELECT 防线缺失');
if (/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE)\b/i.test(repository.replace(/throw new[\s\S]*?;/g,''))) throw new Error('仓库包含疑似写入 SQL');
console.log('UI contract and read-only safety test passed.');
