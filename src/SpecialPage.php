<?php

namespace ACP;

class SpecialPage {

	private $isEnabled;
	private $pagesToCreate = [];

	public function __construct( $isEnabled ) {
		$this->isEnabled = $isEnabled;
	}

	public function isEnabled() {
		return $this->isEnabled;
	}

	public function addPageToCreate( $titleText, $content ) {
		$this->pagesToCreate[$titleText] = $content;
	}

	public function pagesToCreate() {
		return $this->pagesToCreate;
	}
}
