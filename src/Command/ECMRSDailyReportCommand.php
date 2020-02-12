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

class ECMRSDailyReportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('ecmrs:daily:report')
            ->setDescription('Create Report')
			->addOption('date', 'd', InputOption::VALUE_OPTIONAL, 'Reindex all items. Solr will be cleared and all records from tree table will be marked as updated.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);

        $container = $this->getContainer();

        // Bootstrap
        $system = new System();
        $system->bootstrap($container);
		$locationFolder = 41491;

		$date = (!empty($input->getOption('date'))) ? $input->getOption('date') : date('Y-m-d', time() - 60 * 60 * 24);
		//echo('test'.$date);
		//$user = $container->get('doctrine.orm.entity_manager')->getRepository('CaseboxCoreBundle:UsersGroups')->findUserByUsername('root');

		$session = $container->get('session');

        $dbs = Cache::get('casebox_dbs');

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

		$locs = $dbs->query(
			'select
				substring(data, LOCATE(\'"_locationcounty":\', data)+19,
				LOCATE(\'"\',data,LOCATE(\'"_locationcounty":\', data)+19)-
				(LOCATE(\'"_locationcounty":\', data)+19)) locationcounty,
				substring(data, LOCATE(\'"_locationregion":\', data)+19,
				LOCATE(\'"\',data,LOCATE(\'"_locationregion":\', data)+19)-
				(LOCATE(\'"_locationregion":\', data)+19)) locationregion,
				objects.id,name
				from objects,tree where tree.id = objects.id
				and tree.template_id = 3269
				and dstatus = 0'
		);
		$locations = array();

        while ($row = $locs->fetch()) { //START LOCATIONS
			$locations[] = $row;
			//$this->runReport($row['name'], $row['id'], $date, $locationFolder,$dbs);
		} //END LOCATIONS

		//print_r($locations);
		foreach($locations as $key => $value){
		   $regions[$value['locationregion']][$key] = $value['id'];
		   $counties[$value['locationcounty']][$key] = $value['id'];
		   $all[] = $value['id'];
		}


		$this->runReport('', implode(', ',$all), $date, 1204,$dbs);
		$qb = $dbs->getDbh()->prepare(
				'CALL p_relationalize_data'
		);
		$qb->execute();
        $output->success('command casebox:database:report for '. $date);
    }

	private function runReport($areaName, $locations, $date, $pid,$dbs)
	{
    $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
    $reportsql = !empty($configService->get('daily_report_sql'))?$configService->get('daily_report_sql'):'select count(*) from rpt_clients';
    $reportsql = str_replace('#date#', $date,$reportsql);
    $reportsql = str_replace('#locations#', $locations,$reportsql);

			if ($pid === 1204)
			{
				$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
				$staffingTemplateId = !empty($configService->get('staffing_report_id'))?$configService->get('staffing_report_id'):246777;
				$staffingPid = !empty($configService->get('staffing_report_pid'))?$configService->get('staffing_report_pid'):246787;
				//Staffing Report
				$sql3= 'select * from tree where dstatus = 0 and template_id = '.$staffingTemplateId.' and pid = '.$staffingPid.' and (name like \'%'.$areaName.' - '.$date.'%\' or
							name like \'%'.$areaName.' - '.date("d.m.Y", strtotime($date)).'%\' or
							name like \'%'.$areaName.' - '.date("m/d/Y", strtotime($date)).'%\')';
				//echo($sql3);
				$rezzz = $dbs->query(
					$sql3
				);
				$idd = null;
				if ($rzz = $rezzz->fetch()) {
					$idd = $rzz['id'];
				}
				if (is_null($idd))
				{
					$staffing = [];
					$staffing['report_date']=$date.'T00:00:00Z';
					$staffingdata = [
						'id' => is_null($idd)?null:$idd,
						'pid' => $staffingPid,//3286,
						'title' => 'Daily Staffing Report',
						'template_id' => $staffingTemplateId,
						'path' => '/Test Event/Reports/Staffing/',
						'view' => 'edit',
						'name' => 'Daily Staffing Report',
						'data' => $staffing,
						];
					$objService = new Objects();
					$newStaffing =$objService->save(['data'=>$staffingdata]);
				}
			}
        $id = null;

        $res = $dbs->query($reportsql);
        if ($r = $res->fetch()) {
                  echo($reportsql);
						$sql2= 'select * from tree where template_id = 1976 and pid = '.$pid.' and (name like \'%'.$areaName.' - '.$date.'%\' or
									name like \'%'.$areaName.' - '.date("m/d/Y", strtotime($date)).'%\')';
									//echo($sql2);
								$rezz = $dbs->query(
									$sql2
								);

								if ($rz = $rezz->fetch()) {
									$id = $rz['id'];
								}
					$r['report_date']=$date.'T00:00:00Z';
					$r['area'] = $areaName;
					$data = [
						'id' => is_null($id)?null:$id,
						'pid' => $pid,
						'title' => 'New OHSEPR Daily Report',
						'template_id' => 1976,
						'path' => '/Test Event/Reports',
						'view' => 'edit',
						'name' => 'New OHSEPR Daily Report',
						'data' => $r,
						];
            print_r($data);
					$objService = new Objects();
					$newReferral =$objService->save(['data'=>$data]);
				}
	}
}
