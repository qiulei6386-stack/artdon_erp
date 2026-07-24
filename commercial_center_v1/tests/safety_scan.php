<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$legacyWrite = '/(?:\b(?:INSERT\s+INTO|DELETE\s+FROM)\s+(?!`?cc_)[`a-z0-9_]+|\bUPDATE\s+(?!`?cc_)[`a-z0-9_]+\s+SET\b)/i';
$dangerousMigration = '/\b(ALTER\s+TABLE|TRUNCATE\s+TABLE|RENAME\s+TABLE)\b/i';

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, '/storage/')) {
        continue;
    }
    $relative = substr($path, strlen($root) + 1);
    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = $relative . ': unreadable';
        continue;
    }
    if (preg_match($legacyWrite, $contents)) {
        $errors[] = $relative . ': possible legacy table write';
    }
    if (str_contains($relative, 'database/migrations/') && preg_match($dangerousMigration, $contents)) {
        $errors[] = $relative . ': forbidden migration operation';
    }
    if (str_contains($relative, 'database/migrations/') && preg_match('/\bDROP\s+TABLE\b/i', $contents)) {
        $errors[] = $relative . ': DROP is not permitted in migrations';
    }
    if (preg_match("~['\"]password['\"]\\s*=>\\s*['\"][^'\"]+['\"]~i", $contents)) {
        $errors[] = $relative . ': possible hard-coded password';
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "PASS: no legacy writes, forbidden migrations, or hard-coded passwords detected.\n";
