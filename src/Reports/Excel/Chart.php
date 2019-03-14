<?php

namespace Casebox\CoreBundle\Reports\Excel;

use koolreport\core\Utility as Util;

class Chart extends Table
{
    protected function setType()
    {
        $this->params['type'] = 'chart';
        $this->params['chartType'] = Util::getClassName($this);
    }
}