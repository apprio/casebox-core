<!DOCTYPE html>
<html>
    <head>
        <title>Daily Report</title>
        <link rel="stylesheet" href="/report/bootstrap/css/bootstrap.min.css" />
        <link rel="stylesheet" href="/report/bootstrap/css/bootstrap-theme.min.css" />
    </head>
    <body>

<div class="container box-container">
        	
        	<?php 
	use \koolreport\widgets\koolphp\Table;        
	use \koolreport\widgets\google\BarChart;
	use \koolreport\widgets\google\ColumnChart;
	use \koolreport\pivot\widgets\PivotTable;
	use \koolreport\drilldown\DrillDown;	
?>
		<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
            <h1 class="h2"><?php echo($this->params['title']);?></h1>
          </div>

			<?php
			if (isset($this->params['barchart']))
			{
				$this->params['barchart']['dataStore'] = $this->dataStore('groupDs');
				//print_r($this->params['table']);
				BarChart::create($this->params['barchart'],false);

			}
			?>

<?php 
			if (isset($this->params['drilldown']))
			{
 DrillDown::create(array(
        "name"=>"saleDrillDown",
        "title"=>"Referral Drilldown",
        "levels"=>array(
            array(
                "title"=>"All Years",
                "content"=>function($params,$scope)
                {
                    ColumnChart::create(array(
                        "dataSource"=>(
                            $this->src("reportConnection")->query("
                                SELECT YEAR([Referral_Date]) year, SUM([Referred_Charges]) as sale_amount 
                                FROM [FLHMagInk].dbo.[Patient Data]
								where YEAR([Referral_Date]) > 2015
                                GROUP BY  YEAR([Referral_Date])
								ORDER BY YEAR([Referral_Date])
                            ")
                        ),
                        "columns"=>array(
                            "year"=>array(
                                "type"=>"int",
                                "label"=>"Year",
                            ),
                            "sale_amount"=>array(
							  "type"=>"number",
                                "label"=>"Sale Amount"
                            )
                        ),
                        "clientEvents"=>array(
                            "itemSelect"=>"function(params){
                                saleDrillDown.next({year:params.selectedRow[0]});
                            }",
                        )
                    ));
                }
            ),
            array(
                "title"=>function($params,$scope)
                {
                    return "Year ".$params["year"];
                },
                "content"=>function($params,$scope)
                {
                    ColumnChart::create(array(
                        "dataSource"=>(
                            $this->src("reportConnection")->query("
                                SELECT MONTH([Referral_Date]) month, SUM([Referred_Charges]) as sale_amount 
                                FROM [FLHMagInk].dbo.[Patient Data]
                                WHERE YEAR([Referral_Date])=:year
                                GROUP BY MONTH([Referral_Date])
								ORDER BY MONTH([Referral_Date])
                            ")
                            ->params(array(
                                ":year"=>$params["year"]
                            ))
                        )
                        ,
                        "columns"=>array(
                            "month"=>array(
                                "type"=>"int"
                            ),
                            "sale_amount"=>array(
								"type"=>"number",
                                "label"=>"Referral Amount",
                                "prefix"=>"$",
                            )
                        ),
                        "clientEvents"=>array(
                            "itemSelect"=>"function(params){
                                saleDrillDown.next({month:params.selectedRow[0]});
                            }",
                        )
                    ));
                }        
            ),
            array(
                "title"=>function($params,$scope)
                {
                    return date('F', mktime(0, 0, 0, $params["month"], 10));
                },
                "content"=>function($params,$scope)
                {
                    ColumnChart::create(array(
                        "dataSource"=>(
                            $this->src("automaker")->query("
                                SELECT paymentDate, DAY(paymentDate) as day,sum(amount) as sale_amount 
                                FROM payments
                                WHERE 
                                    YEAR(paymentDate)=:year
                                    AND
                                    MONTH(paymentDate)=:month 
                                GROUP BY day
                            ")
                            ->params(array(
                                ":year"=>$params["year"],
                                ":month"=>$params["month"],
                            ))
                        ),
                        "columns"=>array(
                            "day"=>array(
                                "formatValue"=>function($value,$row){
                                    return date("F jS, Y",strtotime($row["paymentDate"]));
                                },
                            ),
                            "sale_amount"=>array(
                                "label"=>"Referral Amount",
                                "prefix"=>"$",
                            )
                        )
                    ));
                }                
            )
        ),
    ));
			}
?>


			<?php
			if (isset($this->params['table']))
			{
				if (isset($this->params['table']['title']))
				{
					echo("<h2>".(isset($this->params['table']['title'])?$this->params['table']['title']:'')."</h2>");	
				}
				echo("<div class=\"table-responsive\">");
				$this->params['table']['dataStore'] = $this->dataStore('reportDs');
				//print_r($this->params['table']);
				Table::create($this->params['table'],false);
				echo("</h2></div>");
			}
			?>
</div>

    </body>
</html>