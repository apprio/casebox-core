<?php

namespace Casebox\CoreBundle\Service\DataModel;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Util;

/**
 * Class Notifications
 */
class Notifications extends Base
{
    /**
     * database table name
     * @var string
     */
    protected static $tableName = 'notifications';

    /**
     * available fields for notifications table
     *
     * associative array of fieldName => type
     *
     * @var array
     */
    protected static $tableFields = [
        'id' => 'int',
        'object_id' => 'int',
        'action_id' => 'int',
        'action_type' => 'varchar',
        'from_user_id' => 'int',
        'user_id' => 'int',
        'seen' => 'int',
        'read' => 'int',
    ];

    /**
     * add a notification record
     * its a bit different from standart create because it updated the record
     * on key dupliaction
     *
     * @param  array $p associative array with table field values
     *
     * @return int   created id
     */
    public static function add($p)
    {
        static::validateParamTypes($p);

        $dbs = Cache::get('casebox_dbs');

        //prepare params
        $params = [
            empty($p['object_id']) ? null : $p['object_id'],
            empty($p['action_id']) ? null : $p['action_id'],
            empty($p['action_type']) ? null : $p['action_type'],
            empty($p['from_user_id']) ? null : $p['from_user_id'],
            empty($p['user_id']) ? null : $p['user_id'],
            empty($p['seen']) ? 0 : $p['seen'],
        ];

        //add database record
        $sql = 'INSERT INTO `'.static::getTableName().'` (
            object_id
            ,action_id
            ,action_ids
            ,action_type
            ,from_user_id
            ,user_id
            ,seen
            )
            VALUES($1, $2, $2, $3, $4, $5, $6)

            ON DUPLICATE KEY

            UPDATE
            action_id = $2
            ,action_ids = CASE WHEN `read` = 1 THEN $2 ELSE CONCAT($2, \',\', action_ids) END
            ,seen = $6
            ,`read` = 0';

        $dbs->query($sql, $params);

        $rez = $dbs->lastInsertId();

        return $rez;
    }

    /**
     * get last notification records for a given user
     *
     * @param  int $userId
     * @param  int $limit max number of records returned
     * @param  int $fromId return only notifications newer than given id
     *
     * @return array
     */
    public static function getLast($userId, $limit = 200, $fromId = false)
    {
        $rez = [];

        //validate params
        Util\raiseErrorIf(
            !is_numeric($userId) ||
            !is_numeric($limit) ||
            ($fromId !== false && !is_numeric($fromId)),
            'ErroneousInputData'
        );

        $dbs = Cache::get('casebox_dbs');

        $sql = 'SELECT
            n.id
            ,l.object_id
            ,l.action_type
            ,n.action_id
            ,n.read
            ,n.from_user_id
            ,n.user_id
            ,l.data
            ,l.action_time
        FROM `'.static::getTableName().'` n
        JOIN action_log l
            ON n.action_id = l.id
        WHERE n.user_id = $1 '.
            (empty($fromId) ? '' : ' AND n.action_id > $2 ').
            'ORDER BY l.action_time DESC, id DESC
        LIMIT '.$limit;

        $params = [
            $userId,
        ];
        if (!empty($fromId)) {
            $params[] = $fromId;
        }
        $res = $dbs->query($sql, $params);

        while ($r = $res->fetch()) {
            $rez[] = $r;
        }
        unset($res);

        return $rez;
    }

    /**
     * get notifications that were not seen
     *
     * @param  int $userId optional
     *
     * @return array
     */
    public static function getUnseen($userId = false)
    {
        $rez = [];

        //validate params
        Util\raiseErrorIf(
            ($userId !== false) && !is_numeric($userId),
            'ErroneousInputData'
        );

        $dbs = Cache::get('casebox_dbs');

        $sql = 'SELECT
            n.id
            ,n.object_id
            ,n.action_ids
            ,n.action_type
            ,n.user_id `to_user_id`
            ,n.`from_user_id`
            ,l.object_pid
            ,l.action_time
            ,l.data
            ,l.activity_data_db
        FROM `'.static::getTableName().'` n
            JOIN action_log l
                ON n.action_id = l.id
        WHERE n.seen = 0 '.
            (($userId == false)
                ? ''
                : ' AND user_id = $1 '
            ).
            'ORDER BY n.user_id
           ,l.`action_time` DESC';

        $res = $dbs->query($sql, $userId);

        while ($r = $res->fetch()) {
            $r['data'] = Util\jsonDecode($r['data']);
            $r['activity_data_db'] = Util\jsonDecode($r['activity_data_db']);
            $rez[] = $r;
        }
        unset($res);

        return $rez;
    }

    /**
     * get last notifications count
     *
     * @param  int $userId
     * @param  int $fromId return only notifications newer than given id
     *
     * @return array
     */
    public static function getCount($userId, $fromId = false)
    {
        $rez = 0;

        //validate params
        Util\raiseErrorIf(!is_numeric($userId), 'ErroneousInputData');

        $dbs = Cache::get('casebox_dbs');

        if (empty($fromId) || !is_numeric($fromId)) {
            $fromId = 0;
        }

        $sql = 'SELECT count(*) `count`FROM `'.static::getTableName().'`WHERE user_id = $1 AND action_id > $2';

        $res = $dbs->query(
            $sql,
            [
                $userId,
                $fromId,
            ]
        );

        if ($r = $res->fetch()) {
            $rez = $r['count'];
        }
        unset($res);

        return $rez;

    }

    /**
     * mark user notifications as read
     *
     * @param  int[] $ids
     * @param  int $userId
     *
     * @return void
     */
    public static function markAsRead($ids, $userId)
    {
        //validate params
        Util\raiseErrorIf(!is_numeric($userId), 'ErroneousInputData');

        $dbs = Cache::get('casebox_dbs');

        $ids = Util\toNumericArray($ids);

        if (!empty($ids)) {
            $dbs->query(
                'UPDATE `'.static::getTableName().'`
                SET `read` = 1
                WHERE user_id = $1 AND id IN ('.implode(',', $ids).')',
                $userId
            );
        }
    }

    /**
     * mark user notifications as seen
     *
     * @param  int $id
     * @param  int $userId
     *
     * @return void
     */
    public static function markAsSeenUpToActionId($id, $userId)
    {
        //validate params
        Util\raiseErrorIf(!is_numeric($userId), 'ErroneousInputData');

        $dbs = Cache::get('casebox_dbs');

        if (is_numeric($id)) {
            $dbs->query(
                'UPDATE `'.static::getTableName().'`
                SET `seen` = 1
                WHERE user_id = $1 AND action_id <= $2 AND seen = 0',
                [$userId, $id]
            );
        }
    }

    /**
     * mark user notifications as seen
     *
     * @param string | array $id notification ids
     * @param  int $userId
     *
     * @return void
     */
    public static function markAsSeen($ids, $userId)
    {
        Util\raiseErrorIf(!is_numeric($userId), 'ErroneousInputData');

        $dbs = Cache::get('casebox_dbs');

        $ids = Util\toNumericArray($ids);
        if (!empty($ids)) {
            $dbs->query(
                'UPDATE `'.static::getTableName().'`
                SET `seen` = 1
                WHERE user_id = $1
                    AND id IN ('.implode(',', $ids).')
                    AND seen = 0',
                $userId
            );
        }
    }

    /**
     * mark all notifications as read for given user
     *
     * @param  int $userId
     *
     * @return void
     */
    public static function markAllAsRead($userId)
    {
        //validate params
        Util\raiseErrorIf(!is_numeric($userId), 'ErroneousInputData');

        $dbs = Cache::get('casebox_dbs');

        $dbs->query(
            'UPDATE `'.static::getTableName().'`
            SET `read` = 1
            WHERE user_id = $1 AND `read` = 0',
            $userId
        );
    }
}
