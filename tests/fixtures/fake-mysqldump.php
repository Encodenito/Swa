<?php

// Stands in for mysqldump in BackupTest. Writes the arguments and whether a password arrived via
// MYSQL_PWD (never its value) to the --result-file, so the test can check how it was called.
$result = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--result-file=')) {
        $result = substr($arg, strlen('--result-file='));
    }
}

if (getenv('FAKE_DUMP_FAIL') === '1' || $result === null) {
    fwrite(STDERR, "mysqldump: Got error: 1045: Access denied\n");
    exit(2);
}

file_put_contents($result, "-- fake dump\n-- args: ".implode(' ', array_slice($argv, 1))."\n-- pwd_env: ".(getenv('MYSQL_PWD') !== false ? 'set' : 'unset')."\n");
