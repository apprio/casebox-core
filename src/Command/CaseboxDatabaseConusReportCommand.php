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

class CaseboxDatabaseConusReportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('casebox:database:conusreport')
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
		$countyFolder = 202481;
		$regionFolder = 202480;
		$conusFolder = 202479;
		$overallFolder = 203101;
		/*
		 //dev
				$countyFolder = 190563;
		$regionFolder = 190562;
		$conusFolder = 190564;
		*/
		
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
				substring(data, LOCATE(\'"_locationstate":\', data)+18, 
				LOCATE(\'"\',data,LOCATE(\'"_locationstate":\', data)+18)-
				(LOCATE(\'"_locationstate":\', data)+18)) locationstate,
				substring(data, LOCATE(\'"_locationcounty":\', data)+19, 
				LOCATE(\'"\',data,LOCATE(\'"_locationcounty":\', data)+19)-
				(LOCATE(\'"_locationcounty":\', data)+19)) locationcounty,
				substring(data, LOCATE(\'"_locationregion":\', data)+19, 
				LOCATE(\'"\',data,LOCATE(\'"_locationregion":\', data)+19)-
				(LOCATE(\'"_locationregion":\', data)+19)) locationregion,
				objects.id,name
				from objects,tree where tree.id = objects.id
				and data like \'%CONUS%\'
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
		   $locationtypes[$value['locationstate']][$key] = $value['id'];
		   $all[] = $value['id'];
		}

		foreach($counties as $countyname => $locations) //START COUNTIES
		{
			//echo($countyname. implode(', ',$locations));
			$this->runReport($countyname, implode(', ',$locations), $date, $countyFolder,$dbs);
		} //END COUNTIES
		//var_dump($newarray);		

		foreach($regions as $regionname => $locations) //START REGIONS
		{
			//echo($regionname. implode(', ',$locations));
			$this->runReport($regionname, implode(', ',$locations), $date, $regionFolder,$dbs);
		} //END REGIONS
		
		foreach($locationtypes as $locationtype => $locations) //START COUNTIES
		{
			//echo($countyname. implode(', ',$locations));
			$this->runReport($locationtype, implode(', ',$locations), $date, $conusFolder,$dbs);
		} //END COUNTIES		
		
		$this->runReport('', implode(', ',$all), $date, $overallFolder,$dbs);
		
        $output->success('command casebox:database:conusreport for '. $date);
    }
	
	private function runReport($areaName, $locations, $date, $pid,$dbs)
	{
		
$femasql = 'select  
			(SELECT count(distinct(IF(template_id=141,id,pid))) FROM 
			tree stree where stree.dstatus = 0 and template_id 
            in (527,311,289,607,141,3114,1175,1120,656,
            651,559,553,533,510,505,489,482,455,440,172) 
			and IF(template_id=141,id,pid) in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))				
			AND DATE(stree.cdate) = \''.$date.'\') total_client_contact, 
			SUM(IF (DATE(tree.cdate) = \''.$date.'\' and sys_data not like \'%"case_status":"Solo informacion"%\',1,0)) new_open_cases,
			SUM(IF (DATE(tree.cdate) = \''.$date.'\',1,0)) client_intake,
			SUM(IF (DATE(tree.cdate) = \''.$date.'\' and sys_data like \'%"case_status":"Solo informacion"%\',1,0)) information_only,
			(select count(*) from tree where tree.name like \'Evaluacion %\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') assessments_total,
			(SELECT COUNT(*) FROM objects where 
      sys_data like \'%"case_status":"Cerrado"%\' 
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF))
      and sys_data not like \'%"closurereason_s":"Metas cumplidas"%\' and
      sys_data like CONCAT(\'%task_d_closed":"\',\''.$date.'\',\'%\')) closed_records_no_recovery_plan,			
			(SELECT COUNT(*) FROM objects where 
      sys_data like \'%"case_status":"Cerrado"%\' 
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF))
      and sys_data like \'%"closurereason_s":"Metas cumplidas"%\' and
      sys_data like CONCAT(\'%task_d_closed":"\',\''.$date.'\',\'%\')) closed_records_recovery_plan_complete,
  (SELECT COUNT(*) FROM objects where
      sys_data like \'%"case_status":"Cerrado"%\'
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF))
      and sys_data like \'%"closurereason_s":"Metas parcialmente cumplidas, en transicion a otro proveedor de servicios%\' and
      sys_data like CONCAT(\'%task_d_closed":"\',\''.$date.'\',\'%\')) closed_records_transitioning,
(SELECT COUNT(*) FROM objects where
      sys_data like \'%"case_status":"Cerrado"%\'
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF))
      and sys_data like \'%"closurereason_s":"Metas parcialmente cumplidas, recursos agotados"%\' and
      sys_data like CONCAT(\'%task_d_closed":"\',\''.$date.'\',\'%\')) closed_records_resources_exhaused,
 (SELECT COUNT(*) FROM objects where
      sys_data like \'%"case_status":"Cerrado"%\'
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF))
      and sys_data like \'%"closurereason_s":"No se puede contactar cliente"%\' and
      sys_data like CONCAT(\'%task_d_closed":"\',\''.$date.'\',\'%\')) closed_records_no_longer_able_to_contact,
  (SELECT COUNT(*) FROM objects where
      sys_data like \'%"case_status":"Cerrado"%\'
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF))
      and sys_data like \'%"closurereason_s":"Programa de desastre desactivado"%\' and
      sys_data like CONCAT(\'%task_d_closed":"\',\''.$date.'\',\'%\')) closed_records_deactivated,
   (SELECT COUNT(*) FROM objects where
      sys_data like \'%"case_status":"Cerrado"%\'
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF))
      and sys_data like \'%"closurereason_s":"Cliente se ha retirado del manejo de caso"%\' and
      sys_data like CONCAT(\'%task_d_closed":"\',\''.$date.'\',\'%\')) closed_records_withdrawn,   
   (SELECT COUNT(*) FROM objects where
      sys_data like \'%"case_status":"Cerrado"%\'
			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF))
      and sys_data like \'%"closurereason_s":"Cliente se ha mudado fuera del área de servicio%\' and
      sys_data like CONCAT(\'%task_d_closed":"\',\''.$date.'\',\'%\')) closed_records_moved, 
         (SELECT COUNT(*) FROM objects, tree where
         tree.id = objects.id and 
         dstatus = 0 and 
      sys_data like \'%"case_status":"Cerrado"%\'
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)) AND
			DATE(SUBSTRING(sys_data,LOCATE(\'"task_d_closed":"\', sys_data)+17,10)) = DATE(\''.$date.'\'))
      		 closed_cases, 
         (SELECT COUNT(*) FROM objects, tree where
         tree.id = objects.id and 
         dstatus = 0 and 
      sys_data like \'%"case_status":"Cerrado"%\'
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)) AND
			DATE(SUBSTRING(sys_data,LOCATE(\'"task_d_closed":"\', sys_data)+17,10)) <= DATE(\''.$date.'\'))
      		 total_closed_cases,
         (SELECT COUNT(*) FROM objects, tree where tree.id = objects.id
         and dstatus = 0
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)) AND
			DATE(tree.cdate) <= DATE(\''.$date.'\'))
      		 total_cases,    	
         (SELECT COUNT(*) FROM objects, tree where tree.id = objects.id
         and sys_data like \'%"case_status":"Solo informacion"%\'
         and dstatus = 0
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)) AND
			DATE(tree.cdate) <= DATE(\''.$date.'\'))
      		 total_information_only_cases,   			 
         (SELECT COUNT(*) FROM objects, tree where tree.id = objects.id
         and sys_data like \'%"case_status":"Activo"%\'
         and dstatus = 0
	  			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)) AND
			DATE(tree.cdate) <= DATE(\''.$date.'\'))
      		 total_open_cases,       		 
			(select count(*) from tree where template_id =607 
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_total,
			 (select count(*) from tree where template_id =607 and name like \'Salud de Comportamiento%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_behavioral,   
			 (select count(*) from tree where template_id =607 and name like \'Nino%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_child,  
			 (select count(*) from tree where template_id =607 and name like \'Ropa%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_clothing,  
			 (select count(*) from tree where template_id =607 and name like \'Empleo%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_employment,  
			  (select count(*) from tree where template_id =607 and name like \'FEMA%\'
						AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_fema,  
			  (select count(*) from tree where template_id =607 and name like \'Financiera%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_financial,  
			  (select count(*) from tree where template_id =607 and name like \'Comida%\'
						AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_food,  
			 (select count(*) from tree where template_id =607 and name like \'Muebles y Electrodomésticos%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_furniture,                          
			 (select count(*) from tree where template_id =607 and name like \'Salud -%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_health,                          
			 (select count(*) from tree where template_id =607 and name like \'Vivienda%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_housing,                          
			 (select count(*) from tree where template_id =607 and name like \'Idioma%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_language,                          
			 (select count(*) from tree where template_id =607 and name like \'Legal Servicio%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_legal,                                                    
			 (select count(*) from tree where template_id =607 and name like \'Servicios de Ancianos%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_senior,                          
			 (select count(*) from tree where template_id =607 and name like \'Transporte%\'
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			and name not like \'%-  []%\' and dstatus = 0 AND DATE(tree.cdate) = \''.$date.'\') referrals_transportation,  			
			SUM(IF (data like \'%"_addresstype":323%\' AND (DATE(tree.cdate) = \''.$date.'\')    ,1,0) ) temporary_housing,
			(SELECT SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1) FROM   objects, tree where objects.id = tree.id 
			AND tree.template_id = 607 and tree.name not like \'%-  []%\' and dstatus = 0 
			AND (DATE(tree.cdate) = \''.$date.'\')
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			group by SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1)
			order by count(*) desc
			limit 0,1) top_client_need,
			(SELECT SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1) FROM   objects, tree where objects.id = tree.id 
			AND tree.template_id = 607 and tree.name not like \'%-  []%\' and dstatus = 0 
			AND (DATE(tree.cdate) = \''.$date.'\')
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			group by SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1)
			order by count(*) desc			
			limit 1,1) second_client_need,
			(SELECT SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1) FROM   objects, tree where objects.id = tree.id 
			AND tree.template_id = 607 and tree.name not like \'%-  []%\' and dstatus = 0 
			AND (DATE(tree.cdate) = \''.$date.'\')
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			group by SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1)
			order by count(*) desc			
			limit 2,1) third_client_need,
			SUM(IF ((sys_data like \'%"fematier":"Nivel 1%\') AND DATE(tree.cdate) = \''.$date.'\',1,0) ) fema_tier_1,
			SUM(IF (sys_data like \'%"fematier":"Nivel 2%\' AND DATE(tree.cdate) = \''.$date.'\',1,0) ) fema_tier_2,
			SUM(IF (sys_data like \'%"fematier":"Nivel 3%\' AND DATE(tree.cdate) = \''.$date.'\',1,0) ) fema_tier_3,
			SUM(IF (sys_data like \'%"fematier":"Nivel 4%\' AND DATE(tree.cdate) = \''.$date.'\',1,0) ) fema_tier_4,
			case_managers.total case_mamager_total,
			case_manager_supervisors.total case_manager_supervisor_total,
			CONCAT(case_manager_supervisors.total,\'/\',case_managers.total) as case_manager_to_supervisor_ratio,
			CONCAT(case_managers.total,\'/\',SUM(IF (DATE(tree.cdate) = \''.$date.'\',1,0))) as case_manager_to_client_ratio
			from objects, 
			tree,
			(select 141 template_id, count(*) total from users_groups 
			where enabled = 1 and users_groups.id IN 
			(SELECT distinct aa.id
                                from objects oo,
                                tree tt,
                                users_groups aa where tt.id = oo.id
                                and tt.template_id = 3269
                                and CONCAT(\',\',substring(oo.data, LOCATE(\'"_usersatlocation":\', oo.data)+20,
                        LOCATE(\'"\',oo.data,LOCATE(\'"_usersatlocation":\', oo.data)+20)-
                        (LOCATE(\'"_usersatlocation":\', oo.data)+20)),\',\') like CONCAT(\'%,\',aa.id,\',%\') 
                                and oo.data like \'%_usersatlocation%\'
                                and tt.dstatus = 0
                                and tt.id in (LOCATION_STUFF)
            )
			and users_groups.id in 
			(select user_id from users_groups_association where DATE(cdate) <= \''.$date.'\' AND group_id in 
			(select id from users_groups where replace(users_groups.name,\'Managers\',\'Manager\') = \'Case Manager\'))) case_managers,
			(select 141 template_id, count(*) total from users_groups 
			where enabled = 1 and users_groups.id IN 
			(SELECT distinct aa.id
                                from objects oo,
                                tree tt,
                                users_groups aa where tt.id = oo.id
                                and tt.template_id = 3269
                                and CONCAT(\',\',substring(oo.data, LOCATE(\'"_usersatlocation":\', oo.data)+20,
                        LOCATE(\'"\',oo.data,LOCATE(\'"_usersatlocation":\', oo.data)+20)-
                        (LOCATE(\'"_usersatlocation":\', oo.data)+20)),\',\') like CONCAT(\'%,\',aa.id,\',%\') 
                                and oo.data like \'%_usersatlocation%\'
                                and tt.dstatus = 0
                                and tt.id in (LOCATION_STUFF)
            )
			and users_groups.id in
			(select user_id from users_groups_association where DATE(cdate) <= \''.$date.'\' AND group_id in
			(select id from users_groups 
			where replace(replace(users_groups.name,\'Managers\',\'Manager\'), \'Supervisors\',\'Supervisor\') = \'Case Manager Supervisor\'))) case_manager_supervisors
			where 
			tree.template_id = 141
			and
			objects.id = tree.id
			and 
			tree.dstatus = 0
	  and
      tree.id in (SELECT distinct(IF(template_id=141,id,pid)) FROM 
			tree stree where template_id 
            in (527,311,289,607,141,3114,1175,1120,656,
            651,559,553,533,510,505,489,482,455,440,172)
            	AND DATE(stree.cdate) = \''.$date.'\'
and IF(template_id=141,id,pid) in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))				
				) 
			group by tree.template_id, case_managers.total, case_manager_supervisors.total';
			
			
	$ohseprsql = 'select  
			COUNT(*) client_fema_registrations, 
			SUM(IF (substring(sys_data, LOCATE(\'identified_unmet_needs_ss\', sys_data), LOCATE(\']\',sys_data,LOCATE(\'identified_unmet_needs_ss\', sys_data))-LOCATE(\'identified_unmet_needs_ss\', sys_data) ) like \'%Ayuda FEMA%\',1,0)) fema_help,
			SUM(IF (substring(sys_data, LOCATE(\'referralservice_ss\', sys_data), LOCATE(\']\',sys_data,LOCATE(\'referralservice_ss\', sys_data))-LOCATE(\'referralservice_ss\', sys_data) ) like \'%SBA%\',1,0)) sba_help,
			SUM(IF (substring(sys_data, LOCATE(\'at_risk_population_ss\', sys_data), LOCATE(\']\',sys_data,LOCATE(\'at_risk_population_ss\', sys_data))-LOCATE(\'at_risk_population_ss\', sys_data) ) like \'% discapacitados %\',1,0)) self_reported_disabilities,
			SUM(IF (substring(sys_data, LOCATE(\'at_risk_population_ss\', sys_data), LOCATE(\']\',sys_data,LOCATE(\'at_risk_population_ss\', sys_data))-LOCATE(\'at_risk_population_ss\', sys_data) ) like \'% English %\',1,0)) self_reported_limited_english,
			SUM(IF (substring(sys_data, LOCATE(\'at_risk_population_ss\', sys_data), LOCATE(\']\',sys_data,LOCATE(\'at_risk_population_ss\', sys_data))-LOCATE(\'at_risk_population_ss\', sys_data) ) like \'%Ninos%\',1,0)) self_reported_children,
			SUM(IF (substring(sys_data, LOCATE(\'at_risk_population_ss\', sys_data), LOCATE(\']\',sys_data,LOCATE(\'at_risk_population_ss\', sys_data))-LOCATE(\'at_risk_population_ss\', sys_data) ) like \'%Envejecientes%\',1,0)) self_reported_elderly,
			SUM(IF (data like \'%"_gender":214%\' and data like \'%_headofhousehold":3108%\',1,0) ) male_hoh,
			SUM(IF (data like \'%"_gender":215%\' and data like \'%_headofhousehold":3108%\',1,0) ) female_hoh,
			SUM(IF (data not like \'%"_gender":215%\' and data not like \'%"_gender":214%\' and data like \'%_headofhousehold":3108%\',1,0) ) other_hoh,
			SUM(IF (data like \'%"_gender":214%\' and data not like \'%_headofhousehold":3108%\',1,0) ) male_not_hoh,
			SUM(IF (data like \'%"_gender":215%\' and data not like \'%_headofhousehold":3108%\',1,0) ) female_not_hoh,
			SUM(IF (data not like \'%"_gender":215%\' and data not like \'%"_gender":214%\' and data not like \'%_headofhousehold":3108%\',1,0) ) other_not_hoh,
			SUM(IF (substring(sys_data, LOCATE(\'at_risk_population_ss\', sys_data), LOCATE(\']\',sys_data,LOCATE(\'at_risk_population_ss\', sys_data))-LOCATE(\'at_risk_population_ss\', sys_data) ) like \'%Ninos%\' and data like \'%"_gender":214%\' and data like \'%"_maritalstatus":3108%\' and data like \'%_headofhousehold":347%\',1,0) ) single_male_hoh_under_18,
			SUM(IF (substring(sys_data, LOCATE(\'at_risk_population_ss\', sys_data), LOCATE(\']\',sys_data,LOCATE(\'at_risk_population_ss\', sys_data))-LOCATE(\'at_risk_population_ss\', sys_data) ) like \'%Ninos%\' and data like \'%"_gender":215%\' and data like \'%"_maritalstatus":3108%\' and data like \'%_headofhousehold":347%\',1,0) ) single_female_hoh_under_18,
			SUM(IF (data like \'%"_hispanicorigin":3104%\',1,0) ) ethnicity_spanishanother,
			SUM(IF (data like \'%"_hispanicorigin":3103%\',1,0) ) ethnicity_cuban,
			SUM(IF (data like \'%"_hispanicorigin":3102%\',1,0) ) ethnicity_puertorican,
			SUM(IF (data like \'%"_hispanicorigin":3101%\',1,0) ) ethnicity_chicano,
			SUM(IF (data like \'%"_hispanicorigin":3100%\',1,0) ) ethnicity_mexicanamerican,
			SUM(IF (data like \'%"_hispanicorigin":3099%\',1,0) ) ethnicity_mexican,
			SUM(IF (data like \'%"_ethnicity":232%\',1,0) ) ethnicity_declined,
			SUM(IF (data like \'%"_ethnicity":231%\',1,0) ) ethnicity_undetermined,			
			SUM(IF (data like \'%"_ethnicity":229%\',1,0) ) ethnicity_nothispanic,			
			SUM(IF (sys_data like \'%"ethnicity":"Hispano o Latino"%\',1,0) ) ethnicity_hispanic,			
			SUM(IF (data like \'%"_race":239%\',1,0) ) race_white,
			SUM(IF (data like \'%"_race":236%\',1,0) ) race_black,
			SUM(IF (data like \'%"_race":234%\',1,0) ) race_american_indian,
			SUM(IF (data like \'%"_race":235%\',1,0) ) race_asian,
			SUM(IF (data like \'%"_race":1143%\',1,0) ) race_hawaiian,
			SUM(IF (data like \'%"_race":1137%\',1,0) ) race_refused,
			SUM(IF (data like \'%"_race":240%\',1,0) ) race_other,
			SUM(IF (data like \'%"_race":241%\',1,0) ) race_undetermined,			
			SUM(IF (data not like \'%"_race"%\',1,0) ) race_not_collected,
			SUM(IF (sys_data like \'%"housingclientdamagerating_s":"Mayor"%\',1,0) ) home_damage_major,
			SUM(IF (sys_data like \'%"housingclientdamagerating_s":"Menor"%\',1,0) ) home_damage_minor,
			SUM(IF (sys_data like \'%"housingclientdamagerating_s":"Destruido"%\',1,0) ) home_damage_destroyed,
			SUM(IF (sys_data like \'%"housingclientdamagerating_s":"No dete%\',1,0) ) home_damage_unknown,
			SUM(IF (sys_data like \'%(TSA)%\',1,0) ) home_enrolled_in_tsa,
			SUM(IF (sys_data like \'%seguro de propietario%\',1,0) ) home_homeowners_insurance,
			SUM(IF (sys_data like \'%específico para el tipo de dano%\',1,0) ) home_hazard_insurance,
			SUM(IF (sys_data like \'%Falta de cobertura de seguro apropiada%\',1,0) ) home_lackof_insurance,
			SUM(IF (sys_data like \'%Cliente no conoce su estado de seguro%\',1,0) ) home_doesntknow_insurance,
			SUM(IF (sys_data like \'%Cliente estaba asegurado pero no tiene informacion de su poliza%\',1,0) ) home_doesnthave_insurance,
			SUM(IF (sys_data like \'%Cliente no estaba asegurado%\',1,0) ) home_uninsured,
			TRUNCATE(AVG(IF (sys_data like \'%financialmonthlyincome_i%\',substring(sys_data, LOCATE(\'financialmonthlyincome_i\',sys_data)+26,LOCATE(\',\', substring(sys_data, LOCATE(\'financialmonthlyincome_i\',sys_data)+27))),0)),2) financial_income_level,
			TRUNCATE(AVG(IF (sys_data like \'%financialpercentageoffederalpoverylevel_f%\',substring(sys_data, LOCATE(\'financialpercentageoffederalpoverylevel_f\',sys_data)+43,LOCATE(\',\', substring(sys_data, LOCATE(\'financialpercentageoffederalpoverylevel_f\',sys_data)+44))),0)),2) financial_federal_poverty_level,
			SUM(IF (sys_data like \'%"employmentreferralneeded_s":"Sí"%\',1,0) ) employment_referral_needed,
			SUM(IF (sys_data like \'%"healthinsurancelostdisaster_s":"Sí"%\',1,0) ) insurance_lost_to_disaster,
			SUM(IF (sys_data like \'%"healthhavehealthinsurance_s":"Sí"%\',1,0) ) insurance_have_insurance,
			SUM(IF (sys_data like \'%"healthinsurancetype_s":"S-Chip%"%\',1,0) ) insurance_s_chip,
			SUM(IF (sys_data like \'%"transportationreferralneeded_s":"Sí"%\',1,0) ) transportation_referral_needed,
			SUM(IF (sys_data like \'%"medicalreferralneeded_s":"Sí"%\',1,0) ) health_referral_needed,
            SUM(IF (sys_data like \'%"medicalliketospeak_s":"Sí"%\',1,0) ) client_speak_to_someone,
            SUM(IF (sys_data like \'%"medicalindistress_s":"Sí"%\',1,0) ) relational_stress,			
			SUM(IF (sys_data like \'%Cuidado de Ninos%\' AND sys_data like \'%"childassesmentreferralneeded_s"%\',1,0) ) child_care_referral_needed,
			SUM(IF (sys_data like \'%"childassesmentfosterchildren_s":"Sí"%\',1,0) ) child_fostercare,
			SUM(IF (sys_data like \'%Head Start%\' AND sys_data like \'%"childassesmentreferralneeded_s"%\',1,0) ) child_headstart_referral_needed,
			SUM(IF (sys_data like \'%Manutencion de Ninos%\' AND sys_data like \'%"childassesmentreferralneeded_s"%\',1,0) ) child_support_referral_needed,
			SUM(IF ((sys_data like \'%Distrito Escolar%\' OR sys_data like \'%school supplies%\') AND sys_data like \'%"childassesmentreferralneeded_s"%\',1,0) ) child_education_support_needed,
			SUM(IF (sys_data like \'%"foodreferralneeded_s":"Sí"%\',1,0) ) food_referral_needed,
			SUM(IF (sys_data like \'%D-SNAP%\',1,0) ) dsnap_referral_needed,
			SUM(IF (sys_data like \'%"clothingreferralneeded_s":"Sí"%\',1,0) ) clothing_referral_needed,
			SUM(IF (sys_data like \'%"furnitureandappliancesreferralneeded_s":"Sí"%\',1,0) ) furniture_referral_needed,
			SUM(IF (sys_data like \'%"seniorservicesreferralneeded_s":"Sí"%\',1,0) ) senior_referral_needed,
			SUM(IF (sys_data like \'%"languagereferralneeded_s":"Sí"%\',1,0) ) language_referral_needed,
			SUM(IF (sys_data like \'%"legalservicesreferralneeded_s":"Sí"%\',1,0) ) legal_referral_needed,
			(SELECT SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1) FROM   objects, tree where objects.id = tree.id 
			AND tree.template_id = 607 and tree.name not like \'%-  []%\' and dstatus = 0 
			AND (DATE(tree.cdate) = \''.$date.'\')
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			group by SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1)
			order by count(*) desc
			limit 0,1) top_client_need,
			(SELECT SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1) FROM   objects, tree where objects.id = tree.id 
			AND tree.template_id = 607 and tree.name not like \'%-  []%\' and dstatus = 0 
			AND (DATE(tree.cdate) = \''.$date.'\')
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			group by SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1)
			order by count(*) desc			
			limit 1,1) second_client_need,
			(SELECT SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1) FROM   objects, tree where objects.id = tree.id 
			AND tree.template_id = 607 and tree.name not like \'%-  []%\' and dstatus = 0 
			AND (DATE(tree.cdate) = \''.$date.'\')
			AND tree.pid in (select id from objects 
            where (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)))
			group by SUBSTR(tree.name, 1, LOCATE(\'-\', tree.name) - 1)
			order by count(*) desc			
			limit 2,1) third_client_need,
			SUM(IF (sys_data like \'%"fematier":"Nivel 1%\',1,0) ) fema_tier_1,
			SUM(IF (sys_data like \'%"fematier":"Nivel 2%\',1,0) ) fema_tier_2,
			SUM(IF (sys_data like \'%"fematier":"Nivel 3%\',1,0) ) fema_tier_3,
			SUM(IF (sys_data like \'%"fematier":"Nivel 4%\',1,0) ) fema_tier_4
			from objects, 
			tree
			where 
			tree.template_id = 141
			and
			objects.id = tree.id
			and 
			tree.dstatus = 0
			and (je(data, \'_location_type\') in (LOCATION_STUFF) or je(data, \'_location_type.value\') in (LOCATION_STUFF)) 
			AND DATE(tree.cdate) = \''.$date.'\'
			group by tree.template_id';		
				
			//echo(str_replace("LOCATION_STUFF",$locations,$femasql));
			//echo(str_replace("LOCATION_STUFF",$locations,$ohseprsql));
		
			$res = $dbs->query(
				str_replace("LOCATION_STUFF",$locations,$femasql)
			);
			if ($r = $res->fetch()) {
				//print_r($r);
				//exit;
				$id = null;
				$sql2= 'select * from tree where template_id = 1205 and pid = '.$pid.' and (name like \'%'.$areaName.' - '.$date.'%\' or 
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
					'pid' => $pid,//3286,
					'title' => 'New FEMA Report',
					'template_id' => 1205,
					'path' => '/Test Event/Reports/Locations',
					'view' => 'edit',
					'name' => 'New FEMA Daily Report',
					'data' => $r,
					];
				$objService = new Objects();
				$newReferral =$objService->save(['data'=>$data]);
			}
				//OHSEPR Location Report
				$res = $dbs->query(
					str_replace("LOCATION_STUFF",$locations,$ohseprsql)
				);
		
				if ($r = $res->fetch()) {
						$id = null;
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
						'path' => '/Test Event/Reports/Counties',
						'view' => 'edit',
						'name' => 'New OHSEPR Daily Report',
						'data' => $r,
						];
					$objService = new Objects();
					$newReferral =$objService->save(['data'=>$data]);
				}					
	}
}