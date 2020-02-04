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
/**
 * Class AccountRepDailyReport
 */
class DynamicReport extends \koolreport\KoolReport
{
	
	use \Casebox\CoreBundle\Reports\Export\Exportable;
	use \Casebox\CoreBundle\Reports\Excel\ExcelExportable;

    protected function defaultParamValues()
    {
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');    			
        return array(
            "reportDate"=>empty($_GET['reportDateInput'])?date("Y-m-d", time() - 60 * 60 * 28):$_GET['reportDateInput'],
            "core_name"=>$configService->get('core_name'),
            "form_path"=>'/c/'.$configService->get('core_name').'/report/AccountRepDailyReport/',            
        );
    }
    protected function bindParamsToInputs()
    {
    	return array(
            "reportDate"=>"reportDateInput",
        );
    }  
    public function settings()
    {
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
		return array(
		    "assets"=>array(
                "path"=>"/var/www/casebox/web/report/"
            ),       
            "dataSources"=>array(
                "reportConnection"=>array(
                    "connectionString"=>
                    isset($this->params['connectionString'])?$configService->get(
					$this->params['connectionString']):
                    "mysql:host=".$configService->get(isset($this->params['host'])?$this->params['host']:'db_host').
                    ";dbname=".$configService->get(isset($this->params['dbname'])?$this->params['dbname']:'db_name')."",
                    "username"=>$configService->get(isset($this->params['username'])?$this->params['username']:'db_user'),
                    "password"=>$configService->get(isset($this->params['password'])?$this->params['password']:'db_pass'),
                    "charset"=>"utf8"
                )
            )
        );
    }

    public function setup()
    {
    	if(array_key_exists('sql',$this->params)){
    		//$this->params['sql'] = 'select * from objects limit 1';
    	$this->src('reportConnection')
        ->query($this->params["sql"])
		->saveTo($source);
		
        $source->pipe($this->dataStore('reportDs'));  
		
		if (array_key_exists('group',$this->params))
		{
			$source->pipe(new Group($this->params["sql"]))->pipe($this->dataStore('groupDs'));
		}
			           		} 
		
    }

}