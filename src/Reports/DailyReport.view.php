<div class="container box-container">
        	
        	<?php 
	use \koolreport\widgets\koolphp\Table;        	
    use \koolreport\widgets\google\BarChart;
?>

<div class="text-center">
    <h1>Daily Report - <?php echo(date_format(date_create($this->params["reportDate"]),'l, M j')) ?></h1>
    <h4>This report shows referred amounts worked by account representative for the day specified</h4>
</div>
<div id="browse_app" class="text-center">
  <a class="btn btn-small btn-info" href="<?php echo($this->params["form_path"]) ?>?xls=2&reportDateInput=<?php echo($this->params["reportDate"]) ?>">Export to Excel</a>
  &nbsp;&nbsp;&nbsp;<a class="btn btn-small btn-info" href="<?php echo($this->params["form_path"]) ?>?pdf=2&reportDateInput=<?php echo($this->params["reportDate"]) ?>">Export to PDF</a>  
</div>
<br/>
<form class="form-inline text-center">
  <div class="form-group mb-2 text-center">
    <label for="reportDateInput" class="sr-only">Change Report Date</label>
    <input type="text" name="reportDateInput" id="reportDateInput" value="<?php echo($this->params["reportDate"]) ?>" onchange="window.location = '#report?dash=%2Fc%2F<?php echo($this->params["core_name"]) ?>%2Freport%2FDailyReport%2F&rd=' + jQuery('#reportDateInput').val();" 
    data-date-format="yyyy-mm-dd">
  

  </div>
<hr/>
     

<?php

?>