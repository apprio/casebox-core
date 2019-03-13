<?php

namespace Casebox\CoreBundle\Event;

use Casebox\CoreBundle\Service\Objects\CBObject;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class BeforeNodeDbUpdateEvent
 */
class BeforeNodeDbUpdateEvent extends Event
{
    /**
     * @var CBObject
     */
    protected $params;

    /**
     * BeforeNodeDbUpdateEvent constructor
     */
    public function __construct(CBObject $object)
    {
        $this->params = $object;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }
}
