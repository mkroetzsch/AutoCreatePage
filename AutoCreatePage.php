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

class AutoCreatePage {

	public static function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'createPage', [ self::class, 'createPageIfNotExisting' ]);
	}

	public function onArticleEditUpdates( $wikiPage, $editInfo, $changed ) {
		self::doCreatePages($wikiPage, $editInfo, $changed);
	}

	/**
	 * Handles the parser function for creating pages that don't exist yet,
	 * filling them with the given default content. It is possible to use &lt;nowiki&gt;
	 * in the default text parameter to insert verbatim wiki text.
	 */
	public static function createPageIfNotExisting( $parser, $newPageTitleText, $newPageContent ) {
		global $egAutoCreatePageMaxRecursion, $egAutoCreatePageIgnoreEmptyTitle,
			   $egAutoCreatePageNamespaces, $wgContentNamespaces;

		$autoCreatePageNamespaces = $egAutoCreatePageNamespaces ?? $wgContentNamespaces;

		if ( $egAutoCreatePageMaxRecursion <= 0 ) {
			return 'Error: Recursion level for auto-created pages exceeded.'; //TODO i18n
		}

		if ( empty( $newPageTitleText ) ) {
			if ( $egAutoCreatePageIgnoreEmptyTitle === false ) {
				return 'Error: this function must be given a valid title text for the page to be created.'; //TODO i18n
			} else {
				return '';
			}
		}

		// Create pages only if the page calling the parser function is within defined namespaces
		if ( !in_array( $parser->getTitle()->getNamespace(), $autoCreatePageNamespaces ) ) {
			return '';
		}

		// Get the raw text of $newPageContent as it was before stripping <nowiki>:
		$newPageContent = $parser->mStripState->unstripNoWiki( $newPageContent );

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
	 * Creates pages that have been requested by the create page parser function. This is done only
	 * after the safe is complete to avoid any concurrent article modifications.
	 * Note that article is, in spite of its name, a WikiPage object since MW 1.21.
	 */
	private static function doCreatePages( $article, $editInfo, $changed ) {
		global $egAutoCreatePageMaxRecursion;

		$createPageData = $editInfo->output->getExtensionData( 'createPage' );
		if ( is_null( $createPageData ) ) {
			return true; // no pages to create
		}

		// Prevent pages to be created by pages that are created to avoid loops:
		$egAutoCreatePageMaxRecursion--;

		$sourceTitle = $article->getTitle();
		$sourceTitleText = $sourceTitle->getPrefixedText();

		foreach ( $createPageData as $pageTitleText => $pageContentText ) {
			$pageTitle = Title::newFromText( $pageTitleText );
			// wfDebugLog( 'createpage', "CREATE " . $pageTitle->getText() . " Text: " . $pageContent );

			if ( !is_null( $pageTitle ) && !$pageTitle->isKnown() && $pageTitle->canExist() ){
				$newWikiPage = new WikiPage( $pageTitle );
				$pageContent = ContentHandler::makeContent( $pageContentText, $sourceTitle );
				$newWikiPage->doEditContent( $pageContent,
					"Page created automatically by parser function on page [[$sourceTitleText]]" ); //TODO i18n

				// wfDebugLog( 'createpage', "CREATED PAGE " . $pageTitle->getText() . " Text: " . $pageContent );
			}
		}

		// Reset state. Probably not needed since parsing is usually done here anyway:
		$editInfo->output->setExtensionData( 'createPage', null );
		$egAutoCreatePageMaxRecursion++;

		return true;
	}

}
