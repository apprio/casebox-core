<?php

namespace Casebox\CoreBundle\Reports\Excel;

use koolreport\core\Utility as Util;

class Table extends \koolreport\core\Widget
{
    protected function setType()
    {
        $this->params['type'] = 'table';
    }

    protected function onRender()
	{
        $this->setType();
        $this->useAutoName();
        $params = $this->params;
        $dataSource = Util::get($params, 'dataSource', []);
        if (! is_string($dataSource)) {
            $this->useDataSource();
            $ds = $this->dataStore;
            if (is_object($ds) && Util::getClassName($ds) === 'DataStore') {
                $params['data'] = $ds->data();
                $params['meta'] = $ds->meta();
                unset($params['dataSource']);
            }
        }
        echo json_encode($params);
	}
}