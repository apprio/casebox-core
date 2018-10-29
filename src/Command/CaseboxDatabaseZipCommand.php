<?php

namespace Casebox\CoreBundle\Command;

use Casebox\CoreBundle\Service\System;
use Casebox\CoreBundle\Service\Objects;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\DataModel\FilesContent;
use Casebox\CoreBundle\Service\DataModel\Files;
use Casebox\CoreBundle\Service\Plugins\Export\Instance;
use Casebox\CoreBundle\Service\Notifications;
use ZipArchive;
use Dompdf\Dompdf;
use Symfony\Component\HttpFoundation\Request;


class CaseboxDatabaseZipCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('casebox:database:zip')
            ->setDescription('Perform Zip')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);

        $container = $this->getContainer();

        // Bootstrap
        $system = new System();
        $system->bootstrap($container);
		
		$session = $container->get('session');

        $dbs = Cache::get('casebox_dbs');
		$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
		$user = [
            'id' => 1,
            'name' => 1,
            'first_name' => 1,
            'last_name' => 1,
            'sex' => 1,
            'email' => 1,
            'language_id' => 1,
            'cfg' => 1,
            'data' => 1,
        ];
		$session->set('user', $user);
		$request = new Request();
		$request->setLocale('en');
		Cache::set('symfony.request', $request);
		
		$export = new Instance();
		$reports = new Notifications();
		$objService = new Objects();
		ini_set('memory_limit', '1024M');
		$baseDirectory = !empty($configService->get('export_directory'))?$configService->get('export_directory'):'/home/dstoudt/transfer/';
		$exportReport = !empty($configService->get('export_report'))?$configService->get('export_report'):2727;
			
		$res = $dbs->query(
        'select replace(case when county_s is null then l.locationcounty else county_s end, \' County\',\'\') county,case when (case_status = \'Transitioned\') THEN \'Closed\' when (case_status=\'Closed\' AND c.closure_reason like \'%transitioning%\' ) THEN \'Open\' ELSE case_status END case_status, ifnull(fema_tier,\'No Tier\') fema_tier, count(*)
			from rpt_clients c, tree t, objects o, rpt_locations l, users_groups u
			where c.clientid = t.id and c.locationid = l.locationid
			and t.dstatus = 0
			and t.id = o.id
			and IFNULL(c.assigned, 1) = u.id
			group by case when (case_status = \'Transitioned\') THEN \'Closed\' when (case_status=\'Closed\' AND c.closure_reason like \'%transitioning%\' ) THEN \'Open\' ELSE case_status END, c.fema_tier,replace(case when county_s is null then l.locationcounty else county_s end, \' County\',\'\')
			order by county');
		while ($r = $res->fetch()) {
			$county = $r['county'];
			$case_status = $r['case_status'];
			$fema_tier = $r['fema_tier'];
			$folder = $baseDirectory.$county.'/'.$case_status.'/'.$fema_tier;
			if (!file_exists($folder))
			{
				mkdir($folder, 0777, true);
			}
			ini_set('memory_limit', '1024M');
			$rez = [];
			$fq = [];
			if ($case_status=='Open')
			{
				$fq[] = 'case_status:"Closed" AND closurereason_s:*transitioning*';
			}
			else
			{
				$fq[] = ($case_status=='Closed')?'case_status:"'.$case_status.'" OR case_status:"Transitioned"':'case_status:"'.$case_status.'"';
			}
			$fq[] = '((county_s:"'.$county.'" OR county_s:"'.$county.' County") OR (!county_s:[* TO *] AND (county:"'.$county.'" OR county:"'.$county.' County")))';
			$fq[] = ($fema_tier=='No Tier')?'!fematier:[* TO *]':'fematier:"'.$fema_tier.'"';
			//$fq[] = 'fematier:"'.$fema_tier.'"';
			$p = [
				'reportId' => $exportReport,
				'skipSecurity' => true,
				'fq' => $fq,
				'rows' => 1
			];
			print_r($p);
			$rez = $export->getFullExport($p);			
			//print_r($rez);
			file_put_contents($folder.'/records.csv',implode("\n", $rez));

			array_shift($rez);
			foreach ($rez as &$r) 
			{
				$arr = explode(",", $r);
				$clientId = $arr[0];
				$zipcode = (!empty($arr[27]) && is_numeric($arr[27]))?$arr[27]:$arr[8];
				
				$filePlugin = new \Casebox\CoreBundle\Service\Objects\Plugins\Files();
				$files = $filePlugin->getData($clientId);

				foreach ($files['data'] as $file) {
					$fileId = $file['id'];
				}		
				
				$fid = (isset($fileId)?Files::read($fileId):null);
				if (!empty($fid)) {
					$content = FilesContent::read($fid['content_id']);
					$file = $configService->get('files_dir').$content['path'].DIRECTORY_SEPARATOR.$content['id'];
					if (file_exists($file))
					{
						copy($file, $folder .'/'.$zipcode. '_'.$clientId.'_consentform.pdf');
					}
				}
				$html = $export->getPDFContent($clientId,"","-en");
				//echo($html);
				
				$dompdf = new Dompdf();
				$dompdf->loadHtml($html);
				$dompdf->setPaper('A4', 'landscape');
				$dompdf->render();
				$recoveryPlan = $dompdf->output();		
				file_put_contents($folder .'/'.$zipcode. '_'.$clientId.'_recoveryplan.pdf',$recoveryPlan);			
			}
				
		}
		
        $output->success('command casebox:database:zip');
    }
}
