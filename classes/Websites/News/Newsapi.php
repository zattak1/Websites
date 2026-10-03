<?php

/**
 * @module Websites
 */

/**
 * NewsAPI.ai adapter: EventRegistry's article API under the newsapi.ai
 * name, and the "newsapi" provider that Websites_News::fetch() uses by
 * default.
 *
 * Normalizes EventRegistry responses into provider-agnostic article items.
 *
 * This file declared Websites_News_Eventregistry, the class in
 * Eventregistry.php, so the autoloader found no Websites_News_Newsapi here
 * and the default provider could not load; loading both files was a fatal
 * redeclaration (ro#1064).
 *
 * @class Websites_News_Newsapi
 */
class Websites_News_Newsapi extends Websites_News implements Websites_News_Interface
{
	protected $apiKey;
	protected $endpoint = 'https://eventregistry.org/api/v1/article/getArticles';

	protected static $languages = null;
	protected static $countries = null;
	protected static $countryNameToIso2 = null;

	public function __construct($options = array())
	{
		if (is_string($options) && $options) {
			$this->apiKey = $options;
		} else {
			$this->apiKey = Q_Config::expect('Websites', 'eventregistry', 'key');
		}
	}

	public function fetchNews(array $options = array())
	{
		$opts = Q::take($options, array(
			'type'        => 'top',
			'endpoint'    => null,
			'country'     => 'us',
			'language'    => 'en',
			'keyword'     => null,
			'q'           => null,
			'max'         => 12,
			'page'        => 1,
			'dateStart'   => null,
			'dateEnd'     => null,
			'ignoreKeyword' => null
		));

		$keyword = $opts['q'] ?: $opts['keyword'];

		if ($opts['type'] === 'search' && !$keyword) {
			throw new Q_Exception_RequiredField(array(
				'field' => 'options.keyword'
			));
		}

		// -------------------------------------------------
		// Build structured EventRegistry query
		// -------------------------------------------------

		$queryParts = array();

		// Country filter (supports array or single)
		if ($opts['country']) {

			$countries = is_array($opts['country'])
				? $opts['country']
				: array($opts['country']);

			$uris = array();

			foreach ($countries as $cc) {
				$uri = $this->countryUri($cc);
				if ($uri) {
					$uris[] = $uri;
				}
			}

			if (!empty($uris)) {
				$queryParts[] = array(
					"sourceLocationUri" => count($uris) === 1 ? $uris[0] : $uris
				);
			}
		}

		// Language filter
		if ($opts['language']) {
			$iso3 = $this->iso3($opts['language']);
			if ($iso3) {
				$queryParts[] = array(
					"lang" => $iso3
				);
			}
		}

		// Keyword filter (supports array)
		if ($keyword) {

			if (is_array($keyword)) {
				foreach ($keyword as $kw) {
					$queryParts[] = array("keyword" => $kw);
				}
			} else {
				$queryParts[] = array("keyword" => $keyword);
			}
		}

		// Ignore keyword
		if ($opts['ignoreKeyword']) {
			$queryParts[] = array(
				"ignoreKeyword" => $opts['ignoreKeyword']
			);
		}

		// Date filters
		if ($opts['dateStart']) {
			$queryParts[] = array("dateStart" => $opts['dateStart']);
		}

		if ($opts['dateEnd']) {
			$queryParts[] = array("dateEnd" => $opts['dateEnd']);
		}

		if (!empty($queryParts)) {
			$querySection = array(
				"\$and" => $queryParts
			);
		} else {
			$fallbackLang = $this->iso3($opts['language'] ?: 'en');
			$querySection = $fallbackLang
				? array("lang" => $fallbackLang)
				: array();
		}

		$body = array(
			"query" => array(
				"\$query" => $querySection,
				"\$filter" => array(
					"forceMaxDataTimeWindow" => "31"
				)
			),
			"resultType"          => "articles",
			"articlesSortBy"      => "date",
			"articlesSortByAsc"   => false,
			"articlesCount"       => min(100, max(1, (int) $opts['max'])),
			"articlesPage"        => max(1, (int) $opts['page']),
			"dataType"            => array("news"),
			"includeArticleBody"  => true,
			"includeArticleImage" => true,
			"includeSourceTitle"  => true,
			"articleBodyLen"      => -1,
			"apiKey"              => $this->apiKey
		);

		$response = Q_Utils::post(
			$this->endpoint,
			json_encode($body),
			null,
			array(),
			array('Content-Type: application/json'),
			30
		);

		if (!$response) {
			Q::log('EventRegistry empty response');
			return array();
		}

		$data = Q::json_decode($response, true);

		if (!is_array($data)) {
			Q::log('EventRegistry invalid JSON: ' . $response);
			return array();
		}

		if (!empty($data['error'])) {
			Q::log('EventRegistry API error: ' . Q::json_encode($data['error']));
			return array();
		}

		if (empty($data['articles']['results'])) {
			return array();
		}

		$items = array();

		foreach ($data['articles']['results'] as $a) {
			$items[] = $this->normalize($a, $opts['language'], $opts['country']);
		}

		return $items;
	}

	protected function normalize(array $a, $requestedLanguage, $requestedCountry)
	{
		$dest = array(); // Q::take() takes it by reference (ro#1058)
		$item = Q::take($a, array(
			'uri'         => null,
			'url'         => null,
			'title'       => null,
			'body'        => null,
			'image'       => null,
			'dateTimePub' => null
		), $dest, array(
			'body'        => 'summary',
			'image'       => 'image',
			'dateTimePub' => 'publishedAt'
		));

		$item['id'] = $item['uri'] ?: $item['url'];
		unset($item['uri']);

		$item['source'] = Q::take(Q::ifset($a, 'source', array()), array(
			'title' => null,
			'uri'   => null
		), $src = array(), array(
			'title' => 'name',
			'uri'   => 'url'
		));

		$lang3 = Q::ifset($a, 'lang', null);
		$derivedLang = $this->iso2FromIso3($lang3);

		$item['language'] = $derivedLang
			? $derivedLang
			: ($requestedLanguage ? explode('-', $requestedLanguage)[0] : null);

		$locationUri = Q::ifset($a, 'source', 'locationUri', null);
		$derivedCountry = $this->countryFromLocationUri($locationUri);

		$item['country'] = $derivedCountry ?: $requestedCountry;
		$item['keywords'] = array();

		return $item;
	}

	protected function iso2FromIso3($iso3)
	{
		if (!$iso3) return null;

		if (!self::$languages) {
			$file = Q_FILES_DIR . DS . 'Q' . DS . 'languages.json';
			$tree = Q_Tree::createAndLoad($file);
			self::$languages = $tree->getAll();
		}

		foreach (self::$languages as $code => $info) {
			if (strtolower(Q::ifset($info, 'ISO-639-2B', '')) === strtolower($iso3)) {
				return strtolower(Q::ifset($info, 'ISO-639-1', null));
			}
		}

		return null;
	}

	protected function countryFromLocationUri($uri)
	{
		if (!$uri) return null;

		if (!self::$countries) {
			self::$countries = Q_Text::get('Places/countries', array(
				'language' => 'en'
			));
		}

		if (!self::$countryNameToIso2) {
			self::$countryNameToIso2 = array();
			foreach (self::$countries as $cc => $info) {
				$name = Q::ifset($info, 0, null);
				if ($name) {
					self::$countryNameToIso2[strtolower($name)] = strtoupper($cc);
				}
			}
		}

		if (preg_match('~/wiki/([^/]+)$~', $uri, $m)) {
			$name = strtolower(str_replace('_', ' ', $m[1]));
			if (isset(self::$countryNameToIso2[$name])) {
				return self::$countryNameToIso2[$name];
			}
		}

		return null;
	}

	protected function iso3($iso2)
	{
		$iso2 = strtolower($iso2);

		if (!self::$languages) {
			$file = Q_FILES_DIR . DS . 'Q' . DS . 'languages.json';
			$tree = Q_Tree::createAndLoad($file);
			self::$languages = $tree->getAll();
		}

		foreach (self::$languages as $code => $info) {
			if (strtolower(Q::ifset($info, 'ISO-639-1', '')) === $iso2) {
				return strtolower(Q::ifset($info, 'ISO-639-2B', null));
			}
		}

		return null;
	}

	protected function countryUri($cc)
	{
		$cc = strtoupper($cc);

		if (!self::$countries) {
			self::$countries = Q_Text::get('Places/countries', array(
				'language' => 'en'
			));
		}

		$name = Q::ifset(self::$countries, $cc, 0, null);
		if (!$name) return null;

		$name = str_replace(' ', '_', $name);

		return 'http://en.wikipedia.org/wiki/' . $name;
	}
}