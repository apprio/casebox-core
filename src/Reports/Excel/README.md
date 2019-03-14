# Introduction

`Excel` package helps you to work with Excel. It can help to pull data from Excel file as well as push data to Excel file. Underline of `ExcelDataSource` is the open-source library called `phpoffice/PHPExcel` which helps us to read various Excel version.

# Installation

1. Unzip folder
2. Copy the `excel` folder to `koolreport\packages`

# Documentation

## Get data from Excel (version >= 1.0.0)

`ExcelDataSource` help you to get data from your current Microsoft Excel file.

### Settings

|Name|type|default|description|
|----|---|---|---|
|class|string||	Must set to '\koolreport\datasources\ExcelDataSource'|
|filePath|string||The full file path to your Excel file.|
|charset|string|`"utf8"`|Charset of your Excel file|
|firstRowData|boolean|`false`|Whether the first row is data. Normally the first row contain the field name so default value of this property is false.|
|sheetName|string|null|Set a sheet name to load instead of all sheets. (version >= 2.1.0)|
|sheetIndex|number|null|Set a sheet index to load instead of all sheets. If both sheetName and sheetIndex are set, priority is given to sheetName first.  (version >= 2.1.0)|

### Example

```
class MyReport extends \koolreport\KoolReport
{
    public function settings()
    {
        return array(
            "dataSources"=>array(
                "sale_source"=>array(
                    "class"=>"\koolreport\excel\ExcelDataSource",
                    "filePath"=>"../data/my_file.xlsx",
                    "charset"=>"utf8",
                    "firstRowData"=>false,//Set true if first row is data and not the header,
                    "sheetName"=>"sheet1", // (version >= 2.1.0)
                    "sheetIndex"=>0, // (version >= 2.1.0)
                )
            )
        );
    }

    public function setup()
    {
        $this->src('sale_source')
        ->pipe(...)
    }
}

```

## Export to Excel (version >= 1.0.0)

To use the export feature in report, you need to register the `ExcelExportable` in your report like below code

```
class MyReport extends \koolreport\KoolReport
{
    use \koolreport\excel\ExcelExportable;
    ...
}
```

Then now you can export your report to excel like this:

```
<?php
$report = new MyReport;
$report->run()->exportToExcel()->toBrowser("myreport.xlsx");
```

If there is a pivot data store in your report, in order to export to excel that data store you need to have the package Pivot.

### General exporting options

When exporting to excel, you could set a number of property for the excel file.

```
<?php
$report = new MyReport;
$report->run()->exportToExcel(array(
    "properties" => array(
        "creator" => "",
        "title" => "",
        "description" => "",1
        "subject" => "",
        "keywords" => "",
        "category" => "",
    )
))->toBrowser("myreport.xlsx");
```

### Normal Excel exporting options (version >= 3.0.0)

```
<?php
$report = new MyReport;
$report->run()->exportToExcel(array(
    "dataStores" => array(
        'salesReport' => array(
            "columns"=>array(
                0, 1, 2, 'column3', 'column4' //if not specifying, all columns are exported
            )
        )
    )
))->toBrowser("myreport.xlsx");
```

### Pivot excel exporting options 

Beside general options, when exporting a pivot data store you could set several options similar to when viewing a pivot table widget.

```
<?php
$report = new MyReport;
$report->run()->exportToExcel(array(
    "dataStores" => array(
        'salesReport' => array(
            'rowDimension' => 'column',
            'columnDimension' => 'row',
            "measures"=>array(
                "dollar_sales - sum", 
            )
        )
    )
))->toBrowser("myreport.xlsx");
```

## Excel export template (version >= 5.0.0)

You could programmatically set up a template file for excel export similar to a report's view file.

```
<?php
//exportExcel.php
include "MyReport.php";
$report = new MyReport;
$report->run();
$report->exportToExcel('MyReport')->toBrowser("MyReport.xls");
```

```
<?php
//MyReport.excel.php
<?php
    use \koolreport\excel\Table;
    use \koolreport\excel\PivotTable;
    use \koolreport\excel\BarChart;
    use \koolreport\excel\LineChart;
?>
<div sheet-name="<?php echo $sheetName; ?>">
    <div cell="A1">
        <?php echo $reportName; ?>
    </div>
    <div>
        <?php
        Table::create(array(
            "dataSource" => $this->dataStore('orders'),
        ));
        ?>
    </div>
    <div range="A25:H45">
        <?php
        LineChart::create(array(
            "dataSource" => $this->dataStore('salesQuarterProduct'),
        ));
        ?>
    </div>
    <div>
        <?php
        PivotTable::create(array(
            "dataSource" => 'salesPivot',
        ));
        ?>
    </div>
</div>
```

To use an excel export template file, pass its name (without the extension '.excel.php') to the exportToExcel() method.

In the template file, have access to your report via $this as well as its parameters $this->params and datastore $this->datastore().

The template file consists of 2 level of div tags. Each first level div represents a separated excel worksheet.

```
<div sheet-name="sheet1">
</div>
```

Second level divs represents blocks of content in each worksheet. A block of content could be some text, a table, a chart, a pivot table. Each block of content could have its top-left cell set via the div's cell attribute or its range set via the div's range attribute. The range attribute would work for text and chart and not for table or pivot table.

 
```
<div sheet-name="sheet1">
    <div cell="A1" range="A1:E1">
        <?php echo $reportName; ?>
    </div>
</div>
```

In the excel package, we have table, pivot table and chart widgets which are similar to the same name widgets in other packages of KoolReport. 

When setting a datasource for a widget, you could use either a datastore name or a datastore object of the your report. 

```
<?php
//MyReport.excel.php
<?php
    use \koolreport\excel\Table;
    use \koolreport\excel\PivotTable;
?>
<div sheet-name="sheet1">
    <div>
        <?php
        Table::create(array(
            "dataSource" => $this->dataStore('orders'),
        ));
        ?>
    </div>
    <div>
        <?php
        PivotTable::create(array(
            "dataSource" => 'salesPivot',
        ));
        ?>
    </div>
</div>
```

With chart widget, there's another property called "excelDataSource" which could be set to be the name of a table widget in the template. In this case data for the chart would be drawn from the table widget instead of from a datastore.

```
<?php
//MyReport.excel.php
<?php
    use \koolreport\excel\Table;
    use \koolreport\excel\BarChart;
?>
<div sheet-name="<?php echo $sheetName; ?>">
    <div range="A25:H45">
        <?php
        Table::create(array(
            "name" => "customerSales",
            "dataSource" => $this->dataStore('sales'),
        ));
        ?>
    </div>
    <div range="A25:H45">
        <?php
        BarChart::create(array(
            'excelDataSource' => 'customerSales', 
        ));
        ?>
    </div>
</div>
```

### Excel template styles

For some elements in the template file you could set their excel style. These includes text and table blocks.

```
    <div cell="A1" range="A1:H1" excelstyle='<?php echo json_encode($styleArray); ?>' >
        Sales Report
    </div>
    <div>
        <?php
        Table::create(array(
            "dataSource" => 'orders',
            "headersExcelStyle" => [
                'customerName' => [
                    'font' => [
                        'italic' => true,
                        'color' => [
                            'rgb' => '808080',
                        ]
                    ],
                ]
            ],
            "columnsExcelStyle" => [
                'customerName' => [
                    'font' => [
                        'italic' => true,
                        'color' => [
                            'rgb' => '808080',
                        ]
                    ],
                ]
            ],

        ));
        ?>
    </div>
```

A style array can dictate a text's, column's a lot of properties.

```
<?php
    $styleArray = [
        'font' => [
            'name' => 'Calibri', //'Verdana', 'Arial'
            'size' => 30,
            'bold' => false,
            'italic' => FALSE,
            'underline' => 'none', //'double', 'doubleAccounting', 'single', 'singleAccounting'
            'strikethrough' => FALSE,
            'superscript' => false,
            'subscript' => false,
            'color' => [
                'rgb' => '000000',
                'argb' => 'FF000000',
            ]
        ],
        'alignment' => [
            'horizontal' => 'general',//left, right, center, centerContinuous, justify, fill, distributed
            'vertical' => 'bottom',//top, center, justify, distributed
            'textRotation' => 0,
            'wrapText' => false,
            'shrinkToFit' => false,
            'indent' => 0,
            'readOrder' => 0,
        ],
        'borders' => [
            'top' => [
                'borderStyle' => 'none', //dashDot, dashDotDot, dashed, dotted, double, hair, medium, mediumDashDot, mediumDashDotDot, mediumDashed, slantDashDot, thick, thin
                'color' => [
                    'rgb' => '808080',
                    'argb' => 'FF808080',
                ]
            ],
            //left, right, bottom, diagonal, allBorders, outline, inside, vertical, horizontal
        ],
        'fill' => [
            'fillType' => 'none', //'solid', 'linear', 'path', 'darkDown', 'darkGray', 'darkGrid', 'darkHorizontal', 'darkTrellis', 'darkUp', 'darkVertical', 'gray0625', 'gray125', 'lightDown', 'lightGray', 'lightGrid', 'lightHorizontal', 'lightTrellis', 'lightUp', 'lightVertical', 'mediumGray'
            'rotation' => 90,
            'color' => [
                'rgb' => 'A0A0A0',
                'argb' => 'FFA0A0A0',
            ],
            'startColor' => [
                'rgb' => 'A0A0A0',
                'argb' => 'FFA0A0A0',
            ],
            'endColor' => [
                'argb' => 'FFFFFF',
                'argb' => 'FFFFFFFF',
            ],
        ],
    ];
?>
```

## Export to CSV (version >= 3.0.0)

CSVExportable trait allows you to export datastores to CSV files.

```
class MyReport extends \koolreport\KoolReport
{
    use \koolreport\excel\CSVExportable;
    ...
}
```

### CSV exporting options

`'delimiter'` or `'fieldSeparator'` option defines a string used to separate columns in the exported CSV file. Default value is a comma.
`'columns'` option is an array defining a list of columns in the exported CSV file. Values could be either column indexes, column keys or column labels. if not specified, all columns are exported. `"BOM"` parameter takes boolean value, default is `false`, BOM determine whether exported CSV will use UTF8 Bit Order Mark (BOM).

```
<?php
$report = new MyReport;
$report->run()->exportToCSV('salesReport', array(
    'delimiter' => ';',
    "columns"=>array(
        0, 1, 2, 'column3', 'column4'
    )
    "BOM"=>false,
))->toBrowser("myreport.csv");
```

## Support

Please use our forum if you need support, by this way other people can benefit as well. If the support request need privacy, you may send email to us at __support@koolreport.com__.
