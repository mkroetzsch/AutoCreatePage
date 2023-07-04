<?php

namespace ACP\Tests\Integration;

use FauxRequest;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use RequestContext;
use Title;
use WikiPage;

/**
 * @covers \ACP\AutoCreatePage
 * @group Database
 */
class AutoCreatePageTest extends MediaWikiIntegrationTestCase {

	private const EXPECTED_TEXT = 'expected text';
	private static $defaultAutoCreatePageMaxRecursion;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		global $egAutoCreatePageMaxRecursion;
		self::$defaultAutoCreatePageMaxRecursion = $egAutoCreatePageMaxRecursion;
	}

	protected function setUp(): void {
		parent::setUp();
		global $egAutoCreatePageMaxRecursion;
		$egAutoCreatePageMaxRecursion = self::$defaultAutoCreatePageMaxRecursion;
	}

	protected function tearDown(): void {
		parent::tearDown();
		global $egAutoCreatePageOnSpecialPages;
		$egAutoCreatePageOnSpecialPages = [];
	}

	public function testCreatesPage() {
		[ $x, $y ] = $this->randomize( [ 'x', 'y' ] );
		$this->insertPage( $x, "{{#createpageifnotex:$y|" . self::EXPECTED_TEXT . "}}" );

		$text = $this->textOf( $y );
		$this->assertEquals( self::EXPECTED_TEXT, $text );

		$text = $this->textOf( $x );
		$this->assertEquals( "{{#createpageifnotex:$y|" . self::EXPECTED_TEXT . "}}", $text );
	}

	public function testOnlyCreatesPageIfItDoesntExist() {
		[ $x, $y ] = self::randomize( [ 'x', 'y' ] );
		$this->insertPage( $y, self::EXPECTED_TEXT );
		$this->insertPage( $x, "{{#createpageifnotex:$y|other text}}" );

		$text = $this->textOf( $y );

		$this->assertEquals( self::EXPECTED_TEXT, $text );
	}

	public function testIgnoresRequestToCreateCurrentPage() {
		[ $x ] = self::randomize( [ 'x' ] );
		$this->insertPage( $x, "{{#createpageifnotex:$x|x}}" );

		$text = $this->textOf( $x );

		$this->assertEquals( "{{#createpageifnotex:$x|x}}", $text );
	}

	public function testDoesntRecurseByDefault() {
		[ $x1, $x2, $x3 ] = self::randomize( [ 'x1', 'x2', 'x3' ] );
		$this->insertPage( $x1,
			"{{#createpageifnotex:$x2|<nowiki>{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT .
			'}}</nowiki>}}' );

		$text2 = $this->textOf( $x2 );
		$this->assertEquals( "{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT . '}}', $text2 );

		$text3 = $this->textOf( $x3 );
		$this->assertNull( $text3 );
	}

	public function testRecursesIfToldSo() {
		global $egAutoCreatePageMaxRecursion;
		$egAutoCreatePageMaxRecursion = 2;

		[ $x1, $x2, $x3 ] = self::randomize( [ 'x1', 'x2', 'x3' ] );
		$this->insertPage( $x1,
			"{{#createpageifnotex:$x2|<nowiki>{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT .
			'}}</nowiki>}}' );

		$text2 = $this->textOf( $x2 );
		$this->assertEquals( "{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT . '}}', $text2 );

		$text3 = $this->textOf( $x3 );
		$this->assertEquals( self::EXPECTED_TEXT, $text3 );
	}

	public function testIsNotCalledFromUnenabledSpecialPage() {
		$page = $page = self::createOnSpecialExpandTemplates( 'NotCreated' );

		$this->assertFalse( $page->exists() );
	}

	public function testIsCalledFromEnabledSpecialPage() {
		global $egAutoCreatePageOnSpecialPages;
		$egAutoCreatePageOnSpecialPages = [ 'ExpandTemplates' ];

		$page = self::createOnSpecialExpandTemplates( 'Created' );

		$this->assertTrue( $page->exists() );
	}

	private static function createOnSpecialExpandTemplates( $title ) {
		$ctx = new RequestContext();
		$ctx->setRequest( new FauxRequest( [
			'wpContextTitle' => 'X',
			'wpInput' => "{{#createpageifnotex:$title|Some content...}}",
		] ) );
		$sp = Title::makeTitle( NS_SPECIAL, 'ExpandTemplates' );
		MediaWikiServices::getInstance()->getSpecialPageFactory()->executePath( $sp, $ctx );

		return WikiPage::factory( Title::newFromText( $title ) );
	}

	private function textOf( $title ) {
		$page = WikiPage::factory( Title::newFromText( $title ) );

		return $page->exists() ? $page->getContent()->getText() : null;
	}

	private static function randomize( $titles ) {
		return array_map( static function ( $t ) {
			return $t . '-' . mt_rand();
		}, $titles );
	}

}
