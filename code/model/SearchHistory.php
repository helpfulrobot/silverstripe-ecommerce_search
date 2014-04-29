<?php

class SearchHistory Extends DataObject {

	private static $db = array(
		"Title" => "Varchar(255)"
	);

	static function add_entry($KeywordString) {
		$obj = new SearchHistory();
		$obj->Title = $KeywordString;
		$obj->write();
	}


	function onBeforeWrite() {
		$this->Title = trim(eregi_replace(" +", " ", $this->Title));
		parent::onBeforeWrite();
	}

}
