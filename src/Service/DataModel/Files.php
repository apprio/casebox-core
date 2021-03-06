<?php

namespace Casebox\CoreBundle\Service\DataModel;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\User;

class Files extends Base
{
    /**
     * database table name
     * @var string
     */
    protected static $tableName = 'files';

    protected static $tableFields = [
        'id' => 'int',
        'content_id' => 'int',
        'date' => 'date',
        'name' => 'varchar',
        'title' => 'varchar',
        'cid' => 'int',
        'cdate' => 'datetime',
        'uid' => 'int',
        'udate' => 'datetime',
    ];

    /**
     * get content data
     *
     * @param  array $id
     *
     * @return array
     */
    public static function getContentData($id)
    {
        $rez = [];

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query(
            'SELECT fc.*
            FROM files f
            LEFT JOIN files_content fc ON f.content_id = fc.id
            WHERE f.id = $1',
            $id
        );

        if ($r = $res->fetch()) {
            $rez = $r;
        }
        unset($res);

        return $rez;
    }

    /**
     * get tipes for given file ids
     *
     * @param  array $ids
     *
     * @return array associative array (id => type)
     */
    public static function getTypes($ids)
    {
        $rez = [];
        $ids = Util\toNumericArray($ids);

        if (!empty($ids)) {
            $dbs = Cache::get('casebox_dbs');

            $res = $dbs->query(
                'SELECT f.id, c.`type`
                FROM files f
                JOIN files_content c
                    ON f.content_id = c.id
                WHERE f.id in ('.implode(',', $ids).')'
            );

            while ($r = $res->fetch()) {
                $rez[$r['id']] = $r['type'];
            }
            unset($res);
        }

        return $rez;
    }

    /**
     * get content ids for given file ids
     *
     * @param  array $ids
     *
     * @return array associative array (id => content_id)
     */
    public static function getContentIds($ids)
    {
        $rez = [];
        $ids = Util\toNumericArray($ids);

        if (!empty($ids)) {
            $dbs = Cache::get('casebox_dbs');

            $sql = 'SELECT id, content_id
                FROM files
                WHERE id in ('.implode(',', $ids).')';

            $res = $dbs->query($sql);
            while ($r = $res->fetch()) {
                $rez[$r['id']] = $r['content_id'];
            }
            unset($res);
        }

        return $rez;
    }

    /**
     * get relative content paths for given file ids
     * path is relative to casebox files directory
     *
     * @param  array $ids
     *
     * @return array associative array (id => relative_content_path)
     */
    public static function getContentPaths($ids)
    {
        $rez = [];
        $ids = Util\toNumericArray($ids);

        if (!empty($ids)) {
            $dbs = Cache::get('casebox_dbs');

            $sql = 'SELECT f.id, c.`path`, f.content_id
                FROM files f
                JOIN files_content c
                    ON f.content_id = c.id
                WHERE f.id in ('.implode(',', $ids).')';

            $res = $dbs->query($sql);
            while ($r = $res->fetch()) {
                $rez[$r['id']] = $r['path'].DIRECTORY_SEPARATOR.$r['content_id'];
            }
            unset($res);
        }

        return $rez;
    }

    /**
     * get file ids that reffer to a given contentId
     * @return array
     */
    public static function getContentIdReferences($contentId)
    {
        $rez = [];

        $dbs = Cache::get('casebox_dbs');

        $sql = 'SELECT id
            FROM files
            WHERE content_id = $1
            ORDER BY id';

        $res = $dbs->query($sql, $contentId);
        while ($r = $res->fetch()) {
            $rez[] = $r['id'];
        }
        unset($res);

        return $rez;
    }

    /**
     * get duplicates files (with same content_id) for a given file id
     *
     * @param  int $id
     *
     * @return array
     */
    public static function getDuplicates($id)
    {
        $rez = [];

        if (!is_numeric($id)) {
            return $rez;
        }

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query(
            'SELECT
                 fd.id
                ,fd.cid
                ,fd.cdate
                ,case when(fd.name = f.name) THEN "" ELSE fd.name END `name`
                ,ti.pids `path`
                ,ti.path `pathtext`
            FROM files f
            JOIN files fd
                ON f.content_id = fd.content_id
                AND fd.id <> $1
            JOIN tree t
                ON fd.id = t.id
                and t.dstatus = 0
            JOIN tree_info ti
                ON t.id = ti.id
            WHERE f.id = $1',
            $id
        );

        while ($r = $res->fetch()) {
            $r['path'] = str_replace(',', '/', $r['path']);
            $rez[] = $r;
        }
        unset($res);

        return $rez;
    }

    /**
     * get file solr data
     *
     * @param  int $id
     *
     * @return array
     */
    public static function getSolrData($id)
    {
        $rez = [];

        $dbs = Cache::get('casebox_dbs');

        $res = $dbs->query(
            'SELECT c.size
            ,(SELECT count(*)
                FROM files_versions
                WHERE file_id = f.id
            ) `versions`
            FROM files f
            LEFT JOIN files_content c
                ON f.content_id = c.id
            WHERE f.id = $1',
            $id
        );

        if ($r = $res->fetch()) {
            $rez = $r;
        }

        unset($res);

        return $rez;
    }

    /**
     * copy file data, but without versions. Should we copy versions also?
     *
     * @param  int $sourceId
     * @param  int $targetId
     *
     * @return void
     */
    public static function copy($sourceId, $targetId)
    {
        $dbs = Cache::get('casebox_dbs');

        $dbs->query(
            'INSERT INTO `files`
                (`id`
                ,`content_id`
                ,`date`
                ,`name`
                ,`title`
                ,`cid`
                ,`uid`
                ,`cdate`
                ,`udate`)
            SELECT
                $2
                ,`content_id`
                ,`date`
                ,`name`
                ,`title`
                ,`cid`
                ,$3
                ,`cdate`
                ,CURRENT_TIMESTAMP
            FROM `files`
            WHERE id = $1',
            [
                $sourceId,
                $targetId,
                User::getId(),
            ]
        );
    }
}
