<?php
declare(strict_types=1);

/**
 * Run a .sql file the way the mysql client does, DELIMITER blocks included,
 * so a test can build its own throwaway database from schema.sql. The schema
 * checks that its own triggers exist, so nothing here skips them.
 */

/** @return list<string> */
function test_sql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $sql)) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (test_sql_has_code($buffer)) throw new RuntimeException('DELIMITER changed with pending SQL.');
            $buffer = '';
            $delimiter = $match[1];
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (!str_ends_with($trimmed, $delimiter)) continue;
        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        if (test_sql_has_code($statement)) $statements[] = $statement;
        $buffer = '';
    }
    if (test_sql_has_code($buffer)) throw new RuntimeException('Unterminated SQL statement.');
    return $statements;
}

function test_sql_has_code(string $sql): bool
{
    return trim((string) preg_replace('/^\s*--.*$/m', '', $sql)) !== '';
}

function test_execute_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) throw new RuntimeException("Cannot read {$path}");
    foreach (test_sql_statements($sql) as $statement) {
        $code = (string) preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement);
        if (preg_match('/^\s*(?:SELECT|SHOW)\b/i', $code) === 1) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
    }
}
