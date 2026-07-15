<?php
function sql_without_comments_and_literals(string $sql): string
{
    $length = strlen($sql);
    $clean = '';
    $i = 0;

    while ($i < $length) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($char === '-' && $next === '-') {
            $clean .= '  ';
            $i += 2;
            while ($i < $length && $sql[$i] !== "\n") {
                $clean .= ' ';
                $i++;
            }
            continue;
        }

        if ($char === '#') {
            $clean .= ' ';
            $i++;
            while ($i < $length && $sql[$i] !== "\n") {
                $clean .= ' ';
                $i++;
            }
            continue;
        }

        if ($char === '/' && $next === '*') {
            $clean .= '  ';
            $i += 2;
            while ($i < $length) {
                if ($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/') {
                    $clean .= '  ';
                    $i += 2;
                    break;
                }
                $clean .= $sql[$i] === "\n" ? "\n" : ' ';
                $i++;
            }
            continue;
        }

        if ($char === '\'' || $char === '"' || $char === '`') {
            $quote = $char;
            $clean .= ' ';
            $i++;
            while ($i < $length) {
                if ($sql[$i] === '\\' && $quote !== '`') {
                    $clean .= ' ';
                    if ($i + 1 < $length) {
                        $clean .= ' ';
                    }
                    $i += 2;
                    continue;
                }

                if ($sql[$i] === $quote) {
                    if ($quote !== '`' && ($sql[$i + 1] ?? '') === $quote) {
                        $clean .= '  ';
                        $i += 2;
                        continue;
                    }
                    $clean .= ' ';
                    $i++;
                    break;
                }

                $clean .= $sql[$i] === "\n" ? "\n" : ' ';
                $i++;
            }
            continue;
        }

        $clean .= $char;
        $i++;
    }

    return $clean;
}

function assert_safe_sql_migration(string $sql, string $fileName): void
{
    $cleanSql = sql_without_comments_and_literals($sql);
    $statements = preg_split('/;/', $cleanSql) ?: [];

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }

        $forbidden = [
            '/\bDROP\s+(?:TEMPORARY\s+)?TABLE\b/i',
            '/\bDROP\s+DATABASE\b/i',
            '/\bTRUNCATE\s+(?:TABLE\b)?/i',
            '/\bDELETE\s+FROM\b/i',
            '/\bALTER\s+TABLE\b[\s\S]*\bDROP\b/i',
        ];

        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $statement)) {
                throw new RuntimeException($fileName . ' 包含禁止 SQL 语句。');
            }
        }
    }
}

function validate_sql_migration_files(): void
{
    $files = glob(__DIR__ . '/../sql/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        assert_safe_sql_migration((string)file_get_contents($file), basename($file));
    }
}

function execute_sql_migrations(PDO $pdo)
{
    $files = glob(__DIR__ . '/../sql/*.sql') ?: [];
    sort($files);
    foreach ($files as $file) {
        $sql = file_get_contents($file);
        assert_safe_sql_migration((string)$sql, basename($file));
        $pdo->exec($sql);
    }
}

function repair_permission_request_schema(PDO $pdo)
{
    execute_sql_migrations($pdo);
}
