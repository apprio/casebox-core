<?php

namespace Casebox\CoreBundle\Service\Objects;

use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Log;

class Comment extends CBObject
{
    /**
     * create method
     * @return void
     */
    public function create($p = false)
    {
        if ($p === false) {
            $p = &$this->data;
        }

        // if (!empty($p['data']['_title'])) {
        //     //all data is html escaped when indexed in solr
        //     //so no need to encode it here
        //     $msg = $this->processAndFormatMessage($p['data']['_title']);
        //     $p['name'] = $msg;
        //     $p['data']['_title'] = $msg;
        // }

        //disable default log from parent CBObject class
        //we'll set comments add as comment action for parent
        $this->configService->setFlag('disableActivityLog', true);

        $rez = parent::create($p);

        $this->configService->setFlag('disableActivityLog', false);

        $this->updateParentFollowers();

        $this->logAction(
            'comment',
            [
                'new' => $this->getParentObject(),
                'comment' => $p['data']['_title'],
                'mentioned' => $this->lastMentionedUserIds,
            ]
        );

        return $rez;
    }

    /**
     * update comment
     *
     * @param  array $p optional properties. If not specified then $this-data is used
     *
     * @return boolean
     */
    public function update($p = false)
    {
        //disable default log from parent CBObject class
        //we'll set comments add as comment action for parent
        $this->configService->setFlag('disableActivityLog', true);

        $rez = parent::update($p);

        $this->configService->setFlag('disableActivityLog', false);

        $p = &$this->data;

        $this->updateParentFollowers();

        $this->logAction(
            'comment_update',
            [
                'new' => Objects::getCachedObject($p['pid']),
                'comment' => $p['data']['_title'],
                'mentioned' => $this->lastMentionedUserIds,
            ]
        );

        return $rez;

    }

    /**
     * method to collect solr data from object data
     * according to template fields configuration
     * and store it in sys_data onder "solr" property
     * @return void
     */
    protected function collectSolrData()
    {
        $rez = [];

        // parent::collectSolrData();
        //
        if (!empty($this->data['data']['_title'])) {
            $rez['content'] = $this->data['data']['_title'];

        }

        $this->data['sys_data']['solr'] = $rez;
    }

    /**
     * function to update parent followers when adding a comment
     * with this user and referenced users from comment
     * @return void
     */
    protected function updateParentFollowers()
    {
        $p = &$this->data;

        $po = $this->getParentObject();
        $posd = $po->getSysData();

        $newUserIds = [];

        $posd['lastComment'] = [
            'user_id' => User::getId(),
            'date' => Util\dateMysqlToISO('now'),
        ];

        $wu = empty($posd['wu']) ? [] : $posd['wu'];
        $uid = User::getId();

        if (!in_array($uid, $wu)) {
            $newUserIds[] = intval($uid);
        }

        //analize comment text and get referenced users
        $this->lastMentionedUserIds = Util\getReferencedUsers($p['data']['_title']);
        foreach ($this->lastMentionedUserIds as $uid) {
            if (!in_array($uid, $wu)) {
                $newUserIds[] = $uid;
            }
        }

        //update only if new users added
        if (!empty($newUserIds)) {
            $wu = array_merge($wu, $newUserIds);
            $wu = Util\toNumericArray($wu);

            $posd['wu'] = array_unique($wu);

        }

        //always update sys_data to change lastComment date
        $po->updateSysData($posd);
    }
}
