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

class CaseboxUserGroupsDeactivateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('casebox:user:deactivate')
            ->setDescription('Deactivate users based on days.')
            ->addArgument('days', InputArgument::REQUIRED, 'The days since last login required to deactivate')
			->addOption('date', 'd', InputOption::VALUE_OPTIONAL, 'Date for action log.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);

        $container = $this->getContainer();
        $system = new System();
		$coreName = ucfirst($container->getParameter('kernel.environment'));
        $system->bootstrap($container);

        $days = intval($input->getArgument('days'));
		
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
		
		$res = $dbs->query(
			'SELECT
				u.id
			FROM users_groups u
			WHERE u.type = 2 
		    and u.system = 0
			and u.enabled = 1
		    and from_unixtime(IFNULL(last_login, cdate)) < DATE_SUB(NOW(), INTERVAL $1 DAY)
	  ',
				 $days
        );

		
		while ($r = $res->fetch()) {
			$p = [
				'id' => $r['id'],
				'enabled' => false
			];
			$rez = $container->get('casebox_core.service.users_groups')->setUserEnabled($p);
		}
		unset($res);
		
		
		$res = $dbs->query(
			'select CONCAT( template_name, \'s\', \' \', action_type, \'d\') action_type, COUNT(*) action_count, CONCAT(count(*), \' \', template_name, \'s\', \' \', action_type, \'d\') action_text, action_date FROM (
				select DATE(action_log.action_time) action_date, action_log.action_time, 
				CASE WHEN (tree.template_id = 527 AND tree.name not like \'%General%\' AND tree.name != \' \') THEN tree.name 
				WHEN templates.name = \'Template\' THEN \'User\'
				ELSE templates.name END template_name, tree.template_id, tree.name object_name, 
				CASE WHEN (action_log.action_type = \'completion_on_behalf\') THEN \'Assigned\' 
				WHEN (action_log.action_type = \'login_fail\') THEN \'logins faile\' ELSE action_type END action_type, 
				users_groups.name username, users_groups.id user_id, (select name from users_groups ug, 
				users_groups_association uga where ug.id = uga.group_id and users_groups.id = uga.user_id LIMIT 1) user_role,
				CASE WHEN(tree.template_id=141) THEN tree.name ELSE parent.name END parent_name, tree.pid parent_id
				from tree, objects, action_log, templates, tree parent, users_groups
				where tree.id = objects.id 
				and tree.pid = parent.id
				and users_groups.id = action_log.user_id
				and tree.template_id = templates.id
				and tree.id
				and action_type not in (\'user_create\',\'status_change\')
				and action_log.object_id = objects.id 
				and action_log.user_id <> 1
				and date(action_log.action_time) = \''.$date.'\'
				) a
				group by template_name, action_type, action_date 
				union
				select distinct \'User Log\',\'\', \'<b>User Log</b>\', \'\' from action_log where (action_type = \'user_create\' 
				or action_type=\'status_change\') and date(action_log.action_time) = \''.$date.'\'
				union
				select CASE WHEN (action_type=\'user_create\') THEN \'User Created\'
           WHEN (action_type=\'status_change\') THEN \'Status Change\' END action_type,CONCAT(je(data,\'text\'), \' [\',DATE_FORMAT(action_log.action_time,\'%H:%i:%s\'),\']\'),CONCAT(je(data,\'text\'), \' [\',DATE_FORMAT(action_log.action_time,\'%H:%i:%s\'),\']\'), action_log.action_time 
				from action_log where (action_type = \'user_create\' 
				or action_type=\'status_change\') and date(action_log.action_time) = \''.$date.'\'
	  ',
				 $days
        );

		$list = [];
		$list[] = '
			<html>
			<head>
			  <title>Daily Action Log for '.$coreName.'</title>
			</head>
			<body>
			  <p>Here are the daily actions for today, '.$date.'</p>
			  <table><tr><td><b>Daily Summary</b></td></tr>';
			  $records = [];
		while ($r = $res->fetch()) {
			$list[] = ' <tr><td>'.$r['action_text'] . '</td></tr>';
			$records[] = $r;
		}
		unset($res);

		$list[] = '
		  </table>
		</body>
		</html>';
		
			$vars = [
				'title' => $coreName.' TRAINING Daily ECMRS Log' ,
				'columnTitle'=> ['actiontype'=>['title'=>'Action Type','title'=>'Action Type','solr_column_name'=>'action_type'],'test'=>['title'=>'Test','title'=>'Test','solr_column_name'=>'action_count']],
				'services'=>$records,
				'currentDate'=> date("m/d/Y") .  ' ' .  date("h:i:sa")
			];
		$container = Cache::get('symfony.container');
    	$twig = $container->get('twig');
		$html = $twig->render('CaseboxCoreBundle:email:reports.html.twig', $vars);	
		$dompdf = new Dompdf();
		$dompdf->loadHtml($html);
		$dompdf->setPaper('A4', 'landscape');
		$dompdf->render();
		$pdfoutput = $dompdf->output();
		$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
		$zipname = $configService->get('files_dir').DIRECTORY_SEPARATOR.$date.'_'.$coreName.'_Action_Log'.'.pdf';//.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.time().'.pdf';
		file_put_contents($zipname, $pdfoutput);
		$message = (new \Swift_Message())
		  // Give the message a subject
		  ->setSubject($vars['title'])
		  ->setFrom(['ecmrshelpdesk@apprioinc.com' => 'ECMRS Helpdesk'])
		  ->setTo(['dstoudt@apprioinc.com'])
		  ->setBody($vars['title'])
		  ->addPart(implode("",$list), 'text/html')
		  ->attach(\Swift_Attachment::fromPath($zipname))
		  ;
		$transport = new \Swift_SendmailTransport('/usr/sbin/sendmail -bs');
		$mailer = new \Swift_Mailer($transport);
		$result = $mailer->send($message);
		
        $output->success('command casebox:user:deactivate for ' . $date);
    }
}
