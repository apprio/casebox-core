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

class CaseboxEmailReportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('casebox:email:report')
            ->setDescription('Email report users based on days.')
			->addOption('date', 'd', InputOption::VALUE_OPTIONAL, 'Date for action log.')
			->addOption('emailTo', 'et', InputArgument::IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Distribution list')		
			->addOption('reports', 'r', InputArgument::IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Report list')	
			->addOption('subject', 's', InputArgument::IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Email subject')				
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);
		$container = $this->getContainer();
        $system = new System();
		$coreName = ucfirst($container->getParameter('kernel.environment'));
        $system->bootstrap($container);
		
		date_default_timezone_set('America/New_York');
		$date = (!empty($input->getOption('date'))) ? $input->getOption('date') : date('Y-m-d', time());
		$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
		$session = $container->get('session');
		
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
		
		$emailTo = (!empty($input->getOption('emailTo'))) ? explode(" ", $input->getOption('emailTo')) : [$configService->get('email_to')];
		$reportList = (!empty($input->getOption('reports'))) ? explode(" ", $input->getOption('reports')) : explode(',',$configService->get('email_reports'));
		$emailSubject = (!empty($input->getOption('subject'))) ? $input->getOption('subject') : 'ECMRS Daily Report';
		$message = (new \Swift_Message())
		  // Give the message a subject
		  ->setSubject($emailSubject)
		  ->setFrom([$configService->get('email_from') => 'ECMRS Helpdesk'])
		  ->setTo($emailTo)
		  ->setBody('ECMRS Report')	  
		  ;
		
		foreach ($reportList as $report) {
			$reports = new Notifications();
		
			$res = $reports->getReport(['reportId'=>$report]);
			array_unshift($res['data'], $res['colTitles']);
			$records = $res['data'];
       		$rez[] = implode(',', array_shift($records));

        	foreach ($records as &$r) {
            	$record = [];
            	foreach ($res['colOrder'] as $t) {
                	$t = strip_tags(isset($r[$t])?$r[$t]:'');

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
			print_r($records);
			$vars = [
				'title' => $res['title'],
				'columnTitle'=> $res['columns'],
				'services'=>$records,
				'currentDate'=> date("m/d/Y") .  ' ' .  date("h:i:sa")
			];
			$twig = $container->get('twig');
			$html = $twig->render('CaseboxCoreBundle:email:reports.html.twig', $vars);	
			$dompdf = new Dompdf();
			$dompdf->loadHtml($html);
			
			$dompdf->setPaper('A4', 'landscape');
			$dompdf->render();
			$pdfoutput = $dompdf->output();
			$zipname = $configService->get('files_dir').DIRECTORY_SEPARATOR.$date.'_'.$coreName.$res['title'].'.pdf';//.DIRECTORY_SEPARATOR.'export'.DIRECTORY_SEPARATOR.time().'.pdf';
			file_put_contents($zipname, $pdfoutput);
			$message->attach(\Swift_Attachment::fromPath($zipname));
		}
		$list = '
			<html>
			<head>
			  <title>ECMRS Daily Report Email '.$coreName.'</title>
			</head>
			<body>
			  <p>Please find attached the report for today, '.$date.'</p>
			  <table><tr><td>For any issues, please contact ecmrshelpdesk@apprioinc.com</td></tr>
		  </table>
		</body>
		</html>';
		$message->addPart($list, 'text/html');
		//$transport = new \Swift_SendmailTransport('/usr/sbin/sendmail -bs');
		$transporter = \Swift_SmtpTransport::newInstance('smtp.gmail.com', 587, 'tls')
		  ->setUsername($configService->get('email_from'))
		  ->setPassword($configService->get('email_pass'));		
		  
		$mailer = new \Swift_Mailer($transporter);
		$result = $mailer->send($message);
		
        $output->success('command casebox:email:report for ' . $date);
    }
}
