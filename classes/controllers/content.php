<?php
/**
 * File containing the ezp7xContentRestController class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
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
     * The POST fields of the request (parentNodeID, classIdentifier, languageLocale and the attribute values): the
     * form fields, or the members of a JSON object sent as the body (Content-Type: application/json).
     *
     * @return array
     */
    protected function postFields()
    {
        if ( is_array( $this->request->post ) && $this->request->post )
            return $this->request->post;
        $body = isset( $this->request->body ) && is_string( $this->request->body ) ? trim( $this->request->body ) : '';
        if ( $body !== '' && $body[0] === '{' )
        {
            $decoded = json_decode( $body, true );
            if ( is_array( $decoded ) )
                return $decoded;
        }
        return array();
    }

    /**
     * The REST answer of an expContentWriter result.
     *
     * @param array $written status, message, object, version (expContentWriter)
     * @param int $successCode 200 or 201
     * @param int $nodeID the node of the request, 0 for the new object's main node
     * @return ezpRestMvcResult
     */
    protected function writeResult( array $written, $successCode, $nodeID = 0 )
    {
        if ( $written['status'] === 'invalid' )
            return $this->errorResult( 400, 'invalid_request', $written['message'] );
        if ( $written['status'] === 'failed' || !$written['object'] instanceof eZContentObject )
            return $this->errorResult( 500, 'failed', $written['message'] );
        $body = array( 'message' => $written['message'],
                       'objectId' => (int)$written['object']->attribute( 'id' ),
                       'nodeId' => $nodeID ? (int)$nodeID : (int)$written['object']->attribute( 'main_node_id' ) );
        if ( $written['version'] )
            $body['version'] = (int)$written['version'];
        if ( !empty( $written['languageLocale'] ) )
            $body['languageLocale'] = $written['languageLocale'];
        $result = new ezpRestMvcResult();
        $result->status = new ezpRestStatusResponse( $written['status'] === 'pending' ? 202 : (int)$successCode, $body );
        return $result;
    }

    /**
     * Whether the kernel can write content for REST (Exponential 6.0.15 or later), as a refusal when it cannot.
     *
     * @return ezpRestMvcResult|null
     */
    protected function writerRefusal()
    {
        if ( class_exists( 'expContentWriter' ) )
            return null;
        return $this->errorResult( 501, 'not_implemented', 'This installation cannot write content over REST: ezprestapi 1.2.9 needs Exponential 6.0.15 or later.' );
    }

    /**
     * Updates the content of a node: publishes a new version of its object with the posted attribute values
     *
     * Requests:
     * - POST /api/ezp/v2/content/node/XXX
     * - PUT /api/ezp/v2/content/node/XXX (or POST with _method=PUT)
     *
     * Fields (form fields or a JSON object): languageLocale (optional, the object's main language by default; a
     * language the object does not have yet adds that translation), and the attribute values by identifier, in the
     * format of a create (rich text as HTML, a file as a public http(s) URL, everything else as the attribute's text
     * form). Attributes left out keep their values.
     *
     * Checks content/edit of the node in the language, as the content module does, and answers 403 without it; 404
     * for an unknown node; 400 for no attribute values, an unknown attribute, a value that is not text, a file that is
     * not a public URL or an unknown language. The new version is published through the publish operation, so
     * workflows run. Answers 200 with {"message", "objectId", "nodeId", "version", "languageLocale"} once published,
     * or 202 when a workflow holds the publication (the version then waits there).
     *
     * @return ezpRestMvcResult
     */
    public function doUpdateContentNode()
    {
        $post = $this->postFields();
        unset( $post['_method'] );
        $nodeID = isset( $this->nodeId ) ? (int)$this->nodeId : 0;
        $node = eZContentObjectTreeNode::fetch( $nodeID );
        $object = $node instanceof eZContentObjectTreeNode ? $node->attribute( 'object' ) : null;
        if ( !$object instanceof eZContentObject )
            return $this->errorResult( 404, 'not_found', 'The node ' . $nodeID . ' does not exist.' );

        if ( isset( $post['languageLocale'] ) && ( !is_scalar( $post['languageLocale'] ) || trim( (string)$post['languageLocale'] ) === '' ) )
            return $this->errorResult( 400, 'invalid_request', 'The parameter languageLocale must be a language, for example eng-US.' );
        $languageCode = isset( $post['languageLocale'] ) ? trim( (string)$post['languageLocale'] ) : '';
        if ( $languageCode === '' )
        {
            $initialLanguage = eZContentLanguage::fetch( $object->attribute( 'initial_language_id' ) );
            $languageCode = $initialLanguage instanceof eZContentLanguage ? $initialLanguage->attribute( 'locale' ) : '';
        }
        if ( !eZContentLanguage::fetchByLocale( $languageCode ) instanceof eZContentLanguage )
            return $this->errorResult( 400, 'invalid_request', 'The language ' . $languageCode . ' is not a language of this installation.' );

        if ( ( $refused = $this->refusal( 'edit', array( 'nodeId' => $nodeID, 'languageLocale' => $languageCode ) ) ) !== null )
            return $refused;
        if ( ( $refused = $this->writerRefusal() ) !== null )
            return $refused;

        $values = $post;
        unset( $values['languageLocale'] );
        return $this->writeResult( expContentWriter::update( $object, $values, $languageCode ), 200, $nodeID );
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
        if ( ( $refused = $this->writerRefusal() ) !== null )
            return $refused;

        $node = eZContentObjectTreeNode::fetch( (int)$nodeID );
        $object = $node instanceof eZContentObjectTreeNode ? $node->attribute( 'object' ) : null;
        if ( !$object instanceof eZContentObject )
            return $this->errorResult( 404, 'not_found', 'The node ' . (int)$nodeID . ' has no object.' );
        $objectID = (int)$object->attribute( 'id' );

        $removed = expContentWriter::remove( $object );
        if ( $removed['status'] !== 'published' )
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
     * Fields (form fields or a JSON object): parentNodeID, classIdentifier, languageLocale, and the attribute values
     * by identifier (rich text as HTML, a file as a public http(s) URL, everything else as the attribute's text form).
     *
     * Checks content/create of the class below the parent node in the language (the Class, ParentClass, Section,
     * Node, Subtree and Language limitations), as the content module does, and answers 403 without it, 400 for a
     * missing field, an unknown class, attribute or language, 404 for an unknown parent. Answers 201 with
     * {"message", "objectId", "nodeId", "version"} once published, or 202 when a workflow holds the publication.
     *
     * @return ezpRestMvcResult
     */
    public function doCreateContentNode()
    {
        $post = $this->postFields();
        unset( $post['_method'] );
        $params = array();
        foreach ( array( 'parentNodeID', 'classIdentifier', 'languageLocale' ) as $name )
            if ( isset( $post[$name] ) )
                $params[$name] = $post[$name];
        if ( !isset( $params['languageLocale'] ) || !is_scalar( $params['languageLocale'] ) || trim( (string)$params['languageLocale'] ) === '' )
            return $this->errorResult( 400, 'invalid_request', 'The parameter languageLocale (the language to create in, for example eng-US) is missing.' );
        if ( ( $refused = $this->refusal( 'create', $params ) ) !== null )
            return $refused;
        if ( ( $refused = $this->writerRefusal() ) !== null )
            return $refused;

        $attributes = $post;
        foreach ( array( 'parentNodeID', 'classIdentifier', 'languageLocale' ) as $name )
            unset( $attributes[$name] );

        return $this->writeResult( expContentWriter::create( array(
            'parentNodeID' => (int)$params['parentNodeID'],
            'classIdentifier' => (string)$params['classIdentifier'],
            'languageLocale' => trim( (string)$params['languageLocale'] ),
            'attributes' => $attributes ) ), 201 );
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
