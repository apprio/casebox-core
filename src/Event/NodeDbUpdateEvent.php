<?php

namespace Casebox\CoreBundle\Event;

use Casebox\CoreBundle\Service\Objects\CBObject as ObjectsObject;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class NodeDbUpdateEvent
 */
class NodeDbUpdateEvent extends Event
{
    /**
     * @var CBObject
     */
    protected $params;

    /**
     * NodeDbUpdateEvent constructor
     * @param ObjectsObject $object
     */
    public function __construct(ObjectsObject $object)
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
