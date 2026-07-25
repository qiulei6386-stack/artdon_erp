<?php
declare(strict_types=1);
function mc_url(string $path=''): string { return MC_BASE_URL . ($path==='' ? '' : '/' . ltrim($path,'/')); }
function mc_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function mc_json_attr(array $v): string { return htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); }
function mc_badge(string $status): string {
  $m=['正式'=>'success','启用'=>'success','待整理'=>'warning','待确认'=>'info','异常'=>'danger','重复候选'=>'danger','停用'=>'muted','归档'=>'muted','草稿'=>'neutral'];
  return $m[$status]??'neutral';
}
function mc_icon(string $name,int $size=18): string {
 $p=[
 'home'=>'<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-7h6v7"/>',
 'layers'=>'<path d="m12 3-9 5 9 5 9-5-9-5Z"/><path d="m3 12 9 5 9-5"/><path d="m3 16 9 5 9-5"/>',
 'zap'=>'<path d="M13 2 3 14h8l-1 8 10-12h-8l1-8Z"/>',
 'cpu'=>'<rect x="7" y="7" width="10" height="10" rx="2"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>',
 'aperture'=>'<circle cx="12" cy="12" r="9"/><path d="M14.5 3.4 9 13l9.5-.2M20.6 14.5 10 13l4.7 8.4M8 20.6 10 11 3.5 16M3.4 9.5 12 11 7.3 2.6"/>',
 'box'=>'<path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="m4 7 8 4 8-4v10l-8 4-8-4V7Z"/><path d="M12 11v10"/>',
 'plug'=>'<path d="M8 3v5M16 3v5"/><path d="M6 8h12v3a6 6 0 0 1-6 6v4"/><path d="M8 21h8"/>',
 'puzzle'=>'<path d="M8 3h5v4a2 2 0 1 0 4 0V3h4v6h-4a2 2 0 1 0 0 4h4v8h-7v-4a2 2 0 1 0-4 0v4H3v-7h4a2 2 0 1 0 0-4H3V3h5Z"/>',
 'package'=>'<path d="m5 7 7-4 7 4-7 4-7-4Z"/><path d="m5 7 7 4 7-4v10l-7 4-7-4V7Z"/>',
 'branch'=>'<path d="M6 3v12"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="6" r="3"/><path d="M9 6h4a5 5 0 0 1 5 5v4"/>',
 'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
 'repeat'=>'<path d="m17 1 4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="m7 23-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
 'upload'=>'<path d="M12 3v12"/><path d="m7 8 5-5 5 5"/><path d="M4 15v6h16v-6"/>',
 'file'=>'<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
 'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9 7 7M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
 'search'=>'<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
 'filter'=>'<path d="M4 4h16l-6 7v6l-4 3v-9L4 4Z"/>',
 'more'=>'<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
 'refresh'=>'<path d="M20 11a8 8 0 1 0 2 5"/><path d="M20 4v7h-7"/>',
 'plus'=>'<path d="M12 5v14M5 12h14"/>',
 'columns'=>'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/>',
 'chevron'=>'<path d="m9 18 6-6-6-6"/>'
 ];
 $body=$p[$name]??$p['box'];
 return '<svg class="mc-icon" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$body.'</svg>';
}
