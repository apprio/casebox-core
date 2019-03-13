<?php

namespace Casebox\CoreBundle\Service\Plugins\DisplayColumns;

use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Casebox\CoreBundle\Service\Cache;
/**
 * Class ActivityStream
 */
class ActivityStream extends Base
{
    use TranslatorTrait;

    protected $fromParam = 'activityStream';

    public function onBeforeSolrQuery(&$p)
    {
        $p['rows'] = 15;
        $p['params']['fl'] = [
            'id',
            'pid',
            'name',
            'template_type',
            'target_id',
            'oid',
            'cid',
            'cdate',
            'uid',
            'udate',
            'comment_user_id',
            'comment_date',
            'template_id',
            'last_action_tdt',
        ];

        $p['params']['sort'] = 'udate desc';
        // return parent::onBeforeSolrQuery($p);
    }

    public function onSolrQuery(&$p)
    {
        $result = &$p['result'];
        $data = &$result['data'];
        $actionLogIds = [];
		$caseId = $p['inputParams']['pid'];
        $comments = new Objects\Plugins\Comments();
		$oldName = 'CBObject';
        $logRecs = DM\Log::getRecordsByParent($caseId);

		foreach ($logRecs as $r) {
			$d = Util\jsonDecode($r['data']);

            switch ($r['action_type']) {
                case 'move':
                case 'copy':
                    $actionLogIds[$r['id']]['diff'] = '<table class="as-diff"><tr><th>'.
                        $this->trans('Path').'</th><td>'.
                        $d['old']['path'].' &#10142; '.$d['new']['path'].'</td></tr></table>';

                    break;
                default:
                    $obj = Objects::getCachedObject($r['object_id']);
					if ($obj)
					{
						$diff = $obj->getDiff($d);
						$oldName = $obj->getName();
					}
                    if (!empty($diff)) {
                        $html = '';
                        foreach ($diff as $fn => $fv) {
                            $html .= "<tr><th>$fn</th><td>$fv</td></tr>";
                        }

                        $diff = "<table class=\"as-diff\">$html</table>";
                    }
            }
			$data[] = ['id'=>$r['id'], 'pid'=>$r['object_pid'], 'diff'=>$diff,'case_id'=>$caseId,'acl_count'=>0, 'system'=>0, 'oid'=>1,'cid'=>$r['user_id'],
			'name'=>$oldName, 'comments'=>['success'=>true,'data'=>[],'total'=>0], 
			'lastAction'=>['type'=>$r['action_type'].'d','time'=>$r['action_time'],'users'=>[$r['user_id']],'users'=>"1",'agoText'=>Util\formatAgoTime($r['action_time']),'uids'=>[$r['user_id']]]];
				
        }
    }

    public function getSolrFields($nodeId = false, $templateId = false)
    {
        // $rez = parent::getSolrFields($nodeId, $templateId);
        $rez = [];

        $rez['sort'] = 'udate desc';

        return $rez;
    }

    public function getDC()
    {
        // $rez = parent::getDC();
        $rez = [];

        $rez['sort'] = [
            'property' => 'udate',
            'direction' => 'DESC',
        ];

        return $rez;
    }

    public function getState($param = null)
    {
        // $rez = parent::getState($param);
        $rez = [];

        $rez['sort'] = [
            'property' => 'udate',
            'direction' => 'DESC',
        ];

        return $rez;
    }
}
