<?php

/**
 * @module Websites
 */

/**
 * EventRegistry (newsapi.ai) adapter.
 *
 * Normalizes EventRegistry responses into provider-agnostic article items.
 *
 * @class Websites_News_Eventregistry
 */
class Websites_News_Eventregistry extends Websites_News implements Websites_News_Interface
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
			'type'     => 'top',
			'country'  => 'us',
			'language' => 'en',
			'keyword'  => null,
			'max'      => 12,
			'page'     => 1
		));

		if ($opts['type'] === 'search' && !$opts['keyword']) {
			throw new Q_Exception_RequiredField(array(
				'field' => 'options.keyword'
			));
		}

		// -------------------------------------------------
		// Build strict structured EventRegistry query
		// -------------------------------------------------

		$queryParts = array();

		// Country filter (publication location)
		if ($opts['country']) {
			$uri = $this->countryUri($opts['country']);
			if ($uri) {
				$queryParts[] = array(
					"sourceLocationUri" => $uri
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

		// Keyword filter (search mode)
		if ($opts['keyword']) {
			$queryParts[] = array(
				"keyword" => $opts['keyword']
			);
		}

		// Properly construct $query section
		if (!empty($queryParts)) {
			$querySection = array(
				"\$and" => $queryParts
			);
		} else {
			// Fallback to language filter to avoid global noise
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
			"articlesPage"        => (int) $opts['page'],
			"dataType"            => array("news"),
			"includeArticleBody"  => true,
			"includeArticleImage" => true,
			"includeSourceTitle"  => true,
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

		$data = Q::json_decode($response, true);

		if (!is_array($data) || empty($data['articles']['results'])) {
			Q::log('EventRegistry error or empty result: ' . $response);
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

		$item['id'] = $item['uri'];
		unset($item['uri']);

		$item['source'] = Q::take(Q::ifset($a, 'source', array()), array(
			'title' => null,
			'uri'   => null
		), $src = array(), array(
			'title' => 'name',
			'uri'   => 'url'
		));

		// -------- REAL LANGUAGE (ISO-3 → ISO-2) --------
		$lang3 = Q::ifset($a, 'lang', null);
		$derivedLang = $this->iso2FromIso3($lang3);

		if ($derivedLang) {
			$item['language'] = $derivedLang;
		} else {
			$item['language'] = $requestedLanguage
				? explode('-', $requestedLanguage)[0]
				: null;
		}

		// -------- REAL COUNTRY --------
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

	/**
	 * Convert ISO-2 → ISO-3 using Q/languages.json
	 */
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

	/**
	 * Convert ISO-2 country → Wikipedia URI using Places/countries
	 */
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