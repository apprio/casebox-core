<?php

namespace Casebox\CoreBundle\Service\Objects;

use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Security;
use Casebox\CoreBundle\Service\Tasks;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\User;

/**
 * Class Task
 */
class Task extends CBObject
{
    public static $STATUS_NONE = 0;

    public static $STATUS_OVERDUE = 1;

    public static $STATUS_ACTIVE = 2;

    public static $STATUS_CLOSED = 3;

    public static $STATUS_PENDING = 4;

    public static $USERSTATUS_NONE = 0;

    public static $USERSTATUS_ONGOING = 1;

    public static $USERSTATUS_DONE = 2;

    /**
     * Create a task with specified params
     *
     * @param array $p object properties
     *
     * @return int
     */
    public function create($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }
        $this->data = $p;

        $this->setParamsFromData($p);

        return parent::create($p);
    }

    /**
     * Load custom data for tasks
     * Note: should be removed after tasks upgraded and custom task tables removed
     */
    protected function loadCustomData()
    {
        parent::loadCustomData();

        $d = &$this->data;

        if (empty($d['data'])) {
            $d['data'] = [];
        }

        // $cd = &$d['data']; //custom data
        // $sd = &$d['sys_data']; //sys_data

        Tasks::setTaskActionFlags($d);
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
        if ($p === false) {
            $p = $this->data;
        }
        $this->data = $p;

        $this->setParamsFromData($p);

        return parent::update($p);
    }

    /**
     * Analyze object data and set 'wu' property in sys_data
     *
     * return newly assigned ids
     */
    protected function setFollowers()
    {
        $rez = parent::setFollowers();

        $d = &$this->data;
        $sd = &$d['sys_data'];

        //add creator as follower for tasks
        if (!in_array($d['cid'], $sd['wu'])) {
            $sd['wu'][] = intval($d['cid']);
            $rez[] = intval($d['cid']);
        }

        $oldAssigned = [];
        if (!empty($this->oldObject)) {
            $oldAssigned = Util\toNumericArray(@$this->oldObject->getFieldValue('assigned', 0)['value']);
        }

        $newAssigned = Util\toNumericArray(@$this->getFieldValue('assigned', 0)['value']);
        $diff = array_diff($newAssigned, $oldAssigned);
        $wu = empty($sd['wu']) ? [] : $sd['wu'];

        $wu = array_merge($wu, $diff);

        $rez = array_merge($rez, $newAssigned);

        // Analyze referenced users from description
        if (!empty($d['data']['description'])) {
            $uids = Util\getReferencedUsers($d['data']['description']);
            if (!empty($uids)) {
                $wu = array_merge($wu, $uids);
                $rez = array_merge($rez, $wu);
            }
        }
        $sd['wu'] = array_unique($wu);

        $rez = array_unique($rez);

        return $rez;
    }

    /**
     * Method to collect solr data from object data
     * according to template fields configuration
     * and store it in sys_data under "solr" property
     */
    protected function collectSolrData()
    {
        parent::collectSolrData();

        $d = &$this->data;
        $sd = &$d['sys_data'];
        $solrData = &$sd['solr'];

        $template = $this->getTemplate();

        $solrData['task_status'] = @$sd['task_status'];
		$solrData['case_status'] = @$sd['case_status'];

        $user_ids = Util\toNumericArray($this->getFieldValue('assigned', 0)['value']);
        if (!empty($user_ids)) {
            $solrData['task_u_assignee'] = $user_ids;
        }

        $user_ids[] = @Util\coalesce($d['oid'], $d['cid']);

        $solrData['task_u_all'] = array_unique($user_ids);

        // $solrData['content'] = @$this->getFieldValue('description', 0)['value'];

        unset($solrData['task_d_closed']);
        unset($solrData['task_ym_closed']);

        if (!empty($sd['task_d_closed'])) {
            $solrData['task_d_closed'] = $sd['task_d_closed'];
            $solrData['task_ym_closed'] = str_replace('-', '', substr($sd['task_d_closed'], 2, 5));
        }

        // Get users that did not complete the task yet
        if (!empty($sd['task_u_done'])) {
            $solrData['task_u_done'] = $sd['task_u_done'];
        }
        if (!empty($sd['task_u_ongoing'])) {
            $solrData['task_u_ongoing'] = $sd['task_u_ongoing'];
        }

        // Set class
        $solrData['cls'] = $template->formatValueForDisplay(
            $template->getField('color'),
            $this->getFieldValue('color', 0)['value'],
            false
        );
    }

    /**
     * Set "sys_data" params from object data
     *
     * @param array &$p
     */
    protected function setParamsFromData(&$p)
    {
        $d = &$p['data'];

        if (empty($d['sys_data'])) {
            $d['sys_data'] = [];
        }

        $sd = &$p['sys_data'];

        $caseId = $p['pid'];
        $case = Objects::getCachedObject($caseId);
        $objService = new Objects();
        if (!empty($caseId) && !empty($case))
        {
        	$p['data']['_clientname'] = $case->getHtmlSafeName();
        }

        $sd['task_due_date'] = $this->getFieldValue('due_date', 0)['value'];
        $sd['task_due_time'] = $this->getFieldValue('due_time', 0)['value'];

        $sd['task_allday'] = empty($sd['task_due_time']);

        //set date_end to be saved in tree table
        if (empty($sd['task_due_date'])) {
            $p['date_end'] = null;
        } else {
            $p['date_end'] = $sd['task_due_date'];
            if (!$sd['task_allday']) {
                $p['date_end'] = substr($sd['task_due_date'], 0, 10).' '.$sd['task_due_time'];
            }
        }

        // Set assigned users
        if (empty($sd['task_u_done'])) {
            $sd['task_u_done'] = [];
        }

        $assigned = Util\toNumericArray($this->getFieldValue('assigned', 0)['value']);
        $sd['task_u_ongoing'] = array_diff($assigned, $sd['task_u_done']);

        // Set status
        $dateEnd = empty($p['date_end']) ? null : Util\dateISOToMysql($p['date_end']);

        $status = static::$STATUS_ACTIVE;
		$userSetStatus = $this->getFieldValue('task_status', 0)['value'];

			Cache::get('symfony.container')->get('logger')->error(
			'sup'.$userSetStatus,
			$sd
		);
			if ($userSetStatus == 1907 && empty($sd['task_d_closed']))
			{
				$status = static::$STATUS_CLOSED;
				$sd['task_d_closed'] = date('Y-m-d\TH:i:s\Z');
			}
			elseif	($userSetStatus == 1906)
			{
				unset($sd['task_u_done']);
				unset($sd['task_d_closed']);
				$status = static::$STATUS_ACTIVE;
			}

		if (!empty($sd['task_d_closed'])) {
			$status = static::$STATUS_CLOSED;
        } elseif (!empty($dateEnd)) {
            if (strtotime($dateEnd) < strtotime('now')) {
                $status = static::$STATUS_OVERDUE;
            }
        }

        $sd['task_status'] = $status;
		$sd['case_status'] = $this->trans('caseStatus'.$status, '');
    }

    /**
     * Mark the task active
     * @return void
     */
    public function markActive()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        unset($sd['task_d_closed']);

        $this->setParamsFromData($d);
    }

    /**
     * Mark the task active, reset done user list and update into db
     * @return void
     */
    public function setActive()
    {
        if (!$this->loaded) {
            $this->load();
        }

        $this->markActive();

        unset($this->data['sys_data']['task_u_done']);

        $this->update();

        $this->logAction('reopen', ['old' => &$this]);
    }

    /**
     * Mark the task as closed
     * @return void
     */
    public function markClosed()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        $sd['task_status'] = static::$STATUS_CLOSED;
        $sd['task_d_closed'] = date('Y-m-d\TH:i:s\Z');
    }

    /**
     * Mark the task as closed and update into db
     * @return void
     */
    public function setClosed()
    {
        if (!$this->loaded) {
            $this->load();
        }

        $this->markClosed();

        $this->logAction('close', ['old' => &$this]);
    }

    /**
     * Simple function to check if task is closed
     * @return boolean
     */
    public function isClosed()
    {
        return ($this->getStatus() == static::$STATUS_CLOSED);
    }

    /**
     * Get task status
     * @return int
     */
    public function getStatus()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        $rez = empty($sd['task_status']) ? static::$STATUS_NONE : $sd['task_status'];

        return $rez;
    }

    /**
     * Get task status text
     *
     * @param integer|bool|false $status
     *
     * @return string
     */
    public function getStatusText($status = false)
    {
        if ($status === false) {
            $status = $this->getStatus();
        }

        return $this->trans('taskStatus'.$status, '');
    }

    /**
     * Get the css class corresponding for status color
     *
     * @param int $status
     *
     * @return string | null
     */
    public function getStatusCSSClass($status = false)
    {
        if ($status === false) {
            $status = $this->getStatus();
        }

        $rez = 'task-status';

        switch ($this->getStatus()) {
            case static::$STATUS_OVERDUE:
                $rez .= ' task-status-overdue';
                break;

            case static::$STATUS_ACTIVE:
                $rez .= ' task-status-active';
                break;

            case static::$STATUS_CLOSED:
                $rez .= ' task-status-closed';
                break;
        }

        return $rez;
    }

    /**
     * Get user status for loaded task
     *
     * @param  int|bool|false $userId
     *
     * @return integer
     */
    public function getUserStatus($userId = false)
    {
        if ($userId == false) {
            $userId = User::getId();
        }

        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (in_array($userId, $sd['task_u_ongoing']) || !is_null($sd['task_u_ongoing'])) {
            return static::$USERSTATUS_ONGOING;
        }

        if (in_array($userId, $sd['task_u_done']) || !is_null($sd['task_u_done'])) {
            return static::$USERSTATUS_DONE;
        }

        return static::$USERSTATUS_NONE;
    }

    /**
     * Change user status for loaded task
     *
     * @param integer $status
     * @param integer|bool|false $userId
     *
     * @return boolean
     */
    public function setUserStatus($status, $userId = false)
    {
        $rez = false;
        $action = '';
        $currentUserId = User::getId();

        if ($userId == false) {
            $userId = $currentUserId;
        }

        $d = &$this->data;
        $sd = &$d['sys_data'];

        switch ($status) {
            case static::$USERSTATUS_ONGOING:
                if (in_array($userId, $sd['task_u_done'])) {
                    $sd['task_u_done'] = array_diff($sd['task_u_done'], [$userId]);
                    $sd['task_u_ongoing'][] = $userId;
                    unset($sd['task_u_d_closed'][$userId]);

                    $rez = true;

                    $action = ($currentUserId == $userId) ? 'reopen' : 'completion_decline';
                }
                break;
            case static::$USERSTATUS_DONE:
                if (in_array($userId, $sd['task_u_ongoing'])) {
                    $sd['task_u_ongoing'] = array_diff($sd['task_u_ongoing'], [$userId]);
                    $sd['task_u_done'][] = $userId;
                    $sd['task_u_d_closed'][$userId] = date(DATE_ISO8601);

                    $rez = true;

                    $action = ($currentUserId == $userId) ? 'complete' : 'completion_on_behalf';
                }
                break;
        }

        if ($rez) {
            $this->checkAutoclose();
            $this->logAction($action, ['old' => &$this, 'forUserId' => $userId]);
            // $this->updateSysData();
        }

        return $rez;
    }

    /**
     * Check if a task status should be changed after user status change
     */
    public function checkAutoclose()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (empty($sd['task_u_ongoing'])) {
            $this->markClosed();
        } else {
            $this->markActive();
        }
    }

    /**
     * Get action flags that a user can do to task
     *
     * @param integer|bool|false $userId
     *
     * @return array
     */
    public function getActionFlags($userId = false)
    {
        if ($userId === false) {
            $userId = User::getId();
        }

        $isAdmin = Security::isAdmin($userId);
        $isOwner = $this->isOwner($userId);
        $isClosed = $this->isClosed();
        $canEdit = !$isClosed && ($isAdmin || $isOwner);

        $rez = [
            // 'edit' => $canEdit
            'close' => $canEdit,
            'reopen' => ($isClosed && $isOwner),
            'complete' => (!$isClosed && ($this->getUserStatus($userId) == static::$USERSTATUS_ONGOING)),
        ];

        return $rez;
    }

    /**
     * Method to get end date for the task
     * @return string | null
     */
    public function getEndDate()
    {
        $rez = null;

        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (!empty($sd['task_due_date'])) {
            $rez = $sd['task_due_date'];
            if (!empty($sd['task_due_time'])) {
                $rez = substr($rez, 0, 11).$sd['task_due_time'].'Z';
                $rez = Util\userTimeToUTCTimezone($rez);
            }
        }

        return $rez;
    }

    /**
     * Generate html preview for a task
     *
     * @param int $id task id
     *
     * @return array
     */
    public function getPreviewBlocks()
    {
        $pb = parent::getPreviewBlocks();

        $data = $this->getData();
        $sd = &$data['sys_data'];

        $template = $this->getTemplate();

        $dateLines = '';
        $ownerRow = '';
        $assigneeRow = '';
        $contentRow = '';
        $coreUri = $this->configService->get('core_uri');

        $userService = Cache::get('symfony.container')->get('casebox_core.service.user');

        //create date and status row
        $ed = $this->getEndDate();

        if (!empty($ed)) {
            $endDate = Util\formatTaskTime($ed, !$sd['task_allday']);

            $dateLines = '<tr><td class="prop-key">'.$this->trans('Due').':</td><td>'.$endDate.'</td></tr>';
            // $dateLine .= '<div class="date">' . $endDate . '</div>';
        }

        if (!empty($sd['task_d_closed'])) {
            $dateLines .= '<tr><td class="prop-key">'.
                $this->trans('Completed').':</td><td>'.
                Util\formatAgoTime($sd['task_d_closed']).'</td></tr>';
        }

        // Create owner row
        $v = $this->getOwner();
        if (!empty($v)) {
            $cn = User::getDisplayName($v);
            $cdt = Util\formatAgoTime($data['cdate']);
            $cd = Util\formatDateTimePeriod(
                $data['cdate'],
                null,
                @Cache::get('session')->get('user')['cfg']['timezone']
            );

            $ownerRow = '<tr><td class="prop-key">'.$this->trans('Owner').':</td><td>'.
                '<table class="prop-val people"><tbody>'.
                '<tr><td class="user"><img alt="User Photo" class="photo32" src="'.
                $coreUri.'photo/'.$v.'.jpg?32='.$userService->getPhotoParam($v).
                '" style="width:32px; height: 32px" alt="'.$cn.'" title="'.$cn.'"></td>'.
                '<td><b>'.$cn.'</b><p class="gr">'.$this->trans('Created').': '.
                '<span class="dttm" title="'.$cd.'">'.$cdt.'</span></p></td></tr></tbody></table>'.
                '</td></tr>';
        }

        // Create assignee row
        $v = $this->getFieldValue('assigned', 0);

        if (!empty($v['value'])) {

            $isOwner = $this->isOwner();
            $assigneeRow .= '<tr><td class="prop-key">'.$this->trans(
                    'TaskAssigned'
                ).':</td><td><table class="prop-val people"><tbody>';
            $v = Util\toNumericArray($v['value']);

            $dateFormat = Util\getOption('long_date_format').' H:i:s';

            foreach ($v as $id) {
                $un = User::getDisplayName($id);
                $completed = ($this->getUserStatus($id) == static::$USERSTATUS_DONE);

                $cdt = ''; //completed date title
                $dateText = '';

                if ($completed && !empty($sd['task_u_d_closed'][$id])) {
                    $cdt = Util\formatMysqlDate($sd['task_u_d_closed'][$id], $dateFormat);
                    $dateText = ': '.Util\formatAgoTime($sd['task_u_d_closed'][$id]);
                }

                $assigneeRow .= '<tr><td class="user"><div style="position: relative">'.
                    '<img alt="User Photo" class="photo32" src="'.$coreUri.'photo/'.$id.'.jpg?32='.$userService->getPhotoParam($id).
                    '" style="width:32px; height: 32px" alt="'.$un.'" title="'.$un.'">'.
                    ($completed ? '<img alt="Completed Task" class="done icon icon-tick-circle" src="/css/i/s.gif" />' : "").
                    '</div></td><td><b>'.$un.'</b>'.
                    '<p class="gr" title="'.$cdt.'">'.(
                    $completed
                        ? $this->trans('Completed').$dateText.
                        ($isOwner
                            ? ' <a class="bt task-action click" action="markincomplete" uid="'.$id.'">'.
                            $this->trans('revoke').'</a>'
                            : ''
                        )
                        : $this->trans('waitingForAction').
                        ($isOwner
                            ? ' <a class="bt task-action click" action="markcomplete" uid="'.$id.'">'.
                            $this->trans('complete').'</a>'
                            : ''
                        )
                    ).'</p></td></tr>';
            }

            $assigneeRow .= '</tbody></table></td></tr>';
        }

        // Create description row
        $v = $this->getFieldValue('description', 0);
        if (!empty($v['value'])) {
            $tf = $template->getField('description');
            $v = $template->formatValueForDisplay($tf, $v);
            $contentRow = '<tr><td class="prop-val" colspan="2">'.$v.'</td></tr>';
        }

        // Insert rows
        $p = $pb[0];
        $pos = strrpos($p, '<tbody>');
        if ($pos !== false) {
            $p = substr($p, $pos + 7);
            $pos = strrpos($p, '</tbody>');
            if ($pos !== false) {
                $p = substr($p, 0, $pos);
            }
        } else {
            $p = '';
        }

        $rtl = empty($this->configService->get('rtl')) ? '' : ' drtl';

        // Log into action_log table in the DB
        $owner = $this->getOwner();
        $userData = User::getUserData($owner);
        $data = $this->getData();

        $userRole = $userData['groups'];
        $userRole = str_replace('315', 'Administrator', $userRole);
        $userRole = str_replace('22', 'Worker', $userRole);
        $userRole = str_replace('30', 'Supervisor', $userRole);
        $userRole = str_replace('34', 'Resource Manager', $userRole);

        $this->logViewAction('view',
        array(
          'template' => 'Task',
          'date' => date("Y/m/d"),
          'time' => date("h:i:sa"),
          'taskId' => $data['id'],
          'taskName' => $data['name'],
          'userId' => User::getID(),
          'userFullName' => User::getDisplayName(User::getID()),
          'userRole' => $userRole
        ));
/*
        $pb[0] = $this->getPreviewActionsRow().
            '<table class="obj-preview'.$rtl.'"><tbody>'.
            $dateLines.
            $p.
            $ownerRow.
            $assigneeRow.
            $contentRow.
            '<tbody></table>';

        return $pb;*/
    }
}
