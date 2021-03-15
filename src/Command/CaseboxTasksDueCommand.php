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

class CaseboxTasksDueCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('casebox:tasks:due')
            ->setDescription('Alert users for tasks due in 24 hrs.')
            //->addArgument('days', InputArgument::REQUIRED, 'The days since last password change to reset force reset')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);
        $container = $this->getContainer();
        $system = new System();
		    $coreName = ucfirst($container->getParameter('kernel.environment'));
        $system->bootstrap($container);

        //$days = intval($input->getArgument('days'));

		/*if ($days == 0)
		{
			$days = 45;
		}*/


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
			'select group_concat(CONCAT(case when je(o.data, \'importance\') = 57 then \'CRITICAL\' when je(o.data, \'importance\') = 56 then \'High\'
      when je(o.data, \'importance\') = 55 then \'Medium\' when je(o.data, \'importance\') = 54 then \'Low\' end,
      concat(\' - ID: \',o.id), \' - \', t.name) SEPARATOR \'\n\t\') task, u.email
      FROM objects o, tree t, users_groups u
      WHERE
      	o.id = t.id AND
      	t.template_id = 7 AND
      	o.data LIKE \'%"assigned"%\' AND
          t.dstatus = 0 AND
          o.data LIKE \'%"task_status":1906%\' AND
          DATE(t.date_end) = DATE(now()) + INTERVAL 1 DAY AND
          je(o.data, \'assigned\') = u.id
      group by u.email');

    $rez = [];
		while ($r = $res->fetch()) {
      $rez[] = $r;
		}
		unset($res);

    foreach($rez as $task) {
      $container = Cache::get('symfony.container');
      $configService = Cache::get('symfony.container')->get('casebox_core.service.config');
      $message = (new \Swift_Message())
        // Give the message a subject
        ->setSubject('Tasks Due Tomorrow')
        ->setFrom([$configService->get('email_from') => 'ECMRS Helpdesk'])
        ->setTo($task['email'])
        ->setBody(
        'The following task(s) are due tomorrow:

        '. $task['task']
        )
        ;
      $transporter = \Swift_SmtpTransport::newInstance('smtp.gmail.com', 587, 'tls')
        ->setUsername($configService->get('email_from'))
        ->setPassword($configService->get('email_pass'));

      $mailer = new \Swift_Mailer($transporter);
      $result = $mailer->send($message);
    }

        $output->success('command casebox:tasks:due');
    }
}
