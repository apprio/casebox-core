<?php

namespace Casebox\CoreBundle\Service\Plugins\Export;

use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\BrowserView;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Objects\Plugins\ContentItems;
use Symfony\Component\DependencyInjection\Container;
use Casebox\CoreBundle\Service\Notifications;
use Dompdf\Dompdf;
use Casebox\CoreBundle\Service\DataModel\FilesContent;
use Casebox\CoreBundle\Service\DataModel\Files;
use ZipArchive;

class Instance
{
    public function install()
    {

    }

    public function init()
    {
    }

    protected function getData($p)
    {
        $rez = [];
        if (empty($p)) {
            return $rez;
        }

        $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        // form columns
        $defaultColumns = $configService->getDefaultGridColumnConfigs();
        $columns = $defaultColumns;

        // retreive data
        $p['start'] = 0;
        $p['rows'] = 500;

        $sr = new BrowserView();
        $results = $sr->getChildren($p);

        if (!empty($results['DC'])) {
            $columns = [];

            foreach ($results['DC'] as $colName => $col) {
                if (@$col['hidden'] !== true) {
                    $columns[$colName] = $col;
                }
            }
        }

        $colTitles = [];
        foreach ($columns as $name => $col) {
            $colTitles[] = empty($defaultColumns[$name]) ? @Util\coalesce($col['title'], $name) : $defaultColumns[$name]['title'];
        }

        //insert header
        $rez[] = $colTitles;

        while (!empty($results['data'])) {
            foreach ($results['data'] as $r) {
                $record = [];
                foreach ($columns as $colName => $col) {

                    if (@$col['xtype'] == 'datecolumn') {
                        $value = Util\dateISOToMysql(@$r[$colName]);

                        if (!empty($col['format'])) {
                            $value = Util\formatMysqlTime($value, $col['format']);

                        } else {
                            $value = Util\formatMysqlTime($value);
                            $tmp = explode(' ', $value);
                            if (!empty($tmp[1]) && ($tmp[1] == '00:00')) {
                                $value = $tmp[0];
                            }
                        }
                        $record[] = $value;

                    } elseif (strpos($colName, 'date') === false) {
                        if (in_array($colName, ['oid', 'cid', 'uid']) && !empty($r[$colName])) {
                            $record[] = User::getDisplayName($r[$colName]);
                        } else {
                            $record[] = @$r[$colName];
                        }
                    }

                }
                $rez[] = $record;
            }

            if (($p['start'] + $p['rows']) < $results['total']) {
                $p['start'] += $p['rows'];
                $results = $sr->getChildren($p);
            } else {
                $results['data'] = [];
            }
        }

        return $rez;
    }

    /**
     * get csv file
     *
     * @param $p object
     */
    public function getCSV($p)
    {
    	if(isset($p['reportId']))
    	{
    		if ($p['reportId'] === 7442)
    		{
    			ini_set('memory_limit', '1024M');
    			$rez = [];
    			$rez = $this->getFullExport($p);
        		header('Content-Type: text/csv; charset=utf-8');
        		header('Content-Disposition: attachment; filename=Exported_Results_'.date('Y-m-d_Hi').'.csv');
    			header("Pragma: no-cache");
    			header("Expires: 0");
    			echo implode("\n", $rez);

    			exit(0);
    		}
			$rez = [];
			$rez = $this->getCSVContent($p);

			header('Content-Description: File Transfer');
			header("Content-type: application/vnd.ms-excel");
			header("Content-disposition: csv" . date("Y-m-d") . ".csv");
			header("Content-disposition: filename=Exported_Results_".date('Y-m-d_Hi').".csv");
			header('Content-Transfer-Encoding: binary');
			header('Pragma: public');
			echo "\xEF\xBB\xBF"; // UTF-8 BOM
			echo implode("\n", $rez);
			exit(0);
    	}
    	else
		{
			echo ('Export functionality has been disabled.  Please contact the ECMRS Helpdesk for reports or exports.');
			exit(0);
		}
    }

    public function getFullExport($p)
    {
    	$container = Cache::get('symfony.container');
    	$twig = $container->get('twig');
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
    	$objService = new Objects();
    	$reports = new Notifications();
    	$res = $reports->getReport($p);
    	array_unshift($res['data'], $res['colTitles']);
    	$records = $res['data'];
    	$rez[] = implode(',', array_shift($records));
    	$clients = [];
    	foreach ($records as &$r) {
    		$obj = $objService->load($r);
    		$clientId = $obj['data']['id'];
    		$r['id'] = $clientId;
    		//print_r($obj['data']['data']['sys_data']);
    		$contentItems = new ContentItems();
    		$items = $contentItems->getData($clientId);
    		$filePlugin = new \Casebox\CoreBundle\Service\Objects\Plugins\Files();
    		$files = $filePlugin->getData($clientId);

    		// Select only required properties for result
    		$properties = [
    				'primarylanguage',
    				'englishspeaker',
    				'addresstype'
    		];
    		foreach ($properties as $property) {
    			unset($r[$property]);
    			if (!empty($obj['data']['data']['_' . $property])) {
    				$objn = Objects::getCachedObject((is_array($obj['data']['data']['_' . $property])?$obj['data']['data']['_' . $property]['value']:$obj['data']['data']['_' . $property]));
    				$r[$property] = empty($objn ) ? '' : $objn ->getHtmlSafeName();
    			}
    		}


			if (!empty($obj['data']['data']['_location_type']) && !empty(Objects::getCachedObject(is_array($obj['data']['data']['_location_type']) ? $obj['data']['data']['_location_type']['value'] : $obj['data']['data']['_location_type'])))
			{
				$location = $objService->load(['id' => is_array($obj['data']['data']['_location_type']) ? $obj['data']['data']['_location_type']['value'] : $obj['data']['data']['_location_type']]);
				$r['_locationname'] = $location['data']['data']['_locationname'];
				$r['_locationtype'] = $location['data']['data']['_locationtype'];
				$r['_locationcity'] = $location['data']['data']['_locationcity'];
				$r['_locationstate'] = isset($location['data']['data']['_locationstate'])?$location['data']['data']['_locationstate']:'';
				$r['_locationzipcode'] = isset($location['data']['data']['_locationzipcode'])?$location['data']['data']['_locationzipcode']:'';
				$r['_locationcounty'] = $location['data']['data']['_locationcounty'];
				$r['_locationregion'] = $location['data']['data']['_locationregion'];
			}

    		$caseManagerId = !empty($obj['data']['data']['assigned'])?$obj['data']['data']['assigned']:$obj['data']['cid'];
    		$r['casemanagerid'] = $caseManagerId;
    		$r['task_u_assignee'] = User::getDisplayName($caseManagerId);
    		$r['casemanageremail'] = User::getEmail($caseManagerId);
    		$r['alias'] = isset($obj['data']['data']['_alias'])?$obj['data']['data']['_alias']:'';
    		$r['suffix'] = isset($obj['data']['data']['_suffix'])?$obj['data']['data']['_suffix']:'';
    		$r['clientage'] = isset($obj['data']['data']['_clientage'])? (string) $obj['data']['data']['_clientage']:'';
    		foreach ($files['data'] as $file) { //consent form signed
    			$r['datereleasesigned'] = substr($file['cdate'],0,10);
    		}

    		$r['casenote'] = '';
    		$familyMemberCount=1;
    		$referralCount=1;
    		foreach ($items['data'] as $item) {
    			if ($item['template_id'] == 311) //address
    			{
    				$address = $objService->load($item);
    				$properties = [
    						'addresstype'
    				];
    				foreach ($properties as $property) {
    					unset($r['postaddresstype_s']);
    					if (!empty($address['data']['data']['_' . $property])) {
    						$objn = Objects::getCachedObject((is_array($address['data']['data']['_' . $property])?$address['data']['data']['_' . $property]['value']:$address['data']['data']['_' . $property]));
    						$r['postaddresstype_s'] = empty($objn ) ? '' : $objn ->getHtmlSafeName();
    					}
    				}
    				//$r['postaddresstype_s'] = 'hey';// $address['data']['data']['_addresstype'];
    				$r['postzipcode_s'] = isset($address['data']['data']['_zip'])?$address['data']['data']['_zip']:'';
    				$r['poststate_s'] = isset($address['data']['data']['_state'])?$address['data']['data']['_state']:'';
    				$r['postcity_s'] = isset($address['data']['data']['_city'])?$address['data']['data']['_city']:'';
    				$r['postaddressone_s'] = isset($address['data']['data']['_addressone'])?$address['data']['data']['_addressone']:'';
    				$r['postaddresstwo_s'] = isset($address['data']['data']['_addresstwo'])?$address['data']['data']['_addresstwo']:'';
    				$r['postcounty_s'] = isset($address['data']['data']['_county'])?$address['data']['data']['_county']:'';
    				$r['postlatlon_s'] = isset($address['data']['data']['_latlon'])?$address['data']['data']['_latlon']:'';
    			}
    			elseif ($item['template_id'] == 289) //family member
    			{
    				$fm = $objService->load($item);
    				if (isset($fm['data']['data']['_firstname']))
    				{
    					$properties = [
    							'relationship',
    							'gender',
    							'race',
    							'ethnicity',
    							'alternatecontact'
    					];
    					foreach ($properties as $property) {
    						unset($r['fm_'.$property.$familyMemberCount]);
    						if (!empty($fm['data']['data']['_' . $property])) {
    							$objn = Objects::getCachedObject((is_array($fm['data']['data']['_' . $property])?$fm['data']['data']['_' . $property]['value']:$fm['data']['data']['_' . $property]));
    							$r['fm_'.$property.$familyMemberCount] = empty($objn ) ? '' : $objn ->getHtmlSafeName();
    						}
    					}


    					$r['fm_firstname'.$familyMemberCount] = $fm['data']['data']['_firstname'];
    					$r['fm_lastname'.$familyMemberCount] = isset($fm['data']['data']['_lastname'])?$fm['data']['data']['_lastname']:'';
    					$r['fm_middlename'.$familyMemberCount] = isset($fm['data']['data']['_middlename'])?$fm['data']['data']['_middlename']:'';
    					$r['fm_age'.$familyMemberCount] = isset($fm['data']['data']['_age'])?(string) $fm['data']['data']['_age']:'';
    					//$r['fm_relationship'.$familyMemberCount] = $fm['data']['data']['_relationship'];
    					//$r['fm_gender'.$familyMemberCount] = $fm['data']['data']['_gender'];
    					//$r['fm_race'.$familyMemberCount] = $fm['data']['data']['_race'];
    					//$r['fm_ethnicity'.$familyMemberCount] = $fm['data']['data']['_ethnicity'];

    					//$r['fm_alternatecontact'.$familyMemberCount] = $fm['data']['data']['_alternatecontact'];


    					if (isset($fm['data']['data']['_alternatecontact']['childs']['_bestphonenumber']))
    					{
    						$r['fm_alternatecontactbestphonenumber'.$familyMemberCount] = $fm['data']['data']['_alternatecontact']['childs']['_bestphonenumber'];
    					}
    					if (isset($fm['data']['data']['_alternatecontact']['childs']['_otherphonenumber']))
    					{
    						$r['fm_alternatecontactotherphonenumber'.$familyMemberCount] = $fm['data']['data']['_alternatecontact']['childs']['_otherphonenumber'];
    					}
    					$familyMemberCount++;
    				}
    			}
    			elseif ($item['template_id'] ==527) //Casenote
    			{
    				$fm = $objService->load($item);
    				$r['casenote'] .= (isset($fm['data']['cdate'])?'['.$fm['data']['cdate']. '] ':'').(isset($fm['data']['data']['_casenote'])?$fm['data']['data']['_casenote']:'');
    			}
    			elseif ($item['template_id'] == 607)
    			{
    				$comments = '';
    				$service = $objService->load($item);
    				if (isset($service['data']['uid']) && $service['data']['uid'] != 1)
    				{
    					$referralType = Objects::getCachedObject($service['data']['data']['_referraltype']['value']);
    					$refferalTypeValue = empty($referralType) ? 'N/A' : $referralType->getHtmlSafeName();
    					$referralSubType = Objects::getCachedObject($service['data']['data']['_referraltype']['childs']['_referralservice']);
    					$result = empty($service['data']['data']['_result'])?'':Objects::getCachedObject($service['data']['data']['_result']);
    					$refferalSubTypeValue = empty($referralSubType) ? 'N/A' : $referralSubType->getHtmlSafeName();
    					if (!empty($service['data']['data']['_provider']) && !empty(Objects::getCachedObject($service['data']['data']['_provider'])))
    					{
    						$resource = $objService->load(['id' => $service['data']['data']['_provider']]);
    					}
    					else
    					{
    						unset($resource);
    					}
    					//$resourceValue = empty($resource) ? 'N/A' : $resource->getHtmlSafeName();
    					//print_r($service);
    					if (!empty($service['data']['data']['_commentgroup']))
    					{
    						foreach ($service['data']['data']['_commentgroup'] as $key) {
    							if (!empty($key['childs']))
    							{
    								$comments = $comments. isset($key['childs']['_comments'])?!empty($key['childs']['_comments'])?$key['childs']['_comments']:'':'' . (isset($key['childs']['_commentdate'])?' ('.Util\formatMysqlDate($key['childs']['_commentdate'], Util\getOption('short_date_format')):'').'),';
    							}
    						}
    						$comments = trim($comments,',') .(!empty($service['data']['data']['_commentgroup']['childs']['_comments'])?$service['data']['data']['_commentgroup']['childs']['_comments']:''). ' ('.(!empty($service['data']['data']['_commentgroup']['childs']['_commentdate'])?Util\formatMysqlDate($service['data']['data']['_commentgroup']['childs']['_commentdate'], Util\getOption('short_date_format')):'').'),';
    					}
    					$r['_referraldate'.$referralCount]  = isset($service['data']['data']['_referraldate'])?$service['data']['data']['_referraldate']:'';
    					$r['_referraltypename'.$referralCount] = $refferalTypeValue;
    					$r['_refferalservicename'.$referralCount] = $refferalSubTypeValue;
    					$r['_provider'.$referralCount] = empty($service['data']['data']['_provider'])?'':$service['data']['data']['_provider'];
    					$r['_resourcename'.$referralCount] = empty($resource) ? 'N/A' : $resource['data']['data']['_providername'];
    					$r['_providercontact'.$referralCount] = empty($resource) ? 'N/A' : (!empty($resource['data']['data']['_streetaddress'])?$resource['data']['data']['_streetaddress']:''). ' ' .(!empty($resource['data']['data']['_city'])?$resource['data']['data']['_city']:''). ' ' .(!empty($resource['data']['data']['_state'])?$resource['data']['data']['_state']:''). ' ' .(!empty($resource['data']['data']['_zipcode'])?$resource['data']['data']['_zipcode']:''). ' ' .(!empty($resource['data']['data']['_phonenumbers'])?$resource['data']['data']['_phonenumbers']:'');
    					$r['_appointmentdate'.$referralCount] = (!empty($service['data']['data']['_appointmentdate'])?Util\formatMysqlDate($service['data']['data']['_appointmentdate'], Util\getOption('short_date_format')):'');
    					$r['_appointmenttime'.$referralCount] = (!empty($service['data']['data']['_appointmenttime'])?$service['data']['data']['_appointmenttime']:'');
    					$r['_comments'.$referralCount] = str_replace('()','',trim($comments,','));
    					$r['_resultname'.$referralCount] = empty($result) ? '' : $result->getHtmlSafeName();
    					$referralCount++;
    /*		Cache::get('symfony.container')->get('logger')->error(
			'sup',
			$r
		);	*/
    				}
    			}
    			else {
    				if ($item['template_id'] ==440) //Housing
    				{
    					$fm = $objService->load($item);
    					$properties = [
    							'clientdamagerating',
    							'predisasterliving'
    					];
    					foreach ($properties as $property) {
    						unset($obj['data'][$property]);
    						if (isset($fm['data']['data']['_' . $property]))
    						{
    						if ($fm['data']['data']['_' . $property] != null) {
    							$objn = Objects::getCachedObject((is_array($fm['data']['data']['_' . $property])?$fm['data']['data']['_' . $property]['value']:$fm['data']['data']['_' . $property]));
    							$obj['data'][$property] = empty($objn ) ? '' :  str_replace('Room Apartment','Room, Apartment',$objn ->getHtmlSafeName());
    						}
    						}
    					}
    					if (isset($fm['data']['data']['_clientdamagerating']['childs']['_otherdamageassessment']))
    					{
    						$obj['data']['clientdamageratingother'] = $fm['data']['data']['_clientdamagerating']['childs']['_otherdamageassessment'];
    					}
    				}
    				$assessment = $objService->load ( $item );
    				$assessmentData = $objService->getPreview ( $assessment ['data'] ['id'] );
    				$r[$assessment ['data'] ['template_id']] = str_replace ( '<td class="prop-val">', ':',
    						str_replace('</tbody></table>','',
    								str_replace('<br />','',
    										str_replace('</td>','',
    												str_replace('</tr>',"\n",
    														str_replace('<ul class="clean"><li class="icon-padding fa fa-sticky-note fa-fl">','',
    																str_replace('</li></ul>','',str_replace('<tr><td class="prop-key">','',
    																		str_replace('<table class="obj-preview"><tbody>','',$assessmentData[0])))))))));

    			}
    		}
    	}
    		foreach ($records as &$r) {
    			$record = [];
    			foreach ($res['colOrder'] as $t) {
    				if (isset($r[$t]))
    				{
    					if (is_string($r[$t]))
    					{
    						$t = strip_tags($r[$t]);
    					}
    					else
    					{
    					$t = '';
    					}
    				}
    				else
    				{
    					$t = '';
    				}

    				if (!empty($t) && !is_numeric($t)) {
    					$t = str_replace(
    							[
    									'"',
    									"\n",
    									"\r",
    							],
    							[
    									'""',
    									'\n',
    									'\r',
    							],
    							$t
    							);
    					$t = '"'.$t.'"';
    				}
    				$record[] = $t;
    			}

    			$rez[] = implode(',', $record);
    		}
    	return $rez;
    }




    public function getXML($p)
    {
    	$container = Cache::get('symfony.container');
    	$twig = $container->get('twig');
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
    	$objService = new Objects();
    	$reports = new Notifications();
    	$res = $reports->getReport($p);
    	array_unshift($res['data'], $res['colTitles']);
    	$records = $res['data'];
    	$rez[] = implode(',', array_shift($records));
    	$clients = [];
    	foreach ($records as &$r) {
    		$obj = $objService->load($r);
    		$clientId = $obj['data']['id'];
    		//print_r($obj['data']['data']['sys_data']);
    		$obj['data']['cdate'] = Util\dateMysqlToISO($obj['data']['cdate']);
    		$contentItems = new ContentItems();
    		$items = $contentItems->getData($clientId);
    		$filePlugin = new \Casebox\CoreBundle\Service\Objects\Plugins\Files();
    		$files = $filePlugin->getData($clientId);

    		$femaNumber = isset($obj['data']['data']['_femanumber'])?str_replace('-','',$obj['data']['data']['_femanumber']):null;

    		if (!empty($femaNumber)) {
    			if(!preg_match('/^[1-9]\d{9}$/', $femaNumber))
    				$femaNumber = null;
    		}
    		$obj['data']['femanumber'] = $femaNumber;


    		// Select only required properties for result
    		$properties = [
    				'race',
    				'gender',
    				'maritalstatus',
    				'ethnicity',
    				'language',
    				'fematier',
    				'headofhousehold',
    			 'location_type',
    			 'primarylanguage',
    			 'englishspeaker'
    		];
    		foreach ($properties as $property) {
    			unset($obj['data'][$property]);
    			if (!empty($obj['data']['data']['_' . $property])) {
    				$objn = Objects::getCachedObject((is_array($obj['data']['data']['_' . $property])?$obj['data']['data']['_' . $property]['value']:$obj['data']['data']['_' . $property]));
    				$obj['data'][$property] = empty($objn ) ? '' : $objn ->getHtmlSafeName();
    			}
    		}
    		if ($obj['data']['data']['_birthdate'] != null) {
    			$obj['data']['birthdate'] = substr($obj['data']['data']['_birthdate'],0,10);
    		}

    		$arrayproperties = [
    				'at_risk_population',
    				'identified_unmet_needs'
    		];
    		$obj['data']['identified_unmet_needs'] = '';
    		foreach ($arrayproperties as $property) {
    			$values = !empty($obj['data']['data'][$property])?$obj['data']['data'][$property]:null;
    			if ($values != null) {
    				$values = Util\toNumericArray($values);
    				foreach ($values as $v) {
    					$objss = Objects::getCachedObject($v);
    					$obj['data']['identified_unmet_needs'] .= empty($objss) ? '' : ','.str_replace('Yes - ','',$objss->getHtmlSafeName());
    				}
    			}
    		}
    		$caseManagerId = !empty($obj['data']['data']['assigned'])?$obj['data']['data']['assigned']:$obj['data']['cid'];
    		$obj['data']['casemanagerid'] = $caseManagerId;
    		$obj['data']['casemanagername'] = User::getDisplayName($caseManagerId);
    		$obj['data']['casemanageremail'] = User::getEmail($caseManagerId);
    		$obj['data']['identified_unmet_needs'] = trim($obj['data']['identified_unmet_needs'],',');
    		$obj['data']['disastername'] = $configService->get('disaster_declaration_number');

    		//$location = isset($obj['data']['data']['_location_type'])?$obj['data']['data']['_femanumber']:null;
    		if (!empty($obj['data']['data']['_fulladdress']))
    		{
    			$addresscomponents = explode(",", $obj['data']['data']['_fulladdress']);
    			$address = [];
    			$address['data']['id'] = $clientId;
    			$address['data']['cdate'] = Util\dateMysqlToISO($obj['data']['cdate']);
    			$address['data']['zipcode'] = '99999';
    			$address['data']['data']['_addresstwo'] = $obj['data']['data']['_addresstwo'];
    			array_pop($addresscomponents);
    			$address['data']['data']['_state'] = trim(array_pop($addresscomponents));
    			$address['data']['data']['_city'] = trim(array_pop($addresscomponents));
    			$address['data']['data']['_addressone'] = join(' ', $addresscomponents);
    			$obj['data']['addresses'][] = $address['data'];
    		}
    		$obj['data']['narrative'] = '';

    		foreach ($files['data'] as $file) { //consent form signed
    			$obj['data']['datereleasesigned'] = substr($file['cdate'],0,10);
    		}

    		foreach ($items['data'] as $item) {
    			if ($item['template_id'] == 311) //address
    			{
    				$address = $objService->load($item);
    				$address['data']['cdate'] = Util\dateMysqlToISO($address['data']['cdate']);
    				$address['data']['zipcode'] = '99999';
    				$addresscomponents = explode(",", $address['data']['data']['_fulladdress']);
    				array_pop($addresscomponents);
    				$address['data']['data']['_state'] = trim(array_pop($addresscomponents));
    				$address['data']['data']['_city'] = trim(array_pop($addresscomponents));
    				$address['data']['data']['_addressone'] = join(' ', $addresscomponents);
    				$obj['data']['addresses'][] = $address['data'];
    			}
    			elseif ($item['template_id'] == 289) //family member
    			{
    				$fm = $objService->load($item);
    				if (isset($fm['data']['data']['_alternatecontact']['childs']['_bestphonenumber']))
    				{
    					$obj['data']['alternatecontactphonenumber'] = $fm['data']['data']['_alternatecontact']['childs']['_bestphonenumber'];
    				}
    				if (isset($fm['data']['data']['_alternatecontact']['childs']['_otherphonenumber']))
    				{
    					$obj['data']['alternatecontactotherphonenumber'] = $fm['data']['data']['_alternatecontact']['childs']['_otherphonenumber'];
    				}
    			}
    			elseif ($item['template_id'] ==440) //Housing
    			{
    				$fm = $objService->load($item);
    				$properties = [
    						'clientdamagerating',
    						'predisasterliving'
    				];
    				foreach ($properties as $property) {
    					unset($obj['data'][$property]);
    					if ($fm['data']['data']['_' . $property] != null) {
    						$objn = Objects::getCachedObject((is_array($fm['data']['data']['_' . $property])?$fm['data']['data']['_' . $property]['value']:$fm['data']['data']['_' . $property]));
    						$obj['data'][$property] = empty($objn ) ? '' :  str_replace('Room Apartment','Room, Apartment',$objn ->getHtmlSafeName());
    					}
    				}
    				if (isset($fm['data']['data']['_clientdamagerating']['childs']['_otherdamageassessment']))
    				{
    					$obj['data']['clientdamageratingother'] = $fm['data']['data']['_clientdamagerating']['childs']['_otherdamageassessment'];
    				}
    			}
    			elseif ($item['template_id'] ==527) //Casenote
    			{
    				$fm = $objService->load($item);
    				$obj['data']['narrative'] .= '['.$fm['data']['cdate']. '] '.$fm['data']['data']['_casenote'];
    			}
    			elseif ($item['template_id'] ==607) //referral
    			{
    				$fm = $objService->load($item);
    				$fm['data']['cdate'] = Util\dateMysqlToISO($fm['data']['cdate']);
    				//$fm['data']['description'] = ;
    				$obj['data']['services'][] = $fm['data'];
    				if (isset($fm['data']['data']['_provider']))
    				{
    					$obj['data']['referrals'][] = $fm['data'];
    				}
    			}
    		}
    		$clients[] = $obj['data'];
    	}
    	$vars = [
    			'clients' => $clients,
    			'disasterEventDate' => $configService->get('disaster_date'),
    			'disasterName' => $configService->get('disaster_name'),
    			'generationStamp' => date('Y-m-d\TH:i:s\Z'),
    			'agencyId' => !empty($configService->get('agency_id'))?$configService->get('agency_id'):'262626',
    			'agencyName' => !empty($configService->get('agency_name'))?$configService->get('agency_name'):'OHSEPR',
    			'caseManagerEmail' => !empty($configService->get('agency_name'))?$configService->get('disaster_email'):'OHSEPR',
    			'disasterDRONumber' => $configService->get('disaster_deron_number'),
    			'cm_phone' =>$configService->get('disaster_phone_number'),
    	];
    	$html = $twig->render('CaseboxCoreBundle:email:dcds_1_1.xml.twig', $vars);
    	return $html;
    }

  public function getExport($p)
    {
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        $zipname = $configService->get('files_dir').DIRECTORY_SEPARATOR.time().'.pdf';//.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.time().'.pdf';
		$zip = new ZipArchive;
		$zip->open($zipname, ZipArchive::CREATE);
    	$reports = new Notifications();
			$res = $reports->getReport($p);
			array_unshift($res['data'], $res['colTitles']);
			$records = $res['data'];
       		$rez[] = implode(',', array_shift($records));
		$count = 0;
		$objService = new Objects();
        foreach ($records as &$r) {
            	$record = [];
            	foreach ($res['colOrder'] as $t) {
                  if (isset($r[$t]))
            			{
            				$t = strip_tags($r[$t]);
            			}
            			else
            			{
            				$t = '';
            			}

                	if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(',', $record);
        if ($count < 100)
        {
        $clientId = $r['id'];
    	$filePlugin = new \Casebox\CoreBundle\Service\Objects\Plugins\Files();
		$files = $filePlugin->getData($clientId);

		foreach ($files['data'] as $file) {
			$fileId = $file['id'];
      $fid = (isset($fileId)?Files::read($fileId):null);
  		if (!empty($fid)) {
        $content = FilesContent::read($fid['content_id']);
        $fileContent = $configService->get('files_dir').$content['path'].DIRECTORY_SEPARATOR.$content['id'];
        $zip->addFile($fileContent, $clientId.'_'.$file['name']);
  		}
		}
		$export = new Instance();

		$html = $export->getPDFContent($clientId,$r['zipcode_s']);
		//echo($html);

		$dompdf = new Dompdf();
		$dompdf->loadHtml($html);
		$dompdf->setPaper('A4', 'landscape');
		$dompdf->render();
		$recoveryPlan = $dompdf->output();

		$zip->addFromString($clientId.'recoveryplan.pdf',$recoveryPlan);
		$data = [
				'pid' => $clientId,
				'title' => 'Record Note',
				'template_id' => 527,
				'path' => 'Tree/System/Clients',
				'view' => 'edit',
				'name' => 'New Record Note',
				'data' => [
						'_entrydate' => date('Y-m-d\TH:i:s\Z'),
						'_casenote' => 'Transfer from Export',
						'_notetype' => [
								'value'=>603,
								'childs' =>[
										'_transferlocation' => 'Transfer'
								]
						]
				],
		];
		$newNote =$objService->create($data);
		  } //count less than 100
		  $count++;
		}
		$zip->addFromString('records.csv',implode("\n", $rez));
				$zip->close();

        header('Content-Type: application/zip; charset=utf-8');
        header('Content-Disposition: attachment; filename='.date('Y-m-d_Hi').'.zip');
        header("Pragma: no-cache");
        header("Expires: 0");
        readfile($zipname);
        exit(0);
    }

	public function getPDF($p)
	{

		$container = Cache::get('symfony.container');
		$twig = $container->get('twig');
		$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        // Check if object id is numeric
        if (is_numeric($p)) {
        	$html = $this->getPDFContent($p);
        }
		else
		{
			if(isset($p['reportId']))
			{
			if ($p['reportId'] === 2727)
			{
				$this->getExport($p);
			}
			}
			$reports = new Notifications();
			$res = $reports->getReport($p);
			array_unshift($res['data'], $res['colTitles']);
			$records = $res['data'];
       		$rez[] = implode(',', array_shift($records));

        	foreach ($records as &$r) {
            	$record = [];
            	foreach ($res['colOrder'] as $t) {
                	$t = strip_tags($r[$t]);

                	if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(',', $record);
        	}

			date_default_timezone_set("America/New_York");

			$vars = [
				'title' => $res['title'],
				'columnTitle'=> $res['columns'],
				'services'=>$records,
				'currentDate'=> date("m/d/Y") .  ' ' .  date("h:i:sa")
			];
			$html = $twig->render('CaseboxCoreBundle:email:reports.html.twig', $vars);
		}
		$dompdf = new Dompdf();
		$dompdf->loadHtml($html);

		// (Optional) Setup the paper size and orientation
		$dompdf->setPaper('A4', 'landscape');

		// Render the HTML as PDF
		$dompdf->render();
		$dompdf->stream('recovery_plan'.$p, array("Attachment" => false));

		exit(0);
	}

    public function getHTML($p)
    {
        $rez = [];
        $records = $this->getData($p);

        $rez[] = '<th>'.implode('</th><th>', array_shift($records)).'</th>';

        foreach ($records as $r) {
            $record = [];
            foreach ($r as $t) {
                $t = strip_tags($t);
                $record[] = $t;
            }
            $rez[] = '<td>'.implode('</td><td>', $record).'</td>';
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename=Exported_Results_'.date('Y-m-d_Hi').'.html');
        header("Pragma: no-cache");
        header("Expires: 0");
        echo '<!DOCTYPE html>
            <html>
            <header>
                <meta http-equiv="content-type" content="text/html; charset=utf-8" >
            </header>
            <body>
            <table border="1" style="border-collapse: collapse">
            <tr>';
        echo implode("</tr>\n<tr>", $rez);
        echo '</tr></table></body></html>';
    }

    public function getCSVContent($p)
    {
    	$rez = [];
    	if(isset($p['reportId']))
		{
			$reports = new Notifications();
			$res = $reports->getReport($p);
			array_unshift($res['data'], $res['colTitles']);
			$records = $res['data'];
       		$rez[] = implode(',', array_shift($records));

        	foreach ($records as &$r) {
            	$record = [];
            	foreach ($res['colOrder'] as $t) {
			if (isset($r[$t]))
			{
				$t = strip_tags($r[$t]);
			}
			else
			{
				$t = '';
			}

                	if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(',', $record);
        	}

		}
		else
		{
			$records = $this->getData($p);
			       $rez[] = implode(',', array_shift($records));

        foreach ($records as &$r) {
            $record = [];
            foreach ($r as $t) {
                $t = strip_tags($t);

                if (!empty($t) && !is_numeric($t)) {
                    $t = str_replace(
                        [
                            '"',
                            "\n",
                            "\r",
                        ],
                        [
                            '""',
                            '\n',
                            '\r',
                        ],
                        $t
                    );
                    $t = '"'.$t.'"';
                }
                $record[] = $t;
            }

            $rez[] = implode(',', $record);
        }
		}
		return $rez;
    }

        public function getPDFContent($p, $zipcode="", $language="")
    {
    	$container = Cache::get('symfony.container');
		$twig = $container->get('twig');
		$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        	$services = null;
			$objService = new Objects();
			$obj = $objService->load(['id' => $p]);
			//print_r($obj['data']['data']['sys_data']);

			$contentItems = new ContentItems();
			$items = $contentItems->getData($p);
			$femaNumber = isset($obj['data']['data']['_femanumber'])?$obj['data']['data']['_femanumber']:null;

			if (empty($femaNumber)) {
				$femaNumber = 'N/A';
			}
			if (!empty($obj['data']['data']['_location_type']) && !empty(Objects::getCachedObject(is_array($obj['data']['data']['_location_type']) ? $obj['data']['data']['_location_type']['value'] : $obj['data']['data']['_location_type'])))
			{
				$location = $objService->load(['id' => is_array($obj['data']['data']['_location_type']) ? $obj['data']['data']['_location_type']['value'] : $obj['data']['data']['_location_type']]);
			}
			 foreach ($items['data'] as $item) {
					if ($item['template_id'] == 607)
					{
						 $comments = '';
						 $service = $objService->load($item);
						 $referralType = Objects::getCachedObject($service['data']['data']['_referraltype']['value']);
						 $refferalTypeValue = empty($referralType) ? 'N/A' : $referralType->getHtmlSafeName();
						 $referralSubType = Objects::getCachedObject($service['data']['data']['_referraltype']['childs']['_referralservice']);
						 $refferalSubTypeValue = empty($referralSubType) ? 'N/A' : $referralSubType->getHtmlSafeName();
						 if (!empty($service['data']['data']['_provider']) && !empty(Objects::getCachedObject($service['data']['data']['_provider'])))
						 {
						    $resource = $objService->load(['id' => $service['data']['data']['_provider']]);
						 }
						 else
						 {
							unset($resource);
						 }
						 //$resourceValue = empty($resource) ? 'N/A' : $resource->getHtmlSafeName();
						 //print_r($service);
						 if (!empty($service['data']['data']['_commentgroup']))
						 {
							foreach ($service['data']['data']['_commentgroup'] as $key) {
							if (!empty($key['childs']))
								{
								 $comments = $comments. isset($key['childs']['_comments'])?!empty($key['childs']['_comments'])?$key['childs']['_comments']:'':'' . ' ('.Util\formatMysqlDate($key['childs']['_commentdate'], Util\getOption('short_date_format')).'),';
								 }
							}
							$comments = trim($comments,',') .(!empty($service['data']['data']['_commentgroup']['childs']['_comments'])?$service['data']['data']['_commentgroup']['childs']['_comments']:''). ' ('.(!empty($service['data']['data']['_commentgroup']['childs']['_commentdate'])?Util\formatMysqlDate($service['data']['data']['_commentgroup']['childs']['_commentdate'], Util\getOption('short_date_format')):'').'),';
						 }
						 $services[] = [
							'referraltype' => $refferalTypeValue,
							'referralsubtype' => $refferalSubTypeValue,
							'resourceagencyname' => empty($resource) ? 'N/A' : $resource['data']['data']['_providername'],
							'resourceagencycontactinformation' => empty($resource) ? 'N/A' : (!empty($resource['data']['data']['_streetaddress'])?$resource['data']['data']['_streetaddress']:''). ' ' .(!empty($resource['data']['data']['_city'])?$resource['data']['data']['_city']:''). ' ' .(!empty($resource['data']['data']['_state'])?$resource['data']['data']['_state']:''). ' ' .(!empty($resource['data']['data']['_zipcode'])?$resource['data']['data']['_zipcode']:''). ' ' .(!empty($resource['data']['data']['_phonenumbers'])?$resource['data']['data']['_phonenumbers']:''),
							'pointofcontact' => (empty($resource) || empty($resource['data']['data']['_pointofcontact']))? 'N/A' : $resource['data']['data']['_pointofcontact'],
							'referralappointmentdatetime' =>(!empty($service['data']['data']['_appointmentdate'])?Util\formatMysqlDate($service['data']['data']['_appointmentdate'], Util\getOption('short_date_format')):''). ' ' . (!empty($service['data']['data']['_appointmenttime'])?$service['data']['data']['_appointmenttime']:''),
							'targetcompletiondate' =>  !empty($service['data']['data']['_targetcompletiondate'])?Util\formatMysqlDate($service['data']['data']['_targetcompletiondate'], Util\getOption('short_date_format')):null,
							'comments' => str_replace('()','',trim($comments,','))
						];
					}
				}
			$v = isset($obj['data']['data']['assigned'])?$obj['data']['data']['assigned']:null;

			if (empty($v)) {
				$assigned = 'N/A';
			}else
			{
				 $assigned = User::getDisplayName($v);
				 //echo(User::getUserData($v)['phone']);
			}

			$vars = [
				'client_name' => empty($obj['data']['data']['_firstname'])?'No First Name':$obj['data']['data']['_firstname'] . ' ' . !empty($obj['data']['data']['_lastname'])?$obj['data']['data']['_lastname']:'No Last Name',
				'client_address' => (!empty($obj['data']['data']['_fulladdress'])?str_replace(", United States","",$obj['data']['data']['_fulladdress']):''). ' ' . $zipcode, //will replace with real zipcode after refactor
				'client_email' =>!empty($obj['data']['data']['_emailaddress'])?$obj['data']['data']['_emailaddress']:'',
				'client_phonenumber' =>!empty($obj['data']['data']['_phonenumber'])?$obj['data']['data']['_phonenumber']:'',
				'client_secondaryphonenumber' => !empty($obj['data']['data']['_otherphonenumber'])?$obj['data']['data']['_otherphonenumber']:'',
				'client_lastname' => empty($obj['data']['data']['_lastname'])?'No Last Name':$obj['data']['data']['_lastname'],
				'client_firstname' => empty($obj['data']['data']['_firstname'])?'No First Name':$obj['data']['data']['_firstname'],
				'disaster_declaration_number' => $configService->get('disaster_declaration_number'),
				'disaster_site_address' => empty($location)?'N/A': $location['data']['data']['_locationname'] .' - '.empty($location['data']['data']['_locationaddress'])?'':$location['data']['data']['_locationaddress'].' '.$location['data']['data']['_locationcity'] ,
				'cm_phone' => $configService->get('disaster_phone_number'),
				'cm_assigned' => $assigned,
				'cm_phone' =>$configService->get('disaster_phone_number'),
				'plan_creation_date' => date("F j, Y, g:i a"),
				'fema_registration_number' => $femaNumber,
				'client_id' => $obj['data']['id'],
				'services' =>
					$services,
			];
			$html = $twig->render('CaseboxCoreBundle:email:recovery-plan'.$language.'.html.twig', $vars);
		return $html;
    }

}
