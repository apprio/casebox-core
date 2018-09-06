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
 * Class Repository
 */
class Repository extends Base
{
    protected function createDefaultFilter()
    {
        $this->fq = [];

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
                case 'Repository':
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
            case 'Repository':
                return $this->trans('Document Library');
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
        $p['fq'][] = 'template_id:6';
		$p['fq'][]= 'pid:246766';
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
                    'name' => $this->trans('Document Library').$count,
                    'id' => $this->getId('Repository'),
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
     * @param  Int $n the total count of Repository
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
		$p['fq'][]= 'template_id:6';
		$p['fq'][]= 'pid:246766';
		if (!isset($p['sort'])) {
			$p['sort'][0]['property'] = 'id';    
			$p['sort'][0]['direction'] = 'desc';                       
		}
		
		if (@$this->requestParams['from'] == 'tree') {
            $s = new \Casebox\CoreBundle\Service\Search();
            $p['rows'] = 0;
            $p['facet'] = true;
            $p['facet.field'] = [
                '{!ex=cid key=cid}cid',
            ];
            $sr = $s->query($p);
            $rez = ['data' => []];
            if (!empty($sr['facets']->facet_fields->{'cid'})) {
				foreach ($sr['facets']->facet_fields->{'cid'} as $k => $v) {
					$r = [
						'name' => $this->getName('au_'.str_replace('/', '&&', $k)).$this->renderCount($v),
						'id' => $this->getId('au_'.str_replace('/', '&&', $k)),
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
        $p['fq'] = 'template_id:141';
		$p['rows'] = 50;		
		$p['fl'] = 'id,udate,uid,pid,name,template_id,cid,cdate,report_dt,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,website_s,hours_s,servicearea_s,qualification_s,zipcode_s';
		
            $s = new Search();
            $rez = $s->query($p);
			//$ids = array_column($rez['data'], 'pid');
			//$output = !empty($ids) ? '(id:'.implode(' OR id:', $ids).')' : '99999999';
			
		
        return $rez;
    }

    
protected function getChildrenTasks()
    {
        $rez = [];

        $userId = User::getId();
        $p = $this->requestParams;
        $p['fq'][]= 'template_id:6';
		$p['fq'][]= 'pid:246766';
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
   
    protected function getAssigneeTasks()
    {
        $p = $this->requestParams;
		$p['fl'] = 'id,udate,uid,pid,name,template_id,cid,cdate,report_dt,additionalinformation_s,streetaddress_s,city_s,state_s,zipcode_s,phones_s,website_s,hours_s,servicearea_s,qualification_s,zipcode_s';
		$p['fq'] = [];
		$p['fq'][]= 'template_id:6';
		$p['fq'][]= 'pid:246766';
		if (!isset($p['sort'])) {
			$p['sort'][0]['property'] = 'id';    
			$p['sort'][0]['direction'] = 'desc';                       
		}
		$user_id = str_replace('au_','',$this->lastNode->id);
		$p['fq'][] = 'cid:'.$user_id;
        // for other views
        $s = new \Casebox\CoreBundle\Service\Search();
        $rez = $s->query($p);

        return $rez;	
    }
}

    