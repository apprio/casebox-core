<?php

namespace Casebox\CoreBundle\Command;

use Casebox\CoreBundle\Service\System;
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
use ZipArchive;
use Dompdf\Dompdf;
use Symfony\Component\HttpFoundation\Request;


class ECMRSDatabaseExportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('ecmrs:database:export')
            ->setDescription('Perform Export of ECMRS Database for Desired Core')
            ->addOption('state', 's', InputOption::VALUE_OPTIONAL, 'The state to run for')
			->addOption('county', 'c', InputOption::VALUE_OPTIONAL, 'The county to run for')
			->addOption('tier', 't', InputOption::VALUE_OPTIONAL, 'The tier to run for')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);

        $container = $this->getContainer();
		
		$state = $input->getOption('state');
		$county = $input->getOption('county');
		$tier = $input->getOption('tier');				
        
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
		$log = $this->getContainer()->get('logger');
        $log->pushHandler($container->get('monolog.handler.nested'));
        $log->addInfo('Starting ecmrs:database:export process');

		$baseDirectory = !empty($configService->get('export_directory'))?$configService->get('export_directory'):'/home/dstoudt/transfer/';
		$exportReport = !empty($configService->get('export_report'))?$configService->get('export_report'):2727;
		$exportSql = !empty($configService->get('export_sql'))?$configService->get('export_sql'):'select * from dual';
		$exportSql = $exportSql . ((isset($state)) ? ' AND state="'.$state.'"':'') . ((isset($tier)) ? ' AND fema_tier="'.$tier.'"':'') . ((isset($county)) ? ' AND county="'.$county.'"':'');
		$log->addInfo('Process ecmrs:database:export - sql:' . $exportSql);
		
		$res = $dbs->query($exportSql
			);
		while ($r = $res->fetch()) {
			$state = $r['state'];
			$county = $r['county'];
			$fema_tier = $r['fema_tier'];
			$log->addInfo('Process ecmrs:database:export - row result:' . var_export($r,true));
			$folder = $baseDirectory.$state.'/'.$county.'/'.$fema_tier;
			if (!file_exists($folder))
			{
				mkdir($folder, 0777, true);
			}
			ini_set('memory_limit', '5000M');
			$rez = [];
			$fq = [];
			if (!empty($r['fq_1']))
			{
				$fq[] =  $r['fq_1'];
			}
			if (!empty($r['fq_2']))
			{
				$fq[] =  $r['fq_2'];
			}
			if (!empty($r['fq_location']))
			{
				$fq[] =  '(location_i:'.str_replace('|',' OR location_i:',$r['fq_location']).')';
			}

			$p = [
				'reportId' => $exportReport,
				'skipSecurity' => true,
				'fq' => $fq,
				'rows' => 1
			];
			$log->addInfo('Process ecmrs:database:export - running report: parameters:' . var_export($p,true));
			$export = new Instance();
			$log->addInfo('Process ecmrs:database:export - getting full export for folder:' . $folder);
			$rez = $export->getFullExport($p);			
			file_put_contents($folder.'/records.csv',implode("\n", $rez));
			$log->addInfo('Process ecmrs:database:export - added records.csv to folder:' . $folder);
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
					if (file_exists($file) && !is_dir($file))
					{
						copy($file, $folder .'/'.$zipcode. '_'.$clientId.'_consentform.pdf');
					}
				}
				//$log->addInfo('Finished ecmrs:database:export clientId:'.$clientId);
				$html = $export->getPDFContent($clientId,"","-en");
				
				$dompdf = new Dompdf();
				$dompdf->loadHtml($html);
				$dompdf->setPaper('A4', 'landscape');
				$dompdf->render();
				$recoveryPlan = $dompdf->output();		
				file_put_contents($folder .'/'.$zipcode. '_'.$clientId.'_recoveryplan.pdf',$recoveryPlan);			
			}
				
		}
		$log->addInfo('Finished ecmrs:database:export process');
        $output->success('command ecmrs:database:export');
    }
}
