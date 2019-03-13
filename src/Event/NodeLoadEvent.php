<?php

namespace Casebox\CoreBundle\Event;

use Casebox\CoreBundle\Service\Objects\CBObject as ObjectsObject;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class NodeLoadEvent
 */
class NodeLoadEvent extends Event
{
    /**
     * @var ObjectsObject
     */
    protected $object;

    /**
     * NodeLoadEvent constructor
     * @param ObjectsObject $object
     */
    public function __construct(ObjectsObject $object)
    {
        $this->object = $object;
    }

    /**
     * @return ObjectsObject
     */
    public function getObject()
    {
        return $this->object;
    }
}
