<?php

/**
 * This extension provides a parser function #createpageifnotex that can be used to create
 * additional auxiliary pages when a page is saved. New pages are only created if they do
 * not exist yet. The function takes two parameters: (1) the title of the new page, 
 * (2) the text to be used on the new page. It is possible to use &lt;nowiki&gt; tags in the
 * text to inserst wiki markup more conveniently.
 * 
 * The created page is attributed to the user who made the edit. The original idea for this
 * code was edveloped by Daniel Herzig at AIFB Karlsruhe. In his code, there were some further
 * facilities to show a message to the user about the pages that have been auto-created. This
 * is not implemented here yet (the basic way of doing this would be to insert some custom
 * HTML during 'OutputPageBeforeHTML').
 *
 * The code restricts the use of the parser function to MediaWiki content namespaces. So
 * templates, for example, cannot create new pages by accident. Also, the code prevents created
 * pages from creating further pages to avoid (unbounded) chains of page creations.
 *
 * @author Markus Kroetzsch
 * @author Daniel Herzig
 * @file
 */

function logthis($asd){ 
    global $egAutoCreatePageLogfile;
    if (isset($egAutoCreatePageLogfile)) {
        try {
            $myfile = fopen($egAutoCreatePageLogfile,"a");
            fwrite($myfile,$asd);
            fwrite($myfile,"\n");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
if ( !defined( 'MEDIAWIKI' ) ) {
    die( 'Not an entry point.' );
}

/**
 * This is decreased during page creation to avoid infinite recursive creation of pages.
 */
$egAutoCreatePageMaxRecursion = 1;

$egAutoCreatePageIgnoreEmptyTitle = false;

$egAutoCreatePageNamespaces = $wgContentNamespaces;

$dummyCSRFToken="+\\";


function editRequest( $csrftoken ,$title,$text) {
    # variable from LocalSettings.php
    
    global $egAutoCreatePageAPIEndpoint;
	$params4 = [
		"action" => "edit",
		"title" => $title,
		"text" => $text,
		"token" => $csrftoken,
        "format" => "json",
        "createonly" => true
	];

	$ch = curl_init();

	curl_setopt( $ch, CURLOPT_URL, $egAutoCreatePageAPIEndpoint );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $params4 ) );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_COOKIEJAR, "cookie.txt" );
	curl_setopt( $ch, CURLOPT_COOKIEFILE, "cookie.txt" );

	$output = curl_exec( $ch );
	curl_close( $ch );

	return $output;
}


$GLOBALS['wgExtensionCredits']['other'][] = array(
	'name'         => 'AutoCreatePage',
	'version'      => '0.6',
	'author'       => '[http://korrekt.org Markus Krötzsch], Daniel Herzig', 
	'url'          => ' ',
	'description'  => 'Provides a parser function to create additional wiki pages with default content when saving a page.', //TODO i18n
	'license-name' => 'GPL-2.0+'
);

$GLOBALS['wgExtensionMessagesFiles']['AutoCreatePageMagic'] =  dirname(__FILE__) . '/AutoCreatePage.i18n.magic.php';

$GLOBALS['wgExtensionFunctions'][] = function() {

	$GLOBALS['wgHooks']['ParserFirstCallInit'][] = function ( \Parser &$parser ) {

		$parser->setFunctionHook( 'createPage', function( $parser ) {
			return createPageIfNotExisting( func_get_args() );
		} );

	};

	$GLOBALS['wgHooks']['RevisionDataUpdates'][] = 'doCreatePages';
};

/**
 * Handles the parser function for creating pages that don't exist yet,
 * filling them with the given default content. It is possible to use &lt;nowiki&gt;
 * in the default text parameter to insert verbatim wiki text.
 */
function createPageIfNotExisting( array $rawParams ) {
    global $egAutoCreatePageMaxRecursion, $egAutoCreatePageIgnoreEmptyTitle, $egAutoCreatePageNamespaces;
    if ( $egAutoCreatePageMaxRecursion <= 0 ) {

		return 'Error: Recursion level for auto-created pages exeeded.'; //TODO i18n
	}

	if ( isset( $rawParams[0] ) && isset( $rawParams[1] ) && isset( $rawParams[2] ) ) {
		$parser = $rawParams[0];
		$newPageTitleText = $rawParams[1];
		$newPageContent = $rawParams[2];
    } else {

		throw new MWException( 'Hook invoked with missing parameters.' );
	}

	if ( empty( $newPageTitleText ) ) {
        if ( $egAutoCreatePageIgnoreEmptyTitle === false ) {
            
			return 'Error: this function must be given a valid title text for the page to be created.'; //TODO i18n
        } else {

			return '';
		}
	}

	// Create pages only if the page calling the parser function is within defined namespaces
	if ( !in_array( $parser->getTitle()->getNamespace(), $egAutoCreatePageNamespaces ) ) {
        
        return '';
	}

	// Get the raw text of $newPageContent as it was before stripping <nowiki>:
	$newPageContent = $parser->getStripState()->unstripNoWiki( $newPageContent );

	// Store data in the parser output for later use:
	$createPageData = $parser->getOutput()->getExtensionData( 'createPage' );
	if ( is_null( $createPageData ) ) {
		$createPageData = array();
	}
	$createPageData[$newPageTitleText] = $newPageContent;
	$parser->getOutput()->setExtensionData( 'createPage', $createPageData );

	return "";
}

/**
 * Creates pages that have been requested by the creat page parser function. This is done only
 * after the safe is complete to avoid any concurrent article modifications.
 * Note that article is, in spite of its name, a WikiPage object since MW 1.21.
 */
function doCreatePages( $title, $renderedRevision, &$updates ) {
	global $egAutoCreatePageMaxRecursion;

    
    $createPageData = $renderedRevision->getRevisionParserOutput()->getExtensionData( 'createPage' );
    if ( is_null( $createPageData ) ) {
		return true; // no pages to create
	}
	// Prevent pages to be created by pages that are created to avoid loops:
	$egAutoCreatePageMaxRecursion--;
    $sourceTitle = Title::newFromId($renderedRevision->getRevision()->getPageId());
	$sourceTitleText = $sourceTitle->getPrefixedText();
        
    foreach ( $createPageData as $pageTitleText => $pageContentText ) {
        $pageTitle = Title::newFromText( $pageTitleText );

        # use api call because its esier to implement    
        logthis("Tring to create page " . $pageTitleText);
        $req = editRequest("+\\",$pageTitleText,$pageContentText);
        logthis("Response was:" . $req);
	}

	// Reset state. Probably not needed since parsing is usually done here anyway:
	$renderedRevision->getRevisionParserOutput()->setExtensionData( 'createPage', null ); 
	$egAutoCreatePageMaxRecursion++;

	return true;
}
?>
