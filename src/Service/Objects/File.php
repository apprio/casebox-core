<?php

namespace Casebox\CoreBundle\Service\Objects;

use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\Log;

/**
 * class for casebox files objects
 */
class File extends CBObject
{
    /**
     * create method
     * @return array
     */
    public function create($p = false)
    {
        //disable default log from parent CBObject class
        //we'll set comments add as comment action for parent
        $disableLogFlag = $this->configService->getFlag('disableActivityLog');

        $this->configService->setFlag('disableActivityLog', true);

        $rez = parent::create($p);

        $this->configService->setFlag('disableActivityLog', $disableLogFlag);

        $p = &$this->data;

        $this->parentObj = Objects::getCachedObject($p['pid']);

        $this->updateParentFollowers();

        $this->logAction(
            'file_upload',
            [
                'file' => [
                    'id' => $p['id'],
                    'name' => $p['name'],
                ],
            ]
        );

        return $rez;
    }

    /**
     * internal function used by create method for creating custom data
     * @return void
     */
    protected function createCustomData()
    {
        parent::createCustomData();

        DM\Files::create(
            [
                'id' => $this->id,
                'content_id' => @$this->data['content_id'],
                'date' => @$this->data['date'],
                'name' => @$this->data['name'],
                'cid' => @$this->data['cid'],
            ]
        );
    }

    /**
     * load custom data for $this->id
     *
     * @return void
     */
    protected function loadCustomData()
    {

        parent::loadCustomData();

        $d = &$this->data;

        $cd = DM\Files::getContentData($this->id);

        if (!empty($cd)) {
            $d['content_id'] = $cd['id'];
            $d['size'] = $cd['size'];
            $d['pages'] = $cd['pages'];
            $d['content_type'] = $cd['type'];
            $d['content_path'] = $cd['path'];
            $d['md5'] = $cd['md5'];
        }

        $this->data['versions'] = DM\FilesVersions::getFileVersions($this->id);
    }

    /**
     * update file
     *
     * @param  array $p optional properties. If not specified then $this-data is used
     *
     * @return boolean
     */
    public function update($p = false)
    {
        //disable default log from parent CBObject class
        $this->configService->setFlag('disableActivityLog', true);

        $rez = parent::update($p);

        $this->configService->setFlag('disableActivityLog', false);

        $p = &$this->data;
		
		$this->parentObj = Objects::getCachedObject($p['pid']);
		
		$this->updateParentFollowers();

        $this->logAction(
            'file_update',
            [
                'file' => [
                    'id' => $p['id'],
                    'name' => $p['name'],
                ],
            ]
        );

        return $rez;

    }

			
    public function deleteCustomData($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }
        $this->parentObj = Objects::getCachedObject($p['pid']);
		$posd = $this->parentObj->getSysData();

		$posd['has_document_s'] = 'No';
		$this->parentObj->updateSysData($posd);
		
        return parent::deleteCustomData($p);

	}
	
    /**
     * update objects custom data
     * @return void
     */
    protected function updateCustomData()
    {
        parent::updateCustomData();

        $updated = DM\Files::update(
            [
                'id' => $this->id,
                'content_id' => @$this->data['content_id'],
                'date' => @$this->data['date'],
                'name' => @$this->data['name'],
                'cid' => @$this->data['cid'],
                'uid' => User::getId(),
            ]
        );

        //create record if doesnt exist yet
        if (!$updated) {
            DM\Files::create(
                [
                    'id' => $this->id,
                    'content_id' => @$this->data['content_id'],
                    'date' => @$this->data['date'],
                    'name' => @$this->data['name'],
                    'cid' => @$this->data['cid'],
                ]
            );
        }
    }

    /**
     * method to collect solr data from object data
     * according to template fields configuration
     * and store it in sys_data onder "solr" property
     * @return void
     */
    protected function collectSolrData()
    {
        parent::collectSolrData();

        $sd = &$this->data['sys_data']['solr'];

        $r = DM\Files::getSolrData($this->id);

        if (!empty($r)) {
            $sd['size'] = $r['size'];
            $sd['versions'] = intval($r['versions']);
        }
    }

    /**
     * copy costom files data to targetId
     *
     * @param  int $targetId
     *
     * @return void
     */
    protected function copyCustomDataTo($targetId)
    {
        DM\Files::copy(
            $this->id,
            $targetId
        );
    }

    /**
     * function to update parent followers when uploading a file
     * with this user
     * @return void
     */
    protected function updateParentFollowers()
    {
        $posd = $this->parentObj->getSysData();

        $newUserIds = [];

        $wu = empty($posd['wu']) ? [] : $posd['wu'];
        $uid = User::getId();

        if (!in_array($uid, $wu)) {
            $newUserIds[] = intval($uid);
        }

		$posd['has_document_s'] = 'Yes';
		$this->parentObj->updateSysData($posd);
        //update only if new users added
        if (!empty($newUserIds)) {
            $wu = array_merge($wu, $newUserIds);
            $wu = Util\toNumericArray($wu);

            $posd['wu'] = array_unique($wu);

            $this->parentObj->updateSysData($posd);
        }
    }
}
