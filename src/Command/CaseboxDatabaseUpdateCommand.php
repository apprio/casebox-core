<?php

namespace Casebox\CoreBundle\Command;

use Casebox\CoreBundle\Service\System;
use Casebox\CoreBundle\Service\Search;
use Casebox\CoreBundle\Service\Objects;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Solr\Client;
use Symfony\Component\HttpFoundation\Request;

class CaseboxDatabaseUpdateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('casebox:database:update')
            ->setDescription('Perform Update')
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
		$request->setLocale('es');
		Cache::set('symfony.request', $request);		
		
		$p['fq'][] = 'template_id:607';
		$p['fq'][] = '!resourcename_s:[* TO *]';
		$p['fq'][] = '(!id:27591 AND !id:31324 AND !id:31329 AND !id:36304 AND !id:52657 AND !id:54160 AND !id:54177 AND !id:54185 AND !id:80090 AND !id:111730 AND !id:216259)';
		$p['rows'] = 1000;
		
        $s = new Search();
        $rez = $s->query($p);
		foreach ($rez['data'] as $row) {
		 $objectId = $row['id'];
			$case = Objects::getCachedObject($objectId);  
			
			echo ($objectId.',');
			
			$case->update();
			
			//$case->geocode();
			$solr = new Client();
			$solr->updateTree(['id' => $objectId]);

			//break;
		}
		print_r($rez);
		exit;
		
        $res = $dbs->query(
            'select * from objects where id = 3405'
        );
        /*
                $res = $dbs->query(
            'select * from objects, tree where tree.id = objects.id 
			and tree.template_id = 2265 and objects.id > 25785 and data not like \'%"PERSON_ID":""%\'
			order by objects.id desc'
        );
        */

		
		$objService = new Objects();
	
        while ($r = $res->fetch()) {
			$objectId = $r['id'];
			echo ($objectId.',');
			$case = Objects::getCachedObject($objectId);  
			
			$case->update();
			
			//$case->geocode();
			$solr = new Client();
			$solr->updateTree(['id' => $objectId]);

			//break;
		}
		
        $output->success('command casebox:database:update');
    }
}
