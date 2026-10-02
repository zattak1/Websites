<?php

/**
 * @module Websites
 */

/**
 * Fetches URLs that a user supplied (link previews, scraping, icons, CSS)
 * without letting the server be used to reach its own network. The checks
 * (http(s) only, public targets only, pinned connection, every redirect
 * re-checked, TLS verified, no proxy) live in Q_Fetch in Platform core, so
 * plugins that cannot depend on Websites (Streams, Calendars) share them;
 * this subclass keeps its own exception class and User-Agent (ro#1034).
 *
 * @class Websites_Fetch
 * @extends Q_Fetch
 * @static
 */
class Websites_Fetch extends Q_Fetch
{
	protected static function userAgent()
	{
		return Q_Config::get('Websites', 'fetch', 'userAgent',
			'Mozilla/5.0 (compatible; Qbix Websites link preview)');
	}

	protected static function refuse($url, $reason)
	{
		throw new Websites_Exception_UnsafeUrl(array(
			'url' => is_string($url) ? $url : gettype($url),
			'reason' => $reason
		));
	}
}
