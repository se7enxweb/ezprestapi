<?php
/**
 * The code of extension/ezprestapi/bin/php/ezrestcall.php, moved into a class (#207 stage 1). The file extension/ezprestapi/bin/php/ezrestcall.php is one call to it.
 * Guide: doc/bc/6.0/cli_cronjob_view_abstractions.md
 */

namespace Exponential\Command\Extension\Ezprestapi
{

class Ezrestcall extends \Exponential\Runnable\Command
{
    public function run()
    {
        // the script's variables were globals; functions of the script read them with "global"
        foreach ( array( 'action', 'cli', 'client', 'host', 'limit', 'oauth_token', 'options', 'protocol', 'res', 'script', 'token', 'uri', 'url' ) as $__name )
            ${$__name} = &$GLOBALS[$__name];
        unset( $__name );

        // Load cli and script environment
        $cli = \eZCLI::instance();
        $script = \eZScript::instance( array( 'description' => ( "eZ Publish Rest Content Creator Handler\nAllows for easy publishing via rest apis of eZ Publish Legacy\n Example: ./bin/php/ezrestcall.php --host=digg.one" ),
                                             'use-session' => true,
                                             'use-modules' => true,
                                             'use-extensions' => true ) ); 
        $script->startup();

        // Fetch default script options
        $options = $script->getOptions( "[host:][token:][action:][uri:]",
                                        "",
        				array( 'host' => 'host domain name no protocol',
                                               'token' => 'Oauth Token Key',
                                               'action' => 'Action of Script. Default: List' ) );
        $script->initialize();

        // Script parameters
        $host = isset( $options['host'] ) ? $options['host'] : 'localhost';
        $token = isset( $options['token'] ) ? $options['token'] : 'false';
        $action = isset( $options['action'] ) ? $options['action'] : 'list';
        $uri  = isset( $options['uri'] ) ? $options['uri'] : 'false';
        $limit  = isset( $options['limit'] ) ? $options['limit'] : 100;

        $protocol = 'https';
        $oauth_token = $token;

        // Some client to post to ez publish rest api (v2 for legacy)
        // From: https://docs.guzzlephp.org/en/stable/quickstart.html
        // The following example requires guzzle to be installed via composer but it is not part of the standard extension distribution requirements.
        // composer require guzzlehttp/guzzle;

        $client = new \GuzzleHttp\Client();

        echo "Starting up ...\n\n";

            if( $action === 'list' )
            {
                //$uri = '/api/ezpl/v2/content/node/687/list';
                $url = $protocol . '://' . $host . $uri . "/limit/" . $limit . "?oauth_token=". $oauth_token;

                // fetch main node children (categories)
        	echo $url ."\n";
        	
                $res = $client->request( 'GET', $url, []);

        	echo $res->getStatusCode();           // 200
        	echo "\n\n";
        	echo var_dump( $res->getHeader('content-type') ); // 'application/json; charset=utf8'
        	echo "\n\n";
        	echo var_dump( json_decode( $res->getBody() ) );// {"type":"User"...'

            }

            if( $action === 'login' )
            {
                // post login details to digg.one/user/login
        	$uri = '/user/login';
                $url = $protocol . '://' . $host . $uri;

            	echo $url; echo "\n\n";

        	$res = $client->request( 'POST', $url, [
            	      'form_params' => [
                      'Login' => "graham", //user name
                      'Password' => "WbjiFuKSchrkK7x", // password
                      'LoginButton' => 'Login',
                      ]
                ]);

                echo $res->getStatusCode();           // 200
                echo "\n\n";

                //echo $res->getHeader('content-type'); // 'application/json; charset=utf8'
                //echo "\n\n";
                //echo $res->getBody();                 // {"type":"User"...'
                //var_export($res->getBody());             // Outputs the JSON decoded data
            }

            if( $action === 'create' )
            {
                // post example story to digg
        	$uri = '/api/ezpl/v2/content/node/create';
        	$url = $protocol . '://' . $host . $uri . "?oauth_token=". $oauth_token;
        	//        'image' => "675",

        	echo $url; echo "\n\n";

        	$res = $client->request( 'POST', $url, [
        	'form_params' => [
                'oauth_token' => $oauth_token,
                'parentNodeID' => 696, //mods category
                'classIdentifier' => "story", // create stories to start images and combo stubs
        	'languageLocale' => "eng-US",
        	'title' => "Title Text String 00001az27x1.3.1",
                'link' => "https://example.com",
                'intro' => "Introduction Sumary String",
                'body' => "Body Text String",
                'enable_comments' => 1,
            	]
        	]);

        	echo $res->getStatusCode();           // 200
        	echo "\n\n";
        	echo var_dump( $res->getHeader('content-type') ); // 'application/json; charset=utf8'
        	echo "\n\n";
        	echo var_dump( json_decode( $res->getBody() ) );// {"type":"User"...'

                // var_export($res->getBody());             // Outputs the JSON decoded data
            }

        // Exit script normally
        $script->shutdown();
    }
}

}
