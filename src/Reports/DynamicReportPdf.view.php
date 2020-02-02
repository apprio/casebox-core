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
?>

<div class="text-center">
    <h1><?php echo($this->params['title']);?></h1>
    <h4><?php echo($this->params['secondarytitle']);?></h4>
</div>
<hr/>
			<?php
			if (isset($this->params['barchart']))
			{
				$this->params['barchart']['dataStore'] = $this->dataStore('groupDs');
				//print_r($this->params['table']);
				BarChart::create($this->params['barchart'],false);

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
        		<br><br><br>

    </body>
</html>