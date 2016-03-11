<?php

namespace Slub\SlubFindExtend\ViewHelpers\Find;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Class HoldingStatusJsonViewHelper
 * @package Slub\SlubFindExtend\ViewHelpers\Find
 */
class HoldingStatusJsonViewHelper extends \TYPO3\CMS\Fluid\Core\ViewHelper\AbstractViewHelper {

	/**
	 * Registers own arguments.
	 */
	public function initializeArguments() {
		parent::initializeArguments();
		$this->registerArgument('data', 'array|string|int|float', 'The holding data', FALSE, NULL);
	}

	/**
	 * Returns the holding state
	 *
	 * @param $exemplare
	 * @return int
	 */
	private function getLocalHoldingStatusFromArray($exemplare) {

		$status = 9999;

		foreach ($exemplare as $exemplar) {

			if(!is_array($exemplar)) {
				$exemplar = (array)$exemplar;
			}

			if($exemplar['elements'] && is_array($exemplar['elements'])) {
				$status = $this->getLocalHoldingStatusFromArray($exemplar['elements']);
			} elseif ( ( $exemplar['_calc_colorcode'] < $status ) && ( $status != 1) ) {
				if(!($exemplar['_calc_colorcode'] == 0 && ($status == 2))) {
					$status = $exemplar['_calc_colorcode'];
				}

			}

		}

		return $status;

	}

	/**
	 * Tries to resolve Article against holdings
	 *
	 */
	private function getElectronicHoldingFromData($data) {

		$status = [];

		if(!$data['enriched']) return;

		$article = $data['enriched']['fields']['rft.atitle'];
		$firstISSN = $data['enriched']['fields']['rft.atitle'];
		$volume = $data['enriched']['fields']['rft.volume'];
		$spage = $data['enriched']['fields']['rft.spage'];
		$epage = $data['enriched']['fields']['rft.epage'];
		$pages = $data['enriched']['fields']['rft.pages'];
		$issue = $data['enriched']['fields']['rft.issue'];
		$genre = $data['enriched']['fields']['rft.genre'];
		$date = $data['enriched']['fields']['rft.date'];
		$language = $data['enriched']['fields']['languages'][0];
		$doi = $data['enriched']['fields']['doi'];
		$jtitle = $data['enriched']['fields']['rft.jtitle'];
		$firstAuthor = (array)$data['enriched']['fields']['authors'][0];
		$firstAuthorAulast = $firstAuthor['rft.aulast'];
		$firstAuthorAufirst = $firstAuthor['rft.aufirst'];

		$url = 'http://www-fr.redi-bw.de/links/?rl_site=slub&atitle='.urlencode($article).
			'&issn='.urlencode($firstISSN).
			'&volume='.urlencode($volume).
			'&spage='.urlencode($spage).
			'&epage='.urlencode($epage).
			'&pages='.urlencode($pages).
			'&issue='.urlencode($issue).
			'&aulast='.urlencode($firstAuthorAulast).
			'&aufirst='.urlencode($firstAuthorAufirst).
			'&genre='.urlencode($genre).
			'&sid=katalogbeta.slub-dresden.de&date='.urlencode($date).
			'&language='.urlencode($language).
			'&id='.urlencode($doi).
			'&title='.urlencode($jtitle);

		$doc = new \DOMDocument();
		$doc->loadHTML(file_get_contents($url));

		$xpath = new \DOMXpath($doc);

		$infolink = $xpath->query("//span[contains(@class,'t_infolink')]/a/@href")->item(0)->nodeValue;

		$access = $xpath->query("//div[@id ='t_ezb']/div/p/b")->item(0)->nodeValue;

		$ezb_status = $xpath->query("//div[contains(@class,'t_ezb_result')]/p/span/@class")->item(0)->nodeValue;
		$ezb_status_via = trim($xpath->query("//div[contains(@class,'t_ezb_result')]/p")->item(0)->nodeValue);
		$ezb_url = $xpath->query("//div[contains(@class,'t_ezb_result')]/p/span[contains(@class,'t_link')]/a/@href")->item(0)->nodeValue;

		$via = substr($ezb_status_via, strpos($ezb_status_via, 'via')+4, -4);

		$status_code = 1;
		switch ($ezb_status) {
			case 't_ezb_red':
				$status_code = 0;
				break;
		}

		$status['infolink'] = $infolink;
		$status['access'] = $access == 'freigeschaltet' ? 1 : 0;
		$status['via'] = $via;
		$status['url'] = $ezb_url;
		$status['status'] = $status_code;

		return $status;

	}

	/**
	 * Tries to resolve Article against holdings
	 *
	 */
	private function getElectronicDatabaseFromData($data) {

		$databaseUrl = '';

		foreach($data['documents'][0]['url'] as $url) {

			if((strpos($url, 'http://www.bibliothek.uni-regensburg.de/dbinfo/frontdoor.php') === 0) ||
				(strpos($url, 'http://rzblx10.uni-regensburg.de/dbinfo/detail.php?titel_id') === 0)) {
				$databaseUrl = $url;
			};
		}


		if(!strlen($databaseUrl)) return;


		$doc = new \DOMDocument();
		libxml_use_internal_errors(true);
		$doc->loadHTML(file_get_contents($databaseUrl));

		$xpath = new \DOMXpath($doc);

		$dbis_url = $xpath->query("//td[@id ='detail_content_start']/a/@href")->item(0)->nodeValue;


		$status['infolink'] = $databaseUrl;
		$status['access'] = 1;
		$status['via'] = '';
		$status['url'] = 'http://rzblx10.uni-regensburg.de/dbinfo/'.$dbis_url;
		$status['status'] = 1;

		return $status;

	}

	/**
	 * @return string
	 */
	public function render() {

		$data = $this->arguments['data'];

		if($data['documents'][0]['access_facet'] == "Local Holdings") {

			if($data['enriched']['fields']['exemplare']) {

				$status = $this->getLocalHoldingStatusFromArray($data['enriched']['fields']['exemplare']);
				return json_encode(array('status' => $status));

			} else {
				// Somehow this is a Local Holdings file with no copies. Send "Action needed" state.
				return json_encode(array('status' => 0));
			}

		} elseif(($data['documents'][0]['access_facet'] =="Electronic Resources") || ($data['documents'][0]['physical'] && in_array('Online-Ressource', $data['documents'][0]['physical']))) {

			$cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('resolv_link_electronic');
			$cacheIdentifier = sha1($data['documents'][0]['id']);
			$entry = $cache->get($cacheIdentifier);
			if (!$entry) {

				// Try to resolve article against holdings
				$entry = $this->getElectronicHoldingFromData($data);

				// Try to resolve article against databases
				if(strlen($entry['url']) === 0) {
					$entry = $this->getElectronicDatabaseFromData($data);
				}

				// Still no luck? Fall back to first url

				if(strlen($entry['url']) === 0) {
					$entry = [
						'infolink' => '',
						'access' => 1,
						'via' => 1,
						'url' => $data['documents'][0]['url'][0],
						'status' => 1
					];
				}

				//$cache->set($cacheIdentifier, $entry);
			}

			$entry['url'] = 'http://wwwdb.dbod.de/login?url='.$entry['url'];

			return json_encode($entry);
		} else {
			return json_encode(array('status' => 0));
		}

	}

}

?>