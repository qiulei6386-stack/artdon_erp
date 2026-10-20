<?php
// Synthetic text only; does not load bootstrap, configuration, sessions, or DB.
require_once dirname(__DIR__).'/includes/quote_spec_order.php';
$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/quote-spec-order.json'), true, 512, JSON_THROW_ON_ERROR);
$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException('Specification ordering assertion failed: '.$name);
};
foreach ($fixtures as $fixture) {
    $item = $fixture['item'];
    $before = json_encode($item, JSON_THROW_ON_ERROR);
    $actual = qso_order_spec($fixture['text'], $item);
    $assert($actual === $fixture['expected'], $fixture['name']);
    $assert(qso_order_spec($actual, $item) === $actual, $fixture['name'].': idempotence');
    $assert(json_encode($item, JSON_THROW_ON_ERROR) === $before, $fixture['name'].': saved item is unchanged');
}
$fields = ['Power: 1.35W', 'Size: 52.05522×120', 'Cut out: Φ55', 'Beam Angle: 24°', 'Optic: OPAL', 'LED: BXRE-40G20FD-C-8X 3000K CRI90', 'Adapter: 48V', 'LED Driver: LF-GDE020', 'Accessories: Clip', 'CCT: 3000K', 'CRI90', 'IP20'];
$numbered = static function (array $rows): string {
    return implode("\n", array_map(static function ($i, $row): string { return ($i + 1).'. '.$row; }, array_keys($rows), $rows));
};
$expected = $numbered(array_merge(['SPECTRUM E'], $fields));
mt_srand(20261020);
for ($n = 0; $n < 128; $n++) {
    $shuffled = $fields;
    shuffle($shuffled);
    $actual = qso_order_spec($numbered(array_merge(['SPECTRUM E'], $shuffled)));
    $assert($actual === $expected, 'random insertion order '.$n);
    $assert(qso_order_spec($actual) === $actual, 'random insertion idempotence '.$n);
}
echo 'Quote specification ordering: '.count($fixtures).' shared fixtures, 128 insertion permutations, '.$checks." PHP checks passed.\n";
