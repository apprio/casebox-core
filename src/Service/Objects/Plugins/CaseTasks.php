<?php
namespace Casebox\CoreBundle\Service\Objects\Plugins;

use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\Objects;

class CaseTasks extends Base
{

    public function getData($id=false)
    {
        if (!$this->isVisible()) {
            return null;
        }
		$rez = parent::getData($id);
        $rez['data'] = [];
		if (empty($this->id)) {
            return $rez;
        }
        $params = $this->getSolrParams();

        $s = new \Casebox\CoreBundle\Service\Search();
		    $params['fq'] = '(case_s:'. $id.') AND (task_status_s:1906)';
        $sr = $s->query($params);
        foreach ($sr['data'] as $d) {
            $d['ago_text'] = Util\formatAgoTime($d['cdate']);
            $d['user'] = @User::getDisplayName($d['cid']);
            $rez['data'][] = $d;
        }

        //send additional config params
        $config = $this->config;
        if (isset($config['limit'])) {
            $rez['limit'] = $config['limit'];
        }
		//print_r($rez);
        return $rez;
    }

    protected function getSolrParams()
    {
        $rez = [
            'fl' => 'id,pid,name,template_id,cdate,cid,date_created_s,date_due_s,importance_s,_title_s,time_due_s,task_status_s'
            ,'sort' => 'cdate desc'
        ];

        $config = $this->config;

        if (!empty($config['fn'])) {
            $ids = $this->getFunctionResult($config['fn']);
            if (!empty($ids)) {
                $rez['fq'] = 'case_s:(' . implode(' OR ', $ids) . ')';
            }

        } elseif (isset($config['fq'])) {
            $fq = str_replace('$id', $this->id, $config['fq']);
            $matches = [];
            preg_match_all('/\$([\w]+)/', $fq, $matches);
            if (!empty($matches[1])) {
                $obj = Objects::getCachedObject($this->id);
                foreach ($matches[1] as $fn) {
                    $v = @$obj->getFieldValue($fn, 0)['value'];
                    if (empty($v)) {
                        $v = 0;
                    }
                    $fq = str_replace('$' . $fn, $v, $fq);
                }
            }

            $rez['fq'] = $fq;

        } else {//if config is empty - use old behavior
            //$rez['pid'] = $this->id;
            $rez['fq'] = ['(template_type:object) OR (target_type:object)'];

            $folderTemplates = $this->configService->get('folder_templates');
            if (!empty($folderTemplates)) {
                $rez['fq'][] = '!template_id:(' .
                    implode(' OR ', Util\toNumericArray($folderTemplates)) . ')';
            }
        }

        if (!empty($config['sort'])) {
            $rez['sort'] = $config['sort'];
        }

        return $rez;
    }
}
