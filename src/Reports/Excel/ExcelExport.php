<?php
/**
 * This file contains class to export data to Microsoft Excel
 *
 * @author KoolPHP Inc (support@koolphp.net)
 * @link https://www.koolphp.net
 * @copyright KoolPHP Inc
 * @license https://www.koolreport.com/license#mit-license
 
 */

namespace Casebox\CoreBundle\Reports\Excel;
use \koolreport\core\Utility as Util;
use \PhpOffice\PhpSpreadsheet as ps;
use \PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use \PhpOffice\PhpSpreadsheet\Chart\Chart as PHPOfficeChart;
use \PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use \PhpOffice\PhpSpreadsheet\Chart\Legend;
use \PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use \PhpOffice\PhpSpreadsheet\Chart\Title;

class ExcelExport {

    protected $chartAutoId = 0;
    protected $tableAutoId = 0;
    protected $tablePositions = [];
    protected $tableSheet = [];
    protected $mapChartTypes = [
        'AreaChart' => DataSeries::TYPE_AREACHART,
        'AreaChart3D' => DataSeries::TYPE_AREACHART_3D,
        'BarChart' => DataSeries::TYPE_BARCHART,
        'BarChart3D' => DataSeries::TYPE_BARCHART_3D,
        'BubbleChart' => DataSeries::TYPE_BUBBLECHART,
        'CandleChart' => DataSeries::TYPE_CANDLECHART,
        'DonutChart' => DataSeries::TYPE_DONUTCHART,
        'DoughnutChart' => DataSeries::TYPE_DOUGHNUTCHART,
        'LineChart' => DataSeries::TYPE_LINECHART,
        'LineChart3D' => DataSeries::TYPE_LINECHART_3D,
        'PieChart' => DataSeries::TYPE_PIECHART,
        'PieChart3D' => DataSeries::TYPE_PIECHART_3D,
        'RadarChart' => DataSeries::TYPE_RADARCHART,
        'ScatterChart' => DataSeries::TYPE_SCATTERCHART,
        'StockChart' => DataSeries::TYPE_STOCKCHART,
        'SurfaceChart' => DataSeries::TYPE_SURFACECHART,
        'SurfaceChart3D' => DataSeries::TYPE_SURFACECHART_3D,
    ];

    public function saveDataStoreToSheet($ds, $sheet, $option) 
    {
        $data = is_array($ds) ? $ds['data'] : $ds->data();
        $meta = is_array($ds) ? $ds['meta'] : $ds->meta();
        $headersExcelStyle = Util::get($option, 'headersExcelStyle', []);
        $columnsExcelStyle = Util::get($option, 'columnsExcelStyle', []);

        $colMetas = $meta['columns'];
        $optCols = Util::get($option, 'columns', array_keys($colMetas));
        $expColKeys = [];

        $i = 0; 
        $startCol = Util::get($option, 'startColumn', 1);
        $startRow = Util::get($option, 'startRow', 1);
        $expColOrder = $startCol;
        $rowOrder = $startRow;
        $maxlength = array();
        foreach ($colMetas as $colKey => $colMeta) {
            $label = Util::get($colMeta, 'label', $colKey);
            $type = Util::get($colMeta, 'type', 'string');
            $excelstyle = Util::get($headersExcelStyle, $colKey, []);
            $alignment = $type === 'number' ? 
                ps\Style\Alignment::HORIZONTAL_RIGHT : ps\Style\Alignment::HORIZONTAL_LEFT;
            foreach ($optCols as $col) {
                if (in_array($colKey, $expColKeys)) continue;
                if ($col !== $i && $col !== $colKey && $col !== $label) continue;
                $cell = Coordinate::stringFromColumnIndex($expColOrder) 
                    . $rowOrder;
                $sheet->setCellValue($cell, $label);
                $style = $sheet->getStyle($cell);
                $style->getAlignment()
                    ->setHorizontal($alignment);
                $style->getFont()->setBold(true);
                $style->applyFromArray($excelstyle);

                $maxlength[] = strlen($label);

                $expColKeys[] = $colKey;
                $expColOrder++;
            } 
            $i++;
        }
        $rowOrder++;

        // $ds->popStart();
        // while ($row = $ds->pop()) {
        foreach ($data as $row) {
            $expColOrder = $startCol;
            foreach ($expColKeys as $i => $colKey) {
                $colMeta = Util::get($colMetas, $colKey, []);
                $excelstyle = Util::get($columnsExcelStyle, $colKey, []);
                $value = $row[$colKey];
                $type = Util::get($colMeta, 'type', 'string');
                $format = $colMeta;
                $formatCode = "";
                $isDateTime = false;
                switch ($type) {
                    case "number":
                        $decimals = Util::get($format,"decimals",0);
                        $dec_point = Util::get($format,"dec_point",".");
                        $thousand_sep = Util::get($format,"thousand_sep",",");
                        $prefix = Util::get($format,"prefix","");
                        $suffix = Util::get($format,"suffix","");
                        $formatCode = "\"{$prefix}\"#{$thousand_sep}##0{$dec_point}00\"{$suffix}\"";
                        break;
                    case "datetime":
                        $datetimeFormat = Util::get($format,"format","Y-m-d H:i:s");
                        $defaultFormat = 'YYYY-MM-DD HH:MM:SS';
                        $isDateTime = true;
                        break;
                    case "date":
                        $datetimeFormat = Util::get($format,"format", "Y-m-d");
                        $defaultFormat = 'YYYY-MM-DD';
                        $isDateTime = true;
                        break;
                    case "time":
                        $datetimeFormat = Util::get($format,"format", "H:i:s");
                        $defaultFormat = 'HH:MM:SS';
                        $isDateTime = true;
                        break;
                    default:
                        $value = Util::format($value, $format);
                        break;
                }
                if ($isDateTime) {
                    $formatCode = Util::get($format, "displayFormat", $defaultFormat);
                    if($date = \DateTime::createFromFormat($datetimeFormat, $value)) {
                        $value = $date;
                    } 
                    $value = ps\Shared\Date::PHPToExcel($value);
                }
                $cell = Coordinate::stringFromColumnIndex($expColOrder) 
                    . $rowOrder;
                $sheet->setCellValue($cell, $value);

                $style = $sheet->getStyle($cell);
                if (! empty($formatCode)) {
                    $style->getNumberFormat()->setFormatCode($formatCode);
                }
                $alignment = $type === 'number' ? ps\Style\Alignment::HORIZONTAL_RIGHT 
                    : ps\Style\Alignment::HORIZONTAL_LEFT;
                $style->getAlignment()
                    ->setHorizontal($alignment);

                $style->applyFromArray($excelstyle);

                if ($maxlength[$i] < strlen($value)) {
                    $maxlength[$i] = strlen($value);
                }
                $expColOrder++;
            }
            $rowOrder++;
        }
        
        // $sheet->calculateColumnWidths();
        for ($i = 0; $i < sizeof($maxlength); $i++) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            // $sheet->getColumnDimension($col)->setWidth($maxlength[$i] + 2);
            // $titlecolwidth = $sheet->getColumnDimension($col)->getWidth();
            // $sheet->getColumnDimension($col)->setAutoSize(false);
            // $sheet->getColumnDimension($col)->setWidth($titlecolwidth);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return [
            'topLeft' => ($startCol) . ":" . ($startRow),
            'bottomRight' => count($expColKeys) . ":" . $rowOrder
        ];
    }

    public function saveContentToSheet($content, $sheet, $chartDataSheet)
    {
        $highestRow = $sheet->getHighestDataRow(); // e.g. 10
        $highestColumn = $sheet->getHighestDataColumn(); // e.g 'F'
        $range = 'A' . $highestRow . ':' . $highestColumn . $highestRow;
        $data = $sheet->rangeToArray(
            $range, NULL, TRUE, FALSE);
        // echo "range = "; print_r($range); echo " <br> ";
        // echo "data = "; print_r($data); echo " <br> ";
        $emptySheet = true;
        foreach ($data as $rows) {
            foreach ($rows as $row) {
                if (! empty($row)) {
                    $emptySheet = false;
                    break; break;
                }
            }
        }
        if (! $emptySheet) $highestRow++;
        $cell = "A" . ($highestRow);
        $contentAttrs = $content['attributes'];
        $dataSource = Util::get($content, 'dataSource', []);
        $cell = Util::get($contentAttrs, 'cell', $cell);
        $range = "$cell:$cell";
        $range = Util::get($contentAttrs, 'range', $range);
        $range = explode(":", $range);
        $excelstyle = Util::get($contentAttrs, 'excelstyle', "[]");
        $excelstyle = json_decode($excelstyle, true);
        switch ($content['type']) {
            case 'text':
                if ($range[0] !== $range[1])
                $sheet->mergeCells($range[0].":".$range[1]);
                $sheet->setCellValue($range[0], (string) $content['text']);
                if (! empty($excelstyle)) {
                    // print_r($excelstyle); exit();
                    $defaultHeight = 12.75;
                    $defaultFontSize = 11;
                    $delta = $defaultHeight - $defaultFontSize;
                    $fontSize = Util::get($excelstyle, ['font', 'size'], $defaultFontSize);
                    $rowNum = preg_replace("/[^\d]*/", "", $range[0]);
                    $sheet->getRowDimension($rowNum)->setRowHeight($fontSize + $fontSize / $defaultFontSize * $delta);
                    $sheet->getStyle($range[0])->applyFromArray($excelstyle);
                }
                break;
            case 'table':
                $option = $content;
                $option['startRow'] = $highestRow;
                $tableAutoName = 'table_' . $this->tableAutoId++;
                $tableName = Util::get($content, 'name', $tableAutoName);
                // echo "option = "; print_r($option); echo " * ";
                $this->tablePositions[$tableAutoName] = $this->tablePositions[$tableName] 
                    = $this->saveDataStoreToSheet($dataSource, $sheet, $option);
                $this->tableSheet[$tableAutoName] = $this->tableSheet[$tableName] 
                    = $sheet->getTitle();
                break;
            case 'chart':
                if (is_string($dataSource)) {
                    $positions = $this->tablePositions[$dataSource];
                    $dataSheet = $this->tableSheet[$dataSource];
                } else {
                    $chartDataHighestRow = $chartDataSheet->getHighestDataRow() + 1; // e.g. 10
                    $option = $content;
                    $option['startRow'] = $chartDataHighestRow;
                    $positions = $this->saveDataStoreToSheet(
                        $dataSource, $chartDataSheet, $option);
                    $dataSheet = 'chart_data';
                }

                $bottomRight = $positions['bottomRight'];
                $bottomRight = explode(":", $bottomRight);
                $bottomRightCol = $bottomRight[0]; $bottomRightRow = $bottomRight[1]; 
                $topLeft = $positions['topLeft'];
                $topLeft = explode(":", $topLeft);
                $topLeftCol = $topLeft[0]; $topLeftRow = $topLeft[1]; 

                // echo "position = $topLeftCol:$topLeftRow - $bottomRightCol:$bottomRightRow * ";
                // $startCell = 'A' . $chartDataHighestRow;
                // $chartDataSheet->fromArray([
                //     ['test', 2010, 2011, 2012],
                //     ['Q1', 12, 15, 21],
                //     ['Q2', 56, 73, 86],
                //     ['Q3', 52, 61, 69],
                //     ['Q4', 30, 32, 20],
                // ], null, $startCell);
                
                // $dataSeriesLabels = [
                //     new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, ''{$dataSheet}'!$B$1', null, 1), //	2010
                //     new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, ''{$dataSheet}'!$C$1', null, 1), //	2011
                //     new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, ''{$dataSheet}'!$D$1', null, 1), //	2012
                // ];

                // $dataSeriesValues = [
                //     new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, '('{$dataSheet}'!$B$2,Worksheet!$B$5)', null, 4),
                //     new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, ''{$dataSheet}'!$C$2:$C$5', null, 4),
                //     new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, ''{$dataSheet}'!$D$2:$D$5', null, 4),
                // ];

                // $xAxisTickValues = [
                //     new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, ''{$dataSheet}'!$A$2:$A$5', null, 4), //	Q1 to Q4
                // ];

                $dataSeriesLabels = [];
                $dataSeriesValues = [];
                for ($i = $topLeftCol + 1; $i <= $bottomRightCol; $i++) {
                    $labelCellPos = "$" . Coordinate::stringFromColumnIndex($i)
                        . "$" . $topLeftRow;
                    $labelSeriesValue = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$dataSheet}'!$labelCellPos", null, 1);
                    $dataSeriesLabels[] = $labelSeriesValue;

                    $valueCellPos1 = "$" . Coordinate::stringFromColumnIndex($i)
                        . "$" . ($topLeftRow + 1);
                    $valueCellPos2 = "$" . Coordinate::stringFromColumnIndex($i)
                        . "$" . ($bottomRightRow - 1);
                    $valueSeriesValue = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$dataSheet}'!$valueCellPos1:$valueCellPos2", null, 1);
                    $dataSeriesValues[] = $valueSeriesValue;
                }
                
                $cellPos1 = "$" . Coordinate::stringFromColumnIndex($topLeftCol)
                    . "$" . ($topLeftRow + 1);
                $cellPos2 = "$" . Coordinate::stringFromColumnIndex($topLeftCol)
                    . "$" . ($bottomRightRow - 1);
                $xAxisTickValues = [
                    new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$dataSheet}'!$cellPos1:$cellPos2", null, 4), //	Q1 to Q4
                ];

                //	Build the dataseries
                $chartType = Util::get($content, 'chartType', 'BarChart');
                $stacked = Util::get($content, 'stacked', false);
                $direction = Util::get($content, 'direction', 'vertical');
                $series = new DataSeries(
                    $this->mapChartTypes[$chartType], // plotType
                    $stacked ? DataSeries::GROUPING_STACKED 
                        : DataSeries::GROUPING_STANDARD, // plotGrouping
                    range(0, count($dataSeriesValues) - 1), // plotOrder
                    $dataSeriesLabels, // plotLabel
                    $xAxisTickValues, // plotCategory
                    $dataSeriesValues,        // plotValues
                    $direction === 'horizontal' ? DataSeries::DIRECTION_HORIZONTAL
                        : DataSeries::DIRECTION_VERTICAL
                );

                $plotArea = new PlotArea(null, [$series]);
                $legend = new Legend(Legend::POSITION_RIGHT, null, false);

                $title = new Title(Util::get($content, 'title', ''));
                $xAxisLabel = new Title(Util::get($content, 'xAxisTitle', ''));
                $yAxisLabel = new Title(Util::get($content, 'yAxisTitle', ''));

                //	Create the chart
                $chartName = Util::get($content, 'name', 'chart_' . $this->chartAutoId++);
                $chart = new PHPOfficeChart(
                    $chartName, // name
                    $title, // title
                    $legend, // legend
                    $plotArea, // plotArea
                    true, // plotVisibleOnly
                    0, // displayBlanksAs
                    $xAxisLabel, // xAxisLabel
                    $yAxisLabel  // yAxisLabel
                );
                $defaultChartWidth = 7;
                $defaultChartHeight = 12;
                if ($range[0] === $range[1]) {
                    $pos = Coordinate::coordinateFromString($range[1]);
                    $newCol = Coordinate::columnIndexFromString($pos[0]) + $defaultChartWidth;
                    $range[1] = Coordinate::stringFromColumnIndex($newCol)
                        . ($pos[1] + $defaultChartHeight);
                }
                $chart->setTopLeftPosition($range[0]);
                $chart->setBottomRightPosition($range[1]);

                $sheet->addChart($chart);
                break;
        }

        // foreach($sheet->getRowDimensions() as $rd) { 
        //     $rd->setRowHeight(-1); 
        // }
    }
  
}
