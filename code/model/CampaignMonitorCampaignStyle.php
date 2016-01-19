<?php

/**
 *@author nicolaas [at] sunnysideup.co.nz
 *
 *
 **/

class CampaignMonitorCampaignStyle extends DataObject {

	private static $db = array(
		"Title" => "Varchar(100)"
	);

	private static $indexes = array(
		"Title" => true
	);

	private static $has_many = array(
		"CampaigMonitorCampaign" => "CampaigMonitorCampaign"
	);

	private static $searchable_fields = array(
		"Subject" => "PartialMatchFilter",
		"Hide" => "ExactMatch"
	);

	private static $summary_fields = array(
		"Subject" => "Subject",
		"SentDate" => "Sent Date"
	);

	private static $singular_name = "Campaigns";

	private static $plural_name = "Campaign";

	private static $default_template = "CampaignTemplate";

	private static $default_sort = "Hide ASC, SentDate DESC";

	public function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->makeFieldReadonly("CampaignID");
		$fields->makeFieldReadonly("WebVersionURL");
		$fields->makeFieldReadonly("WebVersionTextURL");
		$fields->makeFieldReadonly("SentDate");
		$fields->makeFieldReadonly("HasBeenSent");
		//pages
		$fields->removeFieldFromTab("Root", "Pages");
		$source = CampaignMonitorSignupPage::get()->map("ID", "Title")->toArray();
		$fields->removeFieldFromTab("Root", "CreatedFromWebsite");

		if(count($source))  {
			$fields->addFieldToTab("Root.Main", new CheckboxSetField("Pages", "Shown on the following pages ...", $source));
		}

		if($this->HasBeenSentCheck()) {
			$fields->removeFieldFromTab("Root", "CreateFromWebsite");
			$fields->addFieldToTab("Root.Main", new LiteralField("Link", "<h2><a target=\"_blank\" href=\"".$this->Link()."\">Link</a></h2>"), "CampaignID");
		}
		else {
			$fields->removeFieldFromTab("Root", "Hide");
			$fields->addFieldToTab("Root.Main", new LiteralField("PreviewLink", "<h2><a target=\"_blank\" href=\"".$this->PreviewLink()."\">Preview Link</a></h2>"), "CampaignID");
			if($this->CreatedFromWebsite) {
				$fields->removeFieldFromTab("Root", "CreateFromWebsite");
			}
			elseif(!$this->exists()) {
				$fields->removeFieldFromTab("Root", "CreateFromWebsite");
			}
		}
		return $fields;

	}

	function Link($action = ""){
		if($page = $this->Pages()->First()) {
			$link = $page->Link("viewcampaign".$action."/".$this->ID."/");
			return Director::absoluteURL($link);
		}
		return "#";
	}


	function PreviewLink($action = ""){
		if($page = $this->Pages()->First()) {
			$link = $page->Link("previewcampaign".$action."/".$this->ID."/");
			return Director::absoluteURL($link);
		}
		return "";
	}

	function getNewsletterContent(){
		$extension = $this->extend("updateNewsletterContent", $content);
		if($extension !== null) {
			return $extension[0];
		}
		$isThemeEnabled = Config::inst()->get('SSViewer', 'theme_enabled');
		if(!$isThemeEnabled) {
			Config::inst()->update('SSViewer', 'theme_enabled', true);
		}
		Requirements::clear();
		$html = $this->owner->renderWith($this->Template);
		if(!$isThemeEnabled) {
			Config::inst()->update('SSViewer', 'theme_enabled', false);
		}
		if(class_exists('\Pelago\Emogrifier')) {
			$allCSS = "";
			$cssFileLocations = Director::baseFolder() . Config::inst()->get("CampaignMonitorCampaign", "css_files");
			foreach($cssFileLocations as $cssFileLocation) {
				$cssFileHandler = fopen($cssFileLocation, 'r');
				$allCSS .= fread($cssFileHandler,  filesize($cssFileLocation));
				fclose($cssFileHandler);
			}
			$emog = new \Pelago\Emogrifier($html, $allCSS);
			$html = $emog->emogrify();
		}
		return $html;
	}

	function onBeforeWrite(){
		parent::onBeforeWrite();
		if($this->CampaignID) {
			$this->CreateFromWebsite = false;
		}
	}

	function onAfterWrite(){
		parent::onAfterWrite();
		if($this->Pages()->count() == 0) {
			if($page = CampaignMonitorSignupPage::get()->first()) {
				$this->Pages()->add($page);
				$this->write();
			}
		}
		if(!$this->CampaignID  && $this->CreateFromWebsite) {
			$api = $this->getAPI();
			$api->createCampaign($this);
		}
	}

	function onBeforeDelete(){
		parent::onBeforeDelete();
		if($this->HasBeenSentCheck()) {
			//do nothing
		}
		else {
			if($this->CreatedFromWebsite) {
				$api = $this->getAPI();
				$api->deleteCampaign($this->CampaignID);
			}
		}
	}

	private static $_api = null;

	/**
	 *
	 * @return CampaignMonitorAPIConnector
	 */
	protected function getAPI(){
		if(!self::$_api) {
			self::$_api = CampaignMonitorAPIConnector::create();
			self::$_api->init();
		}
		return self::$_api;
	}

	public function HasBeenSentCheck(){
		if(!$this->CampaignID) {
			return false;
		}
		if(!$this->HasBeenSent) {
			$api = $this->getAPI();
			$result = $this->api->getCampaigns();
			if(isset($result)) {
				foreach($result as $key => $campaign) {
					if($this->CampaignID == $campaign->CampaignID) {
						$this->HasBeenSent = true;
						$this->HasBeenSent->write();
						return true;
					}
				}
			}
			else {
				user_error("error");
			}
		}
		return $this->HasBeenSent;
	}


}
