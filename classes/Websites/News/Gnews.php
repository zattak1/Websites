<?php

/**
 * @module Websites
 */
/**
 * GNews adapter.
 *
 * Normalizes GNews API responses into provider-agnostic article items.
 *
 * @class Websites_News_Gnews
 */
class Websites_News_Gnews extends Websites_News implements Websites_News_Interface
{
	protected $apiKey;
	protected $endpoint = 'https://gnews.io/api/v4';

	public function __construct($options = array())
	{
		$this->apiKey = Q_Config::expect('Websites', 'gnews', 'key');
	}

	public function fetchNews(array $options = array())
	{
		// Normalize options
		$opts = Q::take($options, array(
			'type'     => 'top',
			'country'  => 'us',
			'language' => 'en',
			'keyword'  => null,
			'max'      => 12,
			'from'     => null,
			'to'       => null,
			'category' => null
		));

		if ($opts['type'] === 'search' && !$opts['keyword']) {
			throw new Q_Exception_RequiredField(array(
				'field' => 'options.keyword'
			));
		}

		$params = array(
			'token'   => $this->apiKey,
			'lang'    => $opts['language'],
			'country' => $opts['country'],
			'max'     => min(100, max(1, (int) $opts['max']))
		);

		if ($opts['type'] === 'search') {
			$url = $this->endpoint . '/search';
			$params['q'] = $opts['keyword'];
			if ($opts['from']) $params['from'] = $opts['from'];
			if ($opts['to'])   $params['to']   = $opts['to'];
		} else {
			$url = $this->endpoint . '/top-headlines';
			if ($opts['category']) {
				$params['topic'] = $opts['category']; // best-effort mapping
			}
		}

		// Q_Utils::get() has no data argument: the parameters go in the query
		// string. This used to pass $params as the user agent and 30 as the
		// callback, so no parameter was ever sent (ro#1058).
		$url .= '?' . http_build_query($params, '', '&');
		$response = Q_Utils::get($url, null, array(), null, 30);
		$data = json_decode($response, true);

		if (!is_array($data)) {
			throw new Exception("Invalid GNews response");
		}
		if (!empty($data['errors'])) {
			throw new Exception("GNews API error: " . json_encode($data['errors']));
		}

		$items = array();
		foreach ((array) Q::ifset($data, 'articles', array()) as $a) {
			$items[] = $this->normalize($a, $opts['language'], $opts['country']);
		}

		return $items;
	}

	protected function normalize(array $a, $language, $country)
	{
		$item = Q::take($a, array(
			'url'         => null,
			'title'       => null,
			'description' => null,
			'content'     => null,
			'image'       => null,
			'publishedAt' => null
		), $dest = array(), array(
			'description' => 'summary'
		));

		$item['id'] = $item['url'];

		$item['source'] = Q::take(Q::ifset($a, 'source', array()), array(
			'name' => null,
			'url'  => null
		));

		$item['language'] = $language;
		$item['country']  = $country;
		$item['keywords'] = array();

		return $item;
	}
}