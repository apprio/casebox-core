<?php

namespace Casebox\CoreBundle\Event;

use Casebox\CoreBundle\Service\Objects\CBObject;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class NodeDbDeleteEvent
 */
class NodeDbDeleteEvent extends Event
{
    /**
     * @var CBObject
     */
    protected $params;

    /**
     * NodeDbDeleteEvent constructor
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
