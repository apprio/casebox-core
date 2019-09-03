<?php

namespace Casebox\CoreBundle\Reports;

use Casebox\CoreBundle\Service\DataModel as DM;
use Casebox\CoreBundle\Service\Objects\CBObject;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Symfony\Component\HttpFoundation\Response;
use \koolreport\processes\Group;
use \koolreport\processes\Sort;
use \koolreport\processes\Limit;
use \koolreport\processes\ColumnMeta;
use \koolreport\pivot\processes\Pivot;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Notifications; //Reports Class for SOLR Reporting
/**
 * Class SolrReport
 */
class SolrReport extends \koolreport\KoolReport
{
	use \Casebox\CoreBundle\Reports\Export\Exportable;
	use \Casebox\CoreBundle\Reports\Excel\ExcelExportable;
	
    public function settings()
    {
    	$reports = new Notifications();
		if (!isset($this->params["id"]))
		{
			echo('please set id parameter in report configuration');
			exit;
		}
		$this->params['startDate'] = empty($_GET['startDate'])?date("Y-m-d", time() - 360 * 60 * 28):$_GET['startDate'];
		$this->params['endDate'] = empty($_GET['endDate'])?date("Y-m-d", time() - 60 * 60 * 28):$_GET['endDate'];
		$res = $reports->getReport(['reportId'=>$this->params["id"], 'endDate'=>$this->params["endDate"], 'startDate'=>$this->params["startDate"] ]);	
		$this->params['columns'] = $res['columns'];
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
		//exit;
		return array(
			"assets"=>array(
                "path"=>"/var/www/casebox/web/report/"
            ),       
            "dataSources"=>array(
                "reportConnection"=>array(
                    "class"=>'\koolreport\datasources\ArrayDataSource',
                    "data"=>$res['data'],
                    "dataFormat"=>"associate",
                )
            )
        );
    }

    public function setup()
    {
    	$this->src('reportConnection')
		->saveTo($source);
		
        $source->pipe($this->dataStore('reportDs'));  
		
		if (array_key_exists('group',$this->params))
		{
			$source->pipe(new Group($this->params["sql"]))->pipe($this->dataStore('groupDs'));
		}
    }

}