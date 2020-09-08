<?php

namespace Casebox\CoreBundle\Service\Objects;

use Casebox\CoreBundle\Event\BeforeGeneratePreviewEvent;
use Casebox\CoreBundle\Event\BeforeNodeDbCreateEvent;
use Casebox\CoreBundle\Event\BeforeNodeDbDeleteEvent;
use Casebox\CoreBundle\Event\BeforeNodeDbRestoreEvent;
use Casebox\CoreBundle\Event\BeforeNodeDbUpdateEvent;
use Casebox\CoreBundle\Event\GeneratePreviewEvent;
use Casebox\CoreBundle\Event\NodeDbCreateEvent;
use Casebox\CoreBundle\Event\NodeDbCreateOrUpdateEvent;
use Casebox\CoreBundle\Event\NodeDbDeleteEvent;
use Casebox\CoreBundle\Event\NodeDbRestoreEvent;
use Casebox\CoreBundle\Event\NodeDbUpdateEvent;
use Casebox\CoreBundle\Event\NodeLoadEvent;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Config;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Purify;
use Casebox\CoreBundle\Service\Solr\Client;
use Casebox\CoreBundle\Service\Templates\SingletonCollection;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\Log;
use Casebox\CoreBundle\Service\Security;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class CBObject
 *
 * Class for generic casebox objects
 */
class CBObject
{
    use TranslatorTrait;

    /**
     * object id
     * @var int
     */
    protected $id = null;

    /**
     * protected flag to check if object loaded where needed
     * @var boolean
     */
    protected $loaded = false;

    /**
     * variable used to load template for this object
     * @var boolean
     */
    public $loadTemplate = true;

    /**
     * object template
     * @var Template object
     */
    protected $template = null;

    /**
     * object data
     * @var array
     */
    protected $data = [];

    protected $tableFields = [
        'pid',
        'user_id',
        'system',
        'template_id',
        'tag_id',
        'target_id',
        'name',
        'date',
        'date_end',
        'size',
        'cfg',
        'oid',
        'did',
        'dstatus',
    ];

    /**
     * CBObject constructor
     */
    public function __construct($id = null, $loadTemplate = true)
    {
        if (is_numeric($id)) {
            $this->id = $id;
        }

        $this->configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        $this->loadTemplate = $loadTemplate;
    }

    /**
     * Create an object with specified params
     *
     * @param array|bool|false $p CBObject properties
     *
     * @return int
     * @throws \Exception
     */
    public function create($p = false)
    {
        if ($p !== false) {
            if (array_key_exists('id', $p)) {
                if (is_numeric($p['id'])) {
                    $this->id = $p['id'];
                } else {
                    $this->id = null;
                    unset($p['id']);
                }
            }

            $this->data = $p;
            unset($this->linearData);

            $this->template = null;
            if (!empty($this->data['template_id']) && $this->loadTemplate) {
                $this->template = SingletonCollection::getInstance()->getTemplate($this->data['template_id']);
            }
        }

        // Check if there is defaultPid specified in template config
        if (!empty($this->template)) {
            $templateData = $this->template->getData();

            if (!empty($templateData['cfg']['defaultPid'])) {
                $this->data['pid'] = $templateData['cfg']['defaultPid'];
            }
        }

        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('beforeNodeDbCreate', new BeforeNodeDbCreateEvent($this));
        $dispatcher->dispatch('beforeNodeDbCreateOrUpdate', new NodeDbCreateOrUpdateEvent($this));

        $p = &$this->data;

        if (!Security::canCreateActions($p['pid'])) {
            throw new \Exception($this->trans('Access_denied'));
        }

        // check input params
        if (!isset($p['pid'])) {
            throw new \Exception("No pid specified for object creation", 1);
        }

        if (empty($p['name'])) {
            throw new \Exception("No name specified for object creation", 1);
        }

        $this->id = DM\Tree::create(
            $this->collectModelData()
        );

        if (!isset($this->id) || !(intval($this->id) > 0)) {
            trigger_error('Error creating object', E_USER_ERROR);
        }

        $p['id'] = $this->id;

        $this->createCustomData();

        $this->checkDraftChilds();

        // Load the object from db to have all its created data
        $this->load();

        // Fire create event
        $dispatcher->dispatch('nodeDbCreate', new NodeDbCreateEvent($this));
        $dispatcher->dispatch('nodeDbCreateOrUpdate', new NodeDbCreateOrUpdateEvent($this));

        if (empty($p['draft'])) {
            $this->logAction(
                'create',
                [
                    'mentioned' => $this->lastMentionedUserIds,
                ]
            );
        }

        return $this->id;
    }

    /**
     * Internal function to collect data for data model update
     * @return array
     */
    protected function collectModelData()
    {
        $p = &$this->data;

        if (empty($p['pid'])) {
            $p['pid'] = null;
        }

        $draftPid = empty($p['draftPid']) ? null : $p['draftPid'];

        $isDraft = intval(!empty($draftPid) || !empty($p['draft']));

        if (empty($p['date_end'])) {
            $p['date_end'] = null;
        }

        if (empty($p['tag_id'])) {
            $p['tag_id'] = null;
        }

        if (empty($p['cid'])) {
            $p['cid'] = User::getId();
        }
        if (empty($p['oid'])) {
            $p['oid'] = $p['cid'];
        }

        if (empty($p['cdate'])) {
            $p['cdate'] = null;
        }

        $r = DM\Tree::collectData($p);

        $r = array_merge(
            $r,
            [
                'id' => $this->id,
                'draft' => $isDraft,
                'draft_pid' => $draftPid,
                'cdate' => Util\coalesce(@$r['cdate'], 'CURRENT_TIMESTAMP'),
                'updated' => 1,
            ]
        );

        // system flag shouldn't be updated if not set
        if (isset($r['system'])) {
            $r['system'] = intval($r['system']);
        }

        return $r;
    }

    /**
     * @return array
     */
    protected function collectCustomModelData()
    {
        $rez = [];

        if (!empty($this->tableFields)) {
            $p = &$this->data;

            foreach ($this->tableFields as $fieldName) {
                $field = null;

                if (!empty($this->template)) {
                    $field = $this->template->getField($fieldName);
                }

                if (isset($p[$fieldName])) {
                    $rez[$fieldName] = $p[$fieldName];

                } elseif (!empty($field)) {
                    $rez[$fieldName] = @$this->getFieldValue($fieldName, 0)['value'];

                } elseif (!empty($p['data'][$fieldName])) {
                    $rez[$fieldName] = $p['data'][$fieldName];
                }

                if (isset($rez[$fieldName]) && !is_scalar($rez[$fieldName]) && !is_null($rez[$fieldName])) {
                    $rez[$fieldName] = Util\jsonEncode($rez[$fieldName]);
                }
            }
        }

        return $rez;
    }

    /**
     * internal function used by create method for creating custom data
     * @return void
     */
    protected function createCustomData()
    {
        $p = &$this->data;

        $p['data'] = Util\toJSONArray(@$p['data']);
        $p['sys_data'] = Util\toJSONArray(@$p['sys_data']);

        // Filter fields
        $this->filterHTMLFields($p['data']);

        $this->lastMentionedUserIds = $this->setFollowers();

        $this->collectSolrData();

        $data = [
            'id' => $this->id,
            'data' => Util\jsonEncode($p['data']),
            'sys_data' => Util\jsonEncode($p['sys_data']),
        ];

        if (DM\Objects::exists($this->id)) {
            DM\Objects::update($data);
        } else {
            DM\Objects::create($data);
        }
    }

    /**
     * Analize object data and set 'wu' property in sys_data
     *
     * return newly assigned ids
     */
    protected function setFollowers()
    {
        $rez = [];

        $d = &$this->data;
        $sd = &$d['sys_data'];
        $tpl = $this->getTemplate();

        // Add creator as follower by default, but not for folder template
        if (empty($sd['wu'])) {
            $sd['wu'] = [];
        }

        if (!empty($tpl)) {
            $fields = $tpl->getFields();

            foreach ($fields as $f) {
                if (!empty($f['cfg']['mentionUsers'])) {
                    $values = $this->getFieldValue($f['name']);
                    foreach ($values as $v) {
                        if (!empty($v['value'])) {
                            $uids = Util\getReferencedUsers($v['value']);
                            if (!empty($uids)) {
                                $sd['wu'] = array_merge($sd['wu'], $uids);
                                $rez = array_merge($rez, $uids);
                            }
                        }
                    }
                }
            }

        }

        $sd['wu'] = array_unique($sd['wu']);

        $rez = array_unique($rez);

        return $rez;
    }

    /**
     * Method to check if this object has a draftId set in properties
     * and check if there exists other objects that point to this draftId
     *
     * @return void
     */
    protected function checkDraftChilds()
    {
        if (empty($this->data['draftId'])) {
            return;
        }

        DM\Tree::assignChildDrafts(
            $this->data['draftId'],
            $this->id
        );
    }

    /**
     * Load object data into $this->data
     *
     * @param int $id
     *
     * @return array loaded data
     */
    public function load($id = null)
    {
        if (!is_numeric($id)) {
            if (!is_numeric($this->id)) {
                throw new \Exception("No object id specified for load", 1);
            }
            $id = $this->id;
        } else {
            $this->id = $id;
        }

        $this->data = [];
        $this->template = null;
        unset($this->linearData);

        $r = DM\Tree::read($id);
        if (!empty($r)) {
            $this->data = $r;

            $r = DM\TreeInfo::read($id);
            if (!empty($r)) {
                unset($r['updated']);
                $this->data = array_merge($this->data, $r);
            }

            if (!empty($this->data['template_id']) && $this->loadTemplate) {
                $this->template = SingletonCollection::getInstance()->getTemplate($this->data['template_id']);
            }
        }

        $this->loadCustomData();

        $this->loaded = true;

        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('onNodeLoad', new NodeLoadEvent($this));

        return $this->data;
    }

    /**
     * load custom data for $this->id
     *
     * in this partucular case, for objects, this method sets
     * data into $this->data
     * @return void
     */
    protected function loadCustomData()
    {
        $r = DM\Objects::read($this->id);

        if (!empty($r)) {
            $this->data['data'] = Util\toJSONArray($r['data']);
            $this->data['sys_data'] = Util\toJSONArray($r['sys_data']);
            unset($this->linearData);
        }
    }

    /**
     * Update object
     *
     * @param array|bool|false $p Optional properties. If not specified then $this-data is used
     *
     * @return boolean
     */
    public function update($p = false)
    {
        if ($p !== false) {
            $this->data = $p;
            unset($this->linearData);

            if (array_key_exists('id', $p)) {
                $this->id = $p['id'];
            }
            $this->template = null;
            if (!empty($this->data['template_id']) && $this->loadTemplate) {
                $this->template = SingletonCollection::getInstance()->getTemplate($this->data['template_id']);
            }
        }
        if (!is_numeric($this->id)) {
            throw new \Exception("No object id specified for update", 1);
        }

        //load current object from db into a variable to be passed to log and events
        $this->oldObject = clone $this;
        $od = $this->oldObject->load($this->id);

        $wasDraft = !empty($od['draft']);

        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('beforeNodeDbUpdate', new BeforeNodeDbUpdateEvent($this));
        $dispatcher->dispatch('beforeNodeDbCreateOrUpdate', new NodeDbCreateOrUpdateEvent($this));

        $p = &$this->data;

        $data = $this->collectModelData($p);

        $data = array_merge(
            $data,
            [
                'draft' => 0,
                'uid' => User::getId(),
                'udate' => 'CURRENT_TIMESTAMP',
            ]
        );

        DM\Tree::update($data);

        DM\Tree::activateChildDrafts($this->id);

        $this->updateCustomData();

        // set/update this object to cache
        Cache::set('Objects['.$this->id.']', $this);

        $dispatcher->dispatch('nodeDbUpdate', new NodeDbUpdateEvent($this));
        $dispatcher->dispatch('nodeDbCreateOrUpdate', new NodeDbCreateOrUpdateEvent($this));

        if ($wasDraft) {
            $this->logAction(
                'create',
                [
                    'mentioned' => $this->lastMentionedUserIds,
                ]
            );

        } else {
            $this->logAction(
                'update',
                [
                    'old' => $this->oldObject,
                    'mentioned' => $this->lastMentionedUserIds,
                ]
            );
        }

        return true;
    }

    /**
     * Update objects custom data
     * @return boolean
     */
    protected function updateCustomData()
    {
        $d = &$this->data;

        if (empty($d['data'])) {
            $d['data'] = [];
        }
        if (empty($d['sys_data'])) {
            $d['sys_data'] = [];
        }

        $this->filterHTMLFields($d['data']);

        $this->lastMentionedUserIds = $this->setFollowers();

        $this->collectSolrData();

        unset($this->linearData);

        $data = [
            'id' => $d['id'],
            'data' => Util\jsonEncode($d['data']),
            'sys_data' => Util\jsonEncode($d['sys_data']),
        ];

        if (DM\Objects::exists($d['id'])) {
            DM\Objects::update($data);
        } else {
            DM\Objects::create($data);
        }

        return true;
    }

    /**
     * Get objects system data (sysData field)
     * return sysData form this class if loaded or reads directly from db
     *
     * @return array
     */
    public function getSysData()
    {
        $rez = [];

        if ($this->loaded) {
            $rez = Util\toJSONArray(@$this->data['sys_data']);
        } else {
            $r = DM\Objects::read($this->data['id']);

            if (!empty($r)) {
                $rez = $r['sys_data'];
            }
        }

        return $rez;
    }

    /**
     * Update objects system data (sysData field)
     * this method updates data directly and desnt fire update events
     *
     * @param string $sysData Array or json encoded string if not specified then
     *      sysTada from current class will be used for update
     *
     * @return boolean
     */
    public function updateSysData($sysData = false)
    {
        $d = &$this->data;

        if ($sysData !== false) {
            $d['sys_data'] = Util\toJSONArray($sysData);
        }

        $d['sys_data']['lastAction'] = $this->getLastActionData();

        $this->collectSolrData();

        $data = [
            'id' => $d['id'],
            'sys_data' => Util\jsonEncode($d['sys_data']),
        ];

        if (DM\Objects::exists($d['id'])) {
            DM\Objects::update($data);
        } else {
            DM\Objects::create($data);
        }

        // Mark the item as updated so that it'll be reindexed into solr
        DM\Tree::update(
            [
                'id' => $d['id'],
                'updated' => 1,
            ]
        );

        return true;
    }

    /**
     * Get a property from system data of the object (sysData field)
     *
     * @param string $propertyName
     *
     * @return string | null
     */
    public function getSysDataProperty($propertyName)
    {
        $rez = null;

        if (empty($propertyName) || !is_scalar($propertyName)) {
            return $rez;
        }

        $d = $this->getSysData();

        if (isset($d[$propertyName])) {
            $rez = $d[$propertyName];
        }

        return $rez;
    }

    /**
     * Update a property system data of the object (sysData field)
     * if value is null the property is unset from sys_data
     *
     * @param string $propertyName
     * @param string $propertyValue
     *
     * @return boolean
     */
    public function setSysDataProperty($propertyName, $propertyValue = null)
    {
        if (empty($propertyName) || !is_scalar($propertyName)) {
            return false;
        }

        $d = $this->getSysData();

        if (is_null($propertyValue)) {
            unset($d[$propertyName]);
        } else {
            $d[$propertyName] = $propertyValue;
        }

        $this->updateSysData($d);

        return true;
    }

    /**
     * Method to collect solr data from object data
     * according to template fields configuration
     * and store it in sys_data under "solr" property
     * @return void
     */
    protected function collectSolrData()
    {
        // Iterate template fields and collect fieldnames
        // to be indexed in solr, as well as title fields
        $rez = [
            'content' => '',
        ];
        $children = [];
        $indexAsChildrenIds = [];
        $indexAsChildrenIndexes = [];
        $pids = [];

        $d = &$this->data;
        $sd = &$d['sys_data'];

        $tpl = $this->getTemplate();
        $tplCfg = [];

        $languages = $this->configService->get('languages');

        if (!empty($tpl)) {
            $fields = $tpl->getFields();

            // Create a list of possible title fields that should be added to solr
            $titleFields = []; // Will go into title property
            foreach ($languages as $l) {
                $titleFields[] = 'title_'.$l;
            }

            foreach ($fields as $f) {
                $values = $this->getFieldValue($f['name']);

                if (!empty($f['cfg']['indexAsChildren'])) {
                    $indexAsChildrenIds[] = $f['id'];
                    $indexAsChildrenIndexes[$f['id']] = empty($f['cfg']['indexingIdx']) ? $f['id'] : $f['cfg']['indexingIdx'];
                }

                if (empty($f['solr_column_name']) && in_array($f['name'], $titleFields)) {
                    $sfn = $f['name'].'_t'; // Solr field name

                    if (!empty($values[0]['value'])) {
                        $rez[$sfn] = $values[0]['value'];
                    }

                } elseif (!empty($f['cfg']['faceting']) || //backward compatible check
                    !empty($f['cfg']['indexed'])
                ) {
                    $pids[$f['id']] = empty($pids[$f['pid']]) ? [$f['pid'], $f['id']] : array_merge($pids[$f['pid']], [$f['id']]);

                    $childrenRecordIds = array_intersect($pids[$f['id']], $indexAsChildrenIds);

                    $resultValue = [];

                    //iterate each duplicate
                    foreach ($values as $v) {
                        $value = $this->prepareValueforSolr($f['type'], $v);
                        if (!empty($value)) {
                            $resultValue[] = $value;

                            foreach ($childrenRecordIds as $crId) {
                                $idx = $this->id.'_'.$indexAsChildrenIndexes[$crId].'_'.intval($v['idx']);
                                $children[$idx]['idx'] = $indexAsChildrenIndexes[$crId];
                                $children[$idx][$f['solr_column_name']] = $value;
                            }
                        }
                    }

                    // Check result value. If its a single value then set as is.
                    // If multiple values then merge into an array
                    if (!empty($resultValue)) {
                        $finalValue = null;
                        if (sizeof($resultValue) == 1) {
                            $finalValue = array_shift($resultValue);
                        } else {
                            $finalValue = [];
                            foreach ($resultValue as $value) {
                                if (is_array($value)) {
                                    $finalValue = array_merge($finalValue, $value);
                                } else {
                                    $finalValue[] = $value;
                                }

                            }
                        }

                        $rez[$f['solr_column_name']] = $finalValue;
                    }
                }

                // add all textual fields to content
                foreach ($values as $v) {
                    if (!empty($v['value'])) {
                        $rez['content'] .= (in_array($f['name'], ['date_start', 'date_end', 'dates']) ? substr($v, 0, 10) : $v['value']
                            )."\n";
                    }
                }

            }

            if (!empty($children)) {
                // $parentValues = $rez;
                $rez['_childDocuments_'] = [];
                foreach ($children as $k => $v) {
                    $rez['_childDocuments_'][] = array_merge(
                    // $parentValues,
                        $v,
                        [
                            'id' => $this->id,
                            'doc_id' => $k,
                            'child' => 'true',
                        ]
                    );
                }
            }

            $tplCfg = $tpl->getData()['cfg'];
        }

        if (!empty($tplCfg['copySolrFields'])) {
            foreach ($tplCfg['copySolrFields'] as $fns => $sc) {
                $values = [];
                $lvalues = $this->getLookupValues($fns);

                foreach ($lvalues as $v) {
                    $v = is_array($v) ? @$v['value'] : $v;

                    if (!empty($v)) {
                        if (preg_match('/^(\d+,)*\d+$/', $v)) {
                            $v = Util\toNumericArray($v);
                            foreach ($v as $id) {
                                $values[] = $id;
                            }
                        } else {
                            $values[] = $v;
                        }
                    }
                }

                if (!empty($values)) {
                    $values = array_unique($values);
                    $rez[$sc] = $values;
                }
            }
        }
        // add last comment info if present
        if (!empty($sd['lastComment'])) {
            $rez['comment_user_id'] = $sd['lastComment']['user_id'];
            $rez['comment_date'] = $sd['lastComment']['date'];
        }

        // add time spent info if present
        if (!empty($sd['spentTime'])) {
            $rez['time_spent_i'] = $sd['spentTime']['sec'];
            $rez['time_spent_money_f'] = $sd['spentTime']['money'];
        }

        $this->data['sys_data']['solr'] = $rez;
    }

    /**
     * Just for update purposes only
     * Should be removed in future
     * @return void
     */
    public function updateSolrData()
    {
        $this->load();

        // $this->collectSolrData(); // called by updateSysData

        $this->updateSysData();
    }

    /**
     * Prepare a given value for solr according to its type
     *
     * @param string $type (checkbox,combo,date,datetime,float,html,int,memo,_objects,text,time,timeunits,string)
     * @param string $value
     *
     * @return string
     */
    protected function prepareValueforSolr($type, $value)
    {
        if (empty($value) || empty($value['value'])) {
            return null;
        }

        $value = $value['value'];
        switch ($type) {
            case 'boolean': //not used
            case 'checkbox':
                $value = empty($value) ? false : true;
                break;

            case 'date':
            case 'datetime':
                if (!empty($value)) {
                    //check if there is only date, without time
                    if (strlen($value) == 10) {
                        $value .= 'T00:00:00';
                    }

                    if (substr($value, -1) != 'Z') {
                        $value .= 'Z';
                    }

                    if (@$value[10] == ' ') {
                        $value[10] = 'T';
                    }
                }
                break;

            /** time values are stored as seconds representation in solr */
            case 'time':
                if (!empty($value)) {
                    $a = explode(':', $value);
                    @$value = $a[0] * 3600 + $a[1] * 60 + $a[2];
                }
                break;

            case 'combo':
            case 'int':
            case '_objects':

                $arr = Util\toNumericArray($value);
                $value = [];

                //remove zero values
                foreach ($arr as $v) {
                    if (!empty($v)) {
                        $value[] = $v;
                    }
                }

                $value = array_unique($value);

                if (empty($value)) {
                    $value = null;

                } elseif (sizeof($value) == 1) {
                    // set just value if 1 element array
                    $value = array_shift($value);
                }
                break;

            case 'html':
                $value = strip_tags($value);
                break;

        }

        return $value;
    }

    public function getLookupValues($fields, &$resultField = null)
    {
        $rez = [];
        $fields = Util\toTrimmedArray($fields, '.');
        $objects = [&$this];

        do {
            $fn = array_shift($fields);
            $values = [];

            foreach ($objects as &$o) {
                $tpl = $o->getTemplate();
                $tf = $tpl->getField($fn);

                if (!empty($tf)) {
                    $resultField = $tf;
                    $v = $o->getFieldValue($fn);

                    if (!empty($v)) {
                        $values = array_merge($values, $v);
                    }
                }
            }

            $objects = [];
            foreach ($values as $v) {
                $v = is_array($v) ? @$v['value'] : $v;
                $v = Util\toNumericArray($v);
                foreach ($v as $id) {
                    $objects[] = Objects::getCachedObject($id);
                }
            }

            $rez = $values;

        } while (!empty($fields) && !empty($tf['type']) && ($tf['type'] == '_objects'));

        return $rez;
    }

    /**
     * Get action flags that a user can do this object
     *
     * @param int|bool|false $userId
     *
     * @return array
     */
    public function getActionFlags($userId = false)
    {
        return [];
    }

    /**
     * Get actions html row for preview
     * @return array
     */
    public function getPreviewActionsRow()
    {
        $rez = [];
        $flags = $this->getActionFlags();

        foreach ($flags as $k => $v) {
            if (!empty($v)) {
                $rez[] = "<a action=\"$k\" class=\"item-action ib-$k\">".$this->trans(ucfirst($k)).'</a>';
            }
        }

        $rez = empty($rez) ? '' : '<div class="task-actions">'.implode(' ', $rez).'</div>';

        return $rez;
    }

    /**
     * Delete an object from tree or marks it as deleted
     *
     * @param boolean $persistent Specify true to delete the object permanently.
     *      Default to false.
     *
     * @return void
     */
    public function delete($persistent = false)
    {
        // we need to load this object before delete
        // for passing it to log and/or events

        if (!is_numeric($this->id)) {
            return;
        }

        if (!$this->loaded) {
            $this->load();
        }

        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('beforeNodeDbDelete', new BeforeNodeDbDeleteEvent($this));

        DM\Tree::delete($this->id, $persistent);

        if ($persistent) {
            $solrClient = new Client();
            $solrClient->deleteByQuery('id:'.$this->id);
        }

        $this->deleteCustomData($persistent);

        $dispatcher->dispatch('nodeDbDelete', new NodeDbDeleteEvent($this));

        // Don't add log action if persistent deleted
        if (!$persistent) {
            $this->logAction('delete', ['old' => &$this]);
        }
    }

    /**
     * Delete custom data for an object
     *
     * use this method (overwrite it) for descendant classes
     * when there is need to delete custom data on object delete
     * @return integer
     */
    protected function deleteCustomData($permanent)
    {
        $permanent = $permanent; // dummy codacy assignment
    }

    /**
     * restore a deleted object
     * @return void
     */
    public function restore()
    {
        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('beforeNodeDbRestore', new BeforeNodeDbRestoreEvent($this));

        DM\Tree::restore($this->id);

        $this->restoreCustomData();

        $dispatcher->dispatch('nodeDbRestore', new NodeDbRestoreEvent($this));

        // we need to load this object on restore
        // for passing it to log and/or events
        if (!$this->loaded) {
            $this->load();
        }

        $this->logAction('restore');
    }

    /**
     * restore custom data for an object
     *
     * use this method (overwrite it) for descendant classes
     * when there is need to restore custom data on object restore
     * @return integer
     */
    protected function restoreCustomData()
    {

    }

    /**
     * get parent object
     * @return object | null
     */
    protected function getParentObject()
    {
        if (empty($this->parentObj)) {
            $this->parentObj = null;
            if (!empty($this->data['pid'])) {
                $this->parentObj = \Casebox\CoreBundle\Service\Objects::getCachedObject($this->data['pid']);
            }
        }

        return $this->parentObj;
    }

    /**
     * Return the owner of the object
     */
    public function getOwner()
    {
        $d = &$this->data;

        return @Util\coalesce($d['oid'], $d['cid']);
    }

    /**
     * Check if given user is owner of the task
     *
     * @param  integer|bool|false $userId
     *
     * @return bool
     */
    public function isOwner($userId = false)
    {
        $d = &$this->data;

        if ($userId === false) {
            $userId = User::getId();
        }

        $ownerId = empty($d['oid']) ? $d['cid'] : $d['oid'];

        return ($ownerId == $userId);
    }

    /**
     * Get a field value from current objects data ($this->data)
     *
     * This function return an array of values for duplicate fields
     *
     * @param string $fieldName field name
     * @param integer $valueIndex optional value duplication index. default false
     *
     * @return array
     */
    public function getFieldValue($fieldName, $valueIndex = false)
    {
        $rez = [];
        $ld = $this->getAssocLinearData();
        if (!empty($ld[$fieldName])) {
            $rez = $ld[$fieldName];
        }
        if ($valueIndex !== false) {
            $rez = @$rez[$valueIndex];
        }

        return $rez;
    }

    /**
     * get object data
     *
     * @return array object properties
     */
    public function getData()
    {
        // Its not correct to automatically load data
        // because there could be other data set through update or create
        // and by autoloading will override the data set.
        // load method should be called manually when needed
        //
        // if (!$this->loaded && !empty($this->id)) {
        //     $this->load();
        // }
        //
        return $this->data;
    }

    /**
     * get solr data property
     *
     * @return array
     */
    public function getSolrData()
    {
        $rez = [];
        if (!empty($this->data['sys_data']['solr'])) {
            $rez = $this->data['sys_data']['solr'];
        }

        return $rez;
    }

    /**
     * get linear array of properties of object properties
     *
     * @param  boolean $sorted true to sort data according to template fields order
     *
     * @return array|null
     */
    public function getLinearData($sorted = false)
    {
        $paramName = 'linearData'.($sorted ? 'sorted' : '');
        if (!empty($this->$paramName)) {
            return $this->$paramName;
        }

        $this->$paramName = $this->getLinearNodesData($this->data['data'], $sorted);

        return $this->$paramName;
    }

    /**
     * Get an associative linear array of field values
     *
     * @param  array $data template properties
     *
     * @return array
     */
    public function getAssocLinearData()
    {
        $rez = [];
        $linearData = $this->getLinearData();
        foreach ($linearData as $field) {
            $value = array_intersect_key(
                $field,
                [
                    'value' => 1,
                    'info' => 1,
                    'files' => 1,
                    'cond' => 1,
                    'idx' => 1,
                ]
            );

            $rez[$field['name']][] = $value;
        }

        return $rez;
    }

    /**
     * Private function used to sort an array(using php usort function) of field elements
     * according to their template order from template
     *
     * @param array $a
     * @param array $b
     *
     * @return int
     */
    protected function fieldsArraySorter($a, $b)
    {
        if ($a['name'] == $b['name']) { //ordering duplicates by index
			if (!isset($a['idx']) || !isset($b['idx']))
			{
				return 0;
			}
			  elseif ($a['idx'] < $b['idx']) {
                return -1;
            } elseif ($a['idx'] > $b['idx']) {
                return 1;
            }

        } elseif (!empty($this->template)) {
            $o1 = $this->template->getFieldOrder($a['name']);
            $o2 = $this->template->getFieldOrder($b['name']);
            if ($o1 < $o2) {
                return -1;
            } elseif ($o1 > $o2) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @param array $data
     * @param bool $sorted
     *
     * @return array
     */
    protected function getLinearNodesData(&$data, $sorted = false, $maxInstancesIndex = 0)
    {
        $rez = [];
        if (empty($data)) {
            return $rez;
        }

        $template = $this->getTemplate();
        $templateData = $template->getData();
        $headers = $templateData['headers'];

        foreach ($data as $fieldName => $fieldValue) {
            if ($this->isFieldValue($fieldValue)) {
                $fieldValue = [$fieldValue];
            }

            $templateField = $template->getField($fieldName);
            $level = $templateField['level'];

            if ($templateField['type'] == 'H') {
                $prevHeaderField = $templateField;

            } else {
                $headerField = (empty($headers[$fieldName])) ? false : $headers[$fieldName];

                if (!empty($headerField) &&
                    (
                        empty($prevHeaderField) ||
                        ($headerField['name'] !== $prevHeaderField['name'])
                    )
                ) {
                    $prevHeaderField = $headerField;

                    if (!isset($data[$headerField['name']])) {
                        $rez[] = [
                            'name' => $headerField['name'],
                            'value' => null,
                        ];
                    }
                }
            }

            $idx = $maxInstancesIndex;

            foreach ($fieldValue as $fv) {
                $value = [
                    'name' => $fieldName,
                    'idx' => $idx++,
                ];

                if (is_scalar($fv) ||
                    is_null($fv)
                ) {
                    $value['value'] = $fv;
                } else {
                    $value = array_merge($value, $fv);
                }
                $rez[] = $value;
            }
        }

        if ($sorted) {
            usort($rez, [$this, 'fieldsArraySorter']);
        }

        $sortedRez = [];
        // Iterate fields and insert childs if present
        foreach ($rez as $fv) {
            $sortedRez[] = $fv;
            if (!empty($fv['childs'])) {
                $sortedRez = array_merge(
                    $sortedRez,
                    $this->getLinearNodesData($fv['childs'], $sorted, $fv['idx'])
                );
            }
        }

        return $sortedRez;
    }

    /**
     * Set object data
     *
     * @param array $data template properties
     */
    public function setData($data, $filterHtmlValues = true)
    {
        $this->data = $data;
        unset($this->linearData);

        if (array_key_exists('id', $data)) {
            $this->id = $data['id'];
            $this->loaded = true;
        }

        if ($filterHtmlValues && !empty($this->data['data'])) {
            $this->filterHTMLFields($this->data['data']);
        }
    }

    /**
     * Get object template property
     *
     * @return array object properties
     */
    public function getTemplate()
    {
        if (empty($this->template) && $this->loadTemplate && !empty($this->data['template_id'])) {
            $this->template = SingletonCollection::getInstance()->getTemplate(
                $this->data['template_id']
            );
        }

        return $this->template;
    }

    /**
     * Get template name of the object
     * @return string | null
     */
    public function getTemplateName()
    {
        $template = $this->getTemplate();
        if (empty($template)) {
            return null;
        }

        $templateData = $template->getData();

        return @$templateData['name'];
    }

    /**
     * Get name of the object corresponding to current user language
     *
     * @param string|bool|false $language
     *
     * @return string
     */
    public function getName($language = false)
    {
        $d = &$this->data;

        $rez = $d['name'];

        if ($language === false) {
            $language = Cache::get('symfony.request')->getLocale();
        }

        if (is_string($language) && !empty($d['sys_data']['solr']['title_'.$language.'_t'])) {
            $rez = $d['sys_data']['solr']['title_'.$language.'_t'];
        }

        return $rez;
    }

    /**
     * get html safe name
     *
     * @param string $language
     *
     * @return string
     */
    public function getHtmlSafeName($language = false)
    {
        $rez = $this->getName($language);

        $rez = htmlspecialchars($rez, ENT_COMPAT);

        return $rez;
    }

    /**
     * get object type from template
     *
     * @return string
     */
    public function getType()
    {
        $template = $this->getTemplate();
        if (empty($template)) {
            return null;
        }
        $data = $template->getData();

        return @$data['type'];
    }

    /**
     * detect if a given value is a generic field value
     * from json array stored in data fields
     *
     * @param string $value
     *
     * @return boolean
     */
    public static function isFieldValue($value)
    {
        if (is_scalar($value) ||
            is_null($value)
        ) {
            return true;
        }
        // analize array values
        if (is_array($value)) {
            // non associative array
            if (array_values($value) === $value) {
                return false;
            } else { //associative array
                $keys = array_keys($value);
                $diff = array_diff($keys, ['name', 'value', 'info', 'files', 'childs', 'cond']);

                return empty($diff);
            }
        }

        // not detected case;
        return null;
    }

    /**
     * copy an object to $pid or over $targetId
     *
     * better way to copy an object over another one is to delete the target,
     * but this could be very dangerous. We could delete required/important data
     * so i suggest to just mark overwriten object with dstatus = 3.
     * But in this situation appears another problem with child nodes.
     * Childs should be moved to new parent.
     *
     * @param int $pid if not specified then will be set to pid of targetId
     * @param int $targetId
     *
     * @return int the id of copied object
     */
    public function copyTo($pid = false, $targetId = false)
    {
        // check input params
        if (!is_numeric($this->id) ||
            (!is_numeric($pid) && !is_numeric($targetId))
        ) {
            return false;
        }

        /* security check */
        if (!\Casebox\CoreBundle\Service\Security::canRead($this->id)) {
            return false;
        }
        /* end of security check */

        if (is_numeric($targetId)) {
            /* target security check */
            if (!\Casebox\CoreBundle\Service\Security::canWrite($targetId)) {
                return false;
            }
            /* end of target security check */

            // marking overwriten object with dstatus = 3
            DM\Tree::update(
                [
                    'id' => $targetId,
                    'updated' => 1,
                    'dstatus' => 3,
                    'did' => User::getId(),
                ]
            );

            $r = DM\Tree::read($targetId);
            if (!empty($r)) {
                $pid = $r['pid'];
            }

        } else {
            /* pid security check */
            if (!Security::canWrite($pid)) {
                return false;
            }
            /* end of pid security check */
        }

        /* check again if we have pid set
            It can be unset when not existent $targetId is specified
        */
        if (!is_numeric($pid)) {
            return false;
        }

        // copying the object to $pid

        $objectId = DM\Tree::copy(
            $this->id,
            $pid
        );

        /* we have now object created, so we start copy all its possible data:
            - tree_info is filled automaticly by trigger
            - custom security rules from tree_acl
            - custom object data
        */

        // copy node custom security rules if set
        Security::copyNodeAcl($this->id, $objectId);

        $this->copyCustomDataTo($objectId);

        // move childs from overwriten targetId (which has been marked with dstatus = 3)
        // to newly copied object
        if (is_numeric($targetId)) {
            // DM\Tree::update(

            // )
            DM\Tree::moveActiveChildren($targetId, $this->id);
        }

        $newObj = clone $this;
        $newObj->load($objectId);

        $this->logAction(
            'copy',
            [
                'old' => $this,
                'new' => $newObj,
            ]
        );

        return $objectId;
    }

    /**
     * copy data from objects table
     *
     * @param int $targetId
     *
     * @return void
     */
    protected function copyCustomDataTo($targetId)
    {
        DM\Objects::copy($this->id, $targetId);
    }

    /**
     * move an object to $pid or over $targetId
     *
     * we'll use the same principle as for copy
     *
     * @param int $pid if not specified then will be set to pid of targetId
     * @param int $targetId
     *
     * @return int the id of moved object or false
     */
    public function moveTo($pid = false, $targetId = false)
    {
        // check input params
        if (!is_numeric($this->id) ||
            (!is_numeric($pid) && !is_numeric($targetId))
        ) {
            return false;
        }

        if (!Security::canRead($this->id)) {
            return false;
        }

        //load current object from db into a variable to be passed to log and events
        $this->oldObject = clone $this;
        $this->oldObject->load($this->id);

        if (is_numeric($targetId)) {
            if (!Security::canWrite($targetId)) {
                return false;
            }

            // marking over-written object with dstatus = 3
            DM\Tree::update(
                [
                    'id' => $targetId,
                    'updated' => 1,
                    'dstatus' => 3,
                    'did' => User::getId(),
                ]
            );

            $r = DM\Tree::read($targetId);
            if (!empty($r)) {
                $pid = $r['pid'];
            }

        } else {
            if (!Security::canWrite($pid)) {
                return false;
            }
        }

        if (!is_numeric($pid)) {
            return false;
        }

        // moving the object to $pid
        DM\Tree::update(
            [
                'id' => $this->id,
                'pid' => $pid,
                'updated' => 1,
            ]
        );

        $this->moveCustomDataTo($pid);

        // move childs from overwriten targetId (which has been marked with dstatus = 3)
        // to newly copied object
        if (is_numeric($targetId)) {
            DM\Tree::moveActiveChildren($targetId, $this->id);
        }

        $this->load();

        $this->logAction('move', ['old' => $this->oldObject]);

        return $this->id;
    }

    /**
     *  method that should be overwriten in descendants classes
     * if any custom actions should be made on objects move
     */
    protected function moveCustomDataTo($targetId)
    {
        $targetId = $targetId; //dummy assignment for codacy
    }

    /**
     * filter html field with through purify library
     *
     * @param array $fieldsArray
     * @param boolean $htmlEncode set true to encode all special chars from string fields
     *
     * @return void
     */
    protected function filterHTMLFields(&$fieldsArray, $htmlEncode = false)
    {
        $template = $this->getTemplate();

        if (!is_array($fieldsArray) || !is_object($template)) {
            return;
        }

        foreach ($fieldsArray as $fn => $fv) {

            //if dont need to encode special chars then process only html fields
            if ($htmlEncode == false) {
                $templateField = $template->getField($fn);

                if ($templateField['type'] !== 'html') {
                    continue;
                }
            }

            $purify = ($templateField['type'] == 'html');

            // analize value
            if ($this->isFieldValue($fv)) {
                if (is_string($fv)) {
                    $fieldsArray[$fn] = $this->filterFieldValue($fv, $purify, $htmlEncode);

                } elseif (is_array($fv) && !empty($fv['value'])) {
                    $fieldsArray[$fn]['value'] = $this->filterFieldValue($fv['value'], $purify, $htmlEncode);
                    if (!empty($fv['childs'])) {
                        $this->filterHTMLFields($fieldsArray[$fn]['childs']);
                    }
                }
            } elseif (is_array($fv)) { //multivalued field
                for ($i = 0; $i < sizeof($fv); $i++) {
                    if (is_string($fv[$i])) {
                        $fieldsArray[$fn][$i] = $this->filterFieldValue($fv[$i], $purify, $htmlEncode);

                    } elseif (is_array($fv[$i]) && !empty($fv[$i]['value'])) {
                        $fieldsArray[$fn][$i]['value'] = $this->filterFieldValue(
                            $fv[$i]['value'],
                            $purify,
                            $htmlEncode
                        );
                        if (!empty($fv[$i]['childs'])) {
                            $this->filterHTMLFields($fieldsArray[$fn][$i]['childs']);
                        }
                    }
                }
            }
        }
    }

    /**
     * filter a given value
     *
     * @param string $value
     * @param boolean $purify
     * @param boolean $htmlEncode
     *
     * @return string
     */
    protected function filterFieldValue($value, $purify = false, $htmlEncode = false)
    {
        if ($purify) {
            $value = Purify::html($value);
        }

        if ($htmlEncode) {
            $value = htmlspecialchars($value, ENT_COMPAT);
        }

        return $value;
    }

    /**
     * method to generate preview blocks and return them as an array
     * now there are only top and bottom blocks
     * top contains fields from grid, bottom - complex fileds (html, text) edited outside the grid
     *
     * @return array
     */
    public function getPreviewBlocks()
    {
        $top = '';
        $body = '';
        $bottom = '';
        $gf = [];

        if (!$this->loaded && !empty($this->id)) {
            $this->load();
        }

        $linearData = $this->getLinearData(false);

        $template = $this->getTemplate();

        //group fields in display blocks
        foreach ($linearData as $field) {
            $tf = $template->getField($field['name']);

            if (empty($tf)) {
                //fantom data of deleted or moved fields
                continue;
            }

            if (empty($tf['cfg'])) {
                $group = 'body';
            } elseif (@$tf['cfg']['showIn'] == 'top') {
                $group = 'body'; //top
            } elseif (@$tf['cfg']['showIn'] == 'tabsheet') {
                $group = 'bottom';
            } else {
                $group = 'body';
            }

            //show field name if no title set
            if (empty($tf['title'])) {
                $tf['title'] = $tf['name'];
            }

            $field['tf'] = $tf;
            $gf[$group][] = $field;
        }

        $eventParams = [
            'object' => &$this,
            'groupedFields' => &$gf,
        ];

        /** @var EventDispatcher $dispatcher */
        $dispatcher = Cache::get('symfony.container')->get('event_dispatcher');
        $dispatcher->dispatch('beforeGeneratePreview', new BeforeGeneratePreviewEvent($eventParams));

        if (!empty($gf['top'])) {
            foreach ($gf['top'] as $f) {
                if ($f['name'] == '_title') {
                    continue;
                }

                $v = $template->formatValueForDisplay($f['tf'], $f); //['value']
                if (is_array($v)) {
                    $v = implode(', ', $v);
                }
                if (!empty($v)) {
                    $top .= '<tr><td class="prop-key">'.$f['tf']['title'].'</td><td class="prop-val">'.$v.'</td></tr>';
                }
            }
        }

        if (!empty($gf['body'])) {
            foreach ($gf['body'] as $f) {
                $v = $template->formatValueForDisplay($f['tf'], @$f);

                if (is_array($v)) {
                    $v = implode('<br />', $v);
                }

                if (($f['tf']['type'] == 'H')) {
                    $style = empty($f['tf']['level'])
                        ? ''
                        : 'padding-left: '.($f['tf']['level'] * 20).'px;';

                    $style .= empty($f['tf']['cfg']['style'])
                        ? ''
                        : ';'.$f['tf']['cfg']['style'];

                    $body .= '<tr class="prop-header"><th colspan="3" style="'.$style.'">'.
                        $f['tf']['title'].
                        '</th></tr>';
                    continue;
                }

                if (!empty($f['tf']['cfg']['hidePreview']) ||
                    (empty($v) && empty($f['info']))
                ) {
                    continue;
                }

                $body .= '<tr>';
                if (empty($f['tf']['cfg']['noHeader'])) {
                    $body .= '<td'.(
                        empty($f['tf']['level'])
                            ? ''
                            : ' style="padding-left: '.($f['tf']['level'] * 20).'px"'
                        ).' class="prop-key">'.$f['tf']['title'].'</td>'.
                        '<td class="prop-val">';
                } else {
                    $body .= '<td class="prop-val" colspan="2">';
                }

                $body .= $v.(empty($f['info']) ? '' : '<p class="prop-info">'.$f['info'].'</p>').'</td></tr>';
            }
        }

        //add time spent row if template is marked with "timeTracking"
        if (!empty($template->getData()['cfg']['timeTracking'])) {
            $timeSpent = $this->getTimeSpent();
            // Always show spent time for templates with timeTracking so we can click on it
            // to display the plugin
            // if ($timeSpent > 0) {
            $body .= '<tr><td class="prop-key">'.$this->trans('TimeSpent').'</td>'.
                '<td class="prop-val"><span class="time-spent click">'.
                gmdate("G\h i\m", $timeSpent['sec']).
                ' / $'.number_format($timeSpent['money'], 2).
                '</span> <a class="add-time-spent i-add click"></a>'.
                '</td></tr>';
            // }
        }

        if (!empty($gf['bottom'])) {
            foreach ($gf['bottom'] as $f) {
                $v = $template->formatValueForDisplay($f['tf'], $f);
                if (empty($v)) {
                    continue;
                }
                $bottom .= '<div class="obj-preview-h">'.$f['tf']['title'].'</div>'.
                    '<div style="padding: 0 5px">'.$v.'</div><br />';
            }
        }

        $top .= $body;

        if (!empty($top)) {
            $rtl = empty($this->configService->get('rtl')) ? '' : ' drtl';
            $top = '<table class="obj-preview'.$rtl.'"><tbody>'.$top.'</tbody></table><br />';
        }

        $rez = [
            $this->getPreviewActionsRow().$top,
            $bottom,
        ];

        $eventParams['result'] = &$rez;

        $dispatcher->dispatch('generatePreview', new GeneratePreviewEvent($eventParams));

        return $rez;
    }

    /**
     * get time spent value that is set in sys_data
     * @return int seconds
     */
    public function getTimeSpent()
    {
        $rez = [
            'sec' => 0,
            'money' => 0,
        ];

        $sd = $this->getSysData();
        if (!empty($sd['spentTime'])) {
            $rez = $sd['spentTime'];
        }

        return $rez;
    }

    /**
     * add action to log
     *
     * @param string $type
     * @param array $params
     *
     * @return void
     */
    protected function logAction($type, $params = [])
    {
        if (!Cache::get('disable_logs', false) &&
            !$this->configService->getFlag('disableActivityLog')
        ) {
            $params['type'] = $type;

            $obj = &$this;

            if (empty($params['new'])) {
                $params['new'] = &$this;

            } else {
                $obj = &$params['new'];
            }

            $uid = User::getId();

            //add action to object sys_data
            $data = $obj->getData();
            $params['data'] = $data;
            $logActionId = Log::add($params);

            $lastAction = $obj->getLastActionData();

            if ($lastAction['type'] != $type) {
                $lastAction = [
                    'type' => $type,
                    'users' => [],
                ];
            }

            $lastAction['time'] = Util\dateMysqlToISO('now');

            unset($lastAction['users'][$uid]);

            $lastAction['users'][$uid] = $logActionId;

            $obj->setSysDataProperty('lastAction', $lastAction);
        }
    }

    protected function logDataAction($type, $data)
    {
    	if (!Cache::get('disable_logs', false)) {
    		$uid = User::getId();
    		$params = [
    				'object_id' => 10,
    				'object_pid' => 10,
    				'user_id' => $uid,
    				'action_type' => $type,
    				'data' => Util\jsonEncode($data),
    				'activity_data_db' => Util\jsonEncode($uid),
    		];

        $p['action_id'] = DM\Log::create($params);
    	}
    }

    public function getLastActionData()
    {
        $data = $this->getData();

        $sysData = empty($data['sys_data']) ? $this->getSysData() : $data['sys_data'];

        $rez = [];

        if (!empty($sysData['lastAction'])) {
            $rez = $sysData['lastAction'];

        } else {
            if (!empty($sysData['lastComment'])) {
                $rez = [
                    'type' => 'comment',
                    'time' => $sysData['lastComment']['date'],
                    'users' => [
                        $sysData['lastComment']['user_id'] => 0,
                    ],
                ];
            }

            if (!empty($data['udate']) && (empty($rez['time']) || ($data['udate'] > $rez['time']))) {
                $rez = [
                    'type' => 'update',
                    'time' => Util\dateMysqlToISO($data['udate']),
                    'users' => [
                        $data['uid'] => 0,
                    ],
                ];
            }

            if (empty($rez['time'])) {
                $date = Util\dateMysqlToISO(
                    empty($data['cdate']) ? 'now' : $data['cdate']
                );

                $rez = [
                    'type' => 'create',
                    'time' => $date,
                    'users' => [
                        $data['cid'] => 0,
                    ],
                ];
            }
        }

        return $rez;
    }

    /**
     * get diff html for given log record data
     *
     * @param array $logData
     *
     * @return array
     */
    public function getDiff($logData)
    {
        $old = empty($logData['old']) ? [] : $logData['old'];
        $new = empty($logData['new']) ? [] : $logData['new'];

        $rez = [];

        $template = $this->getTemplate();
        $ld = $this->getLinearData(true);

        foreach ($ld as $f) {
            $ov = empty($old[$f['name']]) ? '' : $old[$f['name']][0];

            $nv = empty($new[$f['name']]) ? '' : $new[$f['name']][0];

            if ($ov != $nv) {
                $field = $template->getField($f['name']);

                if ($field['type'] == '_objects') {
                    $a = empty($ov['value']) ? [] : Util\toNumericArray($ov['value']);
                    $b = empty($nv['value']) ? [] : Util\toNumericArray($nv['value']);

                    $c = array_intersect($a, $b);

                    if (!empty($c)) {
                        $a = array_diff($a, $c);
                        $b = array_diff($b, $c);
                        $ov['value'] = implode(',', $a);
                        $nv['value'] = implode(',', $b);
                    }
                }

                $title = Util\coalesce($field['title'], $field['name']);

                $value = empty($ov)
                    ? ''
                    : ('<div class="old-value">'.$template->formatValueForDisplay($field, $ov, false, true).'</div>');

                $value .= empty($nv)
                    ? ''
                    : ('<div class="new-value">'.$template->formatValueForDisplay($field, $nv, false, true).'</div>');

                $rez[$title] = $value;
            }
        }

        return $rez;
    }

    /**
     * @param string $message
     * @param string $replacements
     *
     * @return mixed|string
     */
    public static function processAndFormatMessage($message, $replacements = 'user,object,url')
    {
        if (empty($message)) {
            return $message;
        }

        $replacements = Util\toTrimmedArray($replacements);

        // replace urls with links
        if (in_array('url', $replacements)) {
            $message = \Kwi\UrlLinker::getInstance()->linkUrlsAndEscapeHtml($message);
        }

        // Replace users with their names
        // Doing replace before object reference replacements because object titles can contain user refs
        if (in_array('user', $replacements) &&
            preg_match_all(
                '/@([\w\.\-]+[\w])/',
                $message,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            foreach ($matches as $match) {
                $userId = DM\Users::getIdByName($match[1]);
                if (is_numeric($userId)) {
                    $userName = $match[1];
                    $dn = User::getDisplayName($userId);
                    $replace = '<span class="cDB user-ref" title="'.$dn.'">@'.$userName.'</span>';
                    $message = str_replace(
                        $match[0],
                        $replace,
                        $message
                    );
                }
            }
        }

        //replace object references with links
        if (in_array('object', $replacements) &&
            preg_match_all(
                '/(.?)#(\d+)(.?)/',
                $message,
                $matches,
                PREG_SET_ORDER
            )
        ) {
            foreach ($matches as $match) {
                // check if not a html code
                if (($match[1] == '&') && ($match[3] == ';')) {
                    continue;
                }

                $templateId = Objects::getTemplateId($match[2]);
                $obj = Objects::getCachedObject($match[2]);

                $name = empty($obj) ? '' : $obj->getHtmlSafeName();

                $name = (strlen($name) > 30) ? mb_substr($name, 0, 30).'&hellip;' : $name;

                $message = str_replace(
                    $match[0],
                    $match[1].
                    '<a class="click obj-ref" itemid="'.$match[2].
                    '" templateid= "'.$templateId.
                    '" title="'.$name.'"'.
                    '>#'.$match[2].'</a>'.
                    $match[3],
                    $message
                );
            }
        }

        return $message;
    }
}
