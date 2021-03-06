<?php

namespace Casebox\CoreBundle\Service\Plugins\DisplayColumns;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\State;
use Casebox\CoreBundle\Service\Search;

/**
 * Class Base
 */
class Base
{
    protected $fromParam = 'none';

    public function __construct()
    {
        $this->configService = Cache::get('symfony.container')->get('casebox_core.service.config');
    }

    /**
     * Method used to implement custom logic on before solr query
     *
     * @param array $p search params
     *
     * @return void
     */
    public function onBeforeSolrQuery(&$p)
    {
        $this->params = &$p;

        $sp = &$p['params'];
        $this->inputParams = &$p['inputParams'];

        if (@$this->inputParams['view']['type'] !== $this->fromParam) {
            return;
        }

        $solrFields = $this->getSolrFields();

        if (!empty($solrFields['fields'])) {
            $fl = explode(',', $sp['fl']);
            foreach ($fl as $k => $f) {
                $fl[$k] = trim($f);
            }
            foreach ($solrFields['fields'] as $sc) {
                if (!in_array($sc, $fl)) {
                    $fl[] = $sc;
                }
            }
            $sp['fl'] = implode(',', $fl);
        }

        switch (@$this->inputParams['view']['type']) {
            case 'pivot':
            case 'charts':
                unset($sp['sort']);
                break;

            default:
                if (empty($p['inputParams']['strictSort']) && !empty($solrFields['sort'])) {
                    $sp['sort'] = $solrFields['sort'];

                } elseif (!empty($this->inputParams['sort'][0]['property']) &&
                    empty($solrFields['sort'])
                ) {
                    $sp['sort'] = 'ntsc asc, order asc';
                }
        }
    }

    /**
     * AnalYze custom columns and add needed ids to preloaded objects
     *
     * @param array $p search params
     *
     * @return void
     */
    public function onSolrQueryWarmUp(&$p)
    {
        $ip = &$p['inputParams'];
        // $params = &$p['params'];
        $data = &$p['data'];
        $requiredIds = &$p['requiredIds'];

        if (@$ip['view']['type'] !== $this->fromParam) {
            return;
        }

        $displayColumns = $this->getDC();

        $customColumns = $this->prepareColumnsConfig($displayColumns);

        if (!empty($displayColumns['data'])) {

            foreach ($data as &$doc) {
                if (!is_numeric($doc['id'])) {
                    continue;
                }

                $obj = \Casebox\CoreBundle\Service\Objects::getCachedObject($doc['id']);
                if (!is_object($obj)) {
                    Cache::get('symfony.container')->get('logger')->error(
                        'DisplayColumns object not found: '.$doc['id']
                    );
                    continue;
                }

                $ids = $this->getObjectWarmIds($customColumns, $obj, $doc);
                foreach ($ids as $id) {
                    $requiredIds[$id] = 1;
                }
            }
        }
    }

    protected function getObjectWarmIds(&$customColumns, &$objClass, &$solrData)
    {
        $rez = [];

        $template = $objClass->getTemplate();

        foreach ($customColumns as &$col) { //$fieldName
            // Detect field name
            $customField = $col['fieldName'];
            $templateField = $template->getField($customField);

            // $templateField = null;
            if (!empty($col['solr_column_name'])) {
                $values = [@$solrData[$col['solr_column_name']]];

            } else { //default
                $values = isset($solrData[$customField]) ? [$solrData[$customField]] : $objClass->getFieldValue(
                    $customField
                );
            }

            if (!empty($templateField) && in_array($templateField['type'], ['_objects'])) {
                foreach ($values as $value) {
                    $value = is_array($value) ? @$value['value'] : $value;
                    $value = Util\toNumericArray($value);
                    foreach ($value as $v) {
                        $rez[] = $v;
                    }
                }
            }
        }

        return $rez;
    }

    /**
     * Method used to implement custom logic on solr query
     *
     * @param array $p search params
     *
     * @return void
     */
    public function onSolrQuery(&$p)
    {
        $this->params = &$p;

        $ip = &$p['inputParams'];

        if (@$ip['view']['type'] !== $this->fromParam) {
            return;
        }

        // $sp = &$p['params'];
        $result = &$p['result'];
        $view = &$result['view'];
        $data = &$result['data'];

        $rez = [];

        $displayColumns = $this->getDC();

        // This if remains as backward compatible, but will be removed in future commits
        if (!empty($displayColumns['sort'])) {
            $view['sort'] = $displayColumns['sort'];
        }

        // get state
        $stateFrom = empty($displayColumns['from']) ? 'default' : $displayColumns['from'];

        $state = $this->getState($stateFrom);

        $customColumns = $this->prepareColumnsConfig($displayColumns);

        // set custom display columns data
        if (!empty($displayColumns['data'])) {

            // Fill custom columns data
            foreach ($data as &$doc) {
                if (!is_numeric($doc['id'])) {
                    continue;
                }

                $obj = Objects::getCachedObject($doc['id']);
                if (!is_object($obj)) {
                    Cache::get('symfony.container')->get('logger')->error(
                        'DisplayColumns object not found: '.$doc['id']
                    );
                    continue;
                }

                $template = $obj->getTemplate();

                foreach ($customColumns as $fieldName => &$col) {
                    $templateField = $template->getField($col['fieldName']);
                    $values = [];

                    if (!empty($col['solr_column_name'])) {
                        if (isset($doc[$col['solr_column_name']])) {
                            $values[] = $doc[$col['solr_column_name']];

                            if ($col['solr_column_name'] !== $col['fieldName']) {
                                $v = $doc[$col['solr_column_name']];
                                $doc[$col['fieldName']] = $v;
                                unset($doc[$col['solr_column_name']]);
                                $values = [$v];
                            }
                        }

                        if (empty($templateField)) {
                            $templateField = [
                                'type' => empty($col['fieldType']) ? 'varchar' : $col['fieldType'],
                                'name' => $col['solr_column_name'],
                                'title' => Util\detectTitle($col),
                            ];
                        }

                    } elseif (!empty($col['lookup'])) { // lookup field
                        $values = $obj->getLookupValues($col['lookup'], $templateField);

                    } else { // default
                        if (isset($doc[$col['fieldName']])) {
                            $values = [$doc[$col['fieldName']]];
                        } else {
                            $values = $obj->getFieldValue($col['fieldName']);
                        }
                    }

                    // populate column properties if empty
                    if (empty($col['title'])) {
                        $col['title'] = $templateField['title'];
                    }

                    if (empty($col['sortType']) && (empty($col['solr_column_name']))) {
                        switch ($templateField['type']) {
                            case 'date':
                            case 'datetime':
                                //set to users date format if not set
                                if (empty($col['format'])) {
                                    $col['format'] = User::getUserConfigParam('short_date_format');
                                    // add time if needed
                                    if ($templateField['type'] == 'datetime') {
                                        $col['format'] .= ' '.User::getUserConfigParam('time_format');
                                    }
                                }

                                $col['type'] = 'date';
                                $col['sortType'] = 'asDate';
                                break;

                            case 'float':
                                $col['sortType'] = 'asFloat';
                                break;

                            case 'checkbox':
                            case 'int':
                                $col['sortType'] = 'asInt';
                                break;

                            case 'html':
                            case 'memo':
                            case 'text':
                                $col['sortType'] = 'asUCText';
                                break;

                            case '_objects':
                            default:
                                $col['sortType'] = 'asUCString';
                                break;
                        }
                    }

                    // update value from document if empty from solr query
                    if (in_array($templateField['type'], ['date', 'datetime']) && !empty($values)) {
                        $value = array_shift($values);
                        $doc[$fieldName] = is_array($value) ? @$value['value'] : $value;

                    } elseif (empty($doc[$fieldName]) ||
                        // temporary check, this should be reanalized
                        in_array($templateField['type'], ['_objects', 'time'])
                    ) {
                        $dv = [];
                        foreach ($values as $value) {
                            $value = is_array($value) ? @$value['value'] : $value;
                            $dv[] = $template->formatValueForDisplay($templateField, $value, false);
                        }
                        $doc[$fieldName] = implode(', ', $dv);
                    }
                }
            }

            // remove columns without title
            foreach ($customColumns as $fieldName => &$col) {
                if (empty($col['title'])) {
                    unset($customColumns[$fieldName]);
                }
            }

            $rez = $customColumns;
        }

        // merge the state with display columns
        $defaultColumns = array_keys($this->configService->getDefaultGridViewColumns());

        if (!empty($state['columns'])) {
            $rez = [];

            foreach ($state['columns'] as $k => $c) {
                if (!empty($customColumns[$k])) {
                    unset($customColumns[$k]['hidden']);
                    $c = array_merge($customColumns[$k], $c);
                    unset($customColumns[$k]);
                    $rez[$k] = $c;
                } elseif (in_array($k, $defaultColumns)) {
                    $rez[$k] = $c;
                }
            }

            if (!empty($customColumns)) {
                $rez = array_merge($rez, $customColumns);
            }
        }

        // user clicked a column to sort by
        if (!empty($ip['userSort'])) {
            $view['sort'] = [
                'property' => $ip['sort'][0]['property']
                ,
                'direction' => $ip['sort'][0]['direction'],
            ];

        } elseif (!empty($state['sort'])) {
            $view['sort'] = $state['sort'];
        }

        // check grouping params
        if (!empty($ip['userGroup']) && !empty($ip['group'])) {
            $view['group'] = [
                'property' => $ip['sourceGroupField']
                ,
                'direction' => $ip['group']['direction'],
            ];

        } elseif (isset($state['group'])) {
            $view['group'] = $state['group'];

        } elseif (isset($displayColumns['group'])) {
            $view['group'] = $displayColumns['group'];
        }

        // analyze grouping
        $this->analizeGrouping($p);

        if (!empty($rez)) {
            $result['DC'] = $rez;
        }

        // check if we need to sort records using php (in case sort field is not from solr)
        if (!empty($view['sort']) &&
            !empty($rez[$view['sort']['property']]['localSort']) &&
            !in_array($view['sort']['property'], $defaultColumns)

        ) {
            $s = &$view['sort'];

            Util\sortRecordsArray(
                $data,
                $s['property'],
                $s['direction'],
                (empty($rez[$s['property']]['sortType']) ? 'asString' : $rez[$s['property']]['sortType'])
            );
        }
    }

    /**
     * analyze display columns config and create a generic columns array
     *
     * @param array $dc
     *
     * @return array
     */
    protected function prepareColumnsConfig($dc)
    {
        $rez = [];

        if (!empty($dc['data'])) {
            $idx = 0;
            $userLanguage = Cache::get('symfony.request')->getLocale();

            foreach ($dc['data'] as $k => $col) {
                $fieldName = is_numeric($k) ? $col : $k;
                $rez[$fieldName] = is_numeric($k) ? [] : $col;

                if (empty($rez[$fieldName]['solr_column_name']) && !in_array($fieldName, Search::$defaultFields)) {
                    $rez[$fieldName]['localSort'] = true;
                }

                if (!isset($rez[$fieldName]['idx'])) {
                    $rez[$fieldName]['idx'] = $idx++;
                }

                // detect custom field name
                $customField = $fieldName; //default

                if (!empty($col['field_'.$userLanguage])) {
                    $customField = $col['field_'.$userLanguage];
                } elseif (!empty($col['field'])) {
                    $customField = $col['field'];
                }

                $arr = explode(':', $customField);

                if ($arr[0] == 'solr') {
                    $customField = $arr[1];
                } elseif ($arr[0] == 'calc') { //calculated field
                    // CustomMethod call;
                    // $templateField = $template->getField($fieldName);
                    // $values = array();
                }

                $rez[$fieldName]['fieldName'] = $customField;
            }
        }

        return $rez;
    }

    /**
     * Method to analize grouping params and add group column to result
     *
     * @param array $p search params
     *
     * @return void
     */
    protected function analizeGrouping(&$p)
    {
        if (empty($p['result']['view']['group']['property'])) {
            return;
        }

        // sync grouping sort direction with sorting if same column
        $result = &$p['result'];
        $view = &$result['view'];
        $group = &$view['group'];

        if (!empty($view['sort'])) {
            if (@$group['property'] == $view['sort']['property']) {
                $group['direction'] = $view['sort']['direction'];
            } else {
                $group['direction'] = 'ASC';
            }
        }

        $field = $group['property'];
        $data = &$p['result']['data'];

        $count = sizeof($data);
        for ($i = 0; $i < $count; $i++) {
            $d = &$data[$i];

            $v = @$d[$field];

            switch ($field) {
                case 'cid':
                case 'uid':
                case 'oid':
                    if (empty($v)) {
                        $d['group'] = '';
                        $d['groupText'] = 'none';

                    } else {
                        $d['group'] = $v;
                        $d['groupText'] = User::getDisplayName($d[$field]);
                    }
                    break;

                case 'date':
                case 'date_end':
                case 'cdate':
                case 'udate':
                case 'ddate':
                case 'task_d_closed':
                    if (empty($v)) {
                        $d['group'] = 'empty';
                        $d['groupText'] = 'empty';
                    } else {
                        $d['group'] = substr($v, 0, 7).'-01T00:00:00Z';
                        $d['groupText'] = Util\formatMysqlDate($d['group'], 'Y, F');
                    }

                    break;

                case 'size':
                    if (empty($v)) {
                        $d['group'] = 'up to 1 MB';
                    } else {
                        $t = Util\formatFileSize($v);
                        $d['size'] .= ' - '.$t;
                        $t = explode(' ', $t);

                        if ((@$t[1] == 'KB') || ($t[0] <= 1)) {
                            $t = 1;
                        } else {
                            $q = floor($t[0] / 10) * 10;
                            $t = ($t[0] > $q) ? $q + 10 : $q;
                        }

                        $d['size'] .= ' - '.$t;
                        $d['group'] = ($t < 1) ? 'up to 1 MB' : 'up to '.$t.' MB';
                    }
                    break;

                default:
                    if (empty($d[$field])) {
                        $d['group'] = 'empty';
                    } else {
                        // split values by comma and duplicate records if multivalued
                        $values = is_array($d[$field]) ? $d[$field] : explode(',', $d[$field]);

                        $d['group'] = trim(array_shift($values));

                        for ($j = 0; $j < sizeof($values); $j++) {
                            $newRecord = $d;
                            $newRecord['group'] = trim($values[$j]);
                            array_push($data, $newRecord);
                        }
                    }
            }
        }
    }

    /**
     * Get display columns for last node in active path
     * @return array
     */
    public function getDC()
    {
        $rez = [];

        $p = &$this->params;

        $ip = &$p['inputParams'];

        if (!empty($ip['query'])) {
            $dc = $this->configService->get('search_DC');

            // its a config reference, get it from config
            if (!empty($dc) && is_scalar($dc)) {
                $dc = $this->configService->getDCConfig($dc);
            }

            $rez['data'] = $dc;
        }

        if (empty($rez['data'])) {
            $path = Cache::get('current_path');

            if (!empty($path)) {
                $node = $path[sizeof($path) - 1];
                $rez = $node->getDC();
            }
        }

        if (!empty($ip['query'])) {
            $rez['from'] = 'search';
        }

        // apply properties for default casebox columns
        if (!empty($rez['data'])) {
            $defaults = $this->configService->getDefaultGridColumnConfigs();
            foreach ($rez['data'] as $k => $v) {
                if (!empty($defaults[$k])) {
                    $rez['data'][$k] = array_merge($defaults[$k], $v);
                }
            }
        }

        return $rez;
    }

    /**
     * Get state
     *
     * @param array $param some param if needed
     *
     * @return array
     */
    protected function getState($param = null)
    {
        return [];
    }

    /**
     * Get solr columns for a node based on display columns
     * @return array
     */
    public function getSolrFields($nodeId = false, $templateId = false)
    {
        $rez = [
            'fields' => [],
            'sort' => [],
        ];

        $ip = &$this->inputParams;

        $defaultColumns = array_keys($this->configService->getDefaultGridViewColumns());
        $displayColumns = $this->getDC();
        $DC = empty($displayColumns['data']) ? [] : $displayColumns['data'];

        if (!empty($DC)) {
            foreach ($DC as $columnName => $column) {
                if (is_array($column) && !empty($column['solr_column_name'])) {
                    $rez['fields'][$column['solr_column_name']] = 1;

                    if (empty($column['localSort'])) {
                        if ((@$ip['sort'][0]['property'] == $columnName) &&
                            !empty($ip['sort'][0]['direction'])
                        ) {
                            $rez['sort'][] = $column['solr_column_name'].' '.strtolower($ip['sort'][0]['direction']);
                        } elseif (!empty($column['sort'])) {
                            $rez['sort'][] = $column['solr_column_name'].' '.$column['sort'];
                        }
                    }

                } elseif (is_scalar($column)) {
                    $a = explode(':', $column);
                    if ($a[0] == 'solr') {
                        $rez['fields'][$a[1]] = 1;
                    }
                }
            }
        }

        $property = null;
        $dir = 'asc';

        if (!empty($ip['userSort'])) {
            $dir = strtolower($ip['sort'][0]['direction']);

            if (in_array($dir, ['asc', 'desc']) &&
                preg_match('/^[a-z_0-9]+$/i', $ip['sort'][0]['property'])
            ) {
                $prop = $ip['sort'][0]['property'];
                if (!empty($DC[$prop]['solr_column_name'])) {
                    $col = $DC[$prop];
                    // also check if not marked as localSort
                    if (empty($col['localSort'])) {
                        $property = $col['solr_column_name'];
                    }

                } elseif (in_array($prop, $defaultColumns)) {
                    $property = $prop;
                }
            }

        } else {
            // get user state and check if user has a custom sorting
            $stateFrom = empty($displayColumns['from']) ? 'default' : $displayColumns['from'];

            $state = $this->getState($stateFrom);

            if (!empty($state['sort']['property'])) {
                $property = $state['sort']['property'];
                $dir = strtolower(Util\coalesce(@$state['sort']['direction'], 'asc'));

                if (!empty($DC[$property]['solr_column_name']) && empty($DC[$property]['localSort'])) {
                    $property = $DC[$property]['solr_column_name'];
                } elseif (!in_array($property, $defaultColumns)) {
                    $property = null;
                }
            }
        }

        if (!empty($property)) {
            $rez['sort'] = 'ntsc asc,'.$property.' '.$dir;
        }

        if (!empty($rez['fields'])) {
            $rez['fields'] = array_keys($rez['fields']);
        }

        return $rez;
    }
}
