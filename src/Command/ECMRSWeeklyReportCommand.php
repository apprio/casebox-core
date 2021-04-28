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
use Dompdf\Dompdf;
use Casebox\CoreBundle\Service\Notifications;

class ECMRSWeeklyReportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('ecmrs:weekly:report')
            ->setDescription('Send Weekly Report.')
            ->addOption('days', 'days', InputOption::VALUE_OPTIONAL, 'The days since last login required to deactivate')
			->addOption('date', 'd', InputOption::VALUE_OPTIONAL, 'Date for action log.')
			->addOption('emailTo', 'et', InputArgument::IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Distribution list')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);
		$emailTo = (!empty($input->getOption('emailTo'))) ? explode(" ", $input->getOption('emailTo')) : ['icamunag@apprioinc.com'];
        $container = $this->getContainer();
        $system = new System();
		$coreName = ucfirst($container->getParameter('kernel.environment'));
        $system->bootstrap($container);

        $days = intval($input->getOption('days'));

		date_default_timezone_set('America/New_York');
		$date = (!empty($input->getOption('date'))) ? $input->getOption('date') : date('Y-m-d', time());

		if ($days == 0)
		{
			$days = 60;
		}

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

		$session->set('verified',true);
        $session->set('user', $user);

    $femasql = $dbs->query('
    select
      objects.id, name,
      date(je(data, \'report_date\')) report_date,
      je(data, \'total_cases\') total_cases,
      je(data, \'total_open_cases\') total_open_cases,
      je(data, \'total_information_only_cases\') total_information_only_cases,
      je(data, \'total_closed_cases\') total_closed_cases,
      je(data, \'total_reopen_cases\') total_reopen_cases,
      je(data, \'total_client_contact\') total_client_contact,
      je(data, \'new_open_cases\') new_open_cases,
      je(data, \'information_only\') information_only,
      je(data, \'reopen_active\') reopen_active,
      je(data, \'reopen_information_only\') reopen_information_only,
      je(data, \'closed_cases\') closed_cases,
      je(data, \'closed_records_no_recovery_plan\') closed_records_no_recovery_plan,
      je(data, \'closed_records_recovery_plan_complete\') closed_records_recovery_plan_complete,
      je(data, \'closed_records_transitioning\') closed_records_transitioning,
      je(data, \'closed_records_resources_exhaused\') closed_records_resources_exhaused,
      je(data, \'closed_records_no_longer_able_to_contact\') closed_records_no_longer_able_to_contact,
      je(data, \'closed_records_deactivated\') closed_records_deactivated,
      je(data, \'closed_records_withdrawn\') closed_records_withdrawn,
      je(data, \'closed_records_moved\') closed_records_moved,
      je(data, \'client_intake\') client_intake,
      je(data, \'assessments_total\') assessments_total,
      je(data, \'referrals_total\') referrals_total,
      je(data, \'referrals_behavioral\') referrals_behavioral,
      je(data, \'referrals_child\') referrals_child,
      je(data, \'referrals_clothing\') referrals_clothing,
      je(data, \'referrals_employment\') referrals_employment,
      je(data, \'referrals_fema\') referrals_fema,
      je(data, \'referrals_financial\') referrals_financial,
      je(data, \'referrals_food\') referrals_food,
      je(data, \'referrals_furniture\') referrals_furniture,
      je(data, \'referrals_health\') referrals_health,
      je(data, \'referrals_housing\') referrals_housing,
      je(data, \'referrals_language\') referrals_language,
      je(data, \'referrals_legal\') referrals_legal,
      je(data, \'referrals_senior\') referrals_senior,
      je(data, \'referrals_transportation\') referrals_transportation,
      je(data, \'top_client_need\') top_client_need,
      je(data, \'second_client_need\') second_client_need,
      je(data, \'third_client_need\') third_client_need,
      je(data, \'fema_tier_1\') fema_tier_1,
      je(data, \'fema_tier_2\') fema_tier_2,
      je(data, \'fema_tier_3\') fema_tier_3,
      je(data, \'fema_tier_4\') fema_tier_4,
      je(data, \'case_mamager_total\') case_manager_total,
      je(data, \'case_manager_supervisor_total\') case_manager_supervisor_total,
      je(data, \'case_manager_to_supervisor_ratio\') case_manager_to_supervisor_ratio,
      je(data, \'case_manager_to_client_ratio\') case_manager_to_client_ratio
      from objects, tree
      where objects.id = tree.id and template_id = 1205 and date(je(data, \'report_date\')) > date(NOW() - INTERVAL 7 DAY) and pid = 1204 and dstatus = 0'
    );


    	$ohseprsql = $dbs->query('
      select objects.id, name,
          date(je(data, \'report_date\')) report_date,
          je(data, \'client_fema_registrations\') client_fema_registrations,
          je(data, \'fema_help\') fema_help,
          je(data, \'sba_help\') sba_help,
          je(data, \'self_reported_disabilities\') self_reported_disabilities,
          je(data, \'self_reported_limited_english\') self_reported_limited_english,
          je(data, \'self_reported_children\') self_reported_children,
          je(data, \'self_reported_elderly\') self_reported_elderly,
          je(data, \'male_hoh\') male_hoh,
          je(data, \'female_hoh\') female_hoh,
          je(data, \'other_hoh\') other_hoh,
          je(data, \'male_not_hoh\') male_not_hoh,
          je(data, \'female_not_hoh\') female_not_hoh,
          je(data, \'other_not_hoh\') other_not_hoh,
          je(data, \'single_male_hoh_under_18\') single_male_hoh_under_18,
          je(data, \'single_female_hoh_under_18\') single_female_hoh_under_18,
          je(data, \'ethnicity_spanishanother\') ethnicity_spanishanother,
          je(data, \'ethnicity_cuban\') ethnicity_cuban,
          je(data, \'ethnicity_puertorican\') ethnicity_puertorican,
          je(data, \'ethnicity_chicano\') ethnicity_chicano,
          je(data, \'ethnicity_mexicanamerican\') ethnicity_mexicanamerican,
          je(data, \'ethnicity_mexican\') ethnicity_mexican,
          je(data, \'ethnicity_declined\') ethnicity_declined,
          je(data, \'ethnicity_undetermined\') ethnicity_undetermined,
          je(data, \'ethnicity_nothispanic\') ethnicity_nothispanic,
          je(data, \'ethnicity_hispanic\') ethnicity_hispanic,
          je(data, \'race_white\') race_white,
          je(data, \'race_black\') race_black,
          je(data, \'race_american_indian\') race_american_indian,
          je(data, \'race_asian\') race_asian,
          je(data, \'race_hawaiian\') race_hawaiian,
          je(data, \'race_refused\') race_refused,
          je(data, \'race_other\') race_other,
          je(data, \'race_undetermined\') race_undetermined,
          je(data, \'race_not_collected\') race_not_collected,
          je(data, \'home_damage_major\') home_damage_major,
          je(data, \'home_damage_minor\') home_damage_minor,
          je(data, \'home_damage_destroyed\') home_damage_destroyed,
          je(data, \'home_damage_unknown\') home_damage_unknown,
          je(data, \'home_enrolled_in_tsa\') home_enrolled_in_tsa,
          je(data, \'home_homeowners_insurance\') home_homeowners_insurance,
          je(data, \'home_hazard_insurance\') home_hazard_insurance,
          je(data, \'home_lackof_insurance\') home_lackof_insurance,
          je(data, \'home_doesntknow_insurance\') home_doesntknow_insurance,
          je(data, \'home_doesnthave_insurance\') home_doesnthave_insurance,
          je(data, \'home_uninsured\') home_uninsured,
          je(data, \'financial_income_level\') financial_income_level,
          je(data, \'financial_federal_poverty_level\') financial_federal_poverty_level,
          je(data, \'employment_referral_needed\') employment_referral_needed,
          je(data, \'insurance_lost_to_disaster\') insurance_lost_to_disaster,
          je(data, \'insurance_have_insurance\') insurance_have_insurance,
          je(data, \'insurance_s_chip\') insurance_s_chip,
          je(data, \'transportation_referral_needed\') transportation_referral_needed,
          je(data, \'health_referral_needed\') health_referral_needed,
          je(data, \'client_speak_to_someone\') client_speak_to_someone,
          je(data, \'relational_stress\') relational_stress,
          je(data, \'child_care_referral_needed\') child_care_referral_needed,
          je(data, \'child_fostercare\') child_fostercare,
          je(data, \'child_headstart_referral_needed\') child_headstart_referral_needed,
          je(data, \'child_support_referral_needed\') child_support_referral_needed,
          je(data, \'child_education_support_needed\') child_education_support_needed,
          je(data, \'food_referral_needed\') food_referral_needed,
          je(data, \'dsnap_referral_needed\') dsnap_referral_needed,
          je(data, \'clothing_referral_needed\') clothing_referral_needed,
          je(data, \'furniture_referral_needed\') furniture_referral_needed,
          je(data, \'senior_referral_needed\') senior_referral_needed,
          je(data, \'language_referral_needed\') language_referral_needed,
          je(data, \'legal_referral_needed\') legal_referral_needed,
          je(data, \'top_client_need\') top_client_need,
          je(data, \'second_client_need\') second_client_need,
          je(data, \'third_client_need\') third_client_need,
          je(data, \'fema_tier_1\') fema_tier_1,
          je(data, \'fema_tier_2\') fema_tier_2,
          je(data, \'fema_tier_3\') fema_tier_3,
          je(data, \'fema_tier_4\') fema_tier_4
          from objects, tree
          where objects.id = tree.id and template_id = 1976 and dstatus = 0 and pid = 1204 and date(je(data, \'report_date\')) > date(NOW() - INTERVAL 7 DAY)
      ');

    $referralsql = $dbs->query('
    select
    	b.*,
    	CONCAT(total_results,\':\',(select count(*) from rpt_referrals rds where je(sys_data, \'solr.resultname_s\') NOT LIKE \'N/A\' and ifnull(DATE(je(rds.sys_data, \'solr.result_dt\')), IFNULL(DATE(rds.udate), DATE(rds.cdate))) <= report_date )) totes
    FROM
    	(SELECT
    		CONCAT( ifnull(DATE(je(a.sys_data, \'solr.result_dt\')), IFNULL(DATE(a.udate), DATE(a.cdate))), \'T00:00:00Z\') report_dt,
    		DATE(ifnull(je(a.sys_data, \'solr.result_dt\'), IFNULL(a.udate, a.cdate))) report_date,
    		SUM(IF(je(a.sys_data, \'solr.resultname_s\') = \'Unmet\', 1, 0)) referral_unmet,
    		SUM(IF(je(a.sys_data, \'solr.resultname_s\') = \'N/A\', 1, 0)) referral_na,
    		SUM(IF(je(a.sys_data, \'solr.resultname_s\') = \'Information Only\', 1, 0)) referral_information_only,
    		SUM(IF(je(a.sys_data, \'solr.resultname_s\') = \'No Show\', 1, 0)) referral_noshow,
    		SUM(IF(je(a.sys_data, \'solr.resultname_s\') = \'Rejected\', 1, 0)) referral_rejected,
    		SUM(IF(je(a.sys_data, \'solr.resultname_s\') = \'Met-uninterested/refused\', 1, 0)) referral_refused,
    		SUM(IF(je(a.sys_data, \'solr.resultname_s\') = \'Service Provided\', 1, 0)) referral_provided,
    		SUM( IF( je(a.sys_data, \'solr.resultname_s\') = \'Unmet-resources not available\', 1, 0)) referral_not_available,
    		SUM( IF(je(a.sys_data, \'solr.resultname_s\') = \'Met-service rendered\', 1, 0)) referral_met_service_rendered,
    		SUM( IF(je(a.sys_data, \'solr.resultname_s\') NOT LIKE \'N/A\', 1, 0)) total_results, Count(*) - Sum(IF(je(a.sys_data, \'solr.resultname_s\') = \'Information Only\', 1, 0)) total_referrals,
    		COUNT(je(c.sys_data, \'assessments_completed\')) information_only_assessments
    	FROM rpt_referrals a, objects c
    	WHERE a.cdate >= (DATE(NOW()) - INTERVAL 7 DAY) AND je(c.sys_data, \'case_status\') = \'Information Only\'
    	GROUP BY CONCAT( ifnull(DATE(je(a.sys_data, \'solr.result_dt\')), IFNULL(DATE(a.udate), DATE(a.cdate))), \'T00:00:00Z\'), DATE(ifnull(je(a.sys_data, \'solr.result_dt\'), IFNULL(a.udate, a.cdate))) ) b
      ');

		$list = [];
		$list[] = '
			<html>
			<head>
			  <title>Weekly Report for '.$coreName.'</title>
			</head>
			<body>
			  <p>Here are the reports for today, '.$date.'</p>
			  <table><tr><td><b>Daily Summary</b></td></tr>';
			  $femarecords = [];
        $ohseprrecords = [];
        $referralrecords = [];

		while ($r = $femasql->fetch()) {
			$list[] = ' <tr><td>'.$r['name'] . '</td></tr>';
			$femarecords[] = $r;
		}
		unset($femasql);

    while ($r = $ohseprsql->fetch()) {
			$list[] = ' <tr><td>'.$r['name'] . '</td></tr>';
			$ohseprrecords[] = $r;
		}
		unset($ohseprsql);

    while ($r = $referralsql->fetch()) {
			$list[] = ' <tr><td>'.$r['name'] . '</td></tr>';
			$referralrecords[] = $r;
		}
		unset($referralsql);

		$list[] = '
		  </table>
		</body>
		</html>';

			$femavars = [
				'title' => $coreName.' Immediate Disaster Case Management Summary' ,
				'columnTitle'=> [
            'report_date'=>['title'=>'Report Date','title'=>'Report Date','solr_column_name'=>'report_date'],
            'total_cases'=>['title'=>'Total Cases Entered in ECMRS','title'=>'Total Cases Entered in ECMRS','solr_column_name'=>'total_cases'],
            'total_open_cases'=>['title'=>'Total Open Cases','title'=>'Total Open Cases','solr_column_name'=>'total_open_cases'],
            'total_information_only_cases'=>['title'=>'Total Information Only Cases','title'=>'Total Information Only Cases','solr_column_name'=>'total_information_only_cases'],
            'total_closed_cases'=>['title'=>'Total Closed Cases','title'=>'Total Closed Cases','solr_column_name'=>'total_closed_cases'],
            'total_reopen_cases'=>['title'=>'Total Re-Opened Cases','title'=>'Total Re-Opened Cases','solr_column_name'=>'total_reopen_cases'],
            'total_client_contact'=>['title'=>'Disaster Survivor Contact (Daily)','title'=>'Disaster Survivor Contact (Daily)','solr_column_name'=>'total_client_contact'],
            'new_open_cases'=>['title'=>'New Open Cases (Daily)','title'=>'New Open Cases (Daily)','solr_column_name'=>'new_open_cases'],
            'information_only'=>['title'=>'New Information Only Cases (Daily)','title'=>'New Information Only Cases (Daily)','solr_column_name'=>'information_only'],
            'reopen_active'=>['title'=>'Re-Opened Active Cases (Daily)','title'=>'Re-Opened Active Cases (Daily)','solr_column_name'=>'reopen_active'],
            'reopen_information_only'=>['title'=>'Re-Opened Information Only Cases (Daily)','title'=>'Re-Opened Information Only Cases (Daily)','solr_column_name'=>'reopen_information_only'],
            'closed_cases'=>['title'=>'Case Records Closed Total (Daily)','title'=>'Case Records Closed Total (Daily)','solr_column_name'=>'closed_cases'],
            'closed_records_no_recovery_plan'=>['title'=>'Closed Records - No Recovery Plan Complete','title'=>'Closed Records - No Recovery Plan Complete','solr_column_name'=>'closed_records_no_recovery_plan'],
            'closed_records_recovery_plan_complete'=>['title'=>'Closed Records - Recovery Plan Complete','title'=>'Closed Records - Recovery Plan Complete','solr_column_name'=>'closed_records_recovery_plan_complete'],
            'closed_records_transitioning'=>['title'=>'Closed Records - Transitioning','title'=>'Closed Records - Transitioning','solr_column_name'=>'closed_records_transitioning'],
            'closed_records_resources_exhaused'=>['title'=>'Closed Records - Resources Exhausted','title'=>'Closed Records - Resources Exhausted','solr_column_name'=>'closed_records_resources_exhaused'],
            'closed_records_no_longer_able_to_contact'=>['title'=>'Closed Records - No Longer Able to Contact Disaster Survivor','title'=>'Closed Records - No Longer Able to Contact Disaster Survivor','solr_column_name'=>'closed_records_no_longer_able_to_contact'],
            'closed_records_deactivated'=>['title'=>'Closed Records - Deactivated','title'=>'Closed Records - Deactivated','solr_column_name'=>'closed_records_deactivated'],
            'closed_records_withdrawn'=>['title'=>'Closed Records - Withdrawn','title'=>'Closed Records - Withdrawn','solr_column_name'=>'closed_records_withdrawn'],
            'closed_records_moved'=>['title'=>'Closed Records - Disaster Survivor has moved','title'=>'Closed Records - Disaster Survivor has moved','solr_column_name'=>'closed_records_moved'],
            'client_intake'=>['title'=>'Disaster Survivor Intake Completed','title'=>'Disaster Survivor Intake Completed','solr_column_name'=>'client_intake'],
            'assessments_total'=>['title'=>'Total Assessments Completed','title'=>'Total Assessments Completed','solr_column_name'=>'assessments_total'],
            'referrals_total'=>['title'=>'Referral Totals','title'=>'Referral Totals','solr_column_name'=>'referrals_total'],
            'referrals_behavioral'=>['title'=>'Behavioral Health Referrals','title'=>'Behavioral Health Referrals','solr_column_name'=>'referrals_behavioral'],
            'referrals_child'=>['title'=>'Child Services Referrals','title'=>'Child Services Referrals','solr_column_name'=>'referrals_child'],
            'referrals_clothing'=>['title'=>'Clothing Referrals','title'=>'Clothing Referrals','solr_column_name'=>'referrals_clothing'],
            'referrals_employment'=>['title'=>'Employment Referrals','title'=>'Employment Referrals','solr_column_name'=>'referrals_employment'],
            'referrals_fema'=>['title'=>'FEMA Referrals','title'=>'FEMA Referrals','solr_column_name'=>'referrals_fema'],
            'referrals_financial'=>['title'=>'Financial Referrals','title'=>'Financial Referrals','solr_column_name'=>'referrals_financial'],
            'referrals_food'=>['title'=>'Food Referrals','title'=>'Food Referrals','solr_column_name'=>'referrals_food'],
            'referrals_furniture'=>['title'=>'Furniture Referrals','title'=>'Furniture Referrals','solr_column_name'=>'referrals_furniture'],
            'referrals_health'=>['title'=>'Health Referrals','title'=>'Health Referrals','solr_column_name'=>'referrals_health'],
            'referrals_housing'=>['title'=>'Housing Referrals','title'=>'Housing Referrals','solr_column_name'=>'referrals_housing'],
            'referrals_language'=>['title'=>'Language Referrals','title'=>'Language Referrals','solr_column_name'=>'referrals_language'],
            'referrals_legal'=>['title'=>'Legal Referrals','title'=>'Legal Referrals','solr_column_name'=>'referrals_legal'],
            'referrals_senior'=>['title'=>'Senior Referrals','title'=>'Senior Referrals','solr_column_name'=>'referrals_senior'],
            'referrals_transportation'=>['title'=>'Transportation Referrals','title'=>'Transportation Referrals','solr_column_name'=>'referrals_transportation'],
            'top_client_need'=>['title'=>'Top Disaster Survivor Unmet Need','title'=>'Top Disaster Survivor Unmet Need','solr_column_name'=>'top_client_need'],
            'second_client_need'=>['title'=>'Second Disaster Survivor Unmet Need','title'=>'Second Disaster Survivor Unmet Need','solr_column_name'=>'second_client_need'],
            'third_client_need'=>['title'=>'Third Disaster Survivor Unmet Need','title'=>'Third Disaster Survivor Unmet Need','solr_column_name'=>'third_client_need'],
            'fema_tier_1'=>['title'=>'FEMA Tier 1','title'=>'FEMA Tier 1','solr_column_name'=>'fema_tier_1'],
            'fema_tier_2'=>['title'=>'FEMA Tier 2','title'=>'FEMA Tier 2','solr_column_name'=>'fema_tier_2'],
            'fema_tier_3'=>['title'=>'FEMA Tier 3','title'=>'FEMA Tier 3','solr_column_name'=>'fema_tier_3'],
            'fema_tier_4'=>['title'=>'FEMA Tier 4','title'=>'FEMA Tier 4','solr_column_name'=>'fema_tier_4'],
            'case_mamager_total'=>['title'=>'IDCM Worker Total','title'=>'IDCM Worker Total','solr_column_name'=>'case_mamager_total'],
            'case_manager_supervisor_total'=>['title'=>'IDCM Worker Supervisor Total','title'=>'IDCM Worker Supervisor Total','solr_column_name'=>'case_manager_supervisor_total'],
            'case_manager_to_supervisor_ratio'=>['title'=>'Supervisor to IDCM Worker Ratio','title'=>'Supervisor to IDCM Worker Ratio','solr_column_name'=>'case_manager_to_supervisor_ratio'],
            'case_manager_to_client_ratio'=>['title'=>'IDCM Worker To Disaster Survivor Ratio','title'=>'IDCM Worker To Disaster Survivor Ratio','solr_column_name'=>'case_manager_to_client_ratio']
        ],
				'services'=>$femarecords,
				'currentDate'=> date("m/d/Y") .  ' ' .  date("h:i:sa")
			];

      $ohseprvars = [
				'title' => $coreName.' Supplemental Reporting Data' ,
				'columnTitle'=> [
          'report_date'=>['title'=>'Report Date','title'=>'Report Date','solr_column_name'=>'report_date'],
          'client_fema_registrations'=>['title'=>'Disaster Survivor Registrations','title'=>'Disaster Survivor Registrations','solr_column_name'=>'client_fema_registrations'],
          'fema_help'=>['title'=>'FEMA Help Needed','title'=>'FEMA Help Needed','solr_column_name'=>'fema_help'],
          'sba_help'=>['title'=>'SBA Help Needed','title'=>'SBA Help Needed','solr_column_name'=>'sba_help'],
          'self_reported_disabilities'=>['title'=>'Self-Reported Disabilities','title'=>'Self-Reported Disabilities','solr_column_name'=>'self_reported_disabilities'],
          'self_reported_limited_english'=>['title'=>'Self-Reported Limited English','title'=>'Self-Reported Limited English','solr_column_name'=>'self_reported_limited_english'],
          'self_reported_children'=>['title'=>'Self-Reported Child Issues','title'=>'Self-Reported Child Issues','solr_column_name'=>'self_reported_children'],
          'self_reported_elderly'=>['title'=>'Self-Reported Elderly','title'=>'Self-Reported Elderly','solr_column_name'=>'self_reported_elderly'],
          'male_hoh'=>['title'=>'Male HoH','title'=>'Male HoH','solr_column_name'=>'male_hoh'],
          'female_hoh'=>['title'=>'Female HoH','title'=>'Female HoH','solr_column_name'=>'female_hoh'],
          'other_hoh'=>['title'=>'Other HoH','title'=>'Other HoH','solr_column_name'=>'other_hoh'],
          'male_not_hoh'=>['title'=>'Male Not HoH','title'=>'Male Not HoH','solr_column_name'=>'male_not_hoh'],
          'female_not_hoh'=>['title'=>'Female Not HoH','title'=>'Female Not HoH','solr_column_name'=>'female_not_hoh'],
          'other_not_hoh'=>['title'=>'Other Not HoH','title'=>'Other Not HoH','solr_column_name'=>'other_not_hoh'],
          'single_male_hoh_under_18'=>['title'=>'Single Male HoH Under 18','title'=>'Single Male HoH Under 18','solr_column_name'=>'single_male_hoh_under_18'],
          'single_female_hoh_under_18'=>['title'=>'Single Female HoH Under 18','title'=>'Single Female HoH Under 18','solr_column_name'=>'single_female_hoh_under_18'],
          'ethnicity_spanishanother'=>['title'=>'Ethnicity - Another Spanish Origin','title'=>'Ethnicity - Another Spanish Origin','solr_column_name'=>'ethnicity_spanishanother'],
          'ethnicity_cuban'=>['title'=>'Ethnicity Cuban','title'=>'Ethnicity Cuban','solr_column_name'=>'ethnicity_cuban'],
          'ethnicity_puertorican'=>['title'=>'Ethnicity Puerto Rican','title'=>'Ethnicity Puerto Rican','solr_column_name'=>'ethnicity_puertorican'],
          'ethnicity_chicano'=>['title'=>'Ethnicity Chicano','title'=>'Ethnicity Chicano','solr_column_name'=>'ethnicity_chicano'],
          'ethnicity_mexicanamerican'=>['title'=>'Ethnicity Mexican American','title'=>'Ethnicity Mexican American','solr_column_name'=>'ethnicity_mexicanamerican'],
          'ethnicity_mexican'=>['title'=>'Ethnicity Mexican','title'=>'Ethnicity Mexican','solr_column_name'=>'ethnicity_mexican'],
          'ethnicity_declined'=>['title'=>'Ethnicity Declined','title'=>'Ethnicity Declined','solr_column_name'=>'ethnicity_declined'],
          'ethnicity_undetermined'=>['title'=>'Ethnicity Undetermined','title'=>'Ethnicity Undetermined','solr_column_name'=>'ethnicity_undetermined'],
          'ethnicity_nothispanic'=>['title'=>'Ethnicity Not Hispanic','title'=>'Ethnicity Not Hispanic','solr_column_name'=>'ethnicity_nothispanic'],
          'ethnicity_hispanic'=>['title'=>'Ethnicity Hispanic','title'=>'Ethnicity Hispanic','solr_column_name'=>'ethnicity_hispanic'],
          'race_white'=>['title'=>'Race White','title'=>'Race White','solr_column_name'=>'race_white'],
          'race_black'=>['title'=>'Race Black','title'=>'Race Black','solr_column_name'=>'race_black'],
          'race_american_indian'=>['title'=>'Race Americian Indian','title'=>'Race Americian Indian','solr_column_name'=>'race_american_indian'],
          'race_asian'=>['title'=>'Race Asian','title'=>'Race Asian','solr_column_name'=>'race_asian'],
          'race_hawaiian'=>['title'=>'Race Hawaiian','title'=>'Race Hawaiian','solr_column_name'=>'race_hawaiian'],
          'race_refused'=>['title'=>'Race Refused','title'=>'Race Refused','solr_column_name'=>'race_refused'],
          'race_other'=>['title'=>'Race Other','title'=>'Race Other','solr_column_name'=>'race_other'],
          'race_undetermined'=>['title'=>'Race Undetermined','title'=>'Race Undetermined','solr_column_name'=>'race_undetermined'],
          'race_not_collected'=>['title'=>'Race Not Collected','title'=>'Race Not Collected','solr_column_name'=>'race_not_collected'],
          'home_damage_major'=>['title'=>'Home Damage Major','title'=>'Home Damage Major','solr_column_name'=>'home_damage_major'],
          'home_damage_minor'=>['title'=>'Home Damage Minor','title'=>'Home Damage Minor','solr_column_name'=>'home_damage_minor'],
          'home_damage_destroyed'=>['title'=>'Damage or Destroyed Home','title'=>'Damage or Destroyed Home','solr_column_name'=>'home_damage_destroyed'],
          'home_damage_unknown'=>['title'=>'Damage Unknown','title'=>'Damage Unknown','solr_column_name'=>'home_damage_unknown'],
          'home_enrolled_in_tsa'=>['title'=>'Home Enrolled in TSA','title'=>'Home Enrolled in TSA','solr_column_name'=>'home_enrolled_in_tsa'],
          'home_homeowners_insurance'=>['title'=>'Had Homeowners Insurance','title'=>'Had Homeowners Insurance','solr_column_name'=>'home_homeowners_insurance'],
          'home_hazard_insurance'=>['title'=>'Hazard Specific Insurance','title'=>'Hazard Specific Insurance','solr_column_name'=>'home_hazard_insurance'],
          'home_lackof_insurance'=>['title'=>'Lacked Appropriate Coverage','title'=>'Lacked Appropriate Coverage','solr_column_name'=>'home_lackof_insurance'],
          'home_doesntknow_insurance'=>['title'=>'Does not know insurance for home','title'=>'Does not know insurance for home','solr_column_name'=>'home_doesntknow_insurance'],
          //'home_doesnthave_insurance'=>['title'=>'home_doesnthave_insurance','title'=>'home_doesnthave_insurance','solr_column_name'=>'home_doesnthave_insurance'],
          'home_uninsured'=>['title'=>'Home Uninsured','title'=>'Home Uninsured','solr_column_name'=>'home_uninsured'],
          'financial_income_level'=>['title'=>'Median Monthly Income','title'=>'Median Monthly Income','solr_column_name'=>'financial_income_level'],
          //'financial_federal_poverty_level'=>['title'=>'financial_federal_poverty_level','title'=>'financial_federal_poverty_level','solr_column_name'=>'financial_federal_poverty_level'],
          'employment_referral_needed'=>['title'=>'Employment Referral Need','title'=>'Employment Referral Need','solr_column_name'=>'employment_referral_needed'],
          'insurance_lost_to_disaster'=>['title'=>'Healthcare insurance Lost to Disaster','title'=>'Healthcare insurance Lost to Disaster','solr_column_name'=>'insurance_lost_to_disaster'],
          'insurance_have_insurance'=>['title'=>'Has Healthcare Insurance','title'=>'Has Healthcare Insurance','solr_column_name'=>'insurance_have_insurance'],
          'insurance_s_chip'=>['title'=>'S-CHIP','title'=>'S-CHIP','solr_column_name'=>'insurance_s_chip'],
          'transportation_referral_needed'=>['title'=>'Transportation Referral Needed','title'=>'Transportation Referral Needed','solr_column_name'=>'transportation_referral_needed'],
          'health_referral_needed'=>['title'=>'Behavioral Health Referral Needed','title'=>'Behavioral Health Referral Needed','solr_column_name'=>'health_referral_needed'],
          'client_speak_to_someone'=>['title'=>'Disaster Survivor wants to speak to someone','title'=>'Disaster Survivor wants to speak to someone','solr_column_name'=>'client_speak_to_someone'],
          'relational_stress'=>['title'=>'Household members in distress','title'=>'Household members in distress','solr_column_name'=>'relational_stress'],
          'child_care_referral_needed'=>['title'=>'Child Care Referral Needed','title'=>'Child Care Referral Needed','solr_column_name'=>'child_care_referral_needed'],
          'child_fostercare'=>['title'=>'Child Foster Care Referral Needed','title'=>'Child Foster Care Referral Needed','solr_column_name'=>'child_fostercare'],
          'child_headstart_referral_needed'=>['title'=>'Child Headstart Referral Needed','title'=>'Child Headstart Referral Needed','solr_column_name'=>'child_headstart_referral_needed'],
          'child_support_referral_needed'=>['title'=>'Child Support Referral Needed','title'=>'Child Support Referral Needed','solr_column_name'=>'child_support_referral_needed'],
          'child_education_support_needed'=>['title'=>'Child Education Support Needed','title'=>'Child Education Support Needed','solr_column_name'=>'child_education_support_needed'],
          'food_referral_needed'=>['title'=>'Food Referral Needed','title'=>'Food Referral Needed','solr_column_name'=>'food_referral_needed'],
          'dsnap_referral_needed'=>['title'=>'Clients Seeking D-SNAP','title'=>'Clients Seeking D-SNAP','solr_column_name'=>'dsnap_referral_needed'],
          'clothing_referral_needed'=>['title'=>'Clothing Referral Needed','title'=>'Clothing Referral Needed','solr_column_name'=>'clothing_referral_needed'],
          'furniture_referral_needed'=>['title'=>'Furniture Referral Needed','title'=>'Furniture Referral Needed','solr_column_name'=>'furniture_referral_needed'],
          'senior_referral_needed'=>['title'=>'Senior Services Referral Needed','title'=>'Senior Services Referral Needed','solr_column_name'=>'senior_referral_needed'],
          'language_referral_needed'=>['title'=>'Language Referral Needed','title'=>'Language Referral Needed','solr_column_name'=>'language_referral_needed'],
          'legal_referral_needed'=>['title'=>'Legal Referral Needed','title'=>'Legal Referral Needed','solr_column_name'=>'legal_referral_needed'],
          'top_client_need'=>['title'=>'Top Disaster Survivor Need','title'=>'Top Disaster Survivor Need','solr_column_name'=>'top_client_need'],
          'second_client_need'=>['title'=>'Second Disaster Survivor Need','title'=>'Second Disaster Survivor Need','solr_column_name'=>'second_client_need'],
          'third_client_need'=>['title'=>'Third Disaster Survivor Need','title'=>'Third Disaster Survivor Need','solr_column_name'=>'third_client_need'],
          'fema_tier_1'=>['title'=>'FEMA Tier 1','title'=>'FEMA Tier 1','solr_column_name'=>'fema_tier_1'],
          'fema_tier_2'=>['title'=>'FEMA Tier 2','title'=>'FEMA Tier 2','solr_column_name'=>'fema_tier_2'],
          'fema_tier_3'=>['title'=>'FEMA Tier 3','title'=>'FEMA Tier 3','solr_column_name'=>'fema_tier_3'],
          'fema_tier_4'=>['title'=>'FEMA Tier 4','title'=>'FEMA Tier 4','solr_column_name'=>'fema_tier_4']
        ],
				'services'=>$ohseprrecords,
				'currentDate'=> date("m/d/Y") .  ' ' .  date("h:i:sa")
			];

      $referralvars = [
				'title' => $coreName.' FEMA Referral Results' ,
				'columnTitle'=> [
          'report_dt'=>['title'=>'Report Date','title'=>'Report Date','solr_column_name'=>'report_dt'],
          'totes'=>['title'=>'Ratio of Referrals with Result Indicated to all Referrals Made','title'=>'Ratio of Referrals with Result Indicated to all Referrals Made','solr_column_name'=>'totes'],
          'referral_unmet'=>['title'=>'Referral Result: Unmet','title'=>'Referral Result: Unmet','solr_column_name'=>'referral_unmet'],
          'referral_na'=>['title'=>'Referral Result: N/A','title'=>'Referral Result: N/A','solr_column_name'=>'referral_na'],
          'referral_information_only'=>['title'=>'Referral Result: Information Only','title'=>'Referral Result: Information Only','solr_column_name'=>'referral_information_only'],
          'referral_noshow'=>['title'=>'Referral Result: No Show','title'=>'Referral Result: No Show','solr_column_name'=>'referral_noshow'],
          'referral_rejected'=>['title'=>'Referral Result: Rejected','title'=>'Referral Result: Rejected','solr_column_name'=>'referral_rejected'],
          'referral_refused'=>['title'=>'Referral Result: Met-uninterested/refused','title'=>'Referral Result: Met-uninterested/refused','solr_column_name'=>'referral_refused'],
          'referral_provided'=>['title'=>'Referral Result: Service Provided','title'=>'Referral Result: Service Provided','solr_column_name'=>'referral_provided'],
          'referral_not_available'=>['title'=>'Referral Result: Unmet-resources not available','title'=>'Referral Result: Unmet-resources not available','solr_column_name'=>'referral_not_available'],
          'referral_met_service_rendered'=>['title'=>'Referral Result: Met-service rendered','title'=>'Referral Result: Met-service rendered','solr_column_name'=>'referral_met_service_rendered'],
          'total_results'=>['title'=>'Total Referral Results','title'=>'Total Referral Results','solr_column_name'=>'total_results'],
          //'total_referrals'=>['title'=>'total_referrals','title'=>'total_referrals','solr_column_name'=>'total_referrals'],
          'information_only_assessments'=>['title'=>'Assessments Completed: Information Only','title'=>'Assessments Completed: Information Only','solr_column_name'=>'information_only_assessments']
        ],
				'services'=>$referralrecords,
				'currentDate'=> date("m/d/Y") .  ' ' .  date("h:i:sa")
			];

		$container = Cache::get('symfony.container');
    $twig = $container->get('twig');
		$femahtml = $twig->render('CaseboxCoreBundle:email:weekly_report.html.twig', $femavars);
		$femadompdf = new Dompdf();
		$femadompdf->loadHtml($femahtml);
		$femadompdf->setPaper('A4', 'landscape');
		$femadompdf->render();
		$femapdfoutput = $femadompdf->output();
		$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
		$femazipname = $configService->get('files_dir').DIRECTORY_SEPARATOR.$date.'_'.$coreName.'_FEMA_Weekly_Report'.'.pdf';//.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.time().'.pdf';
		file_put_contents($femazipname, $femapdfoutput);

    $ohseprhtml = $twig->render('CaseboxCoreBundle:email:weekly_report.html.twig', $ohseprvars);
		$ohseprdompdf = new Dompdf();
		$ohseprdompdf->loadHtml($ohseprhtml);
		$ohseprdompdf->setPaper('A4', 'landscape');
		$ohseprdompdf->render();
		$ohseprpdfoutput = $ohseprdompdf->output();
		$ohseprzipname = $configService->get('files_dir').DIRECTORY_SEPARATOR.$date.'_'.$coreName.'_OHSEPR_Weekly_Report'.'.pdf';//.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.time().'.pdf';
		file_put_contents($ohseprzipname, $ohseprpdfoutput);

    $referralhtml = $twig->render('CaseboxCoreBundle:email:weekly_report.html.twig', $referralvars);
		$referraldompdf = new Dompdf();
		$referraldompdf->loadHtml($referralhtml);
		$referraldompdf->setPaper('A4', 'landscape');
		$referraldompdf->render();
		$referralpdfoutput = $referraldompdf->output();
		$referralzipname = $configService->get('files_dir').DIRECTORY_SEPARATOR.$date.'_'.$coreName.'_Referral_Weekly_Report'.'.pdf';//.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.time().'.pdf';
		file_put_contents($referralzipname, $referralpdfoutput);

		$message = (new \Swift_Message())
		  // Give the message a subject
		  ->setSubject('ECMRS Weekly Report')
		  ->setFrom([$configService->get('email_from') => 'ECMRS Helpdesk'])
		  ->setTo($emailTo)
		  ->setBody('test')
		  ->addPart(implode("",$list), 'text/html')
		  ->attach(\Swift_Attachment::fromPath($femazipname))
      ->attach(\Swift_Attachment::fromPath($ohseprzipname))
      ->attach(\Swift_Attachment::fromPath($referralzipname))
		  ;
		//$transport = new \Swift_SendmailTransport('/usr/sbin/sendmail -bs');
		$transporter = \Swift_SmtpTransport::newInstance('smtp.gmail.com', 587, 'tls')
		  ->setUsername($configService->get('email_from'))
		  ->setPassword($configService->get('email_pass'));

		$mailer = new \Swift_Mailer($transporter);
		$result = $mailer->send($message);

        $output->success('command ecmrs:weekly:report for ' . $date);
    }
}
