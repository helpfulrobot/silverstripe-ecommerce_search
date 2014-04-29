<?php
/**
 * @description: Allows user to specifically search products
 **/

class ProductSearchForm extends Form {

	/**
	 * list of additional fields to add to search
	 *
	 * Additional fields array is formatted as follows:
	 * array(
	 *  "FormField" => Field,
	 *  "DBField" => Acts On / Searches,
	 *  "FilterUsed" => SearchFilter
	 * );
	 * e.g.
	 * array(
	 *  [1] => array(
	 *    "FormField" => new TextField("MyDatabaseField", "Keyword"),
	 *    "DBField" => "MyDatabaseField",
	 *    "FilterUsed" => "PartialMatchFilter"
	 *   )
	 * );
	 * @var Array
	 */
	protected $additionalFields = array();

	/**
	 * list of products that need to be searched
	 * @var NULL | Array | Datalist
	 */
	protected $productsToSearch = null;

	/**
	 * class name of the buyables to search
	 * at this stage, you can only search one type of buyable at any one time
	 * e.g. only products or only mydataobject
	 *
	 * @var string
	 */
	protected $baseClassNameForBuyables = "";

	/**
	 * this is mysql specific, see: https://dev.mysql.com/doc/refman/5.0/en/fulltext-boolean.html
	 *
	 * @var Boolean
	 */
	protected $useBooleanSearch = true;

	/**
	 * List of additional fields that should be searched full text.
	 * @var Array
	 */
	protected $extraBuyableFieldsToSearchFullText = array();


	public function setExtraBuyableFieldsToSearchFullText($a) {
		$this->extraBuyableFieldsToSearchFullText = $a;
	}

	public function setBaseClassNameForBuyables($s) {
		$this->baseClassNameForBuyables = $s;
	}

	public function setUseBooleanSearch($b) {
		$this->useBooleanSearch = $b;
	}

	public function addAdditionalField($formField, $dbField, $filterUsed) {
		$this->additionalFields[$dbField] = array(
			"FormField" => $formField,
			"DBField" => $dbField,
			"FilterUsed" => $filterUsed
		);
		$this->fields->push($formField);
	}

	/**

	 *
	 * ProductsToSearch can be left blank to search all products
	 *
	 * @param String $name - name of form
	 * @param Controller $controller - associated controller
	 * @param String $nameOfProductsBeingSearched - name of the products being search (also see productsToSearch below)
	 * @param DataList | Array | Null $productsToSearch  (see comments above)
	 */
	function __construct($name, $controller, $nameOfProductsBeingSearched = "", $productsToSearch = null) {

		//set basics
		if($productsToSearch) {
			if(is_array($productsToSearch) {
				//do nothing
			}
			if($productsToSearch instanceof DataList) {
				$productsToSearch = $productsToSearch->map("ID", "ID")->toArray();
			}
		}
		$this->productsToSearch = $productsToSearch;

		$fields = new FieldList(
			new TextField("Keyword",  _t("ProductSearchForm.KEYWORDS", "Keywords")),
			new NumericField("MinimumPrice", _t("ProductSearchForm.MINIMUM_PRICE", "Minimum Price")),
			new NumericField("MaximumPrice", _t("ProductSearchForm.MAXIMUM_PRICE", "Maximum Price")),
			new CheckboxField("SearchOnlyFieldsInThisSection", _t("ProductSearchForm.ONLY_SHOW", "Only Show")." ".$nameOfProductsBeingSearched, true),
		);
		if($literalFieldSearchAllLink) {
			$fields->push($literalFieldSearchAllLink);
		}
		$actions = new FieldList(
			new FormAction('doProductSearchForm', 'Search')
		);
		parent::__construct($controller, $name, $fields, $actions, $requiredFields = null);
		//extensions need to be set after __construct
		if($this->extend('updateFields',$fields) !== null) {$this->setFields($fields);}
		if($this->extend('updateActions',$actions) !== null) {$this->setActions($actions);}
		if($this->extend('updateValidator',$requiredFields) !== null) {$this->setValidator($requiredFields);}
		$oldData = Session::get("FormInfo.{$this->FormName()}.data");
		if($oldData && (is_array($oldData) || is_object($oldData))) {
			$this->loadDataFrom($oldData);
		}
		$this->extend('updateProductSearchForm',$this);
		return $form;
	}

	function doProductSearchForm($data, $form){

		$baseClassName = $this->baseClassForBuyables;
		if(!$baseClassName) {
			$baseClassName = EcommerceConfig("ProductGroup", "baseclass_for_buyables");
		}
		$baseList = $baseClassName::get()->filter(array("ShowInSearch" => 1));
		$limitToCurrentSection = false;
		if(isset($data["SearchOnlyFieldsInThisSection"]) && $data["SearchOnlyFieldsInThisSection"]) {
			$limitToCurrentSection = true;
			$baseList = $baseList->filter(array("ID" => $this->productsToSearch));
		}
		if(isset($data["MinimumPrice"]) && $data["MinimumPrice"]) {
			$baseList = $baseList->filter(array("Price:GreaterThanOrEqual" => floatval($data["MinimumPrice"])));
		}
		if(isset($data["MaximumPrice"]) && $data["MaximumPrice"]) {
			$baseList = $baseList->filter(array("Price:LessThanOrEqual" => floatval($data["MaximumPrice"])));
		}
		$results = false;
		$ar = new ArrayList();

		//KEYWORD SEARCH - only bother if we have any keywords and results at all ...
		if(isset($data["Keyword"]) && $keyword = $data["Keyword"]) {
			if($baseList->count()) {
				if(strlen($keyword) > 1){
					$keyword = Convert::raw2sql($keyword);
					$keyword = strtolower($keyword);

					SearchHistory::add_entry($keyword);

					// 1) Exact search by code

					if($code = intval($keyword)) {
						$list1 = $baseList->filter(array("InternalItemID" => $code));
						$count = $list1->count ;
						if($count == 1) {
							return Controller::curr()->redirect($list1->First()->Link());
						}
						elseif($count > 1) {
							$result = true;
						}
					}

					// 2) Search of the entire keyword phrase and its replacements

					if(!$results) {

						//find all keywords ...
						$wordArray = array($keyword);
						$words = explode(' ', trim(eregi_replace(' +', ' ', $keyword)));
						foreach($words as $word) {
							$replacements = SearchReplacement::get()
								->where("
									LOWER(\"Search\") = '$keyword' OR
									LOWER(\"Search\") LIKE '%,$keyword' OR
									LOWER(\"Search\") LIKE '$keyword,%' OR
									LOWER(\"Search\") LIKE '%,$keyword,%'"
								);
							if($replacements->count()) {
								$wordArray += array_values($replacements->map('ID', 'Replace')->toArray());
							}
						}
						$wordArray = array_unique($wordArray);

						//work out searches
						$fieldArray = array("Title", "MenuTitle") + $this->extraBuyableFieldsToSearchFullText;
						$searches = $this->getSearchArrays($wordArray, $fieldArray);
						//we search exact matches first then other matches ...
						foreach($searches as $search) {
							$list2 = $baseList->where($wherePhrase);
							$count = $list2->count();
							if($count == 1) {
								return Controller::curr()->redirect($list2->First()->Link());
							}
							else {
								$results = true;
								break;
							}
						}
					}

					// 3) Do the same search for Product Group names

					if(! $results && !$limitToCurrentSection) {
						$searches = $this->getSearchArrays($wordArray);
						foreach($searches as $search) {
							$productGroups = ProductGroup::get()->where($wherePhrase);
							$count = $productGroups->count();
							if($count == 1) {
								return Controller::curr()->redirect($productGroups->First()->Link());
							}
							elseif($count > 1) {
								$productIDArray = array();
								foreach($productGroups as $productGroup) {
									$productIDArray += $productGroup->currentInitialProducts()->map("ID", "ID")->toArray();

								}
								$productIDArray = array_unique($productIDArray);
								$list3 = $baseList->filter(array("ID" => $productIDArray));
								$count = $list3->count();
								if($count == 1) {
									return Controller::curr()->redirect($list3->First()->Link());
								}
								elseif($count > 1) {
									$results = true;
									break;
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * creates three levels of searches that
	 * can be executed one after the other, each
	 * being less specific than the last...
	 *
	 * @return Array
	 */
	protected function getSearchArrays($words, $fields = array("Title", "MenuTitle")){
		//make three levels of search
		$searches = array();
		$wordsAsString = implode(trim(eregi_replace(' +', ' ', $words)));
		foreach($fields as $field) {
			$searches[0][] = "LOWER(\"$field\") = '$wordsAsString'"; // a) Exact match
			$searches[1][] = "LOWER(\"$field\") = '%$wordsAsString%'"; // a) Full match with extra fluff
		}
		$searches[2][] = DB::getconn()->fullTextSearchSQL($fields, $words, $this->useBooleanSearch);
		$returnArray = array();
		foreach($searches as $key => $search) {
			$returnArray[$key] = implode(" OR ", $search);
		}
		return $returnArray;
	}


}

class ProductSearchForm_Advanced extends ProductSearchForm {
	function ShortFilterForm() {
		$fields = new FieldList(
			new TextField("KeywordQuick", "", Session::get("ProductFilter.Keyword"))
		);
		$actions = new FieldList(
			new FormAction('doFilterForm', 'Go')
		);
		$form = new Form($this, "FilterFormQuick", $fields, $actions);
		return $form;
	}

}
