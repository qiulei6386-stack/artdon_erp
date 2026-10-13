<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Services/ChipSpecificationService.php';

use Artdon\MaterialCenter\Services\ChipSpecificationService;

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$class = new ReflectionClass(ChipSpecificationService::class);
$service = $class->newInstanceWithoutConstructor();
$normalize = $class->getMethod('normalizeSelection');
$normalize->setAccessible(true);
$selection = $normalize->invoke($service, [
    'cct' => [3000, '2700', 3000],
    'cri' => [90, '97', 97.0],
    'sdcm' => [3],
    'hidden' => ['cct' => [2200], 'cri' => [98, 97], 'sdcm' => [7]],
]);

if ($selection['cct'] !== [2700, 3000]) $fail('CCT values were not normalized.');
if ($selection['cri'] !== [90, 97]) $fail('Custom CRI 97 was not normalized.');
if (($selection['hidden']['cri'] ?? null) !== [98]) $fail('Selected CRI was not restored from the hidden list.');
if (($selection['hidden']['cct'] ?? null) !== [2200] || ($selection['hidden']['sdcm'] ?? null) !== [7]) {
    $fail('Removed values were not persisted.');
}

$invalidRejected = false;
try {
    $normalize->invoke($service, ['cct' => [3000], 'cri' => [101], 'sdcm' => [3]]);
} catch (ReflectionException $e) {
    throw $e;
} catch (Throwable) {
    $invalidRejected = true;
}
if (!$invalidRejected) $fail('Out-of-range CRI was accepted.');

echo "Chip template value normalization passed.\n";
