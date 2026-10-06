<?php
/**
 * File containing the ezp7xRestApiProvider class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @copyright Copyright (C) 7x and eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

/**
 * The routes of the ezp provider.
 *
 * Reads answer at v1 and v2: v1 is the API of ezprestapiprovider that existing clients call
 * (/api/ezp/v1/content/node/<id>/list/...), and this provider replaces that one for the ezp prefix, so it keeps
 * v1 alive. Writes answer at v2 only:
 *
 *   POST          /content/node/create            create below parentNodeID
 *   POST          /content/node/:nodeId           update (UpdateContentNode)
 *   POST, DELETE  /content/node/delete/:nodeId    remove the node's object
 *
 * Serving one route for several versions needs a kernel whose ezpRestVersionedRoute takes a list of versions
 * (Exponential 6.0.15 and later), which also tries the next route when one matches the path but not the method.
 * On an older kernel the routes are registered for v2 alone, as before.
 */
class ezp7xRestApiProvider implements ezpRestProviderInterface
{
    /**
     * Returns registered versioned routes for provider
     *
     * @return array Associative array. Key is the route name (beware of name collision !). Value is the versioned route.
     */
    public function getRoutes()
    {
        $modern = self::kernelTakesVersionLists();
        $read = $modern ? array( 1, 2 ) : 2;

        $routes = array(
            'ezpListAtom' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/node/:nodeId/listAtom', 'ezpRestAtomController',
                    array( 'http-get' => 'collection' )
                ), $read
            ),
            'ezpNodeCreate' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/node/create', 'ezp7xRestContentController',
                    array( 'http-post' => 'CreateContentNode' )
                ),
                2
            ),
            'ezpNodeDelete' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/node/delete/:nodeId', 'ezp7xRestContentController',
                    array( 'http-post' => 'DeleteContentNode', 'http-delete' => 'DeleteContentNode' )
                ),
                2
            ),
            // @TODO : Make possible to interchange optional params positions
            'ezpList' => new ezpRestVersionedRoute(
                new ezpMvcRegexpRoute(
                    '@^/content/node/(?P<nodeId>\d+)/list(?:/offset/(?P<offset>\d+))?(?:/limit/(?P<limit>\d+))?(?:/sort/(?P<sortKey>\w+)(?:/(?P<sortType>asc|desc))?)?$@',
                    'ezp7xRestContentController', array( 'http-get' => 'list' )
                ),
                $read
            ),
        );

        if ( $modern )
        {
            // one route per method set: the read answers at v1 and v2, the update at v2 only
            $routes['ezpNode'] = new ezpRestVersionedRoute(
                new ezpMvcRailsRoute( '/content/node/:nodeId', 'ezp7xRestContentController', array( 'http-get' => 'viewContent' ) ),
                $read
            );
            $routes['ezpNodeUpdate'] = new ezpRestVersionedRoute(
                new ezpMvcRailsRoute( '/content/node/:nodeId', 'ezp7xRestContentController', array( 'http-post' => 'UpdateContentNode' ) ),
                2
            );
        }
        else
        {
            $routes['ezpNode'] = new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/node/:nodeId', 'ezp7xRestContentController',
                    array( 'http-get' => 'viewContent', 'http-post' => 'UpdateContentNode' )
                ),
                2
            );
        }

        // the field, count and object reads run in this extension's controller too, so they get its checks
        $routes += array(
            'ezpFieldsByNode' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/node/:nodeId/fields', 'ezp7xRestContentController',
                    array( 'http-get' => 'viewFields' )
                ),
                $read
            ),
            'ezpFieldByNode' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/node/:nodeId/field/:fieldIdentifier',
                    'ezp7xRestContentController',
                    array( 'http-get' => 'viewField' )
                ),
                $read
            ),
            'ezpChildrenCount' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/node/:nodeId/childrenCount',
                    'ezp7xRestContentController',
                    array( 'http-get' => 'countChildren' )
                ),
                $read
            ),
            'ezpObject' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/object/:objectId', 'ezp7xRestContentController',
                    array( 'http-get' => 'viewContent' )
                ),
                $read
            ),
            'ezpFieldsByObject' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/object/:objectId/fields',
                    'ezp7xRestContentController',
                    array( 'http-get' => 'viewFields' )
                ),
                $read
            ),
            'ezpFieldByObject' => new ezpRestVersionedRoute(
                new ezpMvcRailsRoute(
                    '/content/object/:objectId/field/:fieldIdentifier',
                    'ezp7xRestContentController',
                    array( 'http-get' => 'viewField' )
                ),
                $read
            )
        );
        return $routes;
    }

    /**
     * Whether the kernel's versioned routes take a list of versions (and its router tries the next route when
     * one matches the path but not the method).
     *
     * @return bool
     */
    public static function kernelTakesVersionLists()
    {
        return method_exists( 'ezpRestVersionedRoute', 'getVersions' );
    }

    /**
     * Returns associated with provider view controller
     *
     * @return ezpRestViewController
     */
    public function getViewController()
    {
        return new ezpRestApiViewController();
    }
}
