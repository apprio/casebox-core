<?php

namespace Casebox\CoreBundle\Command;

use Casebox\CoreBundle\Service\System;
use Casebox\CoreBundle\Service\Objects;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Solr\Client;

class CaseboxDatabaseGeocodeCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('casebox:database:geocode')
            ->setDescription('Perform Geocode')
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
		
        /*$res = $dbs->query(
            'select * from objects where sys_data not like \'%zipcode_s%\' and sys_data like \'%"closurereason_s":"Partial goals met, transitioning to other service provider "%\''
        );*/
		
		$res = $dbs->query(
            'select * from objects where id = 3274'
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
			$case = Objects::getCachedObject($objectId);  
			
			echo ($case.',');
			
			//$case->geocode();
			$solr = new Client();
			$solr->updateTree(['id' => $objectId]);

			//break;
		}
		
        $output->success('command casebox:database:geocode');
    }
}
