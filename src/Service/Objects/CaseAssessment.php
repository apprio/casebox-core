<?php

namespace Casebox\CoreBundle\Service\Objects;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\Solr\Client;
use Casebox\CoreBundle\Service\DataModel as DM;

/**
 * Template class
 */
class CaseAssessment extends CBObject
{
	public function create($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }
		$this->data = $p;
		$this->setParamsFromData($p);

		return parent::create($p);
    }

    public function update($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }

        $this->unSetParamsFromData($p);  //up
        $this->data = $p;
        $this->setParamsFromData($p);
        return parent::update($p);
    }

    public function deleteCustomData($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }
        $this->unSetParamsFromData($p);
        $this->setParamsFromDelete($p);

        return parent::deleteCustomData($p);

	}


    protected function unSetParamsFromData(&$p)
    {
		$caseId = $p['pid'];
		$templateId = $p['template_id'];
		$objectId = $p['id'];

		if ($caseId) {
            $case = Objects::getCachedObject($caseId);
			$caseData = &$case->data;
			$caseSd = &$caseData['sys_data'];

			/* add some values to the parent */

			$tpl = $this->getTemplate();

			if (!empty($tpl)) {
				$fields = $tpl->getFields();

				foreach ($fields as $f) {

					if (!empty($f['solr_column_name'])) {
						$sfn = $f['solr_column_name']; // Solr field name
						if (substr($f['solr_column_name'], -3) === '_ss' && isset($caseSd[$sfn]) && isset($this->data['data'][$f['name']]))
							{
							    $v = $this->data['data'][$f['name']];  // May need to verify this works
							    if ($v == null)
							    {
							    	$v = $this->data['data']['_referraltype']['childs']['_referralservice'];
							    }

								if ($v != null)
								{
									$v = is_array($v) ? @$v['value'] : $v;
									if (!is_numeric($v) && !is_array($v))
									{
										$caseSd[$sfn] = array_diff($caseSd[$sfn], [$v]);
									}
									else
									{
										$v = Util\toNumericArray($v);
										foreach ($v as $id) {
											$obj = Objects::getCachedObject($id);
											$object = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
											$caseSd[$sfn] = array_diff($caseSd[$sfn], [$object]);
										}
									}
								}
							}
					}
				}
			}

			$case->updateSysData();
			$solr = new Client();
			$solr->updateTree(['id' => $caseId]);
        }


    }


    protected function setParamsFromData(&$p)
    {
		$caseId = $p['pid'];
		$templateId = $p['template_id'];
		$objectId = isset($p['id'])?$p['id']:null;

		if ($caseId) {
            $case = Objects::getCachedObject($caseId);
			$caseData = &$case->data;
			$caseSd = &$caseData['sys_data'];

			/* add some values to the parent */
			$tpl = $this->getTemplate();

			//Case Notes
			if (!empty($p['data']['_notetype']['value'])) { //
				if ($p['data']['_notetype']['value'] == 526) //close note
				{
					$case->markClosed();
				}
				if ($p['data']['_notetype']['value'] == 523) //FEMA tier
				{
					$femaTier = $p['data']['_notetype']['childs']['_fematier'];
					$caseData['data']['_fematier'] = $femaTier;
					$caseSd['fematier_i'] = $femaTier;
					$obj = Objects::getCachedObject($femaTier);
					$arr = explode(" -", $obj->getHtmlSafeName(), 2);
					$first = $arr[0];
					$caseSd['fematier'] = $first;
					//$caseSd['fematier'] = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
					$case->updateCustomData();
				}
				if ($p['data']['_notetype']['value'] == 603) //transfer note
				{
					$case->markTransitioned();
				}
			}


			if (isset($p['data']['_city']) || isset($p['data']['_state'])
					|| isset($p['data']['_zip'])|| isset($p['data']['_addressone']))
			{
				$p['data']['_fulladdress'] = (isset($p['data']['_addressone'])?
						$p['data']['_addressone'].' ':'') .
						(isset($p['data']['_addresstwo'])?
								$p['data']['_addresstwo'].' ':'') .
								(isset($p['data']['_city'])?
										$p['data']['_city'].' ':'') .
										(isset($p['data']['_state'])?
												$p['data']['_state'].' ':'') .
					(isset($p['data']['_zip'])?
					$p['data']['_zip'].' ':'');

			}

			if (!empty($p['data']['_fulladdress']))
			{
				$results = $this->lookup($p['data']['_fulladdress']);
				if ($results != null)
				{
					$p['data']['_latlon'] = $results['latitude'] .','.$results['longitude'];
					$p['data']['full_address'] = $results['street'];//$results['full_address'];
					$p['data']['_county'] = $results['county'];
					$p['data']['_addressone'] = $results['street_number']. ' ' . $results['street'];
					$p['data']['_city'] = $results['city'];
					$p['data']['_state'] = $results['state'];
					$p['data']['_zip'] = $results['postal_code'];
					$p['data']['_locationtype'] = $results['location_type'];
				}
			}


			//Referrals
			if (!empty($p['data']['_referraltype'])) { //
				if (!empty($objectId))
				{
				    if (isset($caseSd['referrals_started']))
					{
					if (!in_array($objectId, $caseSd['referrals_started']))
					{
						$caseSd['referrals_started'][] = $objectId;
					}
					}
					if (isset($p['data']['_result']))
					{
					if ($p['data']['_result'] != 595 && !empty($p['data']['_result']))
					{
					    if (isset($caseSd['referrals_completed']))
						{
						if (!in_array($objectId, $caseSd['referrals_completed']))
						{
							$caseSd['referrals_completed'][] = $objectId;
						}
						}
					}
					}
				}

						 $referralType = Objects::getCachedObject($p['data']['_referraltype']['value']);
						 $refferalTypeValue = empty($referralType) ? 'N/A' : $referralType->getHtmlSafeName();
						 $referralSubType = Objects::getCachedObject($p['data']['_referraltype']['childs']['_referralservice']);
						 $refferalSubTypeValue = empty($referralSubType) ? 'N/A' : $referralSubType->getHtmlSafeName();

						 $resullt = isset($p['data']['_result'])?Objects::getCachedObject($p['data']['_result']):null;
						 $resulltValue = empty($resullt) ? 'N/A' : $resullt->getHtmlSafeName();

						 $p['data']['_resultname'] = $resulltValue;
						 $p['data']['_refferalservicename'] = $refferalSubTypeValue;
						 $p['data']['_refferaltypename'] = $refferalTypeValue;
						 $objService = new Objects();
						 if (!empty($caseId) && !empty($case))
						 {
						    $location = Objects::getCachedObject($caseData['data']['_location_type']);
						    $p['data']['_clientname'] = $case->getHtmlSafeName();
						    $p['data']['_clientlocation'] = empty($location) ? '' : $location->getHtmlSafeName();
						    $p['data']['_clientcounty'] = isset($caseSd['solr']['county'])?$caseSd['solr']['county']:'N/A';
						 }
						 if (!empty($p['data']['_provider']) && !empty(Objects::getCachedObject($p['data']['_provider'])))
						 {
						    $resource = $objService->load(['id' => $p['data']['_provider']]);
						 	$p['data']['_resourcename'] = empty($resource) ? 'N/A' : $resource['data']['data']['_providername'];
						    $p['data']['_resourcelocation'] = empty($resource) ? 'N/A' : (isset($resource['data']['data']['_city'])?$resource['data']['data']['_city']:'');
						    //$p['data']['_resourcecounty'] = empty($resource) ? 'N/A' : $resource['data']['data']['_city'];
						 }
						 else
						 {
						 	$p['data']['_resourcename'] = 'Not Identified';
						    $p['data']['_resourcelocation'] = '';
						    //$p['data']['_resourcecounty'] = '';
						 }

			}

			//Assessments
			if (!empty($p['data']['_assessmentdate']))
			{
				$caseSd['assessments_needed'] = array_diff($caseSd['assessments_needed'], [$templateId]);
				if (!in_array($templateId, $caseSd['assessments_completed']))
				{
					$caseSd['assessments_completed'][] = $templateId;
				}
			}
			if (!empty($p['data']['_referralneeded'])) { //assessment
			  /*  $caseSd['assessments_needed'] = array_diff($caseSd['assessments_needed'], [$templateId]);
				if (!in_array($templateId, $caseSd['assessments_completed']))
				{
					$caseSd['assessments_completed'][] = $templateId;
				}*/
				if ($p['data']['_referralneeded']['value'] == 686 || $p['data']['_referralneeded'] == 686)
				{
				if (!in_array($templateId, $caseSd['referrals_needed'])) {
					$caseSd['referrals_needed'][] = $templateId;
					if (!empty($p['data']['_referralneeded']['childs']['_referralservice']))
					{
						$services = Util\toNumericArray($p['data']['_referralneeded']['childs']['_referralservice']);
						foreach ($services as $service) {
							$obj = Objects::getCachedObject($service);
							$objData = $obj->getData();

							//"data":{"_referraltype":{"value":1371,"childs":{"_referralservice":1372}},"_referralstatus":595}}
							$data = [
								'pid' => $caseId,
								'title' => 'Referral',
								'template_id' => 607,
								'path' => 'Tree/Clients',
								'view' => 'edit',
								'name' => 'New Referral',
								'data' => [
									'_referraltype' => [
										'value'=>$objData['pid'],
										'childs' =>[
										'_referralservice' => $service
										]
									]
								],
							];
							$objService = new Objects();
							$newReferral =$objService->create($data);
							$caseSd['referrals_started'][] = $newReferral['data']['id'];
						}
					}
				}
				}
				else
				{
					if (isset($caseSd['referrals_needed']))
					{
						if (is_array($caseSd['referrals_needed']))
						{
							$caseSd['referrals_needed'] = array_diff($caseSd['referrals_needed'], [$templateId]);
						}
					}
				}
			}


			//now set current values
			if (!empty($tpl)) {
				$fields = $tpl->getFields();

				foreach ($fields as $f) {

					$values = $this->getFieldValue($f['name']);
					if (!empty($f['solr_column_name'])) {
						$sfn = $f['solr_column_name']; // Solr field name
						if ($values != null) {
							if (substr($f['solr_column_name'], -2) === '_s')
							{
								unset($caseSd[$sfn]);
								$objects = [];
								foreach ($values as $v) {
									$v = is_array($v) ? @$v['value'] : $v;
									$v = Util\toNumericArray($v);
									foreach ($v as $id) {
										$obj = Objects::getCachedObject($id);
										$objects[] = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
									}
								}
								$caseSd[$sfn] = implode(",", $objects);
							}
							else if (substr($f['solr_column_name'], -3) === '_ss')
							{
								if (!isset($caseSd[$sfn]))
								{
									$caseSd[$sfn] = [];
								}
								if (!is_array($caseSd[$sfn]))
								{
									$caseSd[$sfn] = [];
								}
								$objects = [];
								foreach ($values as $v) {
									$v = is_array($v) ? @$v['value'] : $v;
									if ((!is_numeric($v)) && !is_array($v))
									{
										$caseSd[$sfn][] = $v;
									}
									else
									{
										$v = Util\toNumericArray($v);
										foreach ($v as $id) {
											$obj = Objects::getCachedObject($id);
											$object = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
											if (!in_array($object, $caseSd[$sfn])) {
												$caseSd[$sfn][] = strval($object);
											}
										}
									}
								}
							}
							else
							{
								unset($caseSd[$sfn]);
								$caseSd[$sfn] = $values[0]['value'];
							}
						}
					}
				}
			}

			$case->updateSysData();
			$solr = new Client();
			$solr->updateTree(['id' => $caseId]);
        }

    }

	protected function setParamsFromDelete($p)
    {
		$p = $this->data;
		$caseId = $p['pid'];
		$templateId = $p['template_id'];
		$objectId = $p['id'];

        if ($caseId) {
            $case = Objects::getCachedObject($caseId);
			$caseData = &$case->data;
			$caseSd = &$caseData['sys_data'];

			if (isset($caseSd['referrals_completed']))
			{
				$caseSd['referrals_completed'] = array_diff($caseSd['referrals_completed'], [$objectId]);
			}
			if (isset($caseSd['referrals_started']))
			{
				$caseSd['referrals_started'] = array_diff($caseSd['referrals_started'], [$objectId]);
			}
			/* add some values to the parent */
			$tpl = $this->getTemplate();

			if (!empty($tpl)) {
				$fields = $tpl->getFields();

				foreach ($fields as $f) {
					$values = $this->getFieldValue($f['name']);
					if (!empty($f['solr_column_name'])) {
						$sfn = $f['solr_column_name']; // Solr field name
						if (substr($sfn, -3) != '_ss')
						{
							unset($caseSd[$sfn]);
						}
					}
				}
			}
			if (isset($caseSd['assessments_reported']))
			{
				if (in_array($templateId, $caseSd['assessments_reported'])) {
						$caseSd['assessments_needed'][] = $templateId;
				}
			}
			if (isset($caseSd['referrals_needed']))
			{
				if (in_array($templateId, $caseSd['referrals_needed'])) {
						$caseSd['referrals_needed'] = array_diff($caseSd['referrals_needed'], [$templateId]);
				}
			}
			$case->updateSysData();
			$solr = new Client();
			$solr->updateTree(['id' => $caseId]);
        }

    }

    /**
     *
     * http://www.andrew-kirkpatrick.com/2011/10/google-geocoding-api-with-php/
	 *
     */
	protected function lookup($string){

	   $string = str_replace (" ", "+", urlencode($string));
	   $details_url = "http://maps.googleapis.com/maps/api/geocode/json?address=".$string."&sensor=false";

	   $ch = curl_init();
	   curl_setopt($ch, CURLOPT_URL, $details_url);
	   curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	   $response = json_decode(curl_exec($ch), true);

	   // If Status Code is ZERO_RESULTS, OVER_QUERY_LIMIT, REQUEST_DENIED or INVALID_REQUEST
	   if ($response['status'] != 'OK') {
		return null;
	   }

	   //print_r($response);
	   $geometry = $response['results'][0]['geometry'];

	   $location = array();

  foreach ($response['results'][0]['address_components'] as $component) {
    switch ($component['types']) {
      case in_array('street_number', $component['types']):
        $location['street_number'] = $component['long_name'];
      case in_array('route', $component['types']):
        $location['street'] = $component['long_name'];
      case in_array('sublocality', $component['types']):
        $location['sublocality'] = $component['long_name'];
      case in_array('locality', $component['types']):
        $location['locality'] = $component['long_name'];
      case in_array('administrative_area_level_2', $component['types']):
        $location['admin_2'] = $component['long_name'];
      case in_array('administrative_area_level_1', $component['types']):
        $location['admin_1'] = $component['long_name'];
      case in_array('postal_code', $component['types']):
        $location['postal_code'] = $component['long_name'];
      case in_array('country', $component['types']):
        $location['country'] = $component['long_name'];
    }

  }



		$array = array(
			'longitude' => $geometry['location']['lng'],
			'latitude' => $geometry['location']['lat'],
			'location_type' => $geometry['location_type'],
			'street_number' => isset($location['street_number'])?$location['street_number']:'',
			'street' => isset($location['street'])?$location['street']:'',
			'city' => $location['locality'],
			'state' => $location['admin_1'],
			'full_address' => $response['results'][0]['formatted_address'],
			'county' => $location['admin_2'],
			'postal_code' => isset($location['postal_code'])?$location['postal_code']:''
		);

		return $array;

	}


}
