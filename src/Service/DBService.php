<?php

namespace Casebox\CoreBundle\Service;

use PDO;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * Class DBService
 */
class DBService
{
    use ContainerAwareTrait;

    /**
     * @var PDO
     */
    protected $dbh;

    /**
     * @var array
     */
    protected $lastParams = [];

    /**
     * @param array $p
     *
     * @return PDO
     * @throws \Exception
     */
    public function connect($p = [])
    {
        // Check if not connected already
        if (!empty($this->dbh)) {
            return $this->dbh;
        }

        $platformConfig = Cache::get('platformConfig');

        if (empty($p['db_host'])) {
            $p['db_host'] = $platformConfig['db_host'];
        }
        if (empty($p['db_user'])) {
            $p['db_user'] = $platformConfig['db_user'];
        }
        if (empty($p['db_pass'])) {
            $p['db_pass'] = $platformConfig['db_pass'];
        }
        if (empty($p['db_name'])) {
            $p['db_name'] = $platformConfig['db_name'];
        }
        if (empty($p['db_port'])) {
            $p['db_port'] = $platformConfig['db_port'];
        }

        $this->dbh = $this->connectWithParams($p);

        return $this->dbh;
    }

    /**
     * @param $p
     *
     * @return PDO
     * @throws \Exception
     */
    public function connectWithParams(array $p)
    {
        @$newParams = [
            'host' => (!empty($p['db_host'])) ? $p['db_host'] : '127.0.0.1',
            'user' => $p['db_user'],
            'pass' => $p['db_pass'],
            'name' => $p['db_name'],
            'port' => $p['db_port'],
            'initsql' => $p['initsql'],
        ];

        // Check if new params are different from last params
        if ((@$this->lastParams['host'] != $newParams['host']) ||
            (@$this->lastParams['user'] != $newParams['user']) ||
            (@$this->lastParams['pass'] != $newParams['pass']) ||
            (@$this->lastParams['port'] != $newParams['port'])
        ) {
            // Close previous connection
            if (!empty($this->dbh)) {
                $this->dbh = null;
            }

            // Connect with new params
            try {
                $host = $newParams['host'];

                $port = '';
                if (!empty($newParams['port'])) {
                    $port = ';port='.$newParams['port'];
                }

                $dsn = 'mysql:host='.$host.$port.';dbname='.$newParams['name'];

                $user = $newParams['user'];
                $password = (!empty($newParams['pass'])) ? $newParams['pass'] : null;

                $this->dbh = new PDO($dsn, $user, $password, [PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8']);
                $this->dbh->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $this->dbh->exec('SET @@session.time_zone = "+00:00"');

            } catch (\PDOException $e) {
                throw new \Exception('Unable to connect to DB: '.$e->getMessage());
            }
        }

        $this->lastParams = $newParams;

        return $this->dbh;
    }

    /**
     * @return null
     */
    public function close()
    {
        $this->dbh = null;

        return;
    }

    /**
     * @param string $query
     * @param array $parameters
     *
     * @return \PDOStatement
     * @throws \Exception
     */
    public function query($query, $parameters = [])
    {
        if (empty($this->dbh)) {
            trigger_error('Error Database connections:'.__DIR__.' '.__FILE__.'('.__LINE__.')', E_USER_ERROR);
        }

        $hideErrors = true;

        //replace old style params($1, $2, .. ) into pdo native (:p1, :p2, ...)
        $query = preg_replace('/\$(\d+)/', ':p$1', $query);

        $sth = $this->dbh->prepare($query);

        $this->lastQuery = $query;

        if (!is_array($parameters)) {
            $parameters = [$parameters];
        }

        if (!empty($parameters['showErrors'])) {
            $hideErrors = false;
            unset($parameters['showErrors']);
        }

        if (!empty($parameters)) {
            foreach ($parameters as $k => $v) {
                if (!is_scalar($v) && !is_null($v)) {
                    throw new \Exception("param error: ".print_r($parameters, 1)."\n For SQL: $query", 1);
                }
                $param = ':'.(is_numeric($k) ? 'p'.($k + 1) : $k);
                $sth->bindParam($param, $v);
                unset($v); //Important
            }
        }

        $this->lastQueryParams = $parameters;
        set_error_handler(function ($severity, $message, $file, $line) {

        });
        if (($sth->execute() !== true) && !$hideErrors) {
            $this->raiseError();
        }
        restore_error_handler();

        return $sth;
    }

    /**
     * @return null
     */
    public function raiseError()
    {
        $info = $this->dbh->errorInfo()[2];
        $name = ": \n\r<br /><hr />Query error (".$this->lastParams['name']."): ".$info."<hr /><br />\n\r";
        $result = date('Y-m-d H:i:s').$name;

        if (!empty($this->lastQuery)) {
            $result .= "\n\r<br /><hr />Query: ".$this->lastQuery.$result;
        }

        trigger_error($result, E_USER_ERROR);
    }

    /**
     * @return bool
     */
    public function startTransaction()
    {
        return $this->dbh->beginTransaction();
    }

    /**
     * @return bool
     */
    public function commitTransaction()
    {
        $this->dbh->commit();

        return $this->dbh->setAttribute(PDO::ATTR_AUTOCOMMIT, true);
    }

    /**
     * @return string
     */
    public function lastInsertId()
    {
        return $this->dbh->lastInsertId();
    }

    /**
     * @return PDO
     */
    public function getDbh()
    {
        return $this->dbh;
    }
}
