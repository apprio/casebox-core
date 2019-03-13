<?php

namespace Casebox\CoreBundle\Service;

use Casebox\CoreBundle\Event\BeforeNodeDbCreateEvent;
use Casebox\CoreBundle\Event\NodeObjectsLoadEvent;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Objects\Plugins;
use Casebox\CoreBundle\Service\Templates\SingletonCollection;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class Objects
 */
class Objects
{
    use TranslatorTrait;

    /**
     * Load object and return array response
     *
     * @param array $p Array containing id of object
     *
     * @return array
     * @throws \Exception
     */
    public function load($p)
    {
        // Check if object id is numeric
        if (!is_numeric($p['id'])) {
            throw new \Exception($this->trans('Wrong_input_data'));
        }
        $id = $p['id'];

        // Access check
        if (!Security::canRead($id)) {
            throw new \Exception($this->trans('Access_denied'));
        }
        $object = $this->getCustomClassByObjectId($id) or die($this->trans('Wrong_input_data'));

        $object->load();
        $objectData = $object->getData();

        $template = $object->getTemplate();
        $templateData = $template->getData();

        $resultData = [];

        // Select only required properties for result
        $properties = [
            'id',
            'pid',
            'template_id',
            'name',
            'date',
            'date_end',
            'pids',
            'path',
            'cid',
            'uid',
            'cdate',
            'udate',
            'case_id',
            'status',
            'data',
            'can',
        ];
        foreach ($properties as $property) {
            if (isset($objectData[$property])) {
                $resultData[$property] = $objectData[$property];
            }
        }

        // Rename some properties for gui
        $resultData['date_start'] = @$resultData['date'];
        unset($resultData['date']);

        $arr = [&$resultData];

        $pids = explode(',', $resultData['pids']);

        array_pop($pids);

        $resultData['pids'] = $resultData['path'] = implode('/', $pids);

        Search::setPaths($arr);
        // $resultData['pathtext'] = $resultData['path'];
        // $resultData['path'] = str_replace(',', '/', $resultData['pids']);
        // unset($resultData['pids']);

        if (!empty($objectData['cdate'])) {
            $resultData['cdate_ago_text'] = Util\formatAgoTime($objectData['cdate']);
        }
        if (!empty($objectData['udate'])) {
            $resultData['udate_ago_text'] = Util\formatAgoTime($objectData['udate']);
        }

        // Set type property from template
        $resultData['type'] = $templateData['type'];

        $rez = [
            'success' => true,
            'data' => $resultData,
            'menu' => Browser\CreateMenu::getMenuForPath($p['id']),
        ];

        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onObjectsLoad', new NodeObjectsLoadEvent($rez));

        return $rez;
    }

    /**
     * Create an object
     *
     * @param array $p params
     *
     * @return array      response
     * @throws \Exception
     */
    public function create($p)
    {
        $pid = empty($p['pid']) ? @$p['path'] : $p['pid'];
        if (empty($pid)) {
            throw new \Exception($this->trans('Access_denied'));
        }

        if (empty($p['pid']) || !is_numeric($p['pid'])) {
            $p['pid'] = Path::detectRealTargetId($pid);
        }

        // Security check moved inside objects class
        $template = SingletonCollection::getInstance()->getTemplate(
            $p['template_id']
        );
        $templateData = $template->getData();

        $object = $this->getCustomClassByType($templateData['type']);

        // Prepare params
        if (empty($p['name'])) {
            $p['name'] = $template->getName();
        }
        $p['name'] = $this->getAvailableName($p['pid'], $p['name']);

        $id = $object->create($p);

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');

        $rez = $this->load(['id' => $id]);
        $rez['data']['isNew'] = true;

        return $rez;
    }

    /**
     * Save or create an object
     *
     * @param array $p object properties
     *
     * @return array
     * @throws \Exception
     */
    public function save($p)
    {
        $d = Util\toJSONArray($p['data']);

        // Check if need to create object instead of update
        if (empty($d['id']) || !is_numeric($d['id'])) {
            return $this->create($d);
        }

        // SECURITY: check if current user has write access to this action
        if (!Security::canWrite($d['id'])) {
            throw new \Exception($this->trans('Access_denied'));
        }

        if (empty($d['date']) && !empty($d['date_start'])) {
            $d['date'] = $d['date_start'];
        }

        // Update object
        $object = $this->getCachedObject($d['id']);

        // Set sys_data from object, it can contain custom data
        // that shouldn't be overwritten
        $d['sys_data'] = $object->getSysData();

        $object->update($d);

        Objects::updateCaseUpdateInfo($d['id']);

        // Updating saved document into solr directly (before runing background cron)
        // so that it'll be displayed with new name without delay
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        if (!$configService->getFlag('disableSolrIndexing')) {
            $solrClient = new Solr\Client();
            $solrClient->updateTree(['id' => $d['id']]);
            // Running background cron to index other nodes
            $solrClient->runBackgroundCron();
        }

        return $this->load($d);
    }

    /**
     * Getting preview for an item
     *
     * @param int $id
     *
     * @return array
     * @throws \Exception
     */
    public static function getPreview($id)
    {
        if (!is_numeric($id)) {
            return;
        }

        // SECURITY: check if current user has at least read access to this case
        if (!Security::canRead($id)) {
            throw new \Exception(self::trans('Access_denied'));
        }

        try {
            $obj = static::getCachedObject($id);
        } catch (\Exception $e) {
            return '';
        }

        return $obj->getPreviewBlocks();
    }

    /**
     * Get the list of objects referenced inside another object
     *
     * @param array|int $p Params
     *
     * @return array
     * @throws \Exception
     */
    public static function getAssociatedObjects($p)
    {
        $data = [];

        if (is_numeric($p)) {
            $p = ['id' => $p];
        }

        if (empty($p['id']) && empty($p['template_id'])) {
            return [
                'success' => true,
                'data' => $data,
                's' => '1',
            ];
        }

        $ids = [];

        $template = null;

        if (!empty($p['id']) && is_numeric($p['id'])) {
            // SECURITY: check if current user has at least read access to this case
            if (!Security::canRead($p['id'])) {
                throw new \Exception(self::trans('Access_denied'));
            }

            /* select distinct associated case ids from the case */
            $obj = new Objects\CBObject($p['id']);
            $obj->load();
            $template = $obj->getTemplate();
            $linearData = $obj->getLinearData();
            foreach ($linearData as $f) {
                $tf = $template->getField($f['name']);
                if ($tf['type'] == '_objects') {
                    $a = Util\toIntArray(@$f['value']);
                    $ids = array_merge($ids, $a);
                }
            }
        } else {
            $template = new Objects\Template($p['template_id']);
            $template->load();

        }

        if (!empty($p['data']) && is_array($p['data'])) {
            foreach ($p['data'] as $value) {
                $a = Util\toIntArray($value);
                $ids = array_merge($ids, $a);
            }
        }

        if ($template) {
            $templateData = $template->getData();
            foreach ($templateData['fields'] as $field) {
                if (!empty($field['cfg']['value'])) {
                    $a = Util\toIntArray($field['cfg']['value']);
                    $ids = array_merge($ids, $a);
                }
            }
        }

        $ids = array_unique($ids);
        if (empty($ids)) {
            return ['success' => true, 'data' => []];
        }

        $data = Search::getObjects($ids, 'id,template_id,name,date,status:task_status');
        $data = array_values($data);

        return ['success' => true, 'data' => $data];
    }

    /**
     * Updates udate and uid for a case
     *
     * @param int $caseOrCaseObjectId
     */
    public static function updateCaseUpdateInfo($caseOrCaseObjectId)
    {
        DM\Tree::update(
            [
                'id' => $caseOrCaseObjectId,
                'uid' => User::getId(),
                'udate' => 'CURRENT_TIMESTAMP',
            ]
        );
    }

    /**
     * @param array $p
     *
     * @return array
     * @throws \Exception
     */
    public function setOwnership($p)
    {
        $ids = Util\toNumericArray($p['ids']);

        $rez = ['success' => true, 'data' => $ids];

        if (empty($ids)) {
            return $rez;
        }
        $userId = (empty($p['userId']) || !is_numeric($p['userId'])) ? User::getId() : $p['userId'];

        // Check if user has rights to take ownership on each object
        foreach ($ids as $id) {
            $rez = null;

			if (!empty($id) && is_numeric($id)) {
				$obj = static::getCachedObject($id);
				if (!empty($obj)) {
					if ($obj->getType() == 'case')
					{
						$obj->setUserStatus(1, $userId);
						$rez = ['success' => true, 'data' => $userId ];							
						return $rez;	
					}
					else
					{
						if (!Security::canTakeOwnership($id)) {
						throw new \Exception($this->trans('Access_denied'));
						}
					}
				}
			}
        }

        DM\Tree::updateOwner($ids, $userId);

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');

        return $rez;
    }

    /**
     * Get pids of a given object id
     *
     * @param int $objectId
     *
     * @return array
     */
    public static function getPids($objectId, $excludeItself = true)
    {
        $rez = [];

        if (!is_numeric($objectId)) {
            return $rez;
        }

        $r = DM\TreeInfo::read($objectId);

        if (!empty($r)) {
            $rez = Util\toNumericArray($r['pids']);

            if ($excludeItself) {
                array_pop($rez);
            }
        }

        return $rez;
    }

    /**
     * Get template id of an object
     *
     * @param int $objectId
     *
     * @return int|null
     */
    public static function getTemplateId($objectId)
    {
        $rez = null;
        if (!is_numeric($objectId)) {
            return $rez;
        }

        $r = DM\Tree::read($objectId);

        if (!empty($r)) {
            $rez = $r['template_id'];
        }

        return $rez;
    }

    /**
     * Get template type of an object
     *
     * @param int $objectId
     *
     * @return string|null
     */
    public static function getType($objectId)
    {
        if (!is_numeric($objectId)) {
            return null;
        }

        $varName = 'obj_template_type'.$objectId;

        if (!Cache::exist($varName)) {
            $tc = Templates\SingletonCollection::getInstance();
            Cache::set($varName, $tc->getType(self::getTemplateId($objectId)));
        }

        return Cache::get($varName);
    }

    /**
     * Get name for an object id
     *
     * @param  int $id
     *
     * @return string|null
     */
    public static function getName($id, $htmlSafe = false)
    {
        $rez = null;

        if (!empty($id) && is_numeric($id)) {
            $obj = static::getCachedObject($id);
            if (!empty($obj)) {
                $rez = $htmlSafe
                    ? $obj->getHtmlSafeName()
                    : $obj->getName();
            }
        }

        return $rez;
    }

    /**
     * Get an object from cache or loads id and store in cache
     *
     * @param int $id
     *
     * @return object
     */
    public static function getCachedObject($id)
    {
        $data = static::getCachedObjects($id);

        return array_shift($data);
    }

    /**
     * Get objects from cache or loads them and store in cache
     *
     * @param array $ids
     *
     * @return array
     */
    public static function getCachedObjects($ids)
    {
        $ids = Util\toNumericArray($ids);
        $rez = [];
        $toLoad = [];

        foreach ($ids as $id) {
            // Verify if already have cached result
            $varName = 'Objects['.$id.']';
            if (Cache::exist($varName)) {
                $rez[$id] = Cache::get($varName);
            } else {
                $toLoad[] = $id;
            }
        }

        if (!empty($toLoad)) {
            $tc = SingletonCollection::getInstance();
            $data = DataModel\Objects::readAllData($toLoad);
            /** @var EventDispatcher $dispatcher */
            $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');

            foreach ($data as $objData) {
                $varName = 'Objects['.$objData['id'].']';

                $o = static::getCustomClassByType($tc->getType($objData['template_id']));

                if (!empty($o)) {
                    $o->setData($objData, false);

                    Cache::set($varName, $o);
                    $rez[$objData['id']] = $o;

                    $dispatcher->dispatch('onObjectLoad', new BeforeNodeDbCreateEvent($o));
                }
            }
        }

        return $rez;
    }

    /**
     * Get an instance of the class designed for objectId (based on it's template type)
     *
     * @param int $objectId
     *
     * @return object
     */
    public static function getCustomClassByObjectId($objectId)
    {
        $type = Objects::getType($objectId);

        return Objects::getCustomClassByType($type, $objectId);
    }

    /**
     * Get an instance of the class designed for specified type
     *
     * @param string $type
     * @param int $objectId
     *
     * @return object
     */
    public static function getCustomClassByType($type, $objectId = null)
    {
        if (empty($type)) {
            return null;
        }

        switch ($type) {
            case 'file':
                return new Objects\File($objectId);
                break;

            case 'task':
                return new Objects\Task($objectId);
                break;

            case 'case':
                return new Objects\Cases($objectId);
                break;

			case 'caseassessment':
                return new Objects\CaseAssessment($objectId);
                break;
				
            case 'template':
                return new Objects\Template($objectId);
                break;

            case 'field':
                return new Objects\TemplateField($objectId);
                break;

            case 'comment':
                return new Objects\Comment($objectId);
                break;

            case 'config':
                return new Objects\Config($objectId);
                break;

            case 'shortcut':
                return new Objects\Shortcut($objectId);
                break;

            default:
                return new Objects\CBObject($objectId);
                break;
        }
    }

    /**
     * Copy an unknown object to a $pid or over a $targetId
     *
     * @param int $objectId
     * @param int|bool|false $pid
     * @param int|bool|false $targetId
     *
     * @return int
     */
    public function copy($objectId, $pid = false, $targetId = false)
    {
        $class = $this->getCustomClassByObjectId($objectId);
        $data = $class->load();

        return $class->copyTo($pid, $targetId);
    }

    /**
     * Move an unknown object to a $pid or over a $targetId
     *
     * @param int $objectId
     * @param int|bool|false $pid
     * @param int|bool|false $targetId
     *
     * @return int new moved object id
     */
    public function move($objectId, $pid = false, $targetId = false)
    {
        $class = $this->getCustomClassByObjectId($objectId);

        return $class->moveTo($pid, $targetId);
    }

    /**
     * Get a new name, that does not exist under specified $pid
     *
     * If there is no any active (not deleted) object with specied name under $pid
     * then same name is returned.
     * If name exists then a new name will be generated with " (<number>)" at the end.
     * Note that extension is not changed.
     * Extension is considered any combination of chars delimited by dot
     * at the end of an object and its length is less than 5 chars.
     *
     * @param int $pid parent id
     * @param string $name desired name
     *
     * @return string new name
     */
    public static function getAvailableName($pid, $name)
    {
        $newName = $name;
        $a = explode('.', $name);
        $ext = '';

        if ((sizeof($a) > 1) && (sizeof($a) < 5)) {
            $ext = array_pop($a);
        }

        $name = implode('.', $a);

        $names = DM\Tree::getChildNames($pid, $name, $ext);

        $i = 1;
        while (in_array($newName, $names)) {
            $newName = $name.' ('.$i.')'.(empty($ext) ? '' : '.'.$ext);
            $i++;
        };

        return $newName;
    }

    /**
     * Checks if given id exists in our tree
     *
     * @param int $id
     *
     * @return boolean
     */
    public static function idExists($id)
    {
        $rez = false;
        if (empty($id)) {
            return $rez;
        }

        $r = DM\Tree::read($id);
        $rez = !empty($r);

        return $rez;
    }

    /**
     * Get basic info for a given object id
     *
     * @param int $id
     *
     * @return array response
     */
    public static function getBasicInfoForId($id)
    {
        $rez = [
            'success' => false,
            'id' => $id,
            'data' => [],
        ];

        if (empty($id) || !is_numeric($id)) {
            return $rez;
        }

        $rez['success'] = true;
        $rez['data'] = DM\Tree::getBasicInfo($id);

        return $rez;
    }

    /**
     * Get a child node id by its name under specified $pid
     *
     * @param int $pid
     * @param string|array $name direct child name or the list of child, subchild, ...
     *
     * @return int|null
     */
    public static function getChildId($pid, $name)
    {
        if (!is_array($name)) {
            $name = [$name];
        }

        do {
            $n = array_shift($name);
            $r = DM\Tree::getChildByName($pid, $n);

            if (!empty($r)) {
                $pid = $r['id'];
            } else {
                $pid = null;
            }

        } while (!empty($pid) && !empty($name));

        return $pid;
    }

    /**
     * Set subscription to an object for current user
     *
     * @param  array $p
     *
     * @return array Array response
     * @throws \Exception
     */
    public function setSubscription($p)
    {
        // Validate input params
        if (empty($p['objectId']) || !is_numeric($p['objectId']) ||
            empty($p['type']) || !in_array($p['type'], ['watch', 'ignore'])
        ) {
            throw new \Exception($this->trans('Wrong_input_data'));
        }

        // Set subscription
        $userId = User::getId();
        $obj = $this->getCachedObject($p['objectId']);
        $sd = $obj->getSysData();

        $wu = empty($sd['wu']) ? [] : $sd['wu'];

        // Backward compatibility, move fu to wu
        $fu = empty($sd['fu']) ? [] : $sd['fu'];

        if (!empty($fu)) {
            $wu = array_merge($fu, $wu);
            $wu = array_unique($wu);
            unset($sd['fu']);
        }

        switch ($p['type']) {
            case 'watch':
                $sd['wu'] = array_merge(array_diff($wu, [$userId]), [$userId]);
                break;

            case 'ignore':
                $sd['wu'] = array_diff($wu, [$userId]);
                break;
        }

        $obj->updateSysData($sd);

        return ['success' => true];
    }

    /**
     * Get data for defined plugins to be displayed in properties panel for selected object
     *
     * @param array $p Remote properties containing object id
     *
     * @return array
     */
    public function getPluginsData($p)
    {
        $id = @$p['id'];
        $templateId = @$p['template_id'];
        $template = null;
        $templateData = null;
        $objectPlugins = null;

        $rez = [
            'success' => false,
            'data' => [],
        ];

        if ((empty($id) && empty($templateId)) || (!is_numeric($id) && !is_numeric($templateId))) {
            return $rez;
        }

        if (is_numeric($id)) {
            if (!$this->idExists($id)) {
                return $rez;
            }

            Util\raiseErrorIf(!Security::canRead($id), 'Access_denied');

            $rez['menu'] = Browser\CreateMenu::getMenuForPath($id);

            /*
            Now we'll try to detect plugins config that could be found in following places:
                1. in config of the template for the given object, named object_plugins
                2. in core config, property object_type_plugins (config definitions per available template type values: object, case, task etc)
                3. a generic config,  named default_object_plugins, could be defined in core config
            */

            $o = $this->getCachedObject($id);

            if (!empty($o)) {
                $template = $o->getTemplate();
                if (!empty($template)) {
                    $templateData = $template->getData();
                }
            }
        } else {
            $id = null;
            $templates = Templates\SingletonCollection::getInstance();
            $templateData = $templates->getTemplate($templateId)->getData();
        }

        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        $objectPlugins = empty($templateData['cfg']['object_plugins'])
            ? $configService->get('default_object_plugins')
            : $templateData['cfg']['object_plugins'];

        $rez['success'] = true;

        if (empty($objectPlugins)) {
            return $rez;
        }

        if (!empty($templateData['cfg']['timeTracking']) && !isset($objectPlugins['timeTracking'])) {
            $objectPlugins['timeTracking'] = [
                'order' => 1,
            ];
        }

        Util\sortRecordsArray($objectPlugins, 'order', 'asc', 'asInt', true);

        /** @var Container $container */
        $container = Cache::get('symfony.container');

        foreach ($objectPlugins as $k => $v) {
            if (is_scalar($v)) {
                $className = $v;
                $v = [];

            } else {
                $className = $k;
                if (!empty($v['class'])) {
                    $className = $v['class'];
                }
            }

            $pindex = is_numeric($k) ? $className : $k;

            $v['objectId'] = $id;
            $v['context'] = empty($p['from']) ? '' : $p['from'];
            $v['template_id'] = empty($p['template_id']) ? '' : $p['template_id'];

            if (is_numeric($className)) {
                continue;
            }

            $array = explode('\\', $className);
            $p = array_pop($array);
            $serviceId = 'casebox_core.service_objects_plugins.'.strtolower(trim($p));

            if (!$container->has($serviceId)) {
                $obj = $container->get($className);
                $obj->setId($v);
                $prez = $obj->getData();
            } else {
                $class = get_class($container->get($serviceId));
                $pClass = new $class($v);
                $prez = $pClass->getData();
            }

            $prez['class'] = $className;
            $rez['data'][$pindex] = $prez;
        }

        // Set system properties to common if SystemProperties plugin is not required
        if (empty($rez['data']['systemProperties'])) {
            $class = new Plugins\SystemProperties($id);
            $rez['common'] = $class->getData();
        }

        return $rez;
    }

    /**
     * Add comments for an objects
     *
     * @param  array $p input params (id, msg)
     *
     * @return array
     * @throws \Exception
     */
    public function addComment($p)
    {
        $rez = ['success' => false];
        if (empty($p['id']) || !is_numeric($p['id']) || empty($p['msg'])) {
            $rez['msg'] = $this->trans('Wrong_input_data');

            return $rez;
        }

        if (!Security::canRead($p['id'])) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $commentTemplates = DM\Templates::getIdsByType('comment');
        if (empty($commentTemplates)) {
            $rez['msg'] = 'No comment templates found';

            return $rez;
        }

        $co = new Objects\Comment();

        $data = [
            'pid' => $p['id'],
            'draftId' => @$p['draftId'],
            'template_id' => array_shift($commentTemplates),
            'system' => 2,
            'data' => [
                '_title' => $p['msg'],
            ],
        ];

        $id = $co->create($data);

        // Solr tree Update
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onSolrTreeUpdate');

        return [
            'success' => true,
            'data' => Plugins\Comments::loadComment($id),
        ];
    }

    /**
     * Update own comment
     *
     * @param  array $p input params (id, msg)
     *
     * @return array
     * @throws \Exception
     */
    public function updateComment($p)
    {
        $rez = ['success' => false];

        if (empty($p['id']) || !is_numeric($p['id']) || empty($p['text'])) {
            $rez['msg'] = $this->trans('Wrong_input_data');

            return $rez;
        }

        $comment = static::getCustomClassByObjectId($p['id']);
        $commentData = $comment->load();
        if ($commentData['cid'] == Cache::get('session')->get('user')['id']) {
            $commentData['data']['_title'] = $p['text'];
            $comment->update($commentData);

            // Solr tree Update
            /** @var EventDispatcher $dispatcher */
            $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
            $dispatcher->dispatch('onSolrTreeUpdate');

            $rez = [
                'success' => true,
                'data' => Plugins\Comments::loadComment($commentData['id']),
            ];

        }

        return $rez;
    }

    /**
     * Remove own comment
     *
     * @param  array $p input params (id)
     *
     * @return array
     * @throws \Exception
     */
    public function removeComment($p)
    {
        $rez = ['success' => false];

        if (empty($p['id']) || !is_numeric($p['id'])) {
            $rez['msg'] = $this->trans('Wrong_input_data');

            return $rez;
        }

        $comment = static::getCustomClassByObjectId($p['id']);
        $commentData = $comment->load();

        if ($commentData['cid'] == Cache::get('session')->get('user')['id']) {
            $comment->delete();

            // Solr tree Update
            /** @var EventDispatcher $dispatcher */
            $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
            $dispatcher->dispatch('onSolrTreeUpdate');

            $rez['success'] = true;
        }

        return $rez;
    }
}
