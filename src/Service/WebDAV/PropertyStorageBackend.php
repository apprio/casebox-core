<?php

namespace Casebox\CoreBundle\Service\WebDAV;

use Casebox\CoreBundle\Service\Cache;
use Sabre\DAV\PropertyStorage\Backend\BackendInterface;
use \Sabre\DAV\PropFind;
use \Sabre\DAV\PropPatch;

class PropertyStorageBackend implements BackendInterface
{
    /**
     * Fetches properties for a path.
     *
     * This method received a PropFind object, which contains all the
     * information about the properties that need to be fetched.
     *
     * Ususually you would just want to call 'get404Properties' on this object,
     * as this will give you the _exact_ list of properties that need to be
     * fetched, and haven't yet.
     *
     * @param  string $path
     * @param  PropFind $propFind
     *
     * @return void
     */
    public function propFind($path, PropFind $propFind)
    {
        $propertyNames = $propFind->get404Properties();
        if (!$propertyNames) {
            return;
        }

        $cachedNodes = Cache::get('DAVNodes');

        $path = trim($path, '/');
        $path = str_replace('\\', '/', $path);

        // Node with $path is not in cached nodes, return
        if (!array_key_exists($path, $cachedNodes)) {
            return;
        }

        $node = $cachedNodes[$path];

        foreach ($propertyNames as $prop) {
            if ($prop == '{DAV:}creationdate') {
                $dttm = new \DateTime($node['cdate']);

                // $dttm->getTimestamp()
                $propFind->set($prop, \Sabre\HTTP\Util::toHTTPDate($dttm));
            } elseif ($prop == '{urn:schemas-microsoft-com:office:office}modifiedby' or $prop == '{DAV:}getmodifiedby') {
                // This has to be revised, because the User.login differs from User.DisplayName
                // moreover, during an edit, Word will check for File Properties and we
                // tell Word that the file is modified by another user
                // $propFind->set($prop, \Casebox\CoreBundle\Service\User::getDisplayName($node['uid']));
            }
        }
    }

    /**
     * Updates properties for a path
     *
     * This method received a PropPatch object, which contains all the
     * information about the update.
     *
     * Usually you would want to call 'handleRemaining' on this object, to get;
     * a list of all properties that need to be stored.
     *
     * @param  string $path
     * @param  PropPatch $propPatch
     *
     * @return void
     */
    public function propPatch($path, PropPatch $propPatch)
    {
        return true;
    }

    /**
     * This method is called after a node is deleted.
     *
     * This allows a backend to clean up all associated properties.
     */
    public function delete($path)
    {
    }

    /**
     * This method is called after a successful MOVE
     *
     * This should be used to migrate all properties from one path to another.
     * Note that entire collections may be moved, so ensure that all properties
     * for children are also moved along.
     */
    public function move($source, $destination)
    {
    }
}
