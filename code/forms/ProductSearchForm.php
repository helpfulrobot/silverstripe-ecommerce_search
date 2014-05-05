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

	/**
	 * Maximum number of results to return
	 * we limit this because otherwise the system will choke
	 * the assumption is that no user is really interested in looking at
	 * tons of results
	 * @var Int
	 */
	protected $maximumNumberOfResults = 100;

	/**
	 * The method on the parent controller that can display the results of the
	 * search results
	 * @var String
	 */
	protected $controllerSearchResultDisplayMethod = "searchresults";

	/**
	 * array of IDs of the results found so far
	 * @var Array
	 */
	protected $resultArray = array();

	/**
	 * Number of results found so far
	 * @var Int
	 */
	protected $resultArrayPos = 0;


	public function setControllerSearchResultDisplayMethod($s) {
		$this->controllerSearchResultDisplayMethod = $s;
	}

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
	 * @param Controller $controller - associated controller
	 * @param String $name - name of form
	 * @param String $nameOfProductsBeingSearched - name of the products being search (also see productsToSearch below)
	 * @param DataList | Array | Null $productsToSearch  (see comments above)
	 */
	function __construct($controller, $name, $nameOfProductsBeingSearched = "", $productsToSearch = null) {

		//set basics
		if($productsToSearch) {
			if(is_array($productsToSearch)) {
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
			new NumericField("MaximumPrice", _t("ProductSearchForm.MAXIMUM_PRICE", "Maximum Price"))
		);
		$actions = new FieldList(
			new FormAction('doProductSearchForm', 'Search')
		);
		if($productsToSearch && count($productsToSearch)) {
			$fields->push(
				new CheckboxField("SearchOnlyFieldsInThisSection", _t("ProductSearchForm.ONLY_SHOW", "Only Show Results from")." <i>".$nameOfProductsBeingSearched."</i> "._t("ProductSearchForm.SECTION", "section"), true)
			);
		}
		$requiredFields = array();
		$validator = ProductSearchForm_Validator::create($requiredFields);
		parent::__construct($controller, $name, $fields, $actions, $validator);
		//extensions need to be set after __construct
		if($this->extend('updateFields',$fields) !== null) {$this->setFields($fields);}
		if($this->extend('updateActions',$actions) !== null) {$this->setActions($actions);}
		if($this->extend('updateValidator',$requiredFields) !== null) {$this->setValidator($requiredFields);}
		$oldData = Session::get("FormInfo.".$this->FormName().".data");
		if($oldData && (is_array($oldData) || is_object($oldData))) {
			$this->loadDataFrom($oldData);
		}
		$this->extend('updateProductSearchForm',$this);
		return $this;
	}

	function doProductSearchForm($data, $form){

		//what is the baseclass?
		$baseClassName = $this->baseClassForBuyables;
		if(!$baseClassName) {
			$baseClassName = EcommerceConfig::get("ProductGroup", "base_buyable_class");
		}
		if(!$baseClassName) {
			user_error("Can not find $baseClassName (baseClassName)");
		}
		//basic get
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
		$keywordResults = false;

		//KEYWORD SEARCH - only bother if we have any keywords and results at all ...
		if(isset($data["Keyword"]) && $keyword = $data["Keyword"]) {
			if($baseList->count()) {
				if(strlen($keyword) > 1){
					$keywordResults = true;
					$this->resultArrayPos = 0;
					$this->resultArray = Array();

					$keyword = Convert::raw2sql($keyword);
					$keyword = strtolower($keyword);

					SearchHistory::add_entry($keyword);

					// 1) Exact search by code

					if($code = intval($keyword)) {
						$list1 = $baseList->filter(array("InternalItemID" => $code));
						$count = $list1->count ;
						if($count == 1) {
							return $this->controller->redirect($list1->First()->Link());
						}
						elseif($count > 1) {
							if($this->addToResults($list1)) {
								break;
							}
						}
					}

					// 2) Search of the entire keyword phrase and its replacements

					if($this->resultArrayPos <= $this->maximumNumberOfResults) {

						//find all keywords ...
						$wordArray = array($keyword);
						$words = explode(' ', trim(preg_replace('!\s+!', ' ', $keyword)));
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
							$list2 = $baseList->where($search);
							$count = $list2->count();
							if($count == 1) {
								return $this->controller->redirect($list2->First()->Link());
							}
							elseif($count > 1) {
								if($this->addToResults($list2)) {
									break;
								}
							}
							if($this->resultArrayPos > $this->maximumNumberOfResults) {
								break;
							}
						}
					}

					// 3) Do the same search for Product Group names

					if($this->resultArrayPos <= $this->maximumNumberOfResults) {
						$searches = $this->getSearchArrays($wordArray);
						foreach($searches as $search) {
							$productGroups = ProductGroup::get()->where($search);
							$count = $productGroups->count();
							if($count == 1) {
								return $this->controller->redirect($productGroups->First()->Link());
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
									return $this->controller->redirect($list3->First()->Link());
								}
								elseif($count > 1) {
									if($this->addToResults($list3)) {
										break;
									}
								}
							}
							if($this->resultArrayPos > $this->maximumNumberOfResults) {
								break;
							}
						}
					}
				}
			}
		}
		if(!$keywordResults) {
			$this->addToResults($baseList);
		}
		$this->controller->redirect(
			$this->controller->Link($this->controllerSearchResultDisplayMethod)."?results=".implode(",", $this->resultArray)
		);
	}

	/**
	 * creates three levels of searches that
	 * can be executed one after the other, each
	 * being less specific than the last...
	 *
	 * @return Array
	 */
	private function addToResults($listToAdd){
		$listToAdd = $listToAdd->limit($this->maximumNumberOfResults - $this->resultArrayPos);
		foreach($listToAdd as $page) {
			if(!in_array($page->ID, $this->resultArray)) {
				$this->resultArrayPos++;
				$this->resultArray[$this->resultArrayPos] = $page->ID;
				if($this->resultArrayPos > $this->maximumNumberOfResults) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * creates three levels of searches that
	 * can be executed one after the other, each
	 * being less specific than the last...
	 *
	 * @param Array $words - words being search
	 * @param Array $fields - fields being searched
	 *
	 * @return Array
	 */
	protected function getSearchArrays($words, $fields = array("Title", "MenuTitle")){
		//make three levels of search
		$searches = array();
		$wordsAsString = trim(implode(" ", preg_replace('!\s+!', ' ', $words)));
		foreach($fields as $field) {
			$searches[0][] = "LOWER(\"$field\") = '$wordsAsString'"; // a) Exact match
			$searches[1][] = "LOWER(\"$field\") = '%$wordsAsString%'"; // a) Full match with extra fluff
		}
		$searches[2][] = DB::getconn()->fullTextSearchSQL($fields, $wordsAsString, $this->useBooleanSearch);
		$returnArray = array();
		foreach($searches as $key => $search) {
			$returnArray[$key] = implode(" OR ", $search);
		}
		return $returnArray;
	}

	/**
	 * saves the form into session
	 * @param Array $data - data from form.
	 */
	public function saveDataToSession(){
		$data = $this->getData();
		Session::set("FormInfo.".$this->FormName().".data", $data);
	}

}

class ProductSearchForm_Short extends ProductSearchForm {

	function __construct($controller, $name, $nameOfProductsBeingSearched = "", $productsToSearch = null) {
		parent::__construct($controller, $name, $nameOfProductsBeingSearched, $productsToSearch);
		$this->fields = new FieldList(
			new TextField("Keyword", "")
		);
		$this->actions = new FieldList(
			new FormAction('doProductSearchForm', 'Go')
		);
	}

}


class ProductSearchForm_Validator extends RequiredFields{

	function php($data){
		$this->form->saveDataToSession();
		return parent::php($data);
	}

}
