<?php

namespace Casebox\CoreBundle\Service;

use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Objects\CBObject;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class Notifications
 */
class Notifications
{
    use TranslatorTrait;

    private static $template = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
        "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
        <html xmlns="http://www.w3.org/1999/xhtml" lang="{lang}" xml:lang="{lang}">
        <head><title>CaseBox</title><meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /></head>
        <body>{body}</body></html>';

    private static $updateFieldColors = [
        'added' => 'A8D08D',
        'updated' => '#FFC000',
        'removed' => 'C00000',
    ];

    /**
     * remotely accessible method to get the notifications list
     *
     * @param  array $p Remote params (page, limit, etc)
     *
     * @return array
     */
    public function getList($p)
    {
        $this->prepareParams($p);

        $params = [
            'user_id' => User::getId(),
            'limit' => $p['limit'],
        ];

		$rez = $this->getReport($p);
		
        /*return [
            'success' => true,
            'lastSeenActionId' => User::getUserConfigParam('lastSeenActionId', 0),
            'data' => $this->getRecords($params),
        ];*/
		$rez['success'] = true;
        return $rez;
		
		
    }

	protected function rtrim_str($str, $trim) {
	  if (substr($str, -strlen($trim)) == $trim) {
		return substr($str, 0, -strlen($trim));
	  }
	  return $str; // String not present at end
	}
	
    /**
     * get new notification records
     *
     * @param  array $p containing fromId property
     *
     * @return array
     */
    public function getNew($p)
    {
        if (User::isLogged()) {
            $rez = [
                'success' => true,
                'data' => [],
            ];

            $this->prepareParams($p);

            $p['user_id'] = User::getId();

            $rez['data'] = $this->getRecords($p);
            $rez['lastSeenActionId'] = User::getUserConfigParam('lastSeenActionId', 0);

            User::setUserConfigParam('lastNotifyTime', Util\dateISOToMysql('now'));

        } else {
            $rez = [
                'success' => false,
            ];
        }

        return $rez;
    }

   /**
     * get new report records
     *
     * @param  array $p containing fromId property
     *
     * @return array
     */
    public function getReport($p)
    {
		$obj = Objects::getCachedObject($p['reportId']);		
		$objData = $obj->getData();
		$configuration = \GuzzleHttp\json_decode($objData['data']['value'], true);
		
		if (!empty($configuration['DC'])) {
            $columns = [];
			$fl = [];
            foreach ($configuration['DC'] as $colName => $col) {
                if (@$col['hidden'] !== true) {
                    $columns[$colName] = $col;
                    if (isset($col['solr_column_name']))
                    {
			$fl[] = $col['solr_column_name'];
		    }			
                }
            }
        }
       
         if (!empty($p['teamId']))
         {
         	$p['fq'] = ['team_i:'.$p['teamId']];	
         }
         
		 if (strpos($configuration['title'], 'FEMA') !== false || strpos($configuration['title'], 'OHSEPR') !== false)
		 {
			if (empty($p['startDate']) && empty($p['endDate']))
			{
				$p['fq'] = ['report_dt:[NOW-7DAY/DAY TO NOW]'];	
				$begin = new \DateTime( date("Y-m-d") );
				$end = new \Datetime( strtotime("-1 week +1 day") );
			}
			else
			{
				if (empty($p['startDate'])) {
					$begin = new \DateTime( date("Y-m-d") );
					$p['startDate'] = '*';
				}
				else
				{
					$begin = new \DateTime( $p['startDate'] );
					$p['startDate'] = $p['startDate'] . 'T00:00:00.000Z';
				}
				if (empty($p['endDate'])) {
					$p['endDate'] = '*';
					$end = new \Datetime( strtotime("-1 week +1 day") );
				}
				else
				{
					$end = new \Datetime( $p['endDate'] );
					$p['endDate'] = $p['endDate'] . 'T00:00:00.000Z';
				}				
				$p['fq'] = ['report_dt:['.$p['startDate'].' TO '.$p['endDate'].']'];
			}
			
			
			if (!empty($configuration['sql'])){
				$dbs = Cache::get('casebox_dbs');
				$res = $dbs->query($configuration['sql']);
				$mysqlrecords = [];
				
				while ($r = $res->fetch()) {
					$mysqlrecords[] = $r;
				}
				$rez['data'] = $mysqlrecords;
			}
			else
			{			
			
				$p['id'] = '11-Admin';
				$p['from'] = 'grid';
				$p['rows'] = 7000;
				$p['skipSecurity'] = true;
				$fq = $configuration['query'];

				// check if fq is set and add it to result
				if (!empty($p['fq'])) {
					if (!is_array($p['fq'])) {
						$p['fq'] = [$p['fq']];
					}
					$fq = array_merge($fq, $p['fq']);
				}
				$p['fq'] = $fq;
				$p['fl'] = implode(',',$fl);
				$s = new Search();
				$rez = $s->query($p);		
			}
			$colTitles = [];
			$colOrder = [];
			$newcolumns = [];
			$records = [];
			$res = [];

			$newcolumns['number'] = ["solr_column_name"=>'number',"title"=>'#',"width"=>100];
			$newcolumns['title'] = ["solr_column_name"=>'title',"title"=>'Title',"width"=>200];
			//$begin = new \DateTime( '2010-05-01' );
			//$end = new \Datetime( '2010-05-10' );

			$interval = \DateInterval::createFromDateString('1 day');
			$end->modify( '+1 day' ); 
			$period = new \DatePeriod($begin, $interval, $end);
			foreach ( $period as $dt )
			{
				$newcolumns[$dt->format( "Y-m-d" ).'T00:00:00Z'] = ["solr_column_name"=>$dt->format( "Y-m-d" ).'T00:00:00Z',"title"=>substr($dt->format( "Y-m-d" ),0,10),"width"=>100];						
			}
						
			if(isset($configuration['groupField']))
			{
				$newcolumns['area_s'] = ["solr_column_name"=>'area_s',"title"=>'Area',"width"=>200];	
					$out = array();
					foreach ($rez['data'] as $row) {
						$out[$row['area_s']] = $row;
					}
					$areas = array_values($out); // only required if you mind the new array being assoc
			}
			else
			{
				$areas = ["All"];
			}
			foreach($areas as $area)
			{
				$record['areatotal'] = 0;
				$record['total']  = 0;
				$i = 1;
				foreach($columns as $t)
				{
					if ($t['solr_column_name'] !== 'report_dt' && $t['solr_column_name'] !== 'area_s') {
					$record = [];
					$record['number'] =  $i++;		
					$record['title'] = $t['title'];
					$record['area_s'] = isset($area['area_s'])?$area['area_s']:$area;
					foreach($rez['data'] as &$r)
					{
						if ((isset($r['area_s']) && $r['area_s'] == $area['area_s']) || $area == "All")
						{
							$record[$r['report_dt']] = "0";
							if (isset($r[$t['solr_column_name']]))
							{
								$record[$r['report_dt']] = $r[$t['solr_column_name']];
							}
							if (isset($r[$t['solr_column_name']]) && is_numeric($r[$t['solr_column_name']]))
							{
								$record['areatotal'] = isset($record['areatotal'])?$record['areatotal']+ $r[$t['solr_column_name']]:0 + $r[$t['solr_column_name']];
							}
						}
						if (isset($r[$t['solr_column_name']]) && is_numeric($r[$t['solr_column_name']]))
						{
							$record['total'] = isset($record['total'])?$record['total'] + $r[$t['solr_column_name']]:$r[$t['solr_column_name']];
						}
					}
					$records[]= $record;
					}
				}
			}
			if ($area != "All")
			{
				$newcolumns['areatotal'] = ["solr_column_name"=>'areatotal',"title"=>'Area Total',"width"=>100];
			}
			$newcolumns['total'] = ["solr_column_name"=>'total',"title"=>'Total',"width"=>100];
			unset($rez['data']);
			$rez['data'] = $records;		
			$columns = $newcolumns;
		 }
		 else
		 {
		 
			if (!empty($configuration['sql'])){
				$dbs = Cache::get('casebox_dbs');
				$res = $dbs->query($configuration['sql']);
				$mysqlrecords = [];
				
				while ($r = $res->fetch()) {
					$mysqlrecords[] = $r;
				}
				$rez['data'] = $mysqlrecords;
			}
			else
			{		 
			 
				$p['id'] = '11-Admin';
				$p['from'] = 'grid';
				$p['rows'] = 7000;
				$fq = $configuration['query'];

				// check if fq is set and add it to result
				if (!empty($p['fq'])) {
					if (!is_array($p['fq'])) {
						$p['fq'] = [$p['fq']];
					}
					$fq = array_merge($fq, $p['fq']);
				}
				$p['fq'] = $fq;
				$p['fl'] = implode(',',$fl);
				$s = new Search();
				$rez = $s->query($p);			 
			}
		 }

		$colTitles = [];
        $colOrder = [];
	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
	foreach ($columns as $name => $col) {
            $colTitles[] = empty($defaultColumns[$name]) ? @Util\coalesce($col['title'], $name) : $defaultColumns[$name]['title'];
            $colOrder[] = $name;
	    if (strpos($name, 'config_') !== false)
            {
		$configValue = $configService->get(str_replace('config_','',$name));
            	foreach($rez['data'] as &$r)
		{
			$r[$name] = $configValue;
		}
            }           		
        }
		
		$rez['title'] = $configuration['title'];
		$rez['groupField'] = isset($configuration['groupField'])?$configuration['groupField']:null;
		$rez['columns'] = $columns;
		$rez['colTitles'] = $colTitles;
		$rez['colOrder'] = $colOrder;
		
        return $rez;
    }	
	
	
    /**
     * update last seen laction id
     * @return array response
     *
     * @param  int $actionId
     */
    public static function updateLastSeenActionId($actionId, $userId = false)
    {
        $rez = ['success' => false];

        if ($userId == false) {
            $userId = User::getId();
        }

        if (is_numeric($actionId) and ($actionId > 0)) {
            User::setUserConfigParam('lastSeenActionId', $actionId, $userId);
            DM\Notifications::markAsSeenUpToActionId($actionId, $userId);
            $rez = ['success' => true];
        }

        return $rez;
    }

    /**
     * mark a notification record as read
     *
     * @param  array $p containing "id" (returned client side id) and "ids"
     *
     * @return array  response
     */
    public function markAsRead($p)
    {
        $rez = ['success' => false];

        if (!empty($p['ids'])) {
            DM\Notifications::markAsRead($p['ids'], User::getId());

            $rez = [
                'success' => true,
                'data' => $p,
            ];
        }

        return $rez;
    }

    /**
     * mark a notification record as unread
     *
     * @param  array $p containing "id" (returned client side id) and "ids"
     *
     * @return array  response
     */
    public function markAsUnread($p)
    {
        $rez = ['success' => false];

        if (!empty($p['ids'])) {
            DM\Notifications::markAsRead($p['ids'], User::getId(), 0);

            $rez = [
                'success' => true,
                'data' => $p,
            ];
        }

        return $rez;
    }

    /**
     * mark all unread user notifications  as read
     * @return array response
     */
    public function markAllAsRead()
    {
        DM\Notifications::markAllAsRead(User::getId());

        return [
            'success' => true,
        ];
    }

    /**
     * get details for given notification ids
     *
     * @param  array $p
     *
     * @return array  response
     */
    public function getDetails($p)
    {
        $rez = [
            'success' => true,
            'ids' => $p['ids'],
            'data' => '',
        ];

        //collect action log ids
        $logIds = [];
        $recs = DM\Notifications::readByIds($p['ids']);

        foreach ($recs as $r) {
            $logIds = array_merge($logIds, Util\toNumericArray($r['action_ids']));
        }
        $logIds = array_unique($logIds);

        $recs = DM\Log::getRecords($logIds);
        // $rez['data'].= var_export($recs, 1);
        foreach ($recs as $r) {
            $d = Util\arrayDecode($r['data']);

            $html = '<hr /><b class="user">'.User::getDisplayName($r['user_id']).
                '</b>, <span class="gr" title="'.
                Util\formatMysqlTime($r['action_time']).
                '">'.Util\formatAgoTime($r['action_time']).'</span>';

            switch ($r['action_type']) {
                case 'comment':
                    $html .= '<br />'.nl2br(Comment::processAndFormatMessage($d['comment']));
                    break;

                default:
                    $obj = Objects::getCachedObject($r['object_id']);
                    $diff = $obj->getDiff($d);
                    if (!empty($diff)) {
                        $html .= "<table class=\"as-diff\">";
                        foreach ($diff as $fn => $fv) {
                            $html .= "<tr><th>$fn</th><td>$fv</td></tr>";
                        }
                        $html .= "</table>";
                    }
            }

            $rez['data'] = $html.$rez['data'];
        }

        return $rez;
    }

    /**
     * get action records and group them for notifications display
     *
     * @param  string $sql
     * @param  array $params sql params
     *
     * @return array
     */
    private function getRecords($p)
    {
        $rez = [];

        $recs = DM\Notifications::getLast(
            $p['user_id'],
            $p['limit'],
            empty($p['fromId']) ? false : $p['fromId']
        );

        $actions = [];
        // grouping actions by object_id, action type and read property
        foreach ($recs as $r) {
            $r['data'] = Util\toJsonArray($r['data']);
            $group = $r['object_id'].'_'.$r['action_type'].'_'.$r['read'];
            $actions[$group][$r['from_user_id']] = $r;
        }

        // iterate actions and group into records up to read property
        foreach ($actions as $group => $users) {
            //form id
            $ids = []; //would be comma separated action_ids
            foreach ($users as $r) {
                $ids[] = $r['id'];
            }
            $r = current($users);

            $forUserId = '';
            if (!empty($r['data']['forUserId']) &&
                ($r['from_user_id'] != $r['data']['forUserId'])
            ) {
                $arr = [
                    $r['data']['forUserId'] => 1,
                ];
                $forUserId = ' '.$this->getUsersString($arr).' '.$this->trans('in').' ';

            }

            $record = [
                'ids' => implode(',', $ids),
                'read' => $r['read'],
                'action_id' => $r['action_id'],
                'user_id' => $r['from_user_id'],
                'object_id' => $r['object_id'],
                'text' => $this->getUsersString($users).' '.
                    $this->getActionDeclination($r['action_type']).$forUserId.' '.
                    $this->getObjectName($r['data']). //with icon
                    '<div class="cG" style="padding-top: 2px">'.Util\formatAgoTime($r['action_time']).'</div>',

            ];

            if (in_array($r['action_type'], ['create', 'update', 'comment'])) {
                $record['expandable'] = true;
            }

            if (is_numeric($record['ids'])) {
                $record['id'] = $record['ids'];
            }

            $rez[] = $record;
        }

        return $rez;
    }

    /**
     * forms a user string based on their count
     *
     * @param  array &$usersArray grouped users array
     *
     * @return string
     */
    private function getUsersString(&$usersArray)
    {
        $usersCount = sizeof($usersArray);
        $userIds = array_keys($usersArray);
        $users = [];

        foreach ($userIds as $id) {
            // onClick will show popup user profile
            $users[] = '<a class="user" href="#">'.User::getDisplayName($id).'</a>';
        }

        switch ($usersCount) {
            case 1:
                $rez = $users[0];
                break;

            case 2:
                $rez = $users[0].' '.$this->trans('and').' '.$users[1];
                break;

            case 3:
                $rez = $users[0].', '.$users[1].' '.$this->trans('and').' '.$users[2];
                break;

            default:
                $rez = $users[0].', '.$users[1].' '.$this->trans('and').' '.
                    str_replace('{count}', $usersCount - 2, $this->trans('NNOthers'));
        }

        return $rez;
    }

    /**
     * get action type declination
     *
     * @param  string $actionType
     * @param  bool $lang
     *
     * @return string
     */
    public static function getActionDeclination($actionType, $lang = false)
    {
        switch ($actionType) {
            case 'create':
            case 'update':
            case 'delete':
            case 'complete':
            case 'close':
            case 'rename':
            case 'status_change':
            case 'comment_update':
            case 'move':
                $rez = self::trans($actionType.'d', $lang);
                break;

            case 'reopen':
                $rez = self::trans($actionType.'ed', $lang);
                break;

            case 'comment':
                $rez = self::trans($actionType.'edOn', $lang);
                break;

            case 'file_upload':
                $rez = self::trans($actionType.'ed_to', $lang);
                break;

            case 'file_update':
                $rez = self::trans($actionType.'d_in', $lang);
                break;

            case 'completion_decline':
                $rez = self::trans('completionDeclinedFor', $lang);
                break;

            case 'completion_on_behalf':
                $rez = self::trans('completedOnBehalf', $lang);
                break;

            default:
                $rez = $actionType;
            //to review and discuss
            /*
            'overdue'
            'password_change'
            'permissions'
            'user_delete'
            'user_create'
            'login'
            'login_fail'/**/
        }

        return $rez;
    }

    /**
     * format an object name using its data
     *
     * @param  array $data
     *
     * @return string
     */
    private function getObjectName($data)
    {
        $id = $data['id'];
        $tid = $data['template_id'];
        $name = $data['name'];
        
        return '<a class="click obj-ref" itemid="'.$id.'" templateid="'.$tid.'" title="'.$name.'">'.$name.'</a>';
    }

    /**
     * prepare input params of a request
     *
     * @param  array &$p
     *
     * @return void
     */
    protected function prepareParams(&$p)
    {
        $limit = (empty($p['limit']) || !is_numeric($p['limit'])) ? 200 : intval($p['limit']);

        if ($limit > 500) {
            $limit = 500;
        }

        $p['limit'] = $limit;
    }

    /**
     * analyze given action (from action log)
     * and create corresponding mail body
     * @return string
     */
    public static function getMailBodyForAction(&$action, &$userData)
    {
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        $coreUrl = $configService->get('core_url');
        $name = $action['data']['name'];
        $languages = $configService->get('languages');
        $lang = $languages[$userData['language_id'] - 1];

        // set header row by default
        $rez = '<h3><a href="'.$coreUrl.'view/'.$action['object_id'].'/">'.$name.'</a></h3>';

        switch ($action['action_type']) {
            case 'comment':
            case 'comment_update':
                $rez .= static::getCommentMailBody($action, $userData);
                break;

            case 'create':
            case 'delete':
            case 'move':
                $rez .= static::getObjectMailBody($action);
                break;

            default:
                $rez .= static::getActionDiffMailBody($action, static::$updateFieldColors);
        }

        $rez = str_replace(
            [
                '{lang}',
                '{body}',
            ],
            [
                $lang,
                $rez,
            ],
            static::$template
        );

        return $rez;
    }

    /**
     * get mail body for a comment
     *
     * @param  array $action
     * @param  array $colors
     *
     * @return string
     */

    protected static function getCommentMailBody(&$action, &$userData)
    {
        $rez = nl2br(CBObject::processAndFormatMessage($action['data']['comment']));
        $rez .= '<br /><hr />'.'To add a comment, reply to this email.<br />';

        // <a href="#">Unsubscribe</a> (will not receive emails with new comments for “' . $name . '”)';
        return $rez;
    }

    /**
     * get mail body for a generic object
     *
     * @param  array $action
     * @param  array $colors
     *
     * @return string
     */
    protected static function getObjectMailBody($action, $colors = false)
    {
        $rez = '';
        $rows = [];

        $obj = Objects::getCachedObject($action['object_id']);
        $tpl = $obj->getTemplate();
        $ld = $obj->getLinearData(true);

        $ad = &$action['data'];

        foreach ($ld as $fieldData) {
            $fieldName = $fieldData['name'];
            $field = $tpl->getField($fieldName);

            $type = 'none';
            if (!empty($ad['new'][$fieldName]) && empty($ad['old'][$fieldName])) {
                $type = 'added';
            } elseif (!empty($ad['old'][$fieldName]) && empty($ad['new'][$fieldName])) {
                $type = 'removed';
            }
            /* // we dont have updated cases here
            elseif (!empty($ad['old'][$fieldName]) && !empty($ad['new'][$fieldName]) &&
                ($ad['old'][$fieldName] != $ad['new'][$fieldName])
            ) {
                $type = 'updated';
            }/**/

            $color = empty($colors[$type]) ? '' : (' style="background-color: '.$colors[$type].'"');

            $value = $tpl->formatValueForDisplay($field, $fieldData, true);
            if (!empty($value) || ($type == 'removed')) {
                $rows[] = '<tr><td style="vertical-align: top"><strong>'.$field['title'].'</strong></td>'.
                    '<td'.$color.'>'.$value.'</td></tr>';
            }
        }

        if (!empty($rows)) {
            $rez = '<table border="1" style="border-collapse: collapse" cellpadding="3">'.
                '<th style="background-color: #d9d9d9">'.self::trans('Property').'</th>'.
                '<th style="background-color: #d9d9d9">'.self::trans('Value').'</th></tr>'.
                implode("\n", $rows).'</table>';
        }

        return $rez;
    }

    /**
     * get mail body diff for a generic object
     *
     * @param  array $action
     * @param  array $colors
     *
     * @return string
     */
    protected static function getActionDiffMailBody($action, $colors = false)
    {
        $rez = '';
        $rows = [];

        $obj = Objects::getCachedObject($action['object_id']);
        $tpl = $obj->getTemplate();

        $ad = &$action['data'];

        $oldData = empty($ad['old']) ? [] : $ad['old'];

        $newData = empty($ad['new']) ? [] : $ad['new'];

        $keys = array_keys($oldData + $newData);

        foreach ($keys as $fieldName) {
            $field = $tpl->getField($fieldName);

            $oldValue = null;
            if (!empty($oldData[$fieldName])) {
                $oldValue = [];
                foreach ($oldData[$fieldName] as $v) {
                    $v = $tpl->formatValueForDisplay($field, $v, true, true);
                    if (!empty($v)) {
                        $oldValue[] = $v;
                    }
                }
                $oldValue = implode('<br />', $oldValue);
            }

            $newValue = null;
            if (!empty($newData[$fieldName])) {
                $newValue = [];
                foreach ($newData[$fieldName] as $v) {
                    $v = $tpl->formatValueForDisplay($field, $v, true, true);
                    if (!empty($v)) {
                        $newValue[] = $v;
                    }
                }
                $newValue = implode('<br />', $newValue);
            }

            $type = 'none';
            if (!empty($newValue) && empty($oldValue)) {
                $type = 'added';
            } elseif (!empty($oldValue) && empty($newValue)) {
                $type = 'removed';
            } elseif (!empty($oldValue) && !empty($newValue) && ($oldValue != $newValue)) {
                $type = 'updated';
            }

            $color = empty($colors[$type]) ? '' : (' style="background-color: '.$colors[$type].'"');

            $value = empty($oldValue) ? '' : "<del>$oldValue</del><br />";
            $value .= $newValue;

            if (!empty($value)) {
                $rows[] = '<tr><td style="vertical-align: top"><strong>'.$field['title'].'</strong></td>'.
                    '<td'.$color.'>'.$value.'</td></tr>';
            }
        }

        if (!empty($rows)) {
            $rez = '<table border="1" style="border-collapse: collapse" cellpadding="3">'.
                '<th style="background-color: #d9d9d9">'.self::trans('Property').'</th>'.
                '<th style="background-color: #d9d9d9">'.self::trans('Value').'</th></tr>'.
                implode("\n", $rows).'</table>';
        }

        return $rez;
    }

    /**
     * get the sender formated string
     *
     * @param  integer|false $userId
     *
     * @return string
     */
    public static function getSender($userId = false)
    {
        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');

        $coreName = $configService->get('core_name');

        $commentsEmail = $configService->get('comments_email');

        $senderMail = empty($commentsEmail) ? $configService->get('sender_email') : $commentsEmail;

        $rez = '"'.
            mb_encode_mimeheader(
                str_replace(
                    '"',
                    '\'\'',
                    html_entity_decode(
                        User::getDisplayName($userId)." (".$coreName.")",
                        ENT_QUOTES,
                        'UTF-8'
                    )
                ),
                'UTF-8',
                'B'
            )
            ."\" <".$senderMail.'>';

        return $rez;
    }
}
