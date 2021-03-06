<?php

namespace Casebox\CoreBundle\Event;

use Casebox\CoreBundle\Service\Objects\CBObject;
use Symfony\Component\EventDispatcher\Event;

/**
 * Class NodeDbRestoreEvent
 */
class NodeDbRestoreEvent extends Event
{
    /**
     * @var CBObject
     */
    protected $params;

    /**
     * NodeDbRestoreEvent constructor
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
