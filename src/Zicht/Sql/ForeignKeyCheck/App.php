<?php
/**
 * @author Gerard van Helden <gerard@zicht.nl>
 * @copyright Zicht Online <http://zicht.nl>
 */

namespace Zicht\Sql\ForeignKeyCheck;


/**
 * Command line interface
 */
class App
{
    /**
     * @var null
     */
    private $verbose = null;
    /**
     * @var null
     */
    private $help = null;
    /**
     * @var null
     */
    private $prog = null;
    /**
     * @var null
     */
    private $dbname = null;
    /**
     * @var array
     */
    private $tables = [];

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->connection = ['host' => 'localhost', 'user' => 'root', 'password' => ''];

        if (is_file($cnf = (getenv('HOME') . '/.my.cnf'))) {
            $values = parse_ini_file($cnf, true);

            if (isset($values['client'])) {
                $this->connection = $values['client'] + $this->connection;
            }
        }
    }

    /**
     * Parse the command line arguments
     *
     * @param string[] $argv
     * @return void
     */
    private function parseArgs($argv)
    {
        $flags = array_filter($argv, function($a) {
            return $a[0] === '-';
        });
        $args =  array_filter($argv, function($a) {
            return $a[0] !== '-';
        });

        $this->verbose = (in_array('-vv', $flags) ? 2 : (int)(in_array('-v', $flags) || in_array('--verbose', $flags)));
        $this->help    = in_array('-h', $flags) || in_array('--help', $flags);
        $this->prog    = array_shift($args);
        $this->dbname  = array_shift($args);
        $this->tables  = $args;
    }


    /**
     * Run the app with the specified command line args ($_SERVER['argv'])
     *
     * @param string[] $argv
     * @return void
     */
    public function run($argv)
    {
        $this->parseArgs($argv);

        if ($this->help || !$this->dbname) {
            $this->printHelp();
            exit;
        }

        $pdo = new \PDO('mysql:host=' . $this->connection['host'], $this->connection['user'], $this->connection['password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $schemaUtil = new SchemaUtil(
            $pdo,
            $this->dbname,
            function($level, ...$args) {
                if ($level < $this->verbose) {
                    call_user_func([$this, 'commentedPrintf'], ...$args);
                }
            }
        );

        foreach ($schemaUtil->getForeignKeys($this->tables) as $foreignKey) {
            foreach ($schemaUtil->getFailingConstraintFixes($foreignKey) as $query) {
                printf("%s\n", $query);
            }
        }
    }


    /**
     * Helper for prefixing a printf result with SQL comments
     *
     * @return void
     */
    private function commentedPrintf()
    {
        printf(preg_replace('/^/m', '-- ', vsprintf(func_get_arg(0), array_slice(func_get_args(), 1))));
    }


    /**
     * Prints help text
     *
     * @return void
     */
    private function printHelp()
    {
        printf("Do explicit foreign key checks on your data for all existing\n");
        printf("FOREIGN KEY constraints and propose queries to fix it.\n\n");
        printf("Usage:\n    %s DBNAME [-v|--verbose] [-h|--help]\n\n", $this->prog);
        printf("Parameters:\n");
        printf("    DBNAME              The database (or \"schema\") to validate\n\n");
        printf("Available flags:\n");
        printf("    [-h|--help]         Print this help message and exit\n");
        printf("    [-v|--verbose]      Be verbose\n\n");
        printf("    [-vv]               Be even more verbose\n\n");
    }
}