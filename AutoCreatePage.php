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

if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'Not an entry point.' );
}

/**
 * This is set to false during page creation to avoid recursive creation of pages.
 */
$gEnablePageCreation = true;

$GLOBALS['wgExtensionCredits']['other'][] = array(
	'name'         => 'AutoCreatePage',
	'version'      => '0.4',
	'author'       => '[http://korrekt.org Markus Krötzsch], Daniel Herzig', 
	'url'          => ' ',
	'description'  => 'Provides a parser function to create additional wiki pages with default content when saving a page.', //TODO i18n
	'license-name' => 'GPL-2.0+'
);

$GLOBALS['wgExtensionMessagesFiles']['AutoCreatePageMagic'] =  dirname(__FILE__) . '/AutoCreatePage.i18n.magic.php';

$GLOBALS['wgExtensionFunctions'][] = function() {

	$GLOBALS['wgHooks']['ParserFirstCallInit'][] = function ( \Parser &$parser ) {

		$parser->setFunctionHook( 'createPage', function( $parser ) {
			return createPage( false, func_get_args() );
		} );

	};
	$GLOBALS['wgHooks']['ParserFirstCallInit'][] = function ( \Parser &$parser ) {

		$parser->setFunctionHook( 'createOrReplacePage', function( $parser ) {
			return createPage( true, func_get_args() );
		} );

	};

	$GLOBALS['wgHooks']['ArticleEditUpdates'][] = 'doCreatePages';
};

/**
 * Handles the parser function for creating/editing pages,
 * filling them with the given content. It is possible to use &lt;nowiki&gt;
 * in the default text parameter to insert verbatim wiki text.
 *
 * @param $canReplace true indicates that the content will be replaced with a new
 *                    revision if the page already exists, false indicates that the
 *                    existing page will be left untouched.
 */
function createPage( $canReplace, array $rawParams ) {
	global $wgContentNamespaces, $gEnablePageCreation;

	if ( !$gEnablePageCreation ) {
		return "Error: auto-created pages cannot create other pages."; //TODO i18n
	}

	if ( isset( $rawParams[2] ) && isset( $rawParams[1] ) && isset( $rawParams[2] ) ) {
		$parser = $rawParams[0];
		$newPageTitleText = $rawParams[1];
		$newPageContent = $rawParams[2];
		$newPageMessage = $rawParams[3];
	}

	if ( empty( $newPageTitleText ) ) {
		return "Error: this function must be given a valid title text for the page to be created."; //TODO i18n
	}

	// Create pages only if in contentnamespace (not for templates, forms etc.)
	if ( !in_array( $parser->getTitle()->getNamespace(), $wgContentNamespaces ) ) {
		return "";
	}

	// Get the raw text of $newPageContent as it was before stripping <nowiki>:
	$newPageContent = $parser->mStripState->unstripNoWiki( $newPageContent );

	// Store data in the parser output for later use:
	$createPageData = $parser->getOutput()->getExtensionData( 'createPage' );
	if ( is_null( $createPageData ) ) {
		$createPageData = array(
			false => array(),
			true  => array()
			);
	}
	$createPageData[$canReplace][$newPageTitleText] = array( $newPageContent, $newPageMessage );
	$parser->getOutput()->setExtensionData( 'createPage', $createPageData );

	return "";
}

/**
 * Creates pages that have been requested by the creat page parser function. This is done only
 * after the safe is complete to avoid any concurrent article modifications.
 * Note that article is, in spite of its name, a WikiPage object since MW 1.21.
 */
function doCreatePages( &$article, &$editInfo, $changed ) {
	global $gEnablePageCreation;

	$createPageData = $editInfo->output->getExtensionData( 'createPage' );
	if ( is_null( $createPageData ) ) {
		return true; // no pages to create
	}

	// Prevent pages to be created by pages that are created to avoid loops:
	$gEnablePageCreation = false;

	$sourceTitle = $article->getTitle();
	$sourceTitleText = $sourceTitle->getPrefixedText();

	foreach ( $createPageData as $canReplace => $pageData ) {
	        foreach ( $pageData as $pageTitleText => $pageUpdate ) {
			list( $pageContentText, $pageComment ) = $pageUpdate;
			$pageTitle = Title::newFromText( $pageTitleText );
			// wfDebugLog( 'createpage', "CREATE " . $pageTitle->getText() . " Text: " . $pageContent );

			if ( is_null( $pageTitle ) ) {
				continue;
			}
			$isKnown = $pageTitle->isKnown();
			if ( !is_null( $pageTitle ) && ( $canReplace || !$isKnown ) && $pageTitle->canExist() ) {
				$newWikiPage = new WikiPage( $pageTitle );
				$pageContent = ContentHandler::makeContent( $pageContentText, $sourceTitle );
				$message = isset( $pageComment ) ? $pageComment : 'Page ' . ( $isKnown ? 'upd' : 'cre' ) . "ated automatically by parser function on page [[$sourceTitleText]]"; //TODO i18n
				$newWikiPage->doEditContent( $pageContent, $message );
				// wfDebugLog( 'createpage', "CREATED PAGE " . $pageTitle->getText() . " Text: " . $pageContent );
			}
		}
	}

	// Reset state. Probably not needed since parsing is usually done here anyway:
	$editInfo->output->setExtensionData( 'createPage', null ); 
	$gEnablePageCreation = true;

	return true;
}

