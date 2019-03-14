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
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Reports\Export\Exportable;
	
/**
 * Class AccountRepDailyReport
 */
class DailyReport extends \koolreport\KoolReport
{
	use \Casebox\CoreBundle\Reports\Export\Exportable;
	use \Casebox\CoreBundle\Reports\Excel\ExcelExportable;
	

    public function settings()
    {
    	$configService = Cache::get('symfony.container')->get('casebox_core.service.config');
        return array(
            "assets"=>array(
                "path"=>"../../../../../web/report",
                "url"=>"/report",
            ),        
            "dataSources"=>array(
                "sales"=>array(
                    "connectionString"=>"mysql:host=".$configService->get('db_host').";dbname=".$configService->get('db_name')."",
                    "username"=>$configService->get('db_user'),
                    "password"=>$configService->get('db_pass'),
                    "charset"=>"utf8"
                )
            )
        );
    }

    public function setup()
    {
    	//$reportDate = $_GET['id'];
		$query = "
		SELECT
		* from objects limit 10";
		 //exit;
		//$myDate = date("Y-m-d", time() - 60 * 60 * 28);
    	//concat('$ ', format(sum(patient.referred_charges), 2)) Total_Referred
    	#and date(note_date) = subdate(current_date,2)
		$this->src('sales')
		->query($query)
		->saveTo($source);
        //->pipe(new Limit(array(10)))
        //->pipe($this->dataStore('sales_by_customer'));
		
		
		
    }

}