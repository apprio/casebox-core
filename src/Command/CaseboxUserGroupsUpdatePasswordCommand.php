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

class CaseboxUserGroupsUpdatePasswordCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('casebox:user:password:update')
            ->setDescription('Update user password.')
            ->addArgument('username', InputArgument::REQUIRED, 'The username')
            ->addArgument('password', InputArgument::REQUIRED, 'The password')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);

        $container = $this->getContainer();
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
		

        $username = $input->getArgument('username');
        $password = $input->getArgument('password');

        $res = $container->get('casebox_core.service.users_groups')->updatePassword($username, $password);
		
        if (!$res) {
            $output->success(sprintf('User %s not found.', $username));
        } else {
            $output->success(sprintf('New user %s password is %s', $username, $password));
        }
    }
}
