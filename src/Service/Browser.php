<?php

namespace Casebox\CoreBundle\Service;

use Casebox\CoreBundle\Event\TreeInitializeEvent;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class Browser
 */
class Browser
{
    use TranslatorTrait;

    /**
     * @var array
     */
    protected $path = [];

    /**
     * @var array
     */
    protected $treeNodeConfigs = [];

    /**
     * @var array
     */
    protected $treeNodeGUIDConfigs = [];

    /**
     * @var array
     */
    protected $treeNodeClasses = [];

    /**
     * @param array $p
     *
     * @return array
     */
    public function getChildren($p)
    {
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        // Unset restricted query params from user input
        unset($p['fq']);

        $path = '/';
        if (!isset($p['path']) || (strlen($p['path']) < 1)) {
            if (!empty($p['pid'])) {
                $path = $p['pid'];
            }
        } else {
            $path = $p['path'];
        }
        $p['path'] = $path;

        //check if user have changed the row limit in grid
        if (!empty($p['setMaxRows']) && !empty($p['rows'])) {
            User::setGridMaxRows($p['rows']);
        }

        //the navigation goes from search results. We should get the real path of the node
        if (!empty($p['lastQuery']) && empty($p['query'])) {
            while (substr($path, -1) == '/') {
                $path = substr($path, 0, strlen($path) - 1);
            }
            $a = explode('/', $path);
            if (!empty($a) && is_numeric($a[sizeof($a) - 1])) {
                $path = @Path::getPath(array_pop($a))['path'];
                $p['path'] = $path;
            }
        }

        $this->showFoldersContent = isset($p['showFoldersContent']) ? $p['showFoldersContent'] : false;

        $this->requestParams = $p;

        /* end of prepare params */

        /* we should:
            1. load available plugins for the tree with their configs
            2. fire the on treeInitialize event
            3. call each plugin with received params
            4. join and sort received data
        */

        //detect tree nodes config,
        //but leave only SearchResults plugin when searching
        if (empty($p['search'])) {
            if (empty($p['query'])) {
                $this->treeNodeConfigs = $configService->get('treeNodes');
            }

            // default is only DBNode if nothing defined in cofig
            if (empty($this->treeNodeConfigs)) {
                $this->treeNodeConfigs = ['Dbnode' => []];
            }

        } else {
            $this->treeNodeConfigs = ['SearchResults' => $p['search']];
            $path = Path::getGUID('SearchResults').'-';
        }

        $params = [
            'params' => &$p,
            'plugins' => &$this->treeNodeConfigs,
        ];

        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('treeInitialize', new TreeInitializeEvent($params));

        // Array of all available classes defined in treeNodes,
        // used to check if any class should add its nodes based on last node from current path
        $this->treeNodeClasses = Path::getNodeClasses($this->treeNodeConfigs);

        foreach ($this->treeNodeClasses as &$nodeClass) {
            $cfg = $nodeClass->getConfig();
            $this->treeNodeGUIDConfigs[$cfg['guid']] = $cfg;
        }

        $this->path = Path::createNodesPath($path, $this->treeNodeGUIDConfigs);

        // Set path and input params for last node
        //      because iterating each class and requesting children can
        //      invoke a search that will use last node to get facets and DC
        $lastNode = null;
        if (!empty($this->path)) {
            $lastNode = $this->path[(is_array($path)?sizeof($path):1) - 1];
            $lastNode->path = $this->path;
            $lastNode->requestParams = $this->requestParams;
        }

        Cache::set('current_path', $this->path);

        $this->result = [
            'data' => [],
            'blockData' => [],
            'facets' => [],
            'pivot' => [],
            'search' => [],
            'view' => [],
            'sort' => [],
            'group' => [],
            'stats' => [],
            'DC' => [],
            'total' => 0,
        ];

        // Get view config and apply to request params and for result
        $viewConfig = $this->detectViewConfig();
        $this->requestParams['view'] = $viewConfig;
        $this->result['view'] = $viewConfig;

        //detect availableviews
        $av = 'grid,charts,pivot,activeStream';
        if (!empty($lastNode)) {
            $r = $lastNode->getNodeParam('availableViews');
            if (!empty($r['data'])) {
                $av = $r['data'];
            }
        } else {
            $r = $configService->get('availableViews');
            if (empty($r)) {
                $r = $configService->get('default_availableViews');
            }

            if (!empty($r)) {
                $av = $r;
            }
        }
        $this->result['availableViews'] = Util\toTrimmedArray($av);

        //remove sorting for some views
        if (isset($viewConfig['type'])) {
            switch ($viewConfig['type']) {
                case 'pivot':
                case 'charts':
                case 'activityStream':
                    $this->requestParams['sort'][0]['property'] = 'last_action_tdt';
                    $this->requestParams['sort'][0]['direction'] = 'desc';
                case 'calendar':
                    $this->requestParams['sort'] = null;
            }
        }

        $this->requestParams['facets'] = $this->detectFacets();

        $this->collectAllChildren();
        $this->prepareResult();

        $rez = [
            'success' => true,
            'pathtext' => $this->getPathText($p),
            'folderProperties' => $this->getPathProperties($p),
            'page' => @$p['page'],
            'data' => [],
        ];

        foreach ($this->result as $k => &$v) {
            if (!empty($this->result[$k])) {
                $rez[$k] = &$v;
            }
        }

        return $rez;

    }

    protected function getPathText()
    {
        $rez = [];
        if (empty($this->path)) {
            return '/';
        }

        foreach ($this->path as $n) {
            $rez[] = str_replace('/', '&#47;', $n->getName());
        }

        return implode('/', $rez);
    }

    protected function getPathProperties()
    {
        $rez = [];

        if (empty($this->path)) {
            $rez['path'] = '/';
        } else {
            $rez = $this->path[sizeof($this->path) - 1]->getData();

            $idsPath = [];
            foreach ($this->path as $n) {
                $idsPath[] = $n->getId();
            }

            $rez['name'] = @Util\adjustTextForDisplay($rez['name']);
            $rez['path'] = '/'.implode('/', $idsPath);
            $rp = $this->requestParams;
            $rez['menu'] = $this->path[sizeof($this->path) - 1]->getCreateMenu($rp);
        }

        return $rez;
    }

    /**
     * detect the resulting view and its params
     * from request params and node configs
     *
     * @return array view config
     */
    protected function detectViewConfig()
    {
        $rez = [];

        $rp = &$this->requestParams;

        foreach ($this->treeNodeClasses as $class) {
            try {
                $r = $class->getViewConfig($this->path, $rp);

                if (!empty($r)) {
                    $rez = $r;
                }
            } catch (\Exception $e) {
                Cache::get('symfony.container')->get('logger')->error(
                    get_class($class).' exception on getViewConfig',
                    $rp
                );
            }
        }

        return $rez;
    }

    /**
     * detect facet configs that should be displayed for last node in path
     *
     * @return array
     */
    protected function detectFacets()
    {
        $rez = [];

        $rp = &$this->requestParams;

        if (!empty($this->path)) {
            $rez = $this->path[sizeof($this->path) - 1]->getFacets($rp);
        }

        return $rez;
    }

    protected function collectAllChildren()
    {

        // $this->data = array();
        // $this->facets = array();
        // $this->pivot = array();
        // $this->total = 0;
        // $this->search = array();
        // $this->DC = array();

        $rez = &$this->result;

        foreach ($this->treeNodeClasses as $class) {
            try {
                $r = $class->getChildren($this->path, $this->requestParams);

                //merging all returned records into a single array
                if (!empty($r['data'])) {
                    $rez['data'] = array_merge($rez['data'], $r['data']);

                    //set display columns and sorting if present
                    if (isset($r['DC'])) {
                        $rez['DC'][] = $r['DC'];
                    }

                    /*if (isset($r['view'])) {
                      //print_r(gettype($r['view']));
                        $rez['view'] = array_merge($rez['view'], $r['view']);
                    }*/

                    // if (isset($r['sort'])) {
                    //     $rez['view']['sort'] = $r['sort'];
                    // }

                    // if (isset($r['group'])) {
                    //     $rez['view']['group'] = $r['group'];
                    // }
                }

                $params = [
                    'blockData',
                    'facets',
                    'pivot',
                    // 'view',
                    'stats',
                ];

                foreach ($params as $param) {
                    if (isset($r[$param])) {
                        $rez[$param] = $r[$param];
                    }
                }

                //calc totals accordingly
                if (isset($r['total'])) {
                    $rez['total'] += $r['total'];
                } elseif (!empty($r['data'])) {
                    $rez['total'] += sizeof($r['data']);
                }

                //if its debug host - search params will be also returned
                if (isset($r['search'])) {
                    $rez['search'][] = $r['search'];
                }
            } catch (\Exception $e) {
                Cache::get('symfony.container')->get('logger')->error(
                    get_class($class)." exception on getChildren\n".$e->getTraceAsString(),
                    $this->requestParams
                );
            }
        }

        $this->setCustomIcons($rez['data']);

    }

    protected function sortResult()
    {
        //sorting nodes;
    }

    /**
     * Return records for an objects field based on its config
     *
     * @param array $p
     *
     * @return array repsponce
     */
    public function getObjectsForField($p)
    {
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        // ,"scope": 'tree' //project, parent, self, $node_id
        // ,"field": <field_name> //for field type

        // ,"descendants": true
        // /* filter used for objects */
        // ,+"tags": []
        // ,+"types": []
        // ,+"templates": []
        // ,"templateGroups": []

        //,+query - user query

        //unset restricted query params from user input
        unset($p['fq']);

        $ids = [];
        $fieldConfig = [];
        // get field config from database
        if (!empty($p['fieldId']) && is_numeric($p['fieldId'])) {
            $r = DM\TemplatesStructure::read($p['fieldId']);

            if (!empty($r['cfg'])) {
                $fieldConfig = $r['cfg'];

                //Dont add filter when ids are given (showing selected value)
                if (empty($p['ids']) && !empty($r['cfg']['fq'])) {
                    $p['fq'] = $r['cfg']['fq'];
                }
            }
        }

        if (!empty($fieldConfig['source'])) {
            if (is_array($fieldConfig['source'])) { // a custom source
                $source = $fieldConfig['source'];
                $rez = [];

                if (empty($p['fieldId'])) {
                    return $rez;
                }

                //analize custom method sources
                if (!empty($fieldConfig['source']['fn'])) {

                    $method = explode('.', $fieldConfig['source']['fn']);
                    $class = new $method[0]();
                    $rez = $class->$method[1]($p);

                    // if custom source returned any result then return it right there
                    // otherwise custom source can add some filtering params and we go further processing
                    if (!empty($rez)) {
                        return $rez;
                    }
                }

                //analize facet sources
                if (!empty($source['facet'])) {
                    //creating facets
                    $facetsDefinitions = $configService->get('facet_configs');

                    if (!empty($facetsDefinitions[$source['facet']])) {
                        $conf = $facetsDefinitions[$source['facet']];
                        $conf['name'] = $source['facet'];

                        if (!empty($source['sort'])) {
                            $conf['sort'] = $source['sort'];
                        }

                        $facet = Facets::getFacetObject($conf);

                        if (!empty($facet)) {
                            $p['rows'] = 0;
                            $p['facets'] = [
                                $source['facet'] => &$facet,
                            ];

                            if (empty($p['fq'])) {
                                $p['fq'] = [];
                            }
                            if (!empty($source['fq'])) {
                                $p['fq'] = array_unique(array_merge($p['fq'], $source['fq']));
                            }

                            //apply other params that are set in source
                            $p = array_merge(
                                $p,
                                array_intersect_key(
                                    $source,
                                    [
                                        'templates' => 1,
                                        'pid' => 1,
                                        'query' => 1,
                                        'descendants' => 1,
                                    ]
                                )
                            );

                            $search = new Search();
                            $search->query($p);
                            $cd = $facet->getClientData();

                            foreach ($cd['items'] as $id => $v) {
                                $rez['data'][] = [
                                    'id' => $id,
                                    'name' => $v['name'].' ('.$v['count'].')',
                                ];
                            }
                        }
                    }
                }

                if (!empty($rez)) {
                    return $rez;
                }
            }

            switch ($fieldConfig['source']) {
                case 'field':
                    switch ($fieldConfig['scope']) {
                        case 'project':
                            $ids = DM\Tree::getCaseId(Path::detectRealTargetId($p['path']));
                            break;

                        case 'parent':
                            $ids = Path::detectRealTargetId($p['path']);
                            break;

                        default:
                            if (empty($p['pidValue']) || empty($p['field'])) {
                                break 2;
                            }
                            $ids = $p['pidValue'];

                    }
                    $ids = Util\toNumericArray($ids);

                    if (empty($ids)) {
                        break;
                    }

                    /*get distinct target field values for selected objects in parent field */
                    $obj = new Objects\CBObject();
                    $values = [];
                    foreach ($ids as $id) {
                        $obj->load($id);
                        $fv = $obj->getFieldValue($p['field'], 0);
                        $fv = Util\toNumericArray(@$fv['value']);
                        $values = array_merge($values, $fv);
                    }
                    $values = array_unique($values);

                    if (empty($values)) {
                        return ['success' => true, 'data' => []];
                    }

                    $p['ids'] = $values;
                    break;
            }
        }

        $pids = false;
        if (!empty($fieldConfig['scope'])) {
            $scope = $fieldConfig['scope'];
            switch ($scope) {
                case 'project': /* limiting pid to project. If not in a project then to parent directory */
                    if (!empty($p['objectId']) && is_numeric($p['objectId'])) {
                        $pids = DM\Tree::getCaseId($p['objectId']);
                    } elseif (!empty($p['objectPid']) && is_numeric($p['objectPid'])) {
                        $pids = DM\Tree::getCaseId($p['objectPid']);
                    } elseif (!empty($p['path'])) {
                        $pids = DM\Tree::getCaseId(Path::detectRealTargetId($p['path']));
                    }
                    break;

                case 'parent':
                    if (!empty($p['objectId']) && is_numeric($p['objectId'])) {
                        $pids = Objects::getPids($p['objectId']);
                        if (!empty($pids)) {
                            $p['pids'] = array_pop($pids);
                        }
                    } elseif (!empty($p['path'])) {
                        $pids = Path::detectRealTargetId($p['path']);
                    }

                    break;

                case 'self':
                    if (!empty($p['objectId']) && is_numeric($p['objectId'])) {
                        $p['pids'] = $p['objectId'];
                    } elseif (!empty($p['path'])) {
                        $pids = Path::detectRealTargetId($p['path']);
                    }
                    break;

                case 'variable':
                    $pids = empty($p['pidValue'])
                        ? Path::detectRealTargetId($p['path'])
                        : Util\toNumericArray($p['pidValue']);
                    break;

                default:
                    $pids = Util\toNumericArray($scope);
                    break;
            }
        }
        if (!empty($pids)) {
            if (empty($p['descendants'])) {
                $p['pid'] = $pids;
            } elseif (@$p['source'] !== 'field') {
                $p['pids'] = $pids;
            }
        }

        $p['fl'] = 'id,name,type,template_id,status';
        if (!empty($p['fields'])) {
            if (!is_array($p['fields'])) {
                $p['fields'] = explode(',', $p['fields']);
            }
            for ($i = 0; $i < sizeof($p['fields']); $i++) {
                $fieldName = trim($p['fields'][$i]);
                if ($fieldName == 'project') {
                    $fieldName = 'case';
                }
                if (in_array(
                    $fieldName,
                    [
                        'date',
                        'path',
                        'case',
                        'size',
                        'cid',
                        'oid',
                        'cdate',
                        'udate',
                    ]
                )
                ) {
                    $p['fl'] .= ','.$fieldName;
                }
            }
        }

		if (!empty($p['DC'])) {
            foreach ($p['DC'] as $dctitle => $dc) {
				$fieldName=	isset($dc['solr_column_name'])?$dc['solr_column_name']:null;
				if (!empty($fieldName))
				{
					$p['fl'] .= ','.$fieldName;
				}
				else
				{
					$p['fl'] .= ','.$dctitle;
				}
			}
        }

        //increase number of returned items
        if (!isset($p['rows'])) {
            if (!isset($p['limit'])) {
                if (empty($p['pageSize'])) {
                    $p['rows'] = 50;
                } else {
                    $p['rows'] = $p['pageSize'];
                }
            } else {
                $p['rows'] = $p['limit'];
            }
        }

        if (!is_numeric($p['rows'])) {
            $p['rows'] = 50;
        }

        $search = new Search();

        // temporary: Don't use permissions for Objects fields
        // it can be later reinforced per field in config
        $p['skipSecurity'] = true;
        $rez = $search->query($p);

		if (!empty($p['source']) && $p['source'] == "template")
		{
			$this->setCustomTemplateIcons($rez['data']);
		}
        else
		{
			$this->setCustomIcons($rez['data']);
		}

	if (empty($rez['DC']))
		{
			if (isset($p['DC']))
			{
            	$rez['DC'] = $p['DC'];
            }
            else
            {
            	$rez['DC'] = [
                'name' => [
                    'solr_column_name' => "name",
                    'idx' => 0,
                ],
            	];
            }
        }

        return $rez;
    }

    public function delete($paths)
    {
        if (!is_array($paths)) {
            $paths = [$paths];
        }
        /* collecting ids from paths */
        $ids = [];
        foreach ($paths as $path) {
            $id = explode('/', $path);
            $id = array_pop($id);
            if (!is_numeric($id)) {
                return ['success' => false];
            }
            if (!Security::canDelete($id)) {
                throw new \Exception($this->trans('Access_denied'));
            }
            $ids[] = intval($id);
        }
        if (empty($ids)) {
            return ['success' => false];
        }

        foreach ($ids as $id) {
            $obj = Objects::getCustomClassByObjectId($id);
            $obj->delete();
        }

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');

        return [
            'success' => true,
            'ids' => $ids,
        ];
    }

    public function restore($paths)
    {
        if (!is_array($paths)) {
            $paths = [$paths];
        }
        /* collecting ids from paths */
        $ids = [];
        foreach ($paths as $path) {
            $id = explode('/', $path);
            $id = array_pop($id);
            if (!is_numeric($id)) {
                return ['success' => false];
            }
            if (!Security::canDelete($id)) {
                throw new \Exception($this->trans('Access_denied'));
            }
            $ids[] = intval($id);
        }
        if (empty($ids)) {
            return ['success' => false];
        }

        /* before deleting we should check security for specified paths and all children */

        /* if access is granted then setting dstatus=0 for specified ids
        all their children /**/

        foreach ($ids as $id) {
            $obj = Objects::getCustomClassByObjectId($id);
            $obj->restore();
        }

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');

        return ['success' => true, 'ids' => $ids];
    }

    public function rename($p)
    {
        $id = explode('/', $p['path']);
        $id = array_pop($id);
        $p['name'] = trim($p['name']);

        if (!is_numeric($id) || empty($p['name'])) {
            return ['success' => false];
        }

        /* check security access */
        if (!Security::canWrite($id)) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $p['name'] = Purify::filename($p['name']);

        $rez = [
            'success' => true,
            'data' => [
                'id' => $id,
                'pid' => null,
                'newName' => $p['name'],
            ],
        ];

        $objectType = Objects::getType($id);

        if ($objectType == 'shortcut') {
            $r = DM\Tree::read($id);
            if (!empty($r['target_id'])) {
                $id = $r['target_id'];
                $objectType = Objects::getType($id);
            }
        }

        DM\Tree::update(
            [
                'id' => $id,
                'name' => $p['name'],
            ]
        );

        if ($objectType == 'file') {
            DM\Files::update(
                [
                    'id' => $id,
                    'name' => $p['name'],
                ]
            );
        }

        // Updating renamed document into solr directly (before runing background cron)
        // so that it'll be displayed with new name without delay
        $solrClient = new Solr\Client();
        $solrClient->updateTree(['id' => $id]);

        // Running background cron to index other nodes
        $solrClient->runBackgroundCron();

        $p['name'] = htmlspecialchars($p['name'], ENT_COMPAT);

        // Get pid
        $r = DM\Tree::read($rez['data']['id']);
        if (!empty($r['pid'])) {
            $rez['data']['pid'] = $r['pid'];
        }

        return $rez;
    }

    /**
     * generate a name for for a new copy of an object
     *
     * This function is used to generate a new name lyke "Copy of <old file_name> (1).ext".
     * Usually used when copy/pasting objects and pasted object should receive a new name.
     *
     * @param int $pid parent object/folder id
     * @param string $name old/existing object name
     * @param boolean $excludeExtension if true then characters after last "." will remain unchanged
     *
     * @return string new name
     */
    public function getNewCopyName($pid, $name, $excludeExtension = false)
    {
        $ext = '';

        if ($excludeExtension) {
            $a = explode('.', $name);
            if (sizeof($a) > 1) {
                $ext = '.'.array_pop($a);
            }
            $name = implode('.', $a);
        }

        $id = null;
        $i = 0;

        do {
            $newName = $this->trans('CopyOf').' '.$name.(($i > 0) ? ' ('.$i.')' : '').$ext;
            $id = DM\Tree::toId($newName, 'name', $pid);
            $i++;
        } while (!empty($id));

        return $newName;
    }

    public function saveFile($p)
    {
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        $incommingFilesDir = $configService->get('incomming_files_dir');

        $files = new Files();

        /* clean previous unhandled uploads if any */
        $a = $files->getUploadParams();
        if (($a !== false) && !empty($a['files'])) {
            $user = Cache::get('symfony.container')->get('session')->get('user');
            @unlink($incommingFilesDir.$user['id']);
            $files->removeIncomingFiles($a['files']);
        }
        /* end of clean previous unhandled uploads if any */

        $F = &$_FILES;
        if (empty($p['pid'])) {
            return [
                'success' => false,
                'msg' => $this->trans('Error_uploading_file'),
            ];
        }

        $p['pid'] = Path::detectRealTargetId($p['pid']);

        if (empty($F)) { //update only file properties (no files were uploaded)

            return $files->updateFileProperties($p);
        } else {
            foreach ($F as $k => $v) {
                $F[$k]['name'] = Purify::filename(@$F[$k]['name']);
                $v = $v; //dummy codacy assignment
            }
        }

        if (!Objects::idExists($p['pid'])) {
            return [
                'success' => false,
                'msg' => $this->trans('TargetFolderDoesNotExist'),
            ];
        }

        /*checking if there is no upload error (for any type of upload: single, multiple, archive) */
        foreach ($F as $f) {
            if (!in_array($f['error'], [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE])) {
                return [
                    'success' => false,
                    'msg' => $this->trans('Error_uploading_file').': '.$f['error'],
                ];
            }
        }

        /* retreiving files list  */
        switch (@$p['uploadType']) {
            case 'archive':
                $archiveFiles = [];
                foreach ($F as $fk => $f) {
                    $files->extractUploadedArchive($F[$fk]);
                    $archiveFiles = array_merge($archiveFiles, $F[$fk]);
                }
                $F = $archiveFiles;
                break;

            default:
                if (!$files->moveUploadedFilesToIncomming($F)) {
                    return [
                        'success' => false,
                        'msg' => $this->trans('Error_uploading_file'),
                    ];
                }
                break;
        }

        $p['existentFilenames'] = $files->getExistentFilenames($F, $p['pid']);
        $p['files'] = &$F;

        if (!empty($p['existentFilenames'])) {
            //check if can write target file
            if (!Security::canWrite($p['existentFilenames'][0]['existentFileId'])) {
                return [
                    'success' => false,
                    'msg' => $this->trans('Access_denied'),
                ];
            }

            // store current state serialized in a local file in incomming folder
            $files->saveUploadParams($p);
            if (!empty($p['response'])) {
                //it is supposed to work only for single files upload
                return $this->confirmUploadRequest($p);
            }

            $allow_new_version = false;
            foreach ($p['existentFilenames'] as $f) {
                $mfvc = Files::getMFVC($f['name']);
                if ($mfvc > 0) {
                    $allow_new_version = true;
                }
            }
            $rez = [
                'success' => false,
                'type' => 'filesexist',
                'allow_new_version' => $allow_new_version,
                'count' => sizeof($p['existentFilenames']),
            ];

            if ($rez['count'] == 1) {
                if (empty($p['existentFilenames'][0]['msg'])) {
                    $rez['msg'] = str_replace(
                        '{filename}',
                        '"'.$p['existentFilenames'][0]['name'].'"',
                        $this->trans('FilenameExistsInTarget')
                    );
                } else {
                    $rez['msg'] = $p['existentFilenames'][0]['msg'];
                }

                $rez['suggestedFilename'] = $p['existentFilenames'][0]['suggestedFilename'];
            } else {
                $rez['msg'] = $this->trans('SomeFilenamesExistsInTarget');
            }

            return $rez;

        } else {
            //check if can write in target folder
            if (!Security::canWrite($p['pid'])) {
                return ['success' => false, 'msg' => $this->trans('Access_denied')];
            }
        }

        $files->storeFiles($p); // If everything is ok then store files

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');

        $rez = [
            'success' => true,
            'data' => ['pid' => $p['pid']],
        ];

        $files->attachPostUploadInfo($F, $rez);

        return $rez;
    }

    // called when user was asked about file(s) overwrite
    public function confirmUploadRequest($p)
    {
        //if cancel then delete all uploaded files from incomming
        $files = new Files();
        $a = $files->getUploadParams();
        $a['response'] = $p['response'];

        switch ($p['response']) {
            case 'rename':
                $a['newName'] = Purify::filename($p['newName']);
                //check if the new name does not also exist
                if (empty($a['response'])) {
                    return ['success' => false, 'msg' => $this->trans('FilenameCannotBeEmpty')];
                }
                reset($a['files']);
                $k = key($a['files']);
                $a['files'][$k]['name'] = $a['newName'];
                if ($files->fileExists($a['pid'], $a['newName'])) {
                    $files->saveUploadParams($a);

                    return [
                        'success' => false,
                        'type' => 'filesexist',
                        // 'filename' => $a['newName'],
                        'allow_new_version' => (Files::getMFVC($a['newName']) > 0),
                        'suggestedFilename' => Objects::getAvailableName($a['pid'], $a['newName']),
                        'msg' => str_replace(
                            '{filename}',
                            '"'.$a['newName'].'"',
                            $this->trans('FilenameExistsInTarget')
                        ),
                    ];
                }
            // $files->storeFiles($a);
            // break;
            case 'newversion':
            case 'replace':
            case 'autorename':
                $files->storeFiles($a);
                break;
            default: //cancel
                $files->removeIncomingFiles($a['files']);

                return ['success' => true, 'data' => []];
                break;
        }

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');

        $rez = ['success' => true, 'data' => ['pid' => $a['pid']]];
        $files->attachPostUploadInfo($a['files'], $rez);

        return $rez;
    }

    public static function getRootFolderId()
    {
        if (defined('CB\\ROOT_FOLDER_ID')) {
            return constant('CB\\ROOT_FOLDER_ID');
        }

        $id = DM\Tree::getRootId();

        if (empty($id)) {
            Cache::get('symfony.container')->get('logger')->error('Cant find root folder.');
        }

        define('CB\\ROOT_FOLDER_ID', $id);

        return $id;
    }

    public static function getRootProperties($id)
    {
        $rez = [
            'success' => true,
            'data' => [],
        ];

        $r = DM\Tree::getProperties($id);

        if (!empty($r)) {
            $rez['data'] = [$r];
            Browser::updateLabels($rez['data']);
            $rez['data'] = $rez['data'][0];
        }

        return $rez;
    }

    public function prepareResult()
    {
        $rez = &$this->result;
        $data = &$rez['data'];

        //select first given DC
        if (!empty($rez['DC'])) {
            $rez['DC'] = $rez['DC'][0];
        }

        //prepare data
        if (empty($data) || !is_array($data)) {
            return;
        }

        for ($i = 0; $i < sizeof($data); $i++) {
            $d = &$data[$i];
            if (isset($d['id']) && empty($d['nid'])) {
                $d['nid'] = $d['id'];
                unset($d['id']);
            }
        }
    }

    public static function updateLabels(&$data)
    {
        for ($i = 0; $i < sizeof($data); $i++) {
            $d = &$data[$i];
            unset($d['iconCls']);
            //@$d['nid'] = intval($d['nid']);
            @$d['system'] = intval($d['system']);
            @$d['type'] = intval($d['type']);

            // if ($d['system']) {
            //     $d['name'] = L\getTranslationIfPseudoValue($d['name']);
            // }
        }

        return $data;
    }

    /**
     * set custom items for given records
     *
     * @param array $records
     */
    protected function setCustomIcons(&$records)
    {
        $ids = [];

        foreach ($records as &$r) {
            $ids[] = $r['id'];
        }

        $recs = DM\Tree::readByIds($ids, true);

        foreach ($records as &$r) {
            if (!empty($recs[$r['id']]['cfg']['iconCls'])) {
                $r['iconCls'] = $recs[$r['id']]['cfg']['iconCls'];
            }
        }

		$recs = DM\Objects::readByIds($ids, true);

        foreach ($records as &$r) {
            if (!empty($recs[$r['id']]['data']['iconCls'])) {
                $r['iconCls'] = $recs[$r['id']]['data']['iconCls'];
            }
        }


    }

    /**
     * set custom items for given templates
     *
     * @param array $records
     */
	protected function setCustomTemplateIcons(&$records)
    {
        $ids = [];

        foreach ($records as &$r) {
            $ids[] = $r['id'];
        }

        $recs = DM\Templates::readByIds($ids, true);

        foreach ($records as &$r) {
            if (!empty($recs[$r['id']]['iconCls'])) {
				$r['name'] = $recs[$r['id']]['title_template'];
                $r['iconCls'] = $recs[$r['id']]['iconCls'];
            }
        }
    }

    /**
     * detect object icon by analizing it's data
     *
     * object data could have set a custom iconCls in cfg property of the data,
     * otherwise the icon is determined from it's template
     * TODO: think about shortcuts
     *
     * @param array $data object data
     *
     * @return string iconCls
     */
    public static function getIcon(&$data)
    {

		if (!empty($data['data']) && !empty($data['data']['iconCls'])) {
            return $data['data']['iconCls'];
        }

        if (!empty($data['cfg']) && !empty($data['cfg']['iconCls'])) {
            return $data['cfg']['iconCls'];
        }

        if (empty($data['template_id'])) {
            return 'icon-none';
        }

        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        $templates = Templates\SingletonCollection::getInstance();
        $templateData = $templates->getTemplate($data['template_id'])->getData();

        if (!empty($templateData['iconCls'])) {
            return $templateData['iconCls'];
        }

        switch ($templateData['type']) {
            case 'object':
                if (in_array($data['template_id'], $configService->get('folder_templates'))) {
                    return 'icon-folder';
                }
                break;
            case 2:
                return 'icon-shortcut';//case
                break;

            case 'file':
                return Files::getIcon($data['name']);
                break;
            case 'task':
                if (@$data['status'] == 3) {
                    return 'icon-task-completed';
                }

                return 'icon-task'; //task
                break;
            case 'email':
                return 'icon-mail'; //Message (email)
                break;
        }

        return 'icon-none';
    }
}
