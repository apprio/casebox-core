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
            ->setDescription('Deactivate users based on days.')
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
		
		date_default_timezone_set('America/New_York');
		$date = (!empty($input->getOption('date'))) ? $input->getOption('date') : date('Y-m-d', time());

		$session = $container->get('session');
		$message = (new \Swift_Message())
		  // Give the message a subject
		  ->setSubject('ECMRS Daily Report')
		  ->setFrom(['ecmrshelpdesk@apprioinc.com' => 'ECMRS Helpdesk'])
		  ->setTo(['ecmrshelpdesk@apprioinc.com'])
		  ->setBody('ECMRS Report')	  
		  ;
		  
		$reportList = array(202888,203093);
		foreach ($reportList as $report) {
			$reports = new Notifications();
			$res = $reports->getReport(['reportId' => $report,'startDate'=>$date,'endDate'=>$date]);
			date_default_timezone_set("America/New_York");
			unset($res['columns']['total']); //remove total column
			unset($res['columns']['areatotal']); //remove total column
			$vars = [
				'title' => $res['title'] ,
				'columnTitle'=> $res['columns'],
				'services'=>$res['data'],
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
			  <p>Please find attached the totals for today, '.$date.'</p>
			  <table><tr><td>For any issues, please contact ecmrshelpdesk@apprioinc.com</td></tr>
		  </table>
		</body>
		</html>';
		$message->addPart($list, 'text/html');
		$transport = new \Swift_SendmailTransport('/usr/sbin/sendmail -bs');
		$mailer = new \Swift_Mailer($transport);
		$result = $mailer->send($message);
		
        $output->success('command casebox:email:report for ' . $date);
    }
}
