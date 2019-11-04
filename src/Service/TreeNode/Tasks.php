<?php

namespace Casebox\CoreBundle\Service\TreeNode;

use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Search;
use Casebox\CoreBundle\Service\Templates;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\DataModel as DM;

/**
 * Class Tasks
 */
class Tasks extends Base
{
    protected function createDefaultFilter()
    {
        $this->fq = [];

        // select only task templates
        $taskTemplates = DM\Templates::getIdsByType('task');
        if (!empty($taskTemplates)) {
            $this->fq[] = 'template_id:('.implode(' OR ', $taskTemplates).')';
        }

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
                case 'tasks':
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
            case 'tasks':
                return $this->trans('MyTasks');
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
            case 'assignee':
                return lcfirst($this->trans('Assignee'));
            default:
                if (substr($id, 0, 3) == 'au_') {
                    return User::getDisplayName(substr($id, 3));
                }
        }

        return 'none';
    }

    protected function getRootNodes()
    {
        $p = $this->requestParams;
        $p['fq'] = $this->fq;
        //$p['fq'][] = 'task_u_all:'.User::getId();
        $p['fq'][] = 'task_status:(1 OR 2)';
        $p['fl'] = 'id,due_s,name,cdate,case_status,template_id,clientname_s';
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
                    'name' => $this->trans('MyTasks').$count,
                    'id' => $this->getId('tasks'),
                    'iconCls' => 'icon-task',
                    'cls' => 'tree-header',
                    'has_childs' => true,
                ],
            ],
        ];
    }

   /* protected function getMyUserNodes()
    {
        $p = $this->requestParams;
        $p['fq'] = $this->fq;
        $p['fq'][] = 'task_u_all:'.User::getId();
        $p['fq'][] = 'task_status:(1 OR 2)';
		$p['fl'] = 'id,due_s,name,cdate,case_status,template_id,clientname_s';
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
                    'name' => $this->trans('MyTasks').$count,
                    'id' => $this->getId('tasks'),
                    'iconCls' => 'icon-task',
                    'cls' => 'tree-header',
                    'has_childs' => true,
                ],
            ],
        ];
    }*/



    /**
     *  returns a formatted total number for UI tree
     *
     * @param  Int $n the total count of tasks
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
		$p['fl'] = 'id,due_s,name,cdate,case_status,template_id,clientname_s';
        $p['fq'] = $this->fq;
        $p['fq'][] = 'task_u_all:'.$userId;
        $p['fq'][] = 'task_status:(1 OR 2)';

        if (@$this->requestParams['from'] == 'tree') {
            $s = new \Casebox\CoreBundle\Service\Search();
            $p['rows'] = 0;
            $p['facet'] = true;
            $p['facet.field'] = [
                '{!ex=task_u_assignee key=1assigned}task_u_assignee',
                '{!ex=cid key=2cid}cid',
            ];
            $sr = $s->query($p);
            $rez = ['data' => []];
            //if (!empty($sr['facets']->facet_fields->{'1assigned'}->{$userId})) { 
                //Now, will still populate if empty
                $rez['data'][] = [
                    'name' => $this->trans('AssignedToMe').$this->renderCount(
                            $sr['facets']->facet_fields->{'1assigned'}->{$userId}
                        ),
                    'id' => $this->getId(2),
                    'iconCls' => 'icon-task',
                    'has_childs' => true,
                ];
            //}
            //if (!empty($sr['facets']->facet_fields->{'2cid'}->{$userId})) {
                //Now, will still populate if empty
                $rez['data'][] = [
                    'name' => $this->trans('CreatedByMe').$this->renderCount(
                            $sr['facets']->facet_fields->{'2cid'}->{$userId}
                        ),
                    'id' => $this->getId(3),
                    'iconCls' => 'icon-task',
                    'has_childs' => true,
                ];
            //}

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
		$p['fl'] = 'id,due_s,name,cdate,case_status,template_id,clientname_s';
        if ($this->lastNode->id == 2) {
            $p['fq'][] = 'task_u_ongoing:'.$userId;
        } else {
            $p['fq'][] = 'cid:'.$userId;
        }

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
                    'iconCls' => 'icon-task',
                ];
            }
            if (!empty($sr['facets']->facet_fields->{'0task_status'}->{'2'})) {
                $rez['data'][] = [
                    'name' => lcfirst($this->trans('Ongoing')).$this->renderCount(
                            $sr['facets']->facet_fields->{'0task_status'}->{'2'}
                        ),
                    'id' => $this->getId(5),
                    'iconCls' => 'icon-task',
                ];
            }
            if (!empty($sr['facets']->facet_fields->{'0task_status'}->{'3'})) {
                $rez['data'][] = [
                    'name' => lcfirst($this->trans('Closed')).$this->renderCount(
                            $sr['facets']->facet_fields->{'0task_status'}->{'3'}
                        ),
                    'id' => $this->getId(6),
                    'iconCls' => 'icon-task',
                ];
            }
            // Add assignee node if there are any created tasks already added to result
            if (($this->lastNode->id == 3) && !empty($rez['data'])) {
                $rez['data'][] = [
                    'name' => lcfirst($this->trans('Assignee')),
                    'id' => $this->getId('assignee'),
                    'iconCls' => 'icon-task',
                    'has_childs' => true,
                ];
            }
        } else {
            $p['fq'][] = 'task_status:(1 OR 2)';

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
		$p['fl'] = 'id,due_s,name,cdate,case_status,template_id,clientname_s';
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
            case 'assignee':
                return $this->getAssigneeUsers();
                break;
            default:
                if (substr($this->lastNode->id, 0, 3) == 'au_') {
                    return $this->getAssigneeTasks();
                }
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

        $p['fq'][] = 'cid:'.User::getId();
        $p['fq'][] = 'task_status:[1 TO 2]';

        $user_id = substr($this->lastNode->id, 3);
        $p['fq'][] = 'task_u_ongoing:'.$user_id;

        $s = new Search();

        $sr = $s->query($p);

        return $sr;
    }
}