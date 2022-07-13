<?php

namespace ACP\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * @covers \ACP\AutoCreatePage
 * @group Database
 */
class AutoCreatePageTest extends MediaWikiIntegrationTestCase {

	private const EXPECTED_TEXT = 'expected text';

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

	public function testRecurses() {
		[ $x1, $x2, $x3 ] = self::randomize( [ 'x1', 'x2', 'x3' ] );
		$this->insertPage( $x1,
			"{{#createpageifnotex:$x2|{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT . '}}}}' );

		$text = $this->textOf( $x3 );
		$this->assertEquals( self::EXPECTED_TEXT, $text );

// todo: $x2 should not remain empty!
//		$text = $this->textOf( $x2 );
//		$this->assertEquals( "{{#createpageifnotex:$x3|" . self::EXPECTED_TEXT . '}}', $text );

// todo: should throw an exception instead!
//	public function testDoesNotRecurseTooDeeply() {
//		[ $x1, $x2, $x3, $x4 ] = self::randomize( [ 'x1', 'x2', 'x3', 'x4' ] );
//		$this->insertPage( $x1,
//			"{{#createpageifnotex:$x2|{{#createpageifnotex:$x3|{{#createpageifnotex:$x4|" .
//			self::EXPECTED_TEXT . '}}}}}}' );
//
//		$text = $this->textOf( $x4 );
//
//		$this->assertEquals( self::EXPECTED_TEXT, $text );
	}

	private function textOf( $title ) {
		return $this->getExistingTestPage( $title )->getContent()->getText();
	}

	private static function randomize( $titles ) {
		return array_map( static function ( $t ) { return $t . '-' . mt_rand();
		}, $titles );
	}

}
