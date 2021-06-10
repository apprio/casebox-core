<?php

namespace Casebox\CoreBundle\Service\Objects;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\Solr\Client;
use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\User;

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

				//Log FEMA Tier
				if (isset($p['data']['_notetype'])){
					if ($p['data']['_notetype'] == 523)
					{
						$caseId = $p['pid'];
						if ($caseId) {
							$case = Objects::getCachedObject($caseId);
							$caseData = &$case->data;
							$caseSd = &$caseData['sys_data'];

			        $owner = $this->getOwner();
			        $userData = User::getUserData($owner);
			        if (isset($caseSd['solr']['fematier'])) {
			          $oldtier = $caseSd['solr']['fematier'];
			        } else {
			          $oldtier = '';
			        }

			        $userRole = $userData['groups'];
			        $userRole = str_replace('315', 'Administrator', $userRole);
			        $userRole = str_replace('575', 'Worker - Level I', $userRole);
			        $userRole = str_replace('576', 'Worker - Level II', $userRole);
			        $userRole = str_replace('30', 'Supervisor', $userRole);
			        $userRole = str_replace('34', 'Resource Manager', $userRole);

			        $this->logDataAction('fematier',
			          array(
			            'date' => date("Y/m/d"),
			            'time' => date("h:i:sa"),
			            'survivorId' => $caseId,
			            'survivorName' => $caseData['data']['_lastname'] . ', ' . $caseData['data']['_firstname'],
			            'fematier' => $p['data']['_notetype']['childs']['_fematier'],
			            'prevfematier' => $oldtier,
			            'userId' => User::getID(),
			            'userFullName' => User::getDisplayName(User::getID()),
			          ));
						}
					}
				}

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
			if (!empty($p['data']['_notetype']['value'])) {
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
				else //Follow Up (246807) note - need to create task
				{
					$caseSd['taskCreated'] = [];
					// Auto Create Task

					$objService = new Objects();
					$name = $caseData['name'];
					$ecmrsId = $caseData['id'];

					if (isset($caseSd['fematier'])) {
						$tier = $caseSd['fematier'];
					}

					if (isset($caseData['data']['assigned'])) {
						$assignee = $caseData['data']['assigned'];
					} else {
						$assignee = '';
					}

					if (isset($tier)) {
						if ($tier == "Tier 4") {
							$importance = 57;
						} else {
							$importance = 56;
						}

						date_default_timezone_set('America/New_York');
						if ($tier == "Tier 1") { //'Y-m-d H:i:s'
							$dueDate = Date('Y-m-d H:i:s', strtotime('+21 days')); // Tier 1 +21 Days
						} elseif ($tier == "Tier 2") {
							$dueDate = Date('Y-m-d H:i:s', strtotime('+14 days')); // Tier 2 +14 Days
						} elseif ($tier == "Tier 3") {
							$dueDate = Date('Y-m-d H:i:s', strtotime('+10 days')); // Tier 3 +10 Days
						} else {
							$dueDate = Date('Y-m-d H:i:s', strtotime('+4 days')); // Tier 4 +7 Days
						}
						$dueTime = Date('H:i:s', time()); //Rounded to nearest quarter hour
					}

						if (!empty($p['data']['_notetype']))
						{
							if ( (in_array('246807', $p['data']['_notetype'])) && ($caseSd['case_status'] != 'Information Only'))
								{
									if (!in_array('_notetype', $caseSd['taskCreated']))
									{
											//CREATE TASK HERE
											$data = [
					                                'pid' => 246835,
					                                'path' => '/Development/System/Tasks/',
					                                'template_id' => 7,
					                                'type' => 'task',
					                                'isNew' => 'true',
					                                'data' => [
					                                    'ecmrs_id' => $ecmrsId, // id
					                                    'survivor_name' => $name, // name
					                                    'task_type' => 248276,
					                                    'time_expended' => '',
					                                    'case' => $ecmrsId, // Linked case
					                                    'task_status' => 1906, // Open
					                                    '_task' => $ecmrsId . ' Follow Up: ' . $tier, // [ECMRS ID autofill} + Follow Up: Tier
					                                    'due_date' => $dueDate, // [time of auto task creation + days by Tier]
					                                    'due_time' => $dueTime, // [time of auto task creation]
					                                    'assigned' => $assignee, // [Whoever is assigned to the case at the time of auto creation]
					                                    'importance' => $importance, // For Tiers 1, 2, 3: [56 - High] , for Tier 4: [57 - CRITICAL]
					                                    'description' => 'Follow up with the linked record due to Tier follow up requirements.' //
					                                ],
					                            ];
					                            $newTask = $objService->create($data);
												$caseSd['taskCreated'][] = '_notetype';
									}
								}
						}


				}
			}
			if (!empty($p['data']['_notetype'])) {
				if ($p['data']['_notetype'] == 250357) { //re-open
					$case->markReopened();
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

			//Secondary Intake
			if ($templateId = 250897) {
				foreach ($p['data'] as $key => $value)
				{
					$caseData['data'][$key] = $value;
				}
				$properties = [
        	'race',
          'gender',
          'maritalstatus',
          'ethnicity',
          'language',
					'clientage',
          'headofhousehold',
					'addresstype',
					'fulladdress',
					'parish'
        ];
        foreach ($properties as $property) {
					unset($caseSd[$property]);
					if ($this->getFieldValue('_' . $property, 0)['value'] != null) {
						$obj = Objects::getCachedObject($this->getFieldValue('_' . $property, 0)['value']);
						if ($property == 'addresstype')
						{
							$caseSd[$property.'primary_s'] = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
						}
						elseif ($property == 'fulladdress')
						{
							$caseSd[$property.'_s'] = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
						}
						else
						{
							$caseSd[$property] = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
						}
        	}
				}

				// Create Tasks
		    $objService = new Objects();

		    $caseTasks = [
		      // undetermined
		      '_gender',
		      '_maritalstatus',
		      '_ethnicity',
		      '_hispanicorigin',
		      '_race',
		      '_englishspeaker',
		      '_primarylanguage',
		      '_addresstype',
		      '_headofhousehold',
		      // blanks
					'_linkedsurvivor',
					'_linkedsurvivorname',
		      '_middlename',
		      '_suffix',
		      '_alias',
		      '_clientage',
		      '_fulladdress',
		      '_manualaddressentry',
		      '_parish',
		      '_numberinhousehold',
		      '_emailaddress',
		      '_otherphonenumber',
		      '_verificationdocumentation'
		    ];

		    $p['sys_data']['taskCreated'] = [];
		    $undeterminedfields = [];
		    $blankfields = [];

		    if (isset($caseData['data']['_lastname']) && isset($caseData['data']['_firstname'])) {
		      $name = $caseData['data']['_lastname'] . ', ' . $caseData['data']['_firstname'];
		    } elseif (isset($caseData['data']['_lastname']) && !isset($caseData['data']['_firstname'])) {
		      $name = $caseData['data']['_lastname'];
		    } elseif (!isset($caseData['data']['_lastname']) && isset($caseData['data']['_firstname'])) {
		      $name = $caseData['data']['_firstname'];
		    } else {
		      $name = '';
		    }

		    if (isset($caseData['data']['_fematier'])) {
		      $tier = $caseData['data']['_fematier'];
		    } else {
		      $tier = '';
		    }

		    if (isset($caseData['data']['assigned'])) {
		      $assignee = $caseData['data']['assigned'];
		    } else {
		      $assignee = '';
		    }

		    if ($tier != '') {
		      date_default_timezone_set('America/New_York');
		      if ($tier == 1325) { //'Y-m-d H:i:s'
		        $dueDate = Date('Y-m-d H:i:s', strtotime('+21 days')); // Tier 1 +21 Days
		      } elseif ($tier == 1326) {
		        $dueDate = Date('Y-m-d H:i:s', strtotime('+14 days')); // Tier 2 +14 Days
		      } elseif ($tier == 1327) {
		        $dueDate = Date('Y-m-d H:i:s', strtotime('+10 days')); // Tier 3 +10 Days
		      } else {
		        $dueDate = Date('Y-m-d H:i:s', strtotime('+4 days')); // Tier 4 +7 Days
		      }
		      $dueTime = Date('H:i:s', time());
		    } else {
		      $dueDate = '';
		      $dueTime = '';
		    }

		    foreach ($caseTasks as $caseTask) {
		        if (!empty($p['data'][$caseTask]))
		        {
		            if ($p['data'][$caseTask] == 219 || $p['data'][$caseTask] == 231 || $p['data'][$caseTask] == 241 || $p['data'][$caseTask] == 260 ||
		                $p['data'][$caseTask] == 3110 || $p['data'][$caseTask] == 3225 || $p['data'][$caseTask] == 248196 || $p['data'][$caseTask] == 3105)
		            {
		              $field = $caseTask;
		              $field = str_replace('_gender', 'Gender', $field);
		              $field = str_replace('_ethnicity', 'Ethnicity', $field);
		              $field = str_replace('_race', 'Race', $field);
		              $field = str_replace('_englishspeaker', 'English Speaker', $field);
		              $field = str_replace('_primarylanguage', 'Primary Language', $field);
		              $field = str_replace('_addresstype', 'Address Type', $field);
		              $field = str_replace('_headofhousehold', 'Head of Household?', $field);
		              $field = str_replace('_maritalstatus', 'Marital Status', $field);
		              $field = str_replace('_hispanicorigin', 'Hispanic Origin', $field);
		              array_push($undeterminedfields, $field);
		            }
		         }
		         elseif (empty($p['data'][$caseTask]) && $caseData['data']['_clientstatus'] == 1578){
		             $field = $caseTask;
		             $field = str_replace('_middlename', 'Middle Name', $field);
		             $field = str_replace('_alias', 'Alias', $field);
		             $field = str_replace('_suffix', 'Suffix', $field);
		             $field = str_replace('_gender', 'Gender', $field);
		             $field = str_replace('_ethnicity', 'Ethnicity', $field);
		             $field = str_replace('_race', 'Race', $field);
		             $field = str_replace('_englishspeaker', 'English Speaker', $field);
		             $field = str_replace('_primarylanguage', 'Primary Language', $field);
		             $field = str_replace('_addresstype', 'Address Type', $field);
		             $field = str_replace('_headofhousehold', 'Head of Household?', $field);
		             $field = str_replace('_maritalstatus', 'Marital Status', $field);
		             $field = str_replace('_clientage', 'Disaster Survivor Age', $field);
		             $field = str_replace('_fulladdress', 'Address', $field);
		             $field = str_replace('_parish', 'Parish', $field);
		             $field = str_replace('_manualaddressentry', 'Manual Address', $field);
		             $field = str_replace('_numberinhousehold', 'Number of individuals in household', $field);
		             $field = str_replace('_emailaddress', 'Email Address', $field);
		             $field = str_replace('_otherphonenumber', 'Other Phone Number', $field);
		             $field = str_replace('_verificationdocumentation', 'Verification Documentation', $field);
		             $field = str_replace('assigned', 'Assigned IDCM Worker', $field);
		             $field = str_replace('_location_type', 'Current Facility', $field);
		             $field = str_replace('_hispanicorigin', 'Hispanic Origin', $field);
		             $field = str_replace('_linkedsurvivor', 'Linked Survivor', $field);
		             $field = str_replace('_linkedsurvivorname', 'Linked Survivor Name', $field);
		             array_push($blankfields, $field);
		         }
		     }

		     if (!empty($undeterminedfields)) {
		       if ( !in_array($undeterminedfields, $p['sys_data']['taskCreated']) ){
		         //CREATE TASK HERE - Undetermined Fields
		         $fields = implode(", ",$undeterminedfields);
		          $data = [
		            'pid' => 246835,
		            'path' => '/Development/System/Tasks/',
		            'template_id' => 7,
		            'type' => 'task',
		            'isNew' => 'true',
		            'data' => [
		              'ecmrs_id' => $caseId, // id
		              'survivor_name' => $name, // name
		              'task_type' => 248278,
		              //'undetermined_fields' => intval("250720,250721"),
		              'time_expended' => '',
		              'case' => $caseId, // Linked case
		              'task_status' => 1906, // Open
		              '_task' => 'Undetermined Fields', // [ECMRS ID autofill} + Follow Up: Tier
		              'due_date' => $dueDate, // [time of auto task creation + days by Tier]
		              'due_time' => $dueTime, // [time of auto task creation]
		              'assigned' => $assignee, // [Whoever is assigned to the case at the time of auto creation]
		              'importance' => 55,
		              'description' => "Follow up with the disaster survivor's secondary intake to determine the " . $fields . " fields."
		            ],
		          ];
		          $newTask = $objService->create($data);
		          $p['sys_data']['taskCreated'][] = $fields;
		        } else {
		                //
		        }
		     }

		     if (!empty($blankfields)) {
		       if ( !in_array($blankfields, $p['sys_data']['taskCreated']) ){
		         //CREATE TASK HERE - Blank Fields
		         $fields = implode(", ",$blankfields);
		         $data = [
		           'pid' => 246835,
		           'path' => '/Development/System/Tasks/',
		           'template_id' => 7,
		           'type' => 'task',
		           'isNew' => 'true',
		           'data' => [
		             'ecmrs_id' => $caseId,
		             'survivor_name' => $name,
		             'task_type' => 248476,
		             'time_expended' => '',
		             'case' => $caseId,
		             'task_status' => 1906,
		             '_task' => 'Follow Up: Blank Fields',
		             'due_date' => $dueDate,
		             'due_time' => $dueTime,
		             'assigned' => $assignee,
		             'importance' => 55,
		             'description' => "Follow up with the disaster survivor's secondary intake to determine the " . $fields . " fields."
		            ],
		         ];
		         $newTask = $objService->create($data);
		         $p['sys_data']['taskCreated'][] = $fields;
		        } else {
		               //
		         }
		     }
		    // End auto case tasks

				$case->updateCustomData();
				$case->updateSysData();
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


					// Create task if record has referrals, appointment date is set, and is not Information Only
						$caseSd['referralTaskCreated'] = [];

						$objService = new Objects();
						$name = $caseData['name'];
						$ecmrsId = $caseData['id'];

						if (isset($caseSd['fematier'])) {
							$tier = $caseSd['fematier'];
						}

						if (isset($caseData['data']['assigned'])) {
							$assignee = $caseData['data']['assigned'];
						} else {
							$assignee = '';
						}

						if (isset($tier)) {
							if ($tier == "Tier 4") {
								$importance = 57;
							} else {
								$importance = 56;
							}
						}

						if (!empty($caseSd['appointment_ss']) && ($caseSd['case_status'] != 'Information Only'))
						{
							if (count($caseSd['appointment_ss'])+1 == count($caseSd['referralservice_ss'])) {
								array_push($caseSd['appointment_ss'], $p['data']['_appointmentdate']);
								$maxAppointment = max($caseSd['appointment_ss']);

								date_default_timezone_set('America/New_York');
								$dueDate = Date('Y-m-d H:i:s', strtotime($maxAppointment . '+2 days'));
								$dueTime = Date('H:i:s', time()); //Rounded to nearest quarter hour

								//CREATE TASK HERE
									$data = [
						            	'pid' => 246835,
						                'path' => '/Development/System/Tasks/',
						                'template_id' => 7,
						                'type' => 'task',
						                'isNew' => 'true',
						                'data' => [
						                	'ecmrs_id' => $ecmrsId, // id
						                    'survivor_name' => $name, // name
						                    'task_type' => 248277,
						                    'time_expended' => '',
						                    'case' => $ecmrsId, // Linked case
						                    'task_status' => 1906, // Open
						                    '_task' => $ecmrsId . ' Follow Up: Appointments Completed', // [ECMRS ID autofill] + Follow Up: Tier
						                    'due_date' => $dueDate, // [time of auto task creation + days by Tier]
						                    'due_time' => $dueTime, // [time of auto task creation]
						                    'assigned' => $assignee, // [Whoever is assigned to the case at the time of auto creation]
						                    'importance' => $importance, // For Tiers 1, 2, 3: [56 - High] , for Tier 4: [57 - CRITICAL]
						                    'description' => 'Follow up with the linked record because the last scheduled Referral appointment was scheduled to be completed on' . ' '. $maxAppointment //
						                 ],
						             ];
						             $newTask = $objService->create($data);
									 $caseSd['referralTaskCreated'][] = $maxAppointment;
							}
						 }

			}

			//Assessments
			if (!empty($p['data']['_assessmentdate']))
			{
				// Change FEMA Tier from Assessments
				if (!empty($p['data']['_fematier'])) {
					if ($p['data']['_fematier']['value'] != $caseData['data']['_fematier']) //FEMA tier
					{
						$femaTier = $p['data']['_fematier']['value'];
						$caseData['data']['_fematier'] = $femaTier;
						$caseSd['fematier_i'] = $femaTier;
						$obj = Objects::getCachedObject($femaTier);
						$arr = explode(" -", $obj->getHtmlSafeName(), 2);
						$first = $arr[0];
						$caseSd['fematier'] = $first;
						//$caseSd['fematier'] = empty($obj) ? '' : str_replace('Yes - ','',$obj->getHtmlSafeName());
						$case->updateCustomData();
					}
				}
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
				if (isset($caseSd['referrals_needed'])) {
					if (is_null($caseSd['referrals_needed'])) {
						$caseSd['referrals_needed'] = [];
					}
				}
				else {
					$caseSd['referrals_needed'] = [];
				}

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
