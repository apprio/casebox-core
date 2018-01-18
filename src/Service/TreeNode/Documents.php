<?php

namespace Casebox\CoreBundle\Service\TreeNode;

use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Search;
use Casebox\CoreBundle\Service\Templates;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Log;
use Casebox\CoreBundle\Service\Cache;

/**
 * Class DailyDocument
 */
class Documents extends Base
{
    protected function createDefaultFilter()
    {
        $this->fq = [];

        // select only case template;

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
                case 'DailyDocument':
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
            case 'DailyDocument':
                return $this->trans('Consent Forms');
            case 2:
                return $this->trans('AssignedToMe');
            case 3:
                return $this->trans('No Consent Form');
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
        $p['fq'] = 'template_id:6';
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
                    'name' => $this->trans('Consent Forms').$count,
                    'id' => $this->getId('DailyDocument'),
                    'iconCls' => 'icon-document',
                    'cls' => 'tree-header',
                    'has_childs' => true,
                ],
            ],
        ];
    }

    /**
     *  returns a formatted total number for UI tree
     *
     * @param  Int $n the total count of DailyDocument
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
		$p['fl'] = 'id,udate,uid,pid,name,template_id,cid,cdate,report_dt,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,website_s,hours_s,servicearea_s,qualification_s,zipcode_s';
		$p['fq'] = 'template_id:6';
		if (!isset($p['sort'])) {
			$p['sort'][0]['property'] = 'id';    
			$p['sort'][0]['direction'] = 'desc';                       
		}

       if (@$this->requestParams['from'] == 'tree') {
            $rez = ['data' => []];
                $rez['data'][] = [
                    'name' => $this->trans('No Consent Form'),
                    'id' => $this->getId(3),
                    'iconCls' => 'icon-task-user-status0',
                    'has_childs' => false,
                ];
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
        $p['fq'] = 'template_id:141';
		$p['rows'] = 2000;		
		$p['fl'] = 'id,udate,uid,pid,name,template_id,cid,cdate,report_dt,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,website_s,hours_s,servicearea_s,qualification_s,zipcode_s';
		
            $s = new Search();
            $rez = $s->query($p);
			//$ids = array_column($rez['data'], 'pid');
			//$output = !empty($ids) ? '(id:'.implode(' OR id:', $ids).')' : '99999999';
			
			
			$records = [];
			$p = $this->requestParams;
			$p['fq'] = [];
			$p['fq'][] = 'template_id:6';
			$p['rows'] = 20000;
			$p['fl'] = 'id,udate,uid,pid,name,template_id,cid,cdate,report_dt,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,website_s,hours_s,servicearea_s,qualification_s,zipcode_s';
			//$p['fq'][] = '-'.$output;
			$s = new Search();
			$documents = $s->query($p);					
            foreach ($rez['data'] as &$n) {
					$hasDocument = false;		
				foreach($documents['data'] as &$r)
				{
					if ($r['pid'] == $n['id'])
					{
						$hasDocument = true;
					}
				}
				if ($hasDocument !== true)
				{
					$records[]= $n;
				}
            }
			unset($rez['data']);
			$rez['total'] = count($records);
							/*									Cache::get('symfony.container')->get('logger')->error(
							'hi'.$output,
							[]
							);	*/
		$rez['data'] = $records;		
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
