<?php

namespace Casebox\CoreBundle\Service\TreeNode;

use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\DataModel as DM;

/**
 * Class Favorites
 */
class Favorites extends Base
{
    protected function createDefaultFilter()
    {
        $this->fq = [];
    }

    public function getChildren(&$pathArray, $requestParams)
    {
        $this->path = $pathArray;
        $this->lastNode = @$pathArray[sizeof($pathArray) - 1];
        $this->requestParams = $requestParams;

        if (!$this->acceptedPath($pathArray, $requestParams)) {
            return;
        }

        $ourPid = @intval($this->config['pid']);

        $this->createDefaultFilter();

        if (empty($this->lastNode) ||
            (($this->lastNode->id == $ourPid) && (get_class($this->lastNode) != get_class($this)))
        ) {
            $rez = $this->getRootNodes();
        } else {
            $rez = $this->getContentItems();
        }

        return $rez;

    }

    public function getName($id = false)
    {
        if ($id === false) {
            $id = $this->id;
        }

        $rez = $id;

        switch ($id) {
            case 'favorites':
                return $this->trans('Favorites');

            default:
                /*if (!empty($id) && is_numeric($id)) {
                    $d = DM\Favorites::read($id);
                    $rez = Util\toJSONArray($d['data'])['name'];
                }/**/
                break;
        }

        return $rez;
    }

    protected function getRootNodes()
    {
        return [
            'data' => [
                [
                    'name' => $this->getName('favorites'),
                    'id' => $this->getId('favorites'),
                    'iconCls' => 'i-star',
                    'cls' => 'tree-header',
                    'has_childs' => false,
                ],
            ],
        ];
    }

    public function getContentItems()
    {
        $rez = [
            'data' => [],
        ];

        $fa = DM\Favorites::readAll();

        foreach ($fa as $f) {
            $d = Util\toJSONArray($f['data']);
            $d['nid'] = $f['node_id'];
            $d['targetPath'] = $d['path'];
            $d['path'] = $d['pathText'];
            unset($d['pathText']);

            $d['isFavorite'] = true;

            $rez['data'][] = $d;
        }

        return $rez;
    }
}
