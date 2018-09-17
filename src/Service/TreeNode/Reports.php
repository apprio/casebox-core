<?php

namespace Casebox\CoreBundle\Service\TreeNode;

use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Search;
use Casebox\CoreBundle\Service\Templates;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Cache;

/**
 * Class DailyReport
 */
class Reports extends Base
{
    protected function createDefaultFilter()
    {
        $this->fq = [];

        // select only case templates
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
		$reportTemplateId = !empty($configService->get('staffing_report_id'))?$configService->get('staffing_report_id'):246777;
		$this->fq[] = 'template_id:'.$reportTemplateId;
		//$this->fq[] = 'pid:1204';

    }

    public function getChildren(&$pathArray, $requestParams)
    {

        $this->path = $pathArray;
        $this->lastNode = @$pathArray[sizeof($pathArray) - 1];
        $this->requestParams = $requestParams;

        if (!$this->acceptedPath($pathArray, $requestParams)) {
            return;
        }

        $ourPid = @($this->config['pid']);
        if ($ourPid == '') {
            $ourPid = 0;
        }

        $this->createDefaultFilter();

        if (empty($this->lastNode) ||
            (($this->lastNode->id == $ourPid) && (get_class($this->lastNode) != get_class($this))) ||
            (Objects::getType($this->lastNode->id) == 'case')
        ) {
            $rez = $this->getRootNodes();
        } else {
            switch ($this->lastNode->id) {
                case 'DailyReport':
                    $rez = $this->getDepthChildren2();
                    break;
                case 2:
                case 3:
                    $rez = $this->getDepthChildren3();
                    break;
                default:
                    $rez = $this->getChildrenTasks();
            }
        }

        return $rez;
    }

    public function getName($id = false)
    {
        if ($id === false) {
            $id = $this->id;
        }
        switch ($id) {
            case 'DailyReport':
                return $this->trans('DailyReport');
            case 2:
                return $this->trans('AssignedToMe');
            case 3:
                return $this->trans('CreatedByMe');
            case 4:
                return lcfirst($this->trans('Overdue'));
            case 5:
                return lcfirst($this->trans('Ongoing'));
            case 6:
                return lcfirst($this->trans('Closed'));
            case 7:
                return lcfirst($this->trans('Information'));				
            case 'assignee':
                return lcfirst($this->trans('Assignee'));
            default:
                if (substr($id, 0, 3) == 'au_') {
                    return User::getDisplayName(substr($id, 3));
                }
        }

        return $id;
    }

    protected function getRootNodes()
    {
        $p = $this->requestParams;
        $p['fq'] = $this->fq;
		$p['rows'] = 0;

        $s = new Search();
        $rez = $s->query($p);
        $count = '';
        if (!empty($rez['total'])) {
            $count = $this->renderCount($rez['total']);
        }

        return [
            'data' => [
                [
                    'name' => $this->trans('DailyReport').$count,
                    'id' => $this->getId('DailyReport'),
                    'iconCls' => 'icon-calendar',
                    'cls' => 'tree-header',
                    'has_childs' => true,
                ],
            ],
        ];
    }

    /**
     *  returns a formatted total number for UI tree
     *
     * @param  Int $n the total count of DailyReport
     *
     * @return String   formatted string
     */
    protected function renderCount($n)
    {
        return ' <span style="color: #AAA; font-size: 12px">'.$n.'</span>';
    }

    protected function getDepthChildren2()
    {
        $userId = User::getId();
        $p = $this->requestParams;
		$p['fl'] = 'id,udate,uid,name,template_id,cid,cdate,report_dt,enteredby_s,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,website_s,hours_s,servicearea_s,qualification_s,zipcode_s';
        $p['fq'] = $this->fq;
		if (!isset($p['sort'])) {
			$p['sort'][0]['property'] = 'id';    
			$p['sort'][0]['direction'] = 'desc';                       
		}

        if (@$this->requestParams['from'] == 'tree') {
            $s = new \Casebox\CoreBundle\Service\Search();
            $p['rows'] = 0;
            $p['facet'] = true;
            $p['facet.field'] = [
                '{!ex=resourcetype_s key=resourcetype_s}resourcetype_s',
            ];
            $sr = $s->query($p);
            $rez = ['data' => []];
            if (!empty($sr['facets']->facet_fields->{'resourcetype_s'})) {
				foreach ($sr['facets']->facet_fields->{'resourcetype_s'} as $k => $v) {
					$r = [
						'name' => $k.$this->renderCount($v),
						'id' => $this->getId(str_replace('/', '&&', $k)),
						'iconCls' => 'icon-users',
					];
					$rez['data'][] = $r;
				}
            }

            return $rez;
        }

        // for other views
        $s = new \Casebox\CoreBundle\Service\Search();
        $rez = $s->query($p);

        return $rez;
    }

    protected function getDepthChildren3()
    {
        $userId = User::getId();
        $p = $this->requestParams;
        $p['fq'] = $this->fq;

        if ($this->lastNode->id == 2) {
            $p['fq'][] = 'task_u_ongoing:'.$userId;
        } else {
            $p['fq'][] = 'cid:'.$userId;
        }
		$p['fl'] = 'id,providername_s,name,template_id,cdate,resourcetype_s,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,website_s,qualification_s,hours_s,zipcode_s';
        if (@$this->requestParams['from'] == 'tree') {
            $s = new Search();

            $sr = $s->query(
                [
                    'rows' => 0,
                    'fq' => $p['fq'],
                    'facet' => true,
                    'facet.field' => [
                        '{!ex=task_status key=0task_status}task_status',
                    ],
                ]
            );
            $rez = ['data' => []];
            if (!empty($sr['facets']->facet_fields->{'0task_status'}->{'1'})) {
                $rez['data'][] = [
                    'name' => lcfirst($this->trans('Overdue')).$this->renderCount(
                            $sr['facets']->facet_fields->{'0task_status'}->{'1'}
                        ),
                    'id' => $this->getId(4),
                    'iconCls' => 'icon-folder',
                ];
            }
            if (!empty($sr['facets']->facet_fields->{'0task_status'}->{'2'})) {
                $rez['data'][] = [
                    'name' => lcfirst($this->trans('Ongoing')).$this->renderCount(
                            $sr['facets']->facet_fields->{'0task_status'}->{'2'}
                        ),
                    'id' => $this->getId(5),
                    'iconCls' => 'icon-folder-open',
                ];
            }
            if (!empty($sr['facets']->facet_fields->{'0task_status'}->{'3'})) {
                $rez['data'][] = [
                    'name' => lcfirst($this->trans('Closed')).$this->renderCount(
                            $sr['facets']->facet_fields->{'0task_status'}->{'3'}
                        ),
                    'id' => $this->getId(6),
                    'iconCls' => 'icon-folder',
                ];
            }
            if (!empty($sr['facets']->facet_fields->{'0task_status'}->{'5'})) {
                $rez['data'][] = [
                    'name' => lcfirst($this->trans('Information')).$this->renderCount(
                            $sr['facets']->facet_fields->{'0task_status'}->{'5'}
                        ),
                    'id' => $this->getId(7),
                    'iconCls' => 'icon-information-white',
                ];
            }			
            // Add assignee node if there are any created DailyReport already added to result
            if (($this->lastNode->id == 3) && !empty($rez['data'])) {
                $rez['data'][] = [
                    'name' => lcfirst($this->trans('Assignee')),
                    'id' => $this->getId('assignee'),
                    'iconCls' => 'icon-folder',
                    'has_childs' => true,
                ];
            }
        } else {
            $p['fq'][] = 'task_status:(1 OR 2 OR 5)';

            $s = new Search();
            $rez = $s->query($p);
            foreach ($rez['data'] as &$n) {
                $n['has_childs'] = true;
            }
        }

        return $rez;
    }

    protected function getChildrenTasks()
    {
        $rez = [];

        $userId = User::getId();
        $p = $this->requestParams;
        $p['fq'] = $this->fq;
		$p['fl'] = 'id,providername_s,name,template_id,cdate,resourcetype_s,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,qualification_s,website_s,hours_s,servicearea_s,zipcode_s';
        
        $parent = $this->lastNode->parent;

        if ($parent->id == 2) {
            $p['fq'][] = 'task_u_ongoing:'.$userId;
        } else {
            $p['fq'][] = 'cid:'.$userId;
        }

        // please don't use numeric IDs for named folders: "Assigned to me", "Overdue" etc
        switch ($this->lastNode->id) {
            case 4:
                $p['fq'][] = 'task_status:1';
                break;
            case 5:
                $p['fq'][] = 'task_status:2';
                break;
            case 6:
                $p['fq'][] = 'task_status:3';
                break;
            case 7:
                $p['fq'][] = 'task_status:5';
                break;
			case 'assignee':
                return $this->getAssigneeUsers();
                break;
            default:
				return $this->getAssigneeTasks();
        }

        if (@$this->requestParams['from'] == 'tree') {
            return $rez;
        }

        $s = new Search();
        $rez = $s->query($p);

        return $rez;
    }

    protected function getAssigneeUsers()
    {
        $p = $this->requestParams;
        $p['fq'] = $this->fq;

        $p['fq'][] = 'cid:'.User::getId();
        $p['fq'][] = 'task_status:[1 TO 2]';

        $p['rows'] = 0;
        $p['facet'] = true;
        $p['facet.field'] = [
            '{!ex=task_u_ongoing key=task_u_ongoing}task_u_ongoing',
        ];
        $rez = [];

        $s = new Search();

        $sr = $s->query($p);

        $rez = ['data' => []];
        if (!empty($sr['facets']->facet_fields->{'task_u_ongoing'})) {
            foreach ($sr['facets']->facet_fields->{'task_u_ongoing'} as $k => $v) {
                $k = 'au_'.$k;
                $r = [
                    'name' => $this->getName($k).$this->renderCount($v),
                    'id' => $this->getId($k),
                    'iconCls' => 'icon-user',
                ];

                if (!empty($p['showFoldersContent']) ||
                    (@$this->requestParams['from'] != 'tree')
                ) {
                    $r['has_childs'] = true;
                }
                $rez['data'][] = $r;
            }
        }

        return $rez;
    }

    protected function getAssigneeTasks()
    {
        $p = $this->requestParams;
        $p['fq'] = $this->fq;

        //$p['fq'][] = 'cid:'.User::getId();

        $user_id = $this->lastNode->id;
        $p['fq'][] = 'resourcetype_s:('.str_replace('&&','/',str_replace(' ', '\ ', $user_id)).')';
		$p['fl'] = 'id,providername_s,name,template_id,cdate,resourcetype_s,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,qualification_s,website_s,hours_s,servicearea_s,zipcode_s';
        $s = new Search();

        $sr = $s->query($p);

        return $sr;
    }
}
