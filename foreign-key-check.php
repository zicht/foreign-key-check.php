<?php
/**
 * @author Gerard van Helden <gerard@zicht.nl>
 * @copyright Zicht Online <http://zicht.nl>
 */

$flags = array_filter($_SERVER['argv'], function($a) {
    return $a[0] === '-';
});
$args =  array_filter($_SERVER['argv'], function($a) {
    return $a[0] !== '-';
});

$VERBOSE = (in_array('-vv', $flags) ? 2 : (int)(in_array('-v', $flags) || in_array('--verbose', $flags)));
$HELP    = in_array('-h', $flags) || in_array('--help', $flags);
$PROG    = array_shift($args);
$DBNAME  = array_shift($args);
$TABLES  = $args;

/**
 * Helper for prefixing a printf result with SQL comments
 */
function commented_printf()
{
    printf(preg_replace('/^/m', '-- ', vsprintf(func_get_arg(0), array_slice(func_get_args(), 1))));
}

/**
 * Remove query whitespace (for verbose output only)
 */
function strip_query_ws($query)
{
    return trim(preg_replace('/\s+/', ' ', $query));
}


if ($HELP || !$DBNAME) {
    printf("Do explicit foreign key checks on your data for all existing\n");
    printf("FOREIGN KEY constraints and propose queries to fix it.\n\n");
    printf("Usage:\n    %s DBNAME [-v|--verbose] [-h|--help]\n\n", $PROG);
    printf("Parameters:\n");
    printf("    DBNAME              The database (or \"schema\") to validate\n\n");
    printf("Available flags:\n");
    printf("    [-h|--help]         Print this help message and exit\n");
    printf("    [-v|--verbose]      Be verbose\n\n");
    printf("    [-vv]               Be even more verbose\n\n");
    exit;
}

$connection = ['host' => 'localhost', 'user' => 'root', 'password' => ''];

if (is_file($cnf = (getenv('HOME') . '/.my.cnf'))) {
    $values = parse_ini_file($cnf, true);

    if (isset($values['client'])) {
        $connection = $values['client'] + $connection;
    }
}

$pdo = new \PDO('mysql:host=' . $connection['host'], $connection['user'], $connection['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$foreignKeyRelationsQuery = sprintf(
    'SELECT
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_SCHEMA                foreign_key_schema,
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_NAME                  foreign_key_table,
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE.COLUMN_NAME                 foreign_key_column,
        INFORMATION_SCHEMA.COLUMNS.IS_NULLABLE                          is_nullable,
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE.REFERENCED_TABLE_SCHEMA     primary_schema,
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE.REFERENCED_TABLE_NAME       primary_table,
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE.REFERENCED_COLUMN_NAME      primary_column
    FROM
        INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            INNER JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS ON(
                KEY_COLUMN_USAGE.CONSTRAINT_NAME=TABLE_CONSTRAINTS.CONSTRAINT_NAME
            )
            INNER JOIN INFORMATION_SCHEMA.COLUMNS ON(
                KEY_COLUMN_USAGE.TABLE_SCHEMA=COLUMNS.TABLE_SCHEMA
                AND KEY_COLUMN_USAGE.TABLE_NAME=COLUMNS.TABLE_NAME
                AND KEY_COLUMN_USAGE.COLUMN_NAME=COLUMNS.COLUMN_NAME
            )
    WHERE
        INFORMATION_SCHEMA.TABLE_CONSTRAINTS.CONSTRAINT_TYPE=\'FOREIGN KEY\'
        AND INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_SCHEMA=%s
        %s
    ',
    $pdo->quote($DBNAME),
    $TABLES
        ? sprintf('AND (INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_NAME IN(%1$s) OR INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_NAME IN(%1$s))', join(', ', array_map(array($pdo, 'quote'), $TABLES)))
        : ''
);

$VERBOSE and commented_printf("Fetching all foreign key relations \n%s\n", $foreignKeyRelationsQuery);

foreach ($pdo->query($foreignKeyRelationsQuery)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $whereClause = "{$row['foreign_key_column']} NOT IN(SELECT {$row['primary_column']} FROM {$row['primary_schema']}.{$row['primary_table']})";
    $query = "
        SELECT DISTINCT
            {$row['foreign_key_column']}
        FROM
            {$row['foreign_key_schema']}.{$row['foreign_key_table']}
        WHERE
            $whereClause
    ";

    $VERBOSE > 1 and commented_printf(
        "Querying invalid references from %s.%s.%s pointing to %s.%s.%s\n    %s\n",
        $row['foreign_key_schema'], $row['foreign_key_table'], $row['foreign_key_column'],
        $row['primary_schema'], $row['primary_table'], $row['primary_column'],
        strip_query_ws($query)
    );

    $values = $pdo->query($query)->fetchAll(PDO::FETCH_COLUMN);
    $count = count($values);

    $VERBOSE > 1 and commented_printf("    Found %d matches\n", $count);

    if ($count) {
        commented_printf(
            "%s.%s.%s contains %d invalid references to %s.%s.%s\n",
            $row['foreign_key_schema'],
            $row['foreign_key_table'],
            $row['foreign_key_column'],
            $count,
            $row['primary_schema'],
            $row['primary_table'],
            $row['primary_column']
        );

        $VERBOSE and commented_printf("     values: %s\n", join(', ', $values));

        if ('YES' === $row['is_nullable']) {
            $query = "UPDATE {$row['foreign_key_schema']}.{$row['foreign_key_table']} SET {$row['foreign_key_column']}=NULL WHERE $whereClause;";
        } else {
            $query = "DELETE FROM {$row['foreign_key_schema']}.{$row['foreign_key_table']} WHERE $whereClause;";
        }
        printf("%s\n", $query);
    }
}