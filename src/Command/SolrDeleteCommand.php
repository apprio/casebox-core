<?php

namespace Casebox\CoreBundle\Command;

use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\System;
use GuzzleHttp\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SolrDeleteCommand
 */
class SolrDeleteCommand extends ContainerAwareCommand
{
    const SOLR_DEFAULT_CONFIGSET = 'casebox';

    /**
     * Configure
     */
    protected function configure()
    {
        $this
            ->setName('casebox:solr:delete')
            ->setDescription('Create solr index.');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $system = new System();
        $system->bootstrap($this->getContainer());
        $params = Cache::get('platformConfig');

        $solrSchema = $params['solr_schema'];
        $solrHost = $params['solr_host'];
        $solrPort = $params['solr_port'];
        $solrUsername = $params['solr_username'];
        $solrPassword = $params['solr_password'];

        $client = new Client();

        $solrCores = [
            $params['solr_core'] => self::SOLR_DEFAULT_CONFIGSET,
            $params['solr_core_log'] => self::SOLR_DEFAULT_CONFIGSET.'_log',
        ];

        $url = ltrim($solrSchema, '//').'://'.$solrHost.':'.$solrPort.'/solr/admin/cores';

        foreach ($solrCores as $solrCore => $configSet) {
            $options = [
                'query' => [
                    'action' => 'UNLOAD',
                    'core' => $solrCore,
                    'wt' => 'json',
                ],
            ];

            if (!empty($solrUsername) && !empty($solrPassword)) {
                $status['auth'] = $options['auth'] = [
                    $solrUsername,
                    $solrPassword,
                    'Basic',
                ];
            }

            $status = [
                'query' => [
                    'action' => 'STATUS',
                    'core' => $solrCore,
                ],
            ];

/*            $statusResult = $client->request('GET', $url, $status);

            if ($statusResult->getStatusCode() == '200' && !empty($statusResult->getBody())) {
                $statusArray = json_decode($statusResult->getBody()->getContents(), true);

                if (!empty($statusArray['status'][$solrCore])) {
                    $output->writeln('<notice>'.sprintf("Skip. Unable to created '%s' core.", $solrCore).'</notice>');
                    continue;
                }
            }
*/
            $result = $client->request('GET', $url, $options);

            $statusCode = $result->getStatusCode();
            if ($statusCode == 200) {
                $output->writeln('<info>'.sprintf("Success. Deleted '%s' core.", $solrCore).'</info>');
            } else {
                $output->writeln('<error>'.sprintf("Error. Unable to delete '%s' core.", $solrCore).'</error>');
            }
        }

        $output->writeln('DONE!');
    }
}
