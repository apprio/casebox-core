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
         $rez = [];
		 $rez = $this->getCSVContent($p);
		 	
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Exported_Results_'.date('Y-m-d_Hi').'.csv');
        header("Pragma: no-cache");
        header("Expires: 0");
        echo implode("\n", $rez);
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
		}		
		
		$r = Files::read($fileId);
		if (!empty($r)) {
            $content = FilesContent::read($r['content_id']);
			$file = $configService->get('files_dir').$content['path'].DIRECTORY_SEPARATOR.$content['id'];
			$zip->addFile($file, $clientId.'consentform.pdf');
		}			
		
		$export = new Instance();
		
		$html = $export->getPDFContent($clientId);
		//echo($html);
		
		$dompdf = new Dompdf();
		$dompdf->loadHtml($html);
		$dompdf->setPaper('A4', 'landscape');
		$dompdf->render();
		$recoveryPlan = $dompdf->output();
		
		$zip->addFromString($clientId.'recoveryplan.pdf',$recoveryPlan);
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
    	if($p['reportId'])
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
    
        public function getPDFContent($p)
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
							foreach ($service['data']['data']['_commentgroup'] as $key) {
								 $comments = $comments. $key['childs']['_comments'] . ' ('.Util\formatMysqlDate($key['childs']['_commentdate'], Util\getOption('short_date_format')).'),';
							}
							$comments = trim($comments,',') .$service['data']['data']['_commentgroup']['childs']['_comments']. ' ('.Util\formatMysqlDate($service['data']['data']['_commentgroup']['childs']['_commentdate'], Util\getOption('short_date_format')).'),';
							
						 $services[] = [
							'associatedneed' => $service['data']['data']['_associatedneed'],
							'associatedgoal' => $service['data']['data']['_associatedgoal'],			
							'referraltype' => $refferalTypeValue,
							'referralsubtype' => $refferalSubTypeValue,
							'resourceagencyname' => empty($resource) ? 'N/A' : $resource['data']['data']['_providername'],
							'resourceagencycontactinformation' => empty($resource) ? 'N/A' : $resource['data']['data']['_streetaddress']. ' ' .$resource['data']['data']['_city']. ' ' .$resource['data']['data']['_state']. ' ' .$resource['data']['data']['_zipcode']. ' ' .$resource['data']['data']['phonenumbers'],
							'pointofcontact' => empty($resource) ? 'N/A' : $resource['data']['data']['_pointofcontact'],
							'referralappointmentdatetime' =>Util\formatMysqlDate($service['data']['data']['_appointmentdate'], Util\getOption('short_date_format')). ' ' . $service['data']['data']['_appointmenttime'],
							'targetcompletiondate' =>  Util\formatMysqlDate($service['data']['data']['_targetcompletiondate'], Util\getOption('short_date_format')),
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
				'client_lastname' => $obj['data']['data']['_lastname'],
				'client_firstname' => $obj['data']['data']['_firstname'],			
				'disaster_declaration_number' => $configService->get('disaster_declaration_number'),
				'disaster_site_address' => $configService->get('disaster_site_address'),
				'cm_phone' => $configService->get('disaster_phone_number'),
				'cm_assigned' => $assigned,
				'cm_phone' =>$configService->get('disaster_phone_number'),
				'plan_creation_date' => date("F j, Y, g:i a"),
				'fema_registration_number' => $femaNumber,
				'client_id' => $obj['data']['id'],
				'services' =>
					$services,
			];
			$html = $twig->render('CaseboxCoreBundle:email:recovery-plan.html.twig', $vars);
		return $html;
    }
    
}
