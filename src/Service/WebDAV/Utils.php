<?php
namespace Casebox\CoreBundle\Service\WebDAV;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Files;
use Casebox\CoreBundle\Service\Objects\File;
use Casebox\CoreBundle\Service\Objects\CBObject;
use Casebox\CoreBundle\Service\Search;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class Utils
 */
class Utils
{
    /**
     *  Loads CB CBObject by Id
     *
     * @param int $id nodeId
     *
     * @return CBObject
     */
    public static function getNodeById($id)
    {
        $o = new CBObject();

        return $o->load($id);
    }

    /**
     * @param int $id FileId
     *
     * @return File
     */
    public static function getFileById($id)
    {
        $file = new File($id);

        return $file->load();
    }

    /**
     * @param integer $id
     * @param integer|null $fileId
     *
     * @return \Apache_Solr_Response
     */
    public static function solrGetChildren($id, $fileId = null)
    {
        $s = new Search();

        $query = 'pid: '.$id;

        // fetch only a single file
        if (@$fileId) {
            $query = 'id: '.$fileId;
        }

        $params = [
            'fl' => 'id, name, template_id, date, cdate, uid, udate, size',
            'fq' => [
                'dstatus: 0',
                'system: [0 TO 1]',
            ],
            'sort' => 'sort_name asc',
        ];

        $data = $s->search($query, 0, 1000, $params);

        return $data;
    }

    /**
     * Returns CB nodes as simple array
     *
     * @param integer $id
     * @param string $path
     * @param string $env
     * @param integer $fileId
     *
     * @return array
     */
    public static function getChildren($id, $path, $env, $fileId)
    {
        $defaultFileTemplate = Cache::get('symfony.container')->get('casebox_core.service.config')->get(
            'default_file_template'
        );

        $data = Utils::solrGetChildren($id, $fileId);

        // Process SOLR results into a simple array
        $fileIds = [];
        $array = [];
        foreach ($data->response->docs as $item) {
            $el = [
                'id' => $item->id,
                'name' => $item->name,
                'template_id' => $item->template_id,
                'size' => $item->size,
                'cdate' => $item->cdate,
                'uid' => $item->uid,
                'udate' => $item->udate,
                'path' => $path.DIRECTORY_SEPARATOR.$item->name,
            ];

            // PropertyStorage will use filename as path, without the 'edit-{nodeId}' folder
            if ($env['mode'] == 'edit') {
                $el['path'] = $item->name;
            }

            // Remember Files: more properties will be fetched below
            if ($item->template_id == $defaultFileTemplate) {
                $fileIds[] = $el['id'];
            }

            $array[$el['id']] = $el;
        }

        // Are there any files in Directory?
        if (!empty($fileIds)) {
            $dbs = Cache::get('casebox_dbs');

            $res = $dbs->query(
                'SELECT f.id, CONCAT(c.path, \'/\', f.content_id) `content_path`, c.md5, c.type
                FROM files f
                LEFT JOIN files_content c ON f.content_id = c.id
                WHERE f.id in ('.implode(',', $fileIds).')'
            );

            // Append additional file info (content_path, MD5, type)
            while ($r = $res->fetch()) {
                $array[$r['id']] = array_merge($array[$r['id']], $r);
            }
            unset($res);
        }

        // Save the nodes in Cache for later use in WebDAV\PropertyStorage (creationdate and other props)
        Utils::cacheNodes($array);

        return $array;
    }

    /**
     * @param $array
     */
    public static function cacheNodes($array)
    {
        // Initialize DAVNodes cache
        if (!Cache::exist('DAVNodes')) {
            Cache::set('DAVNodes', []);
        }
        $cachedNodes = Cache::get('DAVNodes');

        // Store nodes in cache
        foreach ($array as $node) {

            $path = str_replace('\\', '/', $node['path']);
            $path = trim($path, '/');

            $cachedNodes[$path] = $node;
        }

        Cache::set('DAVNodes', $cachedNodes);
    }

    /**
     * @param integer $id
     *
     * @return array|null
     */
    public static function getParentNodeId($id)
    {
        $node = Utils::getNodeById($id);

        if ((count($node) == 0) or ($node['dstatus'] != 0)) {
            return null;
        }
        $pids = explode(',', $node['pids']);

        $pid = array_pop($pids);

        return $pid;
    }

    /**
     * @param integer $pid
     * @param string $name
     *
     * @return CBObject|int
     * @throws \Exception
     */
    public static function createDirectory($pid, $name)
    {
        $item = [
            'pid' => $pid,
            'name' => $name,
            // date column is not present in template for folders
            // ,'date' => date('Y-m-d')
            'template_id' => Cache::get('symfony.container')->get('casebox_core.service.config')->get(
                'default_folder_template'
            ),
            'data' => ['_title' => $name],
        ];
        $temp = new CBObject();
        $temp = $temp->create($item);

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');

        return $temp;
    }

    /**
     * @param integer $pid
     * @param string $name
     * @param array|null $data
     *
     * @throws \Exception
     */
    public static function createCaseboxFile($pid, $name, $data = null)
    {
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        $path = $configService->get('incomming_files_dir').$name;

        file_put_contents($path, $data);

        $action = 'newversion';
        //check if file exists and its size is 0
        $id = Files::getFileId($pid, $name);
        if (!empty($id)) {
            if (Files::getSize($id) <= 1) {
                $action = 'replace';
            }
        }

        return;

        $user = Cache::get('symfony.container')->get('casebox_core.service.user')->getUserData();

        $param = [
            'pid' => $pid,
            'title' => $name,
            'localFile' => $path,
            'owner' => $user['id'],
            'tmplId' => $configService->get('default_file_template'),
            'fileExistAction' => $action,
        ];

        $fl = new Files();
        $fl->upload($param);

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');
    }

    /**
     *  Updates the '_title' of a CB node
     *
     * @param int $id
     * @param string $name
     *
     * @return bool
     */
    public static function renameObject($id, $name)
    {
        $file = new File();
        $data = $file->load($id);

        $data['name'] = $name;
        $data['data']['_title'] = $name;

        $file->setData($data);
        $file->update();

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');
    }

    /**
     * @param integer $id
     */
    public static function deleteObject($id)
    {
        $node = new CBObject($id);
        $node->delete();

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');
    }
}
