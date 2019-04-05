<?php
/**
 * This file contains class to export data to Microsoft Excel
 *
 * @author KoolPHP Inc (support@koolphp.net)
 * @link https://www.koolphp.net
 * @copyright KoolPHP Inc
 * @license https://www.koolreport.com/license#mit-license
 * 
 * 
 */

 /*
    $report = new MyReport;
    $report->run()->exportToCSV(array(
        "dataStores" => array(
            'salesReport' => array(
                'delimiter' => ';',
                "columns"=>array(
                    0, 1, 2, 'column3', 'column4', //if not specifying, all columns are exported
                )
            )
        )
    ))->toBrowser("myreport.csv");
 * 
 */


namespace Casebox\CoreBundle\Reports\Excel;
use \koolreport\core\Utility as Util;

trait CSVExportable
{
    public function exportToCSV($params = [], $exportOption = []) {
        $content = "";
        $options = array();
        if (is_string($params)) {
            $dsName = $params;
            $exportDatastores = [$dsName => $this->datastore($dsName)];
            $options = [$dsName => $exportOption];
            $bom = Util::get($exportOption,"BOM",false);
        } else {
            $bom = Util::get($params,"BOM",false);
            $dataStoreNames = Util::get($params,"dataStores",null);
            if (is_string($dataStoreNames))
                $dataStoreNames = array_map('trim', explode(',', $dataStoreNames));
            if (! is_array($dataStoreNames))
                $exportDataStores = $this->dataStores;
            else {
                $options = array();
                $exportDataStores = array();
                foreach ($dataStoreNames as $k => $v)
                    if (isset($this->dataStores[$k])) {
                        $exportDataStores[$k] = $this->dataStores[$k];
                        $options[$k] = $v;
                    }
                    else if (is_string($v) && isset($this->dataStores[$v]))
                        $exportDataStores[$v] = $this->dataStores[$v];
            }
        }

        foreach($exportDatastores as $name=>$ds) {
            $option = Util::get($options, $name, []);
            $colMetas = $ds->meta()['columns'];
            $optCols = Util::get($option, 'columns', array_keys($colMetas));
            $expColKeys = [];
            $expColLabels = [];
            $i = 0;
            foreach ($colMetas as $colKey => $colMeta) {
                $label = Util::get($colMeta, 'label', $colKey);
                foreach ($optCols as $col)
                    if ($col === $i || $col === $colKey || $col === $label) {
                        $expColKeys[] = $colKey;
                        $expColLabels[] = $label;
                    }
                $i++;
            }

            $delimiter = Util::get($option, 'fieldSeparator', ',');
            $delimiter = Util::get($option, 'delimiter', $delimiter);
            $content .= implode($delimiter, $expColLabels) . "\n";

            $ds->popStart();
            while ($row = $ds->pop()) {
                foreach ($expColKeys as $colKey) {
                    $content .= Util::format($row[$colKey], $colMetas[$colKey])
                        . $delimiter;
                }
                $content = substr($content, 0, -1) . "\n";
            }
        }

        $tmpFilePath = sys_get_temp_dir()."/".Util::getUniqueId().".csv";
        $file = fopen($tmpFilePath, 'w') or die('Cannot open file:  '.$tmpFilePath);
        fwrite($file, ($bom)?(chr(239).chr(187).chr(191).$content):($content));
        fclose($file);

        return new FileHandler($tmpFilePath);
    }
}
