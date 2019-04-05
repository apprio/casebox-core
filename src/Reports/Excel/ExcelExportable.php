<?php
/**
 * This file contains class to export data to Microsoft Excel
 *
 * @author KoolPHP Inc (support@koolphp.net)
 * @link https://www.koolphp.net
 * @copyright KoolPHP Inc
 * @license https://www.koolreport.com/license#mit-license
 */

/*
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

    

 */

namespace Casebox\CoreBundle\Reports\Excel;
use \koolreport\core\Utility as Util;
use \PhpOffice\PhpSpreadsheet as ps;

trait ExcelExportable
{
    protected $excelExport;
    protected $pivotExcelExport;

    protected function getDataStoreType($dataStore) {
        if (! $dataStore) {
            return 'table';
        }
        if (is_string($dataStore)) {
            return 'table';
        } elseif (is_array($dataStore)) {
            $meta = Util::get($dataStore, ['meta', 'columns'], []);
            $row = Util::get($dataStore, ['data', 0], []);
        } else {
            $meta = $dataStore->meta()['columns'];
            $dataStore->popStart();
            $row = $dataStore->pop();
        }
        $colNames = array_keys($row);
        foreach ($colNames as $colName) {
            $type = Util::get($meta, [$colName, 'type'], '');
            if ($type === 'dimension')
                return 'pivot';
        }
        return 'table';
    }

    protected function getExportObject($type) {
        if ($type === 'pivot') {
            if (! isset($this->pivotExcelExport))
                $this->pivotExcelExport = new \koolreport\pivot\PivotExcelExport();
            return $this->pivotExcelExport;
        }
        else {
            if (! isset($this->excelExport))
                $this->excelExport = new ExcelExport();
            return $this->excelExport;
        }
    }

    public function exportToExcel($params = array())
    {
        $spreadsheet = new ps\Spreadsheet();
        if (is_string($params)) {
            $currentDir = dirname(Util::getClassPath($this));
            $tplPath = $currentDir."/".$params.".excel.php";
        }

        if (is_array($params)) {
            $properties = Util::get($params,"properties",array());
            
            $spreadsheet->getProperties()
            ->setCreator(Util::get($properties,"creator","KoolReport"))
            ->setTitle(Util::get($properties,"title",""))
            ->setDescription(Util::get($properties,"description",""))
            ->setSubject(Util::get($properties,"subject",""))
            ->setKeywords(Util::get($properties,"keywords",""))
            ->setCategory(Util::get($properties,"category",""));

            $options = array();
            $dataStoreNames = Util::get($params,"dataStores",null);
            if (! isset($dataStoreNames) || ! is_array($dataStoreNames))
                $exportDataStores = $this->dataStores;
            else {
                $options = array();
                $exportDataStores = array();
                foreach ($dataStoreNames as $k => $v)
                    if (isset($this->dataStores[$k])) {
                        $exportDataStores[$k] = $this->dataStores[$k];
                        $options[$k] = $v;
                    }
                    else if (isset($this->dataStores[$v]))
                        $exportDataStores[$v] = $this->dataStores[$v];
            }
            $k=0;
            foreach($exportDataStores as $name=>$dataStore) {
                if ($k==0) {
                    $sheet = $spreadsheet->getSheet(0);
                }
                else {
                    $sheet = new ps\Worksheet\Worksheet($spreadsheet, $name);
                    $spreadsheet->addSheet($sheet, $k);
                }
                $sheet->setTitle($name);
                $option = Util::get($options,$name,array());
                $type = $this->getDataStoreType($dataStore);
                $exportObject = $this->getExportObject($type);
                $exportObject->saveDataStoreToSheet($dataStore, $sheet, $option);
                $k++;
            }
        } elseif (is_file($tplPath)) {
            ob_start();
            include($tplPath);
            $tplContent = ob_get_clean();
            // $tplContent = str_replace('<', '&lt;', $tplContent);
            // echo $tplContent; exit();

            libxml_use_internal_errors(true);
            $doc = new \DomDocument();
            $doc->loadHTML($tplContent);
            $metas = $doc->getElementsByTagName("meta");

            $properties = $spreadsheet->getProperties();
            $metaNames = ['creator', 'title', 'description', 'subject', 'keywords', 'category'];
            foreach ($metas as $meta) {
                $nameProperty = $meta->getAttribute('name');
                if (! in_array($nameProperty, $metaNames)) continue;
                $value = $meta->getAttribute('content');
                $method = "set$nameProperty"; 
                $properties->{$method}($value);
            }

            $chartDataSheet = new ps\Worksheet\Worksheet($spreadsheet, 'chart_data');
            $spreadsheet->addSheet($chartDataSheet);

            // error_reporting(0);
            function isJson($string) {
                $firstChar = mb_substr($string, 0, 1);
                $lastChar = mb_substr($string, -1);
                if (($firstChar !== "{" && $firstChar !== "[") ||
                    ($lastChar !== "}" && $lastChar !== "]")) {
                    return false;
                }
                json_decode($string);
                $isJson = json_last_error() == JSON_ERROR_NONE;
                return $isJson;
            }
            $xpath = new \DomXPath($doc);
            $sheetXmls = $xpath->query("*/div");
            $i = 0;
            foreach ($sheetXmls as $sheetXml) {
                $sheetName = $sheetXml->getAttribute('sheet-name');
                $sheetName = ! empty($sheetName) ? $sheetName : "Sheet" . ($i+1);
                if ($i === 0) {
                    $sheet = $spreadsheet->getSheet(0);
                    $sheet->setTitle($sheetName);
                }
                else {
                    $sheet = new ps\Worksheet\Worksheet($spreadsheet, $sheetName);
                    $spreadsheet->addSheet($sheet, $i);
                }

                $contentXmls = $xpath->query("div", $sheetXml);
                foreach ($contentXmls as $contentXml) {
                    $contentStr = trim($contentXml->textContent);
                    $content = isJson($contentStr) ? 
                        json_decode($contentStr, true) : [
                            'type' => 'text',
                            'text' => $contentStr
                        ];
                    if (isset($content['dataSource'])) {
                        $dataSource = $this->dataStore($content['dataSource']);
                    } elseif (isset($content['excelDataSource'])) {
                        $dataSource = $content['excelDataSource'];
                    } else {
                        $data = Util::get($content, 'data', null);
                        $meta = Util::get($content, 'meta', null);
                        $dataSource = [
                            'data' => $data,
                            'meta' => $meta,
                        ];
                    }
                    $content['dataSource'] = $dataSource;

                    $contentAttrs = [];
                    $attrs = $contentXml->attributes;
                    foreach ($attrs as $attr) {
                        $contentAttrs[$attr->nodeName] = $attr->nodeValue;
                        // if ($attr->nodeName === 'excelstyle') {
                        //     echo $attr->nodeValue;
                        //     exit();
                        // }
                    }
                    $content['attributes'] = $contentAttrs;

                    $type = $this->getDataStoreType($dataSource);
                    $exportObject = $this->getExportObject($type);
                    $exportObject->saveContentToSheet(
                        $content, $sheet, $chartDataSheet);
                }
                $i++;
            }
        }
        // exit();
        
        $spreadsheet->setActiveSheetIndex(0);
        // $chartDataSheet->setSheetState(ps\Worksheet\Worksheet::SHEETSTATE_VERYHIDDEN);
        
        // echo sys_get_temp_dir();
        $tmpFilePath = sys_get_temp_dir()."/".Util::getUniqueId().".xlsx";
        $objWriter = ps\IOFactory::createWriter($spreadsheet, "Xlsx");
        $objWriter->setPreCalculateFormulas(false);
        $objWriter->setIncludeCharts(TRUE);
        $objWriter->save($tmpFilePath);

        return new FileHandler($tmpFilePath);
    }
}
