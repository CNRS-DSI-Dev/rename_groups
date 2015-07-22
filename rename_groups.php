<?php

/**
 *
 * Command line php script for renaming groups from a CSV file
 * See /tmp by default for logs. To use the script, modify the CONF constants as needed and then run the following command on an existing database :
 * cd <install_dir_script>; php rename_groups.php
 *
 * @copyright 2015 CNRS DSI
 * @author Patrick Paysant patrick.paysant@linagora.com
 * @licence This file is licensed under the Affero General Public License version 3 or later
 *
 */

/******* CONF *********************/
// Config file complete path
define('CONF_FILE', '/var/www/owncloud/config/config.php');
// Database server
define('DB_HOST', 'localhost');
// Port to database
define('DB_PORT', 3306);
// Schema to scan
define('DB_BASE', 'owncloud');
// Connection username
define('DB_USER', 'login');
// Connection password
define('DB_PASS', 'password');
// Database name for "Information Schema", if there's a name change, in the future
define('INFO_SCHEMA', 'information_schema');
// Name of the field identifying a group
define('SEARCHED_FIELD', 'gid');
// CSV File containing the groups oldname / newname pairs
define('CSV_FILE', './test_csv.csv');
// log file
define('LOGFILE', '/tmp/renameGroups.log');
/******* END CONF *********************/

require_once('vendor/autoload.php');

use League\Csv\Reader;

/******* MAIN *********************/
$climate = new League\CLImate\CLImate;
$climate->green()->out('RenameGroups launched');

$logger = new Logger(LOGFILE);

$gr = new GroupRenamer();
// comment if you don't want complete log (only exceptions will be logged)
$gr->setLogger($logger);

$gr->setCli($climate);

try {
    $gr->run();
}
catch (Exception $e) {
    $climate->red()->out('ERROR ! ' . $e->getMessage());
    $logger->put($e->getMessage());
}

$climate->green()->out('END');
/******* END MAIN *********************/

class GroupRenamer
{
    protected $db_schema;
    protected $db;
    protected $logger;
    protected $cli;

    /**
     * Initialize PDO connections (PDO need one connection for each base)
     */
    public function __construct()
    {
        $this->db_schema = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . INFO_SCHEMA . ';charset=utf8', DB_USER, DB_PASS);
        $this->db_schema->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db_schema->setAttribute(PDO::MYSQL_ATTR_FOUND_ROWS, true);

        $this->db = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_BASE . ';charset=utf8', DB_USER, DB_PASS);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::MYSQL_ATTR_FOUND_ROWS, true);
    }

    /**
     * Start the real work
     */
    public function run()
    {
        $this->log("Starting.");

        // Maintenance ?
        if ($this->isMaintenanceMode()) {
            $this->cli->red()->out('We are in maintenance mode, exiting now.');
            exit;
        }

        // Look for group renaming to do
        $this->cli->green()->out('- Reading the csv file ' . CSV_FILE);
        $groups = $this->readCSVFile();
        $this->cli->darkGray()->table($groups);

        // Look for tables where group renaming will take place
        $this->cli->green()->out('- Looking for tables containing ' . SEARCHED_FIELD);
        $tables = $this->getTablesWithSearchedField();
        $this->cli->green()->out('- Found following tables :');
        $this->cli->darkGray()->table($tables);

        // Change, for each group in each table the oldname to the new one
        $this->cli->green()->out('- Starting renaming process :');
        if ($this->changeName($groups, $tables)) {
            $this->cli->green()->out('- SUCCESS');
        }
        else {
            $this->cli->red()->out('- FAIL');
        }

    }

    /**
     * Return true if owncloud site is in maintenance (from config.php)
     * @return boolean
     */
    protected function isMaintenanceMode()
    {
        $maintenance = false;

        // quick & dirty conf read
        if (is_readable(CONF_FILE)) {
            include(CONF_FILE);
            if (isset($CONFIG['maintenance'])) {
                $maintenance = $CONFIG['maintenance'];
            }
        }
        else {
            $this->cli->red()->out('Error: config.php file not found, please set the const CONF_FILE in top of this script.');
        }

        return $maintenance;
    }

    /**
     * Parse CSV_FILE and returns an array of oldname / newname pairs (each in an array)
     * [
     *    ['old' => 'name1', 'new' => 'newname1'],
     *    ['old' => 'name2', 'new' => 'newname2'],
     * ...
     * ]
     * @return array
     */
    protected function readCSVFile()
    {
        $groupsToRename = array();

        $reader = Reader::createFromPath(CSV_FILE);
        // Skip first line (headers)
        $groupsToRename = $reader->setOffset(1)->fetchAssoc(['old', 'new']);

        return $groupsToRename;
    }

    /**
     * Get list of tables containing the SEACRHED_FIELD
     * @return array
     */
    protected function getTablesWithSearchedField()
    {
        $tables = array();

        $sql = "SELECT DISTINCT(TABLE_NAME) AS name FROM COLUMNS WHERE TABLE_SCHEMA = :baseName AND COLUMN_NAME = :columnName";
        $st = $this->db_schema->prepare($sql);
        $st->execute(array(
            ':baseName' => DB_BASE,
            ':columnName' => SEARCHED_FIELD,
        ));

        if ($st->rowCount() > 0) {
            $this->log("Found " . $st->rowCount() . " tables with '" . SEARCHED_FIELD ."' field.");
            while($table = $st->fetch(PDO::FETCH_ASSOC)) {
                array_push($tables, array($table['name']));
            }
        }

        return $tables ;
    }

    /**
     * Change, for each group in each table the oldname to the new one
     * @param array $groups
     * @param array $tables
     * @return boolean
     */
    protected function changeName($groups, $tables)
    {
        // seriously ?
        if (empty($groups) or !is_array($groups) or empty($tables) or !is_array($tables)) {
            return false;
        }

        // all in a single transaction
        $this->db->beginTransaction();

        foreach($tables as $table) {
            $this->cli->darkGray()->out('Processing ' . $table[0]);

            // Verify that one newName does not already exist in target table
            if ($this->checkAvailabity($table[0], $groups)) {
                $sql = "UPDATE " . $table[0] . " SET `" . SEARCHED_FIELD . "`= :newName WHERE `" . SEARCHED_FIELD . "`= :oldName";
                $st = $this->db->prepare($sql);

                foreach ($groups as $group) {
                    $st->execute(array(
                        ':newName' => $group['new'],
                        ':oldName' => $group['old'],
                    ));
                }
            }
            else {
                // cancel transaction
                $this->db->rollBack();
                // TODO: throw exception
                return false;
            }
        }

        // Validate all changes
        $this->db->commit();

        return true;
    }

    /**
     * Verify that one newName does not already exist in target table
     * @param string $targetTable
     * @param array $groups Contains old and new values for each group
     * @return boolean
     **/
    function checkAvailabity($targetTable, $groups)
    {
        if (empty($groups) or !is_array($groups)) {
            return false;
        }

        foreach($groups as $group) {
            $sql = "SELECT `" . SEARCHED_FIELD . "` FROM " . $targetTable . " WHERE `" . SEARCHED_FIELD . "`= :newName";
            $st = $this->db->prepare($sql);
            $st->execute(array(
                ':newName' => $group['new'],
            ));

            if ($st->rowCount() > 0) {
                $this->cli->red()->out('FATAL : ' . $group['new'] . ' already exists in table ' . $targetTable);
                return false;
            }
        }

        return true;
    }

    /**
     * Setter for $logger
     * @param Logger $logger Instance of Logger class
     */
    public function setLogger(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Setter for $cli
     * @param League\CLImate\Climate $cli class used for pretty display on CLI
     */
    public function setCli(League\CLImate\CLImate $cli)
    {
        $this->cli = $cli;
    }

    /**
     * API for logger, verify if logger is set
     * @param string $msg Message to log
     */
    protected function log($msg)
    {
        if (isset($this->logger)) {
            $this->logger->put($msg);
        }
    }
}

/**
 * Allow quick & easy logging
 */
class Logger
{
    const LOGFILE = '/tmp/script.log';

    protected $logFile;

    /**
     * Open the file for future adding
     */
    public function __construct($logfile='')
    {
        if ($logfile == '') {
            $logfile = self::LOGFILE;
        }
        $this->logFile = fopen($logfile, 'a');
    }

    /**
     * Add a string to the logfile, display it to console
     * @param string $msg Message to log
     */
    public function put($msg)
    {
        if (!empty($msg)) {
            $date = date("d/m/Y H:i:s");
            $logMsg = $date . ' ' . $msg . "\n";
            fputs($this->logFile, $logMsg);
        }
    }

    public function __destruct()
    {
        fclose($this->logFile);
    }
}
