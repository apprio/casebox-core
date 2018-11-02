<?php

namespace Casebox\CoreBundle\Service;

use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Traits\TranslatorTrait;

class Tasks
{
    use TranslatorTrait;

    /**
     * Update dates of a event or task (startDate, endDate)
     *
     * @param array $p Params object containing id and dates
     *
     * @return array
     * @throws \Exception
     */
    public function updateDates($p)
    {
        $rez = ['success' => true];
        if (!Security::canManageTask($p['id'])) {
            throw new \Exception($this->trans('Access_denied'), 1);
        }

        $obj = Objects::getCachedObject($p['id']);
        $data = $obj->getData();

        $data['cdate'] = $p['date_start'];
        if (empty($p['date_end'])) {
            unset($data['data']['due_date']);
            unset($data['data']['due_time']);
        } else {
            $data['data']['due_date'] = $p['date_end'];
            if (substr($p['date_end'], 12, 5) == '00:00') {
                unset($data['data']['due_time']);
            } else {
                $data['data']['due_time'] = $p['date_end'];
            }
        }

        $obj->update($data);

        $this->afterUpdate($p['id']);

        return $rez;
    }

    /**
     * Set complete or incomplete status for a task responsible user
     *
     * @param array $p params
     *
     * @return array
     * @throws \Exception
     */
    public function setUserStatus($p)
    {
        $rez = [
            'success' => true,
            'id' => $p['id'],
        ];

        $obj = Objects::getCachedObject($p['id']);
        $data = $obj->getData();

		if ($obj->getType() == 'case')
		{
			$obj->setUserStatus($status, false); //Set user to current user
			return $rez;
		}
		
        if ((User::getId() != $data['cid']) && !Security::isAdmin()) {
            throw new \Exception($this->trans('Access_denied'));
        }

        if ($obj->getUserStatus($p['user_id']) == Objects\Task::$USERSTATUS_NONE) {
            throw new \Exception($this->trans('Wrong_id'));
        }

        $status = ($p['status'] == 1) ? Objects\Task::$USERSTATUS_DONE : Objects\Task::$USERSTATUS_ONGOING;

        $obj->setUserStatus($status, $p['user_id']);
        // $obj->updateSysData();

        $this->afterUpdate($p['id']);

        return $rez;
    }

    /**
     * Task completion method for currently authenticated user
     *
     * @param array $p
     *
     * @return array
     * @throws \Exception
     */
    public function complete($p)
    {
        // check if current user can manage this task
        if (!Security::canManageTask($p['id'])) {
            throw new \Exception($this->trans('Access_denied'));
        }

        $obj = Objects::getCachedObject($p['id']);

        if ($obj->getUserStatus() != Objects\Task::$USERSTATUS_ONGOING) {
            throw new \Exception($this->trans('Task_already_completed'));
        }

        $obj->setUserStatus(Objects\Task::$USERSTATUS_DONE);
        // $obj->updateSysData();

        $this->afterUpdate($p['id']);

        return ['success' => true];
    }

    /**
     * Method for marking task as closed
     *
     * @param int $id task id
     *
     * @return array response
     */
    public function close($id)
    {
        return $this->changeStatus($id, Objects\Task::$STATUS_CLOSED);
    }

    /**
     * Reopen a task
     *
     * @param int $id
     *
     * @return array response
     */
    public function reopen($id)
    {
        return $this->changeStatus($id, Objects\Task::$STATUS_ACTIVE);
    }

    /**
     * change status for a task
     *
     * @param  int $status
     * @param  int $id
     *
     * @return array response
     */
    protected function changeStatus($id, $status)
    {
        $obj = Objects::getCachedObject($id);

        // status change for task is allowed only for owner or admin
        if (!$obj->isOwner() && !Security::isAdmin()) {
            return [
                'success' => false,
                'msg' => $this->trans('No_access_for_this_action'),
            ];
        }

        switch ($status) {
            case Objects\Task::$STATUS_ACTIVE:
                $obj->setActive();
                break;

            case Objects\Task::$STATUS_CLOSED:
                $obj->setClosed();
                break;

            default:
                return [
                    'success' => false,
                    'id' => $id,
                ];
        }

        $this->afterUpdate($id);

        return [
            'success' => true,
            'id' => $id,
        ];
    }

    /**
     * Method called after a task have been updated
     * used now to update solr and cases date
     *
     * @param  int $taskId
     *
     * @return void
     */
    protected function afterUpdate($taskId)
    {
        Objects::updateCaseUpdateInfo($taskId);

        $solr = new  Solr\Client();
        $solr->updateTree(['id' => $taskId]);
    }

    /**
     * Set the flags for actions that could be made to the tasks by a specific or current user
     *
     * @param array $taskData
     * @param integer|bool $userId
     */
    public static function setTaskActionFlags(&$taskData, $userId = false)
    {
        $p = [&$taskData];
        static::setTasksActionFlags($p, $userId);
    }

    /**
     * Set the flags for actions that could be made to the tasks by a specific or current user
     *
     * @param array $tasksDataArray
     * @param integer|bool $userId
     */
    public static function setTasksActionFlags(&$tasksDataArray, $userId = false)
    {
        $taskTemplates = DM\Templates::getIdsByType('task');

        foreach ($tasksDataArray as &$d) {
            if ((!in_array(@$d['template_id'], $taskTemplates)) ||
                empty($d['status'])
            ) {
                continue;
            }

            $task = Objects::getCachedObject($d['id']);
            $d['can'] = $task->getActionFlags();
        }
    }
}
