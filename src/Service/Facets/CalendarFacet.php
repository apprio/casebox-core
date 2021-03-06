<?php

namespace Casebox\CoreBundle\Service\Facets;

use Casebox\CoreBundle\Traits\TranslatorTrait;

/**
 * Class CalendarFacet
 */
class CalendarFacet extends StringsFacet
{
    use TranslatorTrait;

    public function getTitle()
    {
        return $this->trans('Calendar');
    }

    public function getSolrParams()
    {
        return [];
    }

    public function getClientData($options = [])
    {
        $rez = parent::getClientData();

        $rez['type'] = 'calendar';

        return $rez;
    }
}
