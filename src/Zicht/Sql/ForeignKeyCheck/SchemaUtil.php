<?php
/**
 * @author Gerard van Helden <gerard@zicht.nl>
 * @copyright Zicht Online <http://zicht.nl>
 */

namespace Zicht\Sql\ForeignKeyCheck;

/**
 * Class SchemaUtil
 */
class SchemaUtil
{
    /**
     * Constructor.
     *
     * @param \Pdo $connection
     * @param string $dbname
     * @param callable $logger
     */
    public function __construct(\Pdo $connection, $dbname, $logger)
    {
        $this->connection = $connection;
        $this->dbname = $dbname;
        $this->logger = $logger;
    }


    /**
     * @param array $tables
     * @return array
     */
    public function getForeignKeys(array $tables = [])
    {
        $foreignKeyRelationsQuery = sprintf('
            SELECT
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
            $this->connection->quote($this->dbname),
            $tables
                ? sprintf(
                    'AND (INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_NAME IN(%1$s) OR INFORMATION_SCHEMA.KEY_COLUMN_USAGE.TABLE_NAME IN(%1$s))',
                    join(
                        ', ',
                        array_map(
                            array($this->connection, 'quote'),
                            $tables
                        )
                    )
                )
                : ''
        );

        call_user_func($this->logger, 2, "Fetching all foreign key relations \n%s\n", $foreignKeyRelationsQuery);

        return $this->connection->query($foreignKeyRelationsQuery)->fetchAll(\PDO::FETCH_ASSOC);
    }


    /**
     * Get all queries that may be executed to fix foreign key issues
     *
     * @param array $foreignKey
     * @return \Generator
     */
    public function getFailingConstraintFixes($foreignKey)
    {
        $whereClause = "{$foreignKey['foreign_key_column']} NOT IN(SELECT {$foreignKey['primary_column']} FROM {$foreignKey['primary_schema']}.{$foreignKey['primary_table']})";
        $query = "
            SELECT DISTINCT
                {$foreignKey['foreign_key_column']}
            FROM
                {$foreignKey['foreign_key_schema']}.{$foreignKey['foreign_key_table']}
            WHERE
                $whereClause
        ";

        call_user_func(
            $this->logger,
            2,
            "Querying invalid references from %s.%s.%s pointing to %s.%s.%s\n    %s\n",
            $foreignKey['foreign_key_schema'], $foreignKey['foreign_key_table'], $foreignKey['foreign_key_column'],
            $foreignKey['primary_schema'], $foreignKey['primary_table'], $foreignKey['primary_column'],
            $this->stripQueryWs($query)
        );

        $values = $this->connection->query($query)->fetchAll(\PDO::FETCH_COLUMN);
        $count = count($values);

        call_user_func(
            $this->logger,
            2,
            "    Found %d matches\n",
            $count
        );

        if ($count) {
            call_user_func(
                $this->logger,
                0,
                "%s.%s.%s contains %d invalid references to %s.%s.%s\n",
                $foreignKey['foreign_key_schema'],
                $foreignKey['foreign_key_table'],
                $foreignKey['foreign_key_column'],
                $count,
                $foreignKey['primary_schema'],
                $foreignKey['primary_table'],
                $foreignKey['primary_column']
            );

            call_user_func($this->logger, 1, "     values: %s\n", join(', ', $values));

            if ('YES' === $foreignKey['is_nullable']) {
                $query = "UPDATE {$foreignKey['foreign_key_schema']}.{$foreignKey['foreign_key_table']} SET {$foreignKey['foreign_key_column']}=NULL WHERE $whereClause;";
            } else {
                $query = "DELETE FROM {$foreignKey['foreign_key_schema']}.{$foreignKey['foreign_key_table']} WHERE $whereClause;";
            }
            yield $query;
        }
    }

    /**
     * Remove query whitespace (for verbose output only)
     *
     * @param string $query
     * @return string
     */
    private function stripQueryWs($query)
    {
        return trim(preg_replace('/\s+/', ' ', $query));
    }
}