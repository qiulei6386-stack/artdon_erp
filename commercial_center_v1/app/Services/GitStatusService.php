<?php
declare(strict_types=1);

namespace Artdon\CommercialCenter\Services;

final class GitStatusService
{
    public function summary(): array
    {
        $gitDirectory = CC_LEGACY_ROOT . '/.git';
        $headValue = $this->read($gitDirectory . '/HEAD');
        $branch = 'detached';
        $commit = $headValue;
        if (str_starts_with($headValue, 'ref: ')) {
            $reference = trim(substr($headValue, 5));
            $branch = basename($reference);
            $commit = $this->read($gitDirectory . '/' . $reference);
            if ($commit === '') {
                $commit = $this->packedReference($gitDirectory . '/packed-refs', $reference);
            }
        }
        $head = $commit !== '' ? substr($commit, 0, 7) : 'unknown';
        return [
            'branch' => $branch,
            'head' => $head,
            'changes' => null,
            'summary' => $branch . '@' . $head . ' · 工作区状态见安全审计',
        ];
    }

    private function read(string $file): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }
        return trim((string)file_get_contents($file));
    }

    private function packedReference(string $file, string $reference): string
    {
        $contents = $this->read($file);
        if ($contents === '') {
            return '';
        }
        foreach (explode("\n", $contents) as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }
            [$commit, $name] = array_pad(preg_split('/\s+/', $line, 2), 2, '');
            if ($name === $reference) {
                return $commit;
            }
        }
        return '';
    }
}
