<?php
    use \koolreport\excel\Table;
    use \koolreport\excel\PivotTable;

    $sheet1 = "Report Summary";
	$sheet2 = "Report Detail";	
?>
<meta charset="UTF-8">
<meta name="description" content="Apprio Health Report">
<meta name="keywords" content="Daily Report">
<meta name="creator" content="DNS">
<meta name="subject" content="DailyReport">
<meta name="title" content="DailyReport">
<meta name="category" content="reporting">
<div sheet-name="<?php echo $sheet2; ?>">

    <div cell="A1" range="A1:H1" excelstyle='<?php echo json_encode($styleArray); ?>' >
               <?php		
               print_r($this->params);
			   exit;
        		if (isset($this->params['table']['title']))
				{
					echo("<h2>".(isset($this->params['table']['title'])?$this->params['table']['title']:'')."</h2>");	
				}
				?>
    </div>

    <div>
        <?php
        	$this->params['table']['dataStore'] = $this->dataStore('reportDs');
        ?>
    </div>
</div>

