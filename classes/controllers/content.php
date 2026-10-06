<?php
/**
 * File containing the ezp7xContentRestController class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */

/**
 * This controller is used for serving content
 */
class ezp7xRestContentController extends ezpRestMvcController
{
    /**
     * Expected Response groups for content viewing
     * @var string
     */
    const VIEWCONTENT_RESPONSEGROUP_METADATA = 'Metadata',
          VIEWCONTENT_RESPONSEGROUP_LOCATIONS = 'Locations',
          VIEWCONTENT_RESPONSEGROUP_FIELDS = 'Fields';

    /**
     * Expected Response groups for field viewing
     * @var string
     */
    const VIEWFIELDS_RESPONSEGROUP_FIELDVALUES = 'FieldValues',
          VIEWFIELDS_RESPONSEGORUP_METADATA = 'Metadata';

    /**
     * Expected Response groups for content children listing
     * @var string
     */
    const VIEWLIST_RESPONSEGROUP_METADATA = 'Metadata',
          VIEWLIST_RESPONSEGROUP_FIELDS = 'Fields';

    /**
     * The status of a refused call, with a JSON body: {"error": "<reason>", "error_message": "<text>"}.
     *
     * @param int $code 400, 403, 404, 500, 501
     * @param string $reason invalid_request, access_denied, not_found, failed, not_implemented
     * @param string $message
     * @return ezpRestMvcResult
     */
    protected function errorResult( $code, $reason, $message )
    {
        $result = new ezpRestMvcResult();
        $result->status = new ezpRestStatusResponse( (int)$code, array( 'error' => $reason, 'error_message' => $message ) );
        return $result;
    }

    /**
     * The current user's rights for $action on the content of this request, checked the way the content module
     * checks them (expRestContentPermission of the kernel), whatever the request was authenticated with: an
     * OAuth token, a personal API key, HTTP basic authentication, or the anonymous user.
     *
     * @param string $action read, create, edit or remove
     * @param array $params nodeId, objectId, parentNodeID, classIdentifier, languageLocale
     * @return ezpRestMvcResult|null a refusal (400, 403, 404), or null to go on
     */
    protected function refusal( $action, array $params )
    {
        if ( !class_exists( 'expRestContentPermission' ) )
        {
            // reads are still checked by ezpContent (content/read); a write is refused rather than run unchecked
            if ( $action === 'read' )
                return null;
            return $this->errorResult( 403, 'access_denied', 'This installation cannot check the rights of a REST write: ezprestapi 1.2.5 needs Exponential 6.0.15 or later.' );
        }
        $refusal = expRestContentPermission::check( $action, $params );
        return $refusal === null ? null : $this->errorResult( $refusal['status'], $refusal['reason'], $refusal['message'] );
    }

    /**
     * The read check of the node or object of this request.
     *
     * @return ezpRestMvcResult|null
     */
    protected function readRefusal()
    {
        $params = array();
        if ( isset( $this->nodeId ) )
            $params['nodeId'] = $this->nodeId;
        else if ( isset( $this->objectId ) )
            $params['objectId'] = $this->objectId;
        return $this->refusal( 'read', $params );
    }

    /**
     * The POST fields of the request (parentNodeID, classIdentifier, languageLocale and the attribute values).
     *
     * @return array
     */
    protected function postFields()
    {
        return is_array( $this->request->post ) ? $this->request->post : array();
    }

    /**
     * Updates the content of a node
     *
     * Request:
     * - POST /api/ezp/v2/content/node/XXX
     *
     * Checks content/edit of the node (in languageLocale, when one is posted) and answers 403 without it. Updating
     * the attribute values is not implemented: an allowed call answers 501 and changes nothing.
     *
     * @return ezpRestMvcResult
     */
    public function doUpdateContentNode()
    {
        $post = $this->postFields();
        $params = array( 'nodeId' => isset( $this->nodeId ) ? $this->nodeId : null );
        if ( isset( $post['languageLocale'] ) )
            $params['languageLocale'] = $post['languageLocale'];
        if ( ( $refused = $this->refusal( 'edit', $params ) ) !== null )
            return $refused;

        return $this->errorResult( 501, 'not_implemented', 'Updating content over REST is not implemented yet; node ' . (int)$this->nodeId . ' is unchanged.' );
    }

    /**
     * Removes the object of a node, with all its locations and what is below them
     *
     * Requests:
     * - POST /api/ezp/v2/content/node/delete/XXX
     * - DELETE /api/ezp/v2/content/node/delete/XXX
     *
     * Checks content/remove of every location of the object and of everything below them, as the content module
     * does before a removal, and answers 403 without it (nothing is removed then). Answers 200 with
     * {"message", "nodeId", "objectId"} once removed.
     *
     * @return ezpRestMvcResult
     */
    public function doDeleteContentNode()
    {
        $nodeID = isset( $this->nodeId ) ? $this->nodeId : null;
        if ( ( $refused = $this->refusal( 'remove', array( 'nodeId' => $nodeID ) ) ) !== null )
            return $refused;

        $node = eZContentObjectTreeNode::fetch( (int)$nodeID );
        $object = $node instanceof eZContentObjectTreeNode ? $node->attribute( 'object' ) : null;
        if ( !$object instanceof eZContentObject )
            return $this->errorResult( 404, 'not_found', 'The node ' . (int)$nodeID . ' has no object.' );
        $objectID = (int)$object->attribute( 'id' );

        $pc = new nxcPowerContent( false, true );
        if ( !$pc->removeObject( $object ) )
            return $this->errorResult( 500, 'failed', 'The node ' . (int)$nodeID . ' could not be removed.' );

        $result = new ezpRestMvcResult();
        $result->status = new ezpRestStatusResponse( 200, array( 'message' => 'Removed', 'nodeId' => (int)$nodeID, 'objectId' => $objectID ) );
        return $result;
    }

    /**
     * Creates and publishes content below a node
     *
     * Request:
     * - POST /api/ezp/v2/content/node/create
     *
     * POST fields: parentNodeID, classIdentifier, languageLocale, and the attribute values by identifier.
     *
     * Checks content/create of the class below the parent node in the language (the Class, ParentClass, Section,
     * Node, Subtree and Language limitations), as the content module does, and answers 403 without it, 400 for a
     * missing field, an unknown class or language, 404 for an unknown parent. Answers 201 with
     * {"message", "objectId", "nodeId"} once published.
     *
     * @return ezpRestMvcResult
     */
    public function doCreateContentNode()
    {
        $post = $this->postFields();
        $params = array();
        foreach ( array( 'parentNodeID', 'classIdentifier', 'languageLocale' ) as $name )
            if ( isset( $post[$name] ) )
                $params[$name] = $post[$name];
        if ( !isset( $params['languageLocale'] ) || !is_scalar( $params['languageLocale'] ) || trim( (string)$params['languageLocale'] ) === '' )
            return $this->errorResult( 400, 'invalid_request', 'The parameter languageLocale (the language to create in, for example eng-US) is missing.' );
        if ( ( $refused = $this->refusal( 'create', $params ) ) !== null )
            return $refused;

        $parentNode = eZContentObjectTreeNode::fetch( (int)$params['parentNodeID'] );
        $class = eZContentClass::fetchByIdentifier( (string)$params['classIdentifier'] );

        $attributes = $post;
        foreach ( array( 'parentNodeID', 'classIdentifier', 'languageLocale' ) as $name )
            unset( $attributes[$name] );

        $pc = new nxcPowerContent( false, true );
        $object = $pc->createObject( array(
            'parentNode' => $parentNode,
            'class' => $class,
            'languageLocale' => (string)$params['languageLocale'],
            'attributes' => $attributes,
            'visibility' => true
        ) );
        if ( !$object instanceof eZContentObject )
            return $this->errorResult( 500, 'failed', 'The content could not be created.' );

        $result = new ezpRestMvcResult();
        $result->status = new ezpRestStatusResponse( 201, array( 'message' => 'Created',
                                                                 'objectId' => (int)$object->attribute( 'id' ),
                                                                 'nodeId' => (int)$object->attribute( 'main_node_id' ) ) );
        return $result;
    }

    /**
     * Handles content requests per node or object ID
     *
     * Requests:
     * - GET /api/content/node/XXX
     * - GET /api/content/object/XXX
     *
     * Optional HTTP parameters:
     * - translation=xxx-XX: an optionally forced locale to return
     *
     * @return ezpRestMvcResult
     */
    public function doViewContent()
    {
        if ( ( $refused = $this->readRefusal() ) !== null )
            return $refused;

        $this->setDefaultResponseGroups( array( self::VIEWCONTENT_RESPONSEGROUP_METADATA ) );
        $isNodeRequested = false;
        if ( isset( $this->nodeId ) )
        {
            $content = ezpContent::fromNodeId( $this->nodeId );
            $isNodeRequested = true;
        }
        else if ( isset( $this->objectId ) )
        {
            $content = ezpContent::fromObjectId( $this->objectId );
        }

        $result = new ezpRestMvcResult();

        // translation parameter
        if ( $this->hasContentVariable( 'Translation' ) )
            $content->setActiveLanguage( $this->getContentVariable( 'Translation' ) );

        // Handle metadata
        if ( $this->hasResponseGroup( self::VIEWCONTENT_RESPONSEGROUP_METADATA ) )
        {
            $objectMetadata = ezpRestContentModel::getMetadataByContent( $content );
            if ( $isNodeRequested )
            {
                $nodeMetadata = ezpRestContentModel::getMetadataByLocation( ezpContentLocation::fetchByNodeId( $this->nodeId ) );
                $objectMetadata = array_merge( $objectMetadata, $nodeMetadata );
            }
            $result->variables['metadata'] = $objectMetadata;
        }

        // Handle locations if requested
        if ( $this->hasResponseGroup( self::VIEWCONTENT_RESPONSEGROUP_LOCATIONS ) )
        {
            $result->variables['locations'] = ezpRestContentModel::getLocationsByContent( $content );
        }

        // Handle fields content if requested
        if ( $this->hasResponseGroup( self::VIEWCONTENT_RESPONSEGROUP_FIELDS ) )
        {
            $result->variables['fields'] = ezpRestContentModel::getFieldsByContent( $content );
        }

        // Add links to fields resources
        $result->variables['links'] = ezpRestContentModel::getFieldsLinksByContent( $content, $this->request );

        if ( $outputFormat = $this->getContentVariable( 'OutputFormat' ) )
        {
            $renderer = ezpRestContentRenderer::getRenderer( $outputFormat, $content, $this );
            $result->variables['renderedOutput'] = $renderer->render();
        }

        return $result;
    }

    /**
     * Handles a content request with fields per object or node id
     * Request: GET /api/content/object/XXX/fields
     * Request: GET /api/content/node/XXX/fields
     *
     * @return ezpRestMvcResult
     */
    public function doViewFields()
    {
        if ( ( $refused = $this->readRefusal() ) !== null )
            return $refused;

        $this->setDefaultResponseGroups( array( self::VIEWFIELDS_RESPONSEGROUP_FIELDVALUES ) );

        $isNodeRequested = false;
        if ( isset( $this->nodeId ) )
        {
            $content = ezpContent::fromNodeId( $this->nodeId );
            $isNodeRequested = true;
        }
        else if ( isset( $this->objectId ) )
        {
            $content = ezpContent::fromObjectId( $this->objectId );
        }

        $result = new ezpRestMvcResult();

        // translation parameter
        if ( $this->hasContentVariable( 'Translation' ) )
            $content->setActiveLanguage( $this->getContentVariable( 'Translation' ) );

        // Handle field values
        if ( $this->hasResponseGroup( self::VIEWFIELDS_RESPONSEGROUP_FIELDVALUES ) )
        {
            $result->variables['fields'] = ezpRestContentModel::getFieldsByContent( $content );
        }

        // Handle object/node metadata
        if ( $this->hasResponseGroup( self::VIEWFIELDS_RESPONSEGORUP_METADATA ) )
        {
            $objectMetadata = ezpRestContentModel::getMetadataByContent( $content );
            if ( $isNodeRequested )
            {
                $nodeMetadata = ezpRestContentModel::getMetadataByLocation( ezpContentLocation::fetchByNodeId( $this->nodeId ) );
                $objectMetadata = array_merge( $objectMetadata, $nodeMetadata );
            }
            $result->variables['metadata'] = $objectMetadata;
        }

        return $result;
    }

    /**
     * Handles a content unique field request through an object or node ID
     *
     * Requests:
     * - GET /api/content/node/:nodeId/field/:fieldIdentifier
     * - GET /api/content/object/:objectId/field/:fieldIdentifier
     *
     * @return ezpRestMvcResult
     */
    public function doViewField()
    {
        if ( ( $refused = $this->readRefusal() ) !== null )
            return $refused;

        $this->setDefaultResponseGroups( array( self::VIEWFIELDS_RESPONSEGROUP_FIELDVALUES ) );

        $isNodeRequested = false;
        if ( isset( $this->nodeId ) )
        {
            $isNodeRequested = true;
            $content = ezpContent::fromNodeId( $this->nodeId );
        }
        else if ( isset( $this->objectId ) )
        {
            $content = ezpContent::fromObjectId( $this->objectId );
        }

        if ( !isset( $content->fields->{$this->fieldIdentifier} ) )
        {
            throw new ezpContentFieldNotFoundException( "'$this->fieldIdentifier' field is not available for this content." );
        }

        // Translation parameter
        if ( $this->hasContentVariable( 'Translation' ) )
            $content->setActiveLanguage( $this->getContentVariable( 'Translation' ) );

        $result = new ezpRestMvcResult();

        // Field data
        if ( $this->hasResponseGroup( self::VIEWFIELDS_RESPONSEGROUP_FIELDVALUES ) )
        {
            $result->variables['fields'][$this->fieldIdentifier] = ezpRestContentModel::attributeOutputData( $content->fields->{$this->fieldIdentifier} );
        }

        // Handle object/node metadata
        if ( $this->hasResponseGroup( self::VIEWFIELDS_RESPONSEGORUP_METADATA ) )
        {
            $objectMetadata = ezpRestContentModel::getMetadataByContent( $content, $isNodeRequested );
            if ( $isNodeRequested )
            {
                $nodeMetadata = ezpRestContentModel::getMetadataByLocation( ezpContentLocation::fetchByNodeId( $this->nodeId ) );
                $objectMetadata = array_merge( $objectMetadata, $nodeMetadata );
            }
            $result->variables['metadata'] = $objectMetadata;
        }

        return $result;
    }

    /**
     * Handles a content request to view a node children list
     * Requests :
     *   - GET /api/v1/content/node/<nodeId>/list(/offset/<offset>/limit/<limit>/sort/<sortKey>/<sortType>)
     *   - Every parameters in parenthesis are optional. However, to have offset/limit and sort, the order is mandatory
     *     (you can't provide sorting params before limit params). This is due to a limitation in the regexp route.
     *   - Following requests are valid :
     *     - /api/ezp/content/node/2/list/sort/name => will display 10 (default limit) children of node 2, sorted by ascending name
     *     - /api/ezp/content/node/2/list/limit/50/sort/published/desc => will display 50 children of node 2, sorted by descending publishing date
     *     - /api/ezp/content/node/2/list/offset/100/limit/50/sort/published/desc => will display 50 children of node 2 starting from offset 100, sorted by descending publishing date
     *
     * Default values :
     *   - offset : 0
     *   - limit : 10
     *   - sortType : asc
     */
    public function doList()
    {
        if ( ( $refused = $this->refusal( 'read', array( 'nodeId' => isset( $this->nodeId ) ? $this->nodeId : null ) ) ) !== null )
            return $refused;

        $this->setDefaultResponseGroups( array( self::VIEWLIST_RESPONSEGROUP_METADATA ) );
        $result = new ezpRestMvcResult();
        $crit = new ezpContentCriteria();

        // Location criteria
        // Hmm, the following sequence is too long...
        $crit->accept[] = ezpContentCriteria::location()->subtree( ezpContentLocation::fetchByNodeId( $this->nodeId ) );
        $crit->accept[] = ezpContentCriteria::depth( 1 ); // Fetch children only

        // Limit criteria
        $offset = isset( $this->offset ) ? $this->offset : 0;
        $limit = isset( $this->limit ) ? $this->limit : 10;
        $crit->accept[] = ezpContentCriteria::limit()->offset( $offset )->limit( $limit );

        // Sort criteria
        if ( isset( $this->sortKey ) )
        {
            $sortOrder = isset( $this->sortType ) ? $this->sortType : 'asc';
            $crit->accept[] = ezpContentCriteria::sorting( $this->sortKey, $sortOrder );
        }

        $result->variables['childrenNodes'] = ezpRestContentModel::getChildrenList( $crit, $this->request, $this->getResponseGroups() );
        // REST links to children nodes
        // Little dirty since this should belong to the model layer, but I don't want to pass the router nor the full controller to the model
        $contentQueryString = $this->request->getContentQueryString( true );
        for ( $i = 0, $iMax = count( $result->variables['childrenNodes'] ); $i < $iMax; ++$i )
        {
            $linkURI = $this->getRouter()->generateUrl( 'ezpNode', array( 'nodeId' => $result->variables['childrenNodes'][$i]['nodeId'] ) );
            $result->variables['childrenNodes'][$i]['link'] = $this->request->getHostURI().$linkURI.$contentQueryString;
        }

        // Handle Metadata
        if ( $this->hasResponseGroup( self::VIEWLIST_RESPONSEGROUP_METADATA ) )
        {
            $childrenCount = ezpRestContentModel::getChildrenCount( $crit );
            $result->variables['metadata'] = array(
                'childrenCount' => $childrenCount,
                'parentNodeId'  => $this->nodeId
            );

        }

        return $result;
    }

    /**
     * Counts children of a given node
     * Request :
     *   - GET /api/ezp/content/node/childrenCount
     */
    public function doCountChildren()
    {
        if ( ( $refused = $this->refusal( 'read', array( 'nodeId' => isset( $this->nodeId ) ? $this->nodeId : null ) ) ) !== null )
            return $refused;

        $this->setDefaultResponseGroups( array( self::VIEWLIST_RESPONSEGROUP_METADATA ) );
        $result = new ezpRestMvcResult();

        if ( $this->hasResponseGroup( self::VIEWLIST_RESPONSEGROUP_METADATA ) )
        {
            $crit = new ezpContentCriteria();
            $crit->accept[] = ezpContentCriteria::location()->subtree( ezpContentLocation::fetchByNodeId( $this->nodeId ) );
            $crit->accept[] = ezpContentCriteria::depth( 1 ); // Fetch children only
            $childrenCount = ezpRestContentModel::getChildrenCount( $crit );
            $result->variables['metadata'] = array(
                'childrenCount' => $childrenCount,
                'parentNodeId'  => $this->nodeId
            );
        }

        return $result;
    }
}
?>
