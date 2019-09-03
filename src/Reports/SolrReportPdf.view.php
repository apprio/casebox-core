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

<div class="text-center">
    <?php		
		if (isset($this->params['title']))
		{
			echo("<h1>".(isset($this->params['title'])?$this->params['title']:'')."</h1>");	
		}
		if (isset($this->params['secondaryTitle']))
		{
			echo("<h4>".(isset($this->params['secondaryTitle'])?$this->params['secondaryTitle']:'')."</h2>");	
		}
	?>	    
</div>
<hr/>
        			<?php
			if (isset($this->params['table']))
			{
				if (isset($this->params['table']['title']))
				{
					echo("<h2>".(isset($this->params['table']['title'])?$this->params['table']['title']:'')."</h2>");	
				}
				echo("<div class=\"table-responsive\">");
				Table::create(array(
			        "dataStore"=>$this->dataStore('reportDs'),
			        "columns"=>$this->params['columns'],
			        "cssClass"=>array(
			            "table"=>"table table-hover table-bordered"
			        )
			    ));	
				echo("</h2></div>");
			}
			?>
        		<br><br><br>

    </body>
</html>