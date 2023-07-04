<?php

namespace ACP;

use ContentHandler;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\SpecialPage\Hook\SpecialPageAfterExecuteHook;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\Storage\Hook\RevisionDataUpdatesHook;
use Parser;
use Title;
use WikiPage;

/**
 * This extension provides a parser function #createpageifnotex that can be used to create
 * additional auxiliary pages when a page is saved. New pages are only created if they do
 * not exist yet. The function takes two parameters: (1) the title of the new page,
 * (2) the text to be used on the new page. It is possible to use &lt;nowiki&gt; tags in the
 * text to insert wiki markup more conveniently.
 *
 * The created page is attributed to the user who made the edit. The original idea for this
 * code was developed by Daniel Herzig at AIFB Karlsruhe. In his code, there were some further
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
class AutoCreatePage implements
	ParserFirstCallInitHook,
	RevisionDataUpdatesHook,
	SpecialPageBeforeExecuteHook,
	SpecialPageAfterExecuteHook
{

	/**
	 * @var SpecialPage[]
	 */
	private static $specialPageStack = [];

	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'createPage', [ self::class, 'createPageIfNotExisting' ] );
	}

	/**
	 * Handles the parser function for creating pages that don't exist yet,
	 * filling them with the given default content. It is possible to use &lt;nowiki&gt;
	 * in the default text parameter to insert verbatim wiki text.
	 * @param Parser $parser
	 * @param string $newPageTitleText
	 * @param string $newPageContent
	 * @return string
	 */
	public static function createPageIfNotExisting( $parser, $newPageTitleText, $newPageContent ) {
		global $egAutoCreatePageMaxRecursion, $egAutoCreatePageIgnoreEmptyTitle,
			   $egAutoCreatePageNamespaces, $wgContentNamespaces;

		$autoCreatePageNamespaces = $egAutoCreatePageNamespaces ?? $wgContentNamespaces;

		if ( $egAutoCreatePageMaxRecursion <= 0 ) {
			return 'Error: Recursion level for auto-created pages exceeded.'; // TODO i18n
		}

		if ( empty( $newPageTitleText ) ) {
			if ( $egAutoCreatePageIgnoreEmptyTitle === false ) {
				return 'Error: this function must be given a valid title text for the page to be created.'; // TODO i18n
			} else {
				return '';
			}
		}

		// Get the raw text of $newPageContent as it was before stripping <nowiki>:
		$newPageContent = $parser->getStripState()->unstripNoWiki( $newPageContent );

		$enabledSpecialPage = self::enabledSpecialPage();
		if ( $enabledSpecialPage ) {
			// Store data in static variable for later use in onSpecialPageAfterExecute:
			$enabledSpecialPage->addPageToCreate( $newPageTitleText, $newPageContent );
		} elseif ( in_array( $parser->getTitle()->getNamespace(), $autoCreatePageNamespaces ) ) {
			// For pages with namespace in $autoCreatePageNamespaces store data in the parser output
			// for later use in onRevisionDataUpdates:s
			$createPageData = $parser->getOutput()->getExtensionData( 'createPage' );
			if ( $createPageData === null ) {
				$createPageData = [];
			}
			$createPageData[$newPageTitleText] = $newPageContent;
			$parser->getOutput()->setExtensionData( 'createPage', $createPageData );
		}

		return "";
	}

	/**
	 * Create pages that have been requested by the create page parser function for pages with
	 * namespace in $egAutoCreatePageNamespaces.
	 * This is done only after the safe is complete to avoid any concurrent article modifications.
	 * Note that article is, in spite of its name, a WikiPage object since MW 1.21.
	 */
	public function onRevisionDataUpdates( $title, $renderedRevision, &$updates ) {
		global $egAutoCreatePageMaxRecursion;

		$output = $renderedRevision->getRevisionParserOutput();
		$createPageData = $output->getExtensionData( 'createPage' );
		if ( $createPageData === null ) {
			return true; // no pages to create
		}

		// Prevent pages to be created by pages that are created to avoid loops:
		$egAutoCreatePageMaxRecursion--;

		foreach ( $createPageData as $pageTitleText => $pageContentText ) {
			self::createPage( $title, $pageTitleText, $pageContentText );
		}

		// Reset state. Probably not needed since parsing is usually done here anyway:
		$output->setExtensionData( 'createPage', null );
		$egAutoCreatePageMaxRecursion++;

		return true;
	}

	/**
	 * Register special page in static $specialPageStack
	 */
	public function onSpecialPageBeforeExecute( $special, $subPage ) {
		global $egAutoCreatePageOnSpecialPages;

		$isEnabled = in_array( $special->getName(), $egAutoCreatePageOnSpecialPages );
		array_push( self::$specialPageStack, new SpecialPage( $isEnabled ) );
	}

	/**
	 * Create pages requested on enabled special pages
	 */
	public function onSpecialPageAfterExecute( $special, $subPage ) {
		$specialPage = array_pop( self::$specialPageStack );

		if ( $specialPage->isEnabled() ) {
			foreach ( $specialPage->pagesToCreate() as $pageTitleText => $pageContentText ) {
				self::createPage( $special->getFullTitle(), $pageTitleText, $pageContentText );
			}
		}
	}

	/**
	 * @return SpecialPage|null the current special page if it is enabled, null otherwise
	 */
	private static function enabledSpecialPage() {
		$count = count( self::$specialPageStack );
		$currentSpecialPage = $count === 0 ? null : self::$specialPageStack[ $count - 1 ];
		return $currentSpecialPage !== null && $currentSpecialPage->isEnabled() ? $currentSpecialPage : null;
	}

	private static function createPage( $sourceTitle, $pageTitleText, $pageContentText ) {
		$sourceTitleText = $sourceTitle->getPrefixedText();
		$pageTitle = Title::newFromText( $pageTitleText );
		// wfDebugLog( 'createpage', "CREATE " . $pageTitle->getText() . " Text: " . $pageContent );

		if ( $pageTitle !== null && !$pageTitle->isKnown() && $pageTitle->canExist() ) {
			$newWikiPage = new WikiPage( $pageTitle );
			$pageContent = ContentHandler::makeContent( $pageContentText, $sourceTitle );
			$newWikiPage->doEditContent( $pageContent,
				"Page created automatically by parser function on page [[$sourceTitleText]]" ); // TODO i18n
			// wfDebugLog( 'createpage', "CREATED PAGE " . $pageTitle->getText() . " Text: " . $pageContent );
		}
	}
}
