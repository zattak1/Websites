<?php

use HeadlessChromium\BrowserFactory;

/**
 * @module Websites
 */
/**
 * Class for dealing with websites webpage
 * 
 * @class Websites_Webpage
 */
class Websites_Webpage extends Base_Websites_Webpage
{
	/**
	 * Get URL, load page and crape info to array
	 * @method scrape
	 * @static
	 * @param {string} $url Page source to load
	 * @throws Q_Exception
	 * @return array
	 */
	static function scrape($url)
	{
		$parts = explode('#', $url);
		$url = reset($parts);
		$originalUrl = $url;

		// add scheme to url if not exist
		if (parse_url($url, PHP_URL_SCHEME) === null) {
			$url = 'https://'.$url;
		}

		if (!Q_Valid::url($url)) {
			throw new Exception("Invalid URL");
		}

		$parsedUrl = parse_url($url);
		$host = $parsedUrl["host"];
		$port = Q::ifset($parsedUrl, "port", null);

		$result = array(
			'host' => $host,
			'port' => $port
		);

		//$document = file_get_contents($url);

        // try to get cache
		$cached = self::cacheGet($originalUrl);
		if (!$cached) {
			if (substr($url, -1) === '/') {
				$cached = self::cacheGet(substr($originalUrl, 0, -1));
			} else {
				$cached = self::cacheGet($originalUrl.'/');
			}
		}
		if ($cached) {
			return $cached;
		}

		// One request, through Websites_Fetch: http(s) only, no private or
		// loopback targets on any hop, redirects re-checked, TLS verified
		//. It replaces get_headers() + file_get_contents() +
		// Q_Utils::get() with verification off, each of which fetched the
		// user's URL (and followed its redirects) with no such checks.
		$response = Websites_Fetch::get($url, array(
			'maxBytes' => Q_Config::get('Websites', 'scrape', 'maxBytes', 2097152)
		));
		// (an error status is not refused: as before, whatever page came back
		// is parsed, since some sites answer bots 403 with full metadata)
		$url = $response['url'];
		$headers = $response['headers'];
		$contentType = Q::ifset($headers, 'content-type', 'text/html');

        // for non text/html content use another approach
        if (!stristr($contentType, 'text/html')) {
            $fileInfo = self::_fileInfoFromResponse($response, 65536);

            $extension = Q::ifset($fileInfo, 'fileformat', Q::ifset($fileInfo, 'mime_type', strtolower(pathinfo($url, PATHINFO_EXTENSION))));
            $extension = preg_replace("/.*\//", '', $extension);

            // check if this extension exist in Streams/files/Streams/icons/files
            $dirname = STREAMS_PLUGIN_FILES_DIR.DS.'Streams'.DS.'icons'.DS.'files';
            $urlPrefix = '{{Streams}}/img/icons/files';
            $icon = file_exists($dirname.DS.$extension)
                ? "$urlPrefix/$extension/80.png"
                : "$urlPrefix/_blank/80.png";


            $result = array_merge($result, array(
                'title' => html_entity_decode(Q::ifset($fileInfo, 'comments', 'name', null), ENT_QUOTES, 'UTF-8'),
                'url' => $url,
                'iconBig' => $icon,
                'iconSmall' => $icon,
                'type' => $extension
            ));

            return self::_returnScrape($originalUrl, $url, $result);
        }

		// curl decodes gzip/deflate itself (CURLOPT_ENCODING)
		$document = $response['body'];
		if (!$document) {
			throw new Exception("Unable to access the site");
		}

		$doc = new DOMDocument();
		// set error level
		$internalErrors = libxml_use_internal_errors(true);
		$encoded = mb_encode_numericentity($document, array(0x80, 0x10FFFF, 0, ~0), 'UTF-8' );
		$doc->loadHTML($encoded);
		// Restore error level
		libxml_use_internal_errors($internalErrors);

		$xpath = new DOMXPath($doc);
		$query = $xpath->query('//*/meta');

		// get metas
		$ogMetas = array();
		$metas = array();
		foreach ($query as $item) {
			$name = $item->getAttribute('name');
			$content = $item->getAttribute('content');
			$property = $item->getAttribute('property');

			if(!empty($property) && preg_match('#^og:#', $property)) {
				$ogMetas[str_replace("og:", "", $property)] = $content;
			} elseif(!empty($name)) {
				$metas[$name] = $content;
			}
		}

		$result = array_merge($result, $metas, $ogMetas);

		$result['headers'] = array();

		// merge headers into string
		foreach ($headers as $key => $item) {
			if (is_array($item)) {
				$item = end($item);
			}
			$result['headers'][trim($key)] = trim($item);
		}

		// collect language from diff metas
		$result['lang'] = Q::ifset($result, 'language', Q::ifset(
			$result, 'lang', Q::ifset($result, 'locale', null)
		));

		// if language empty, collect from html tag or headers
		if (empty($result['lang'])) {
			// get title
			$html = $doc->getElementsByTagName("html");
			if($html->length > 0){
				$result['lang'] = $html->item(0)->getAttribute('lang');
			}

			if (empty($result['lang'])) {
				$result['lang'] = Q::ifset($result, 'headers', 'language', Q::ifset($result, 'headers', 'content-language', 'en'));
			}
		}

		// get title
		$titleNode = $xpath->query('//title')->item(0);
		$result['title'] = $titleNode ? html_entity_decode($titleNode->textContent, ENT_QUOTES, 'UTF-8') : 'Untitled Webpage';

		$elements = $xpath->query('//*/link');
		$icons = array();
		$canonicalUrl = null;
		foreach ($elements as $element) {
			$rel = strtolower($element->getAttribute('rel'));
			$href = $element->getAttribute('href');

			if(!empty($rel)){
				if (preg_match('#icon#', $rel)) {
					$icons[$rel] = self::normalizeHref($href, $url);
				}

				if ($rel == 'canonical') {
					$canonicalUrl = self::normalizeHref($href, $url);
				}
			}
		}

		$elements = $xpath->query('//*/meta');
		foreach ($elements as $element) {
			$itemprop = strtolower($element->getAttribute('itemprop'));
			$metaname = strtolower($element->getAttribute('name'));
			if ($itemprop === 'image'
			or strpos($metaname, ':image') !== false) {
				$href = $element->getAttribute('content');
				if (!$href) {
					$href = $element->getAttribute('value');
				}
				$result['image'] = self::normalizeHref($href, $url);
			}
		}

		// parse url
		$result['url'] = $canonicalUrl ? $canonicalUrl : $url;

		// get big icon
		$icon = Q::ifset($result, 'image', null);
		$bigIconAllowedMetas = array( // search icon among <link> with these "rel"
			'apple-touch-icon',
			'apple-touch-icon-precomposed',
			'image'
		);
		if (Q_Valid::url($icon)) {
			$result['iconBig'] = $icon;
		} else {
			foreach ($bigIconAllowedMetas as $item) {
				if ($item = Q::ifset($icons, $item, null)) {
					$result['iconBig'] = $item;
					break;
				}
			}
		}

		// get small icon
		$result['iconSmall'] = $result['iconBig']; // default
		$smallIconAllowedMetas = array( // search icon among <link> with these "rel"
			'icon',
			'shortcut icon'
		);
		foreach ($smallIconAllowedMetas as $item) {
			if ($item = Q::ifset($icons, $item, null)) {
				$result['iconSmall'] = $item;
				break;
			}
		}

		// as we don't support SVG images in Users::importIcon, try to select another image
		// when we start support SVG, just remove these blocks
		if (!empty($result['iconBig'])
		and pathinfo($result['iconBig'], PATHINFO_EXTENSION) == 'svg') {
			reset($bigIconAllowedMetas);
			foreach ($bigIconAllowedMetas as $item) {
				$item = Q::ifset($icons, $item, null);
				if ($item && pathinfo($item, PATHINFO_EXTENSION) != 'svg') {
					$result['iconBig'] = $item;
					break;
				}
			}
		}
		if (!empty($result['iconSmall'])
		and pathinfo($result['iconSmall'], PATHINFO_EXTENSION) == 'svg') {
			reset($smallIconAllowedMetas);
			foreach ($smallIconAllowedMetas as $item) {
				$item = Q::ifset($icons, $item, null);
				if ($item && pathinfo($item, PATHINFO_EXTENSION) != 'svg') {
					$result['iconSmall'] = $item;
					break;
				}
			}
		}
		//---------------------------------------------------------------

		// if big icon empty, set it to small icon
		if (empty($result['iconBig']) && !empty($result['iconSmall'])) {
			$result['iconBig'] = $result['iconSmall'];
		}

		$result['iconBig'] = self::normalizeHref($result['iconBig'], $url);
		$result['iconSmall'] = self::normalizeHref($result['iconSmall'], $url);

		// additional handler for youtube.com
		if (in_array($host, array('www.youtube.com', 'youtube.com'))) {
			preg_match("/^(?:http(?:s)?:\/\/)?(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user|shorts)\/))([^\?&\"'>]+)/u", $url, $videoId);
			$videoId = end($videoId);
			$youtubeData = self::youtube(@compact("videoId"));
			$youtubeData  = reset($youtubeData);
			$result = array_merge($result, $youtubeData);
		}

		$result['iconBig'] = Q::ifset($result, 'iconBig', Q_Uri::interpolateUrl("{{baseUrl}}/{{Websites}}/img/icons/Websites/webpage/80.png"));
		$result['iconSmall'] = Q::ifset($result, 'iconSmall', Q_Uri::interpolateUrl("{{baseUrl}}/{{Websites}}/img/icons/Websites/webpage/40.png"));

		return self::_returnScrape($originalUrl, $url, $result);
	}

	private static function _returnScrape ($originalUrl, $url, $result) {
		Websites_Webpage::cacheSet($originalUrl, $result);
		if ($url !== $originalUrl) {
			Websites_Webpage::cacheSet($url, $result);
		}
		return $result;
	}

	/**
	 * Get search youtube videos or get info about video.
	 * @method youtube
	 * @static
	 * @param {array} $options
	 * @param {string} [$options.videoId] id of youtube video to get info about single video
	 * @param {string|array} [$options.query] If string - query string to search videos. If array - replace ytQuery object.
	 * @param {string} [$options.channel] youtube channel id
	 * @param {integer} [$options.maxResults=10] limit search results
	 * @param {string} [$options.order=date] Results order by.
	 * @param {boolean} [$options.pureResult=false] If true, return exactly result got from youtube API
	 * @param {integer} [$options.cacheDuration] response cache life time in seconds
	 * @return {array|boolean} decoded json if found or false
	 */
	static function youtube ($options)
	{
		$apiKey = Q_Config::expect("Websites", "youtube", "keys", "server");
		$videoId = Q::ifset($options, "videoId", null);
		$query = Q::ifset($options, "query", null);
		$pureResult = Q::ifset($options, "pureResult", false);

		if ($videoId === null && $query === null) {
			throw new Exception('Websites_Webpage::youtube: videoId or query should defined');
		}

		$type = $videoId ? "videos" : "search";
		$endPoint = "https://youtube.googleapis.com/youtube/v3/".$type;

		$ytQuery = is_array($query) ? $query : array(
			"part" => ($type === "videos" ? "snippet,topicDetails" : "snippet")
		);

		if ($type == "search") {
			$ytQuery["maxResults"] = Q::ifset($options, "maxResults", 10);
			$ytQuery["order"] = Q::ifset($options, "order", "date");
			if (is_string($query)) {
				$ytQuery["q"] = $query;
			}

			$channelId = Q::ifset($options, "channel", null);
			if ($channelId) {
				$ytQuery["channelId"] = $channelId;
			}
		} elseif ($type == "videos") {
			$ytQuery["id"] = $videoId;
		}

		$cacheKey = $endPoint . '?' . http_build_query(array_merge($ytQuery, [
			'_type' => $type
		]));

		// check for cache
		$cached = Websites_Webpage::cacheGet($cacheKey);
		if ($cached) {
			return self::_returnYoutube($cached, $pureResult);
		}

		$ytQuery["key"] = $apiKey;

		$youtubeApiUrl = $endPoint.'?'.http_build_query($ytQuery);
		$result = Q::json_decode(Q_Utils::get($youtubeApiUrl), true);
		if (Q::ifset($result, "error", null)) {
			throw new Exception("Youtube API error: ".Q::ifset($result, "error", "message", null));
		}

		$cacheDuration = $type == "search"
			? Q::ifset($options, "cacheDuration", Q_Config::get("Websites", "youtube", "list", "cacheDuration", 43200))
			: null;

		Websites_Webpage::cacheSet($cacheKey, $result, $cacheDuration);

		return self::_returnYoutube($result, $pureResult);
	}

	/**
	 * Normalize YouTube API response.
	 * @method _returnYoutube
	 * @static
	 * @protected
	 * @param {array} $data
	 * @param {boolean} $pureResult
	 * @return {array}
	 */
	protected static function _returnYoutube(array $data, $pureResult)
	{
		if ($pureResult) {
			return $data;
		}

		$results = array();

		foreach ($data["items"] as $item) {
			$snippet = $item["snippet"];

			$tags = Q::ifset($snippet, 'tags', null);
			$keywords = is_array($tags) && count($tags)
				? implode(',', $tags)
				: "";

			$videoId = Q::ifset($item, "id", "videoId", Q::ifset($item, "id", null));
			if (!is_string($videoId)) {
				continue;
			}

			$result = array(
				"platform" => "youtube",
				"videoId" => $videoId,
				"extended" => false,
				"title" => html_entity_decode($snippet["title"], ENT_QUOTES, 'UTF-8'),
				"icon" => Q::ifset($snippet, "thumbnails", "default", "url", null),
				"iconBig" => Q::ifset($snippet, "thumbnails", "high", "url", null),
				"iconSmall" => "{{Websites}}/img/icons/Websites/youtube/32.png",
				"description" => html_entity_decode($snippet["description"], ENT_QUOTES, 'UTF-8'),
				"keywords" => $keywords,
				"publishTime" => strtotime(Q::ifset($snippet, "publishTime", Q::ifset($snippet, "publishedAt", "now"))),
				"url" => "https://www.youtube.com/watch?v=$videoId"
			);

			Websites_Webpage::cacheSet($result["url"], $result);
			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Get cached url response
	 * @method cacheGet
	 * @static
	 * @param string $url
	 * @return array|boolean decoded json if found or false
	 */
	static function cacheGet ($url) {
		if (!Q_Config::get('Websites', 'cache', 'webpage', true)) {
			return false;
		}

		$webpageCache = new Websites_Webpage();
		$webpageCache->url = $url;
		if (!$webpageCache->retrieve()) {
			// if not retrieved try to find url ended with slash (to avoid duplicates of save source)
			$webpageCache->url = $url.'/';
			$webpageCache->retrieve();
		}

		if ($webpageCache->retrieved) {
			$updatedTime = $webpageCache->updatedTime;
			if (isset($updatedTime)) {
				$db = $webpageCache->db();
				$updatedTime = $db->fromDateTime($updatedTime);
				$currentTime = $db->getCurrentTimestamp();
				$cacheDuration = $webpageCache->duration; // default 1 month
				if ($currentTime - $updatedTime < $cacheDuration) {
					// there are cached webpage results that are still viable
					return json_decode($webpageCache->results, true);
				} else {
					$webpageCache->remove();
				}
			}
		}

		return false;
	}
	/**
	 * Save url response to cache
	 * @method cacheSet
	 * @static
	 * @param string $url
	 * @param array $result
	 * @param integer [$duration=null] cache life time in seconds
	 */
	static function cacheSet ($url, $result, $duration = null) {
		$webpageCache = new Websites_Webpage();
		$webpageCache->url = $url;

		if ($duration) {
			$webpageCache->duration = $duration;
		}

		// dummy interest block for cache
		$result['interest'] = array(
			'title' => $url,
			'icon' => Q::ifset($result, "iconSmall", Q::ifset($result, "icon", Q::ifset($result, "iconBig", null)))
		);
		$webpageCache->results = json_encode($result);
		$webpageCache->save(true);
	}
	/**
	 * Normalize href like '//path/to' or '/path/to' to valid URL
	 * @method normalizeHref
	 * @static
	 * @param string $href
	 * @param string $baseUrl
	 * @throws Exception
	 * @return string
	 */
	static function normalizeHref ($href, $baseUrl) {
		$parts = parse_url($baseUrl);

		if ($parts['scheme'] == 'https' && parse_url($href, PHP_URL_SCHEME) == 'http') {
			$href = preg_replace("/^http/i", "https", $href);
		}

		if (preg_match("#^\\/\\/#", $href)) {
			return $parts['scheme'].':'.$href;
		}

		if (preg_match("#^\\/#", $href)) {
			return $parts['scheme'] . '://' . $parts['host'] . $href;
		}

		if (!Q_Valid::url($href)) {
			return $parts['scheme'] . '://' . $parts['host'] . '/' . $href;
		}

		if (substr($baseUrl, -1) === '/') {
			$baseUrl = substr($baseUrl, 0, -1);
		}
		if (preg_match("#^.\\/#", $href)) {
			return $baseUrl . '/' . substr($href, 2);
		}

		return $href;
	}
	/**
	 * Normalize url to use as part of stream name like Websites/webpage/[normalized]
	 * @method normalizeUrl
	 * @static
	 * @param {string} $url
	 * @return string
	 */
	static function normalizeUrl($url) {
		// we have "name" field max size 255, Websites/webpage/ = 18 chars
		return substr(Q_Utils::normalize($url), 0, 200);
	}
	/**
	 * If Websites/webpage stream for this $url already exists - return one.
	 * @method fetchStream
	 * @static
	 * @param {string} $url URL string to search stream by.
     * @param {string} [$streamType=null] Type of stream to search. If null it auto detected with getStreamType method.
	 * @return Streams_Stream
	 */
	static function fetchStream($url, $streamType = null) {
        if (!$streamType) {
            $streamType = self::getStreamType($url);
        }

		$streams = new Streams_Stream();
		$streams->name = $streamType.'/'.self::normalizeUrl($url);
		if ($streams->retrieve()) {
			return Streams_Stream::fetch($streams->publisherId, $streams->publisherId, $streams->name);
		}

		$streams->name .= '_';
		if ($streams->retrieve()) {
			return Streams_Stream::fetch($streams->publisherId, $streams->publisherId, $streams->name);
		}

		return null;
	}
    /**
     * Get stream type from url
     * @method getType
     * @static
     * @param {string} $url
     * @return String
     */
	static function getStreamType ($url) {
        $parsed = parse_url($url);
        $host = Q::ifset($parsed, 'host', null);

        $path_info = pathinfo($url);
        $extension = Q::ifset($path_info, 'extension', '');

        $videoHosts = Q_Config::get("Websites", "videoHosts", array());
        $videoExtensions = Q_Config::get("Websites", "videoExtensions", array());

        $audioHosts = Q_Config::get("Websites", "audioHosts", array());
        $audioExtensions = Q_Config::get("Websites", "audioExtensions", array());

        if (false !== Q::striposa($host, $videoHosts) || false !== Q::striposa($extension, $videoExtensions)) {
            return 'Streams/video';
        } elseif (false !== Q::striposa($host, $audioHosts) || false !== Q::striposa($extension, $audioExtensions)) {
            return 'Streams/audio';
        }

        return 'Websites/webpage';
    }
	/**
	 * Get limited data from remote url
	 * (through Websites_Fetch: http(s) only, no private targets)
	 * @method readURL
	 * @static
	 * @param {string} $url
	 * @param {integer} [$dataLimit=65536] Limit data length (bites) to download. Default 64Kb.
	 * @throws Q_Exception
	 * @return string
	 */
	static function readURL ($url, $dataLimit = 65536) {
		$response = Websites_Fetch::get($url, array('maxBytes' => (int)$dataLimit));
		if ($response['status'] < 200 || $response['status'] >= 400) {
			throw new Q_Exception('Error opening URL for reading');
		}
		return $response['body'];
	}
    /**
     * Get meta data from remote file by url
     * (through Websites_Fetch: http(s) only, no private targets)
     * @method getRemoteFileInfo
     * @static
     * @param {string} $url
     * @param {integer} [$dataLimit=65536] Limit data length (bites) to download. Default 64Kb.
	 * @param {boolean} [$closeFile=true] Whether to remove temp file after method executed
     * @throws Q_Exception
     * @return {array} Array of "name", "comments", "fileHandler"
     */
    static function getRemoteFileInfo ($url, $dataLimit = 65536, $closeFile = true) {
        $response = Websites_Fetch::get($url, array('maxBytes' => (int)$dataLimit));
        if ($response['status'] < 200 || $response['status'] >= 400) {
            throw new Q_Exception('Error opening URL for reading');
        }
        return self::_fileInfoFromResponse($response, $dataLimit, $closeFile);
    }

    /**
     * Fetch $url through Websites_Fetch into a temporary file, call
     * $callback with its path, and remove the file afterwards.
     * @method _withFetchedFile
     * @static
     * @private
     */
    private static function _withFetchedFile ($url, $callback) {
        $path = Websites_Fetch::toTempFile($url);
        try {
            return call_user_func($callback, $path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * getRemoteFileInfo() on a response already fetched by Websites_Fetch::get()
     * @method _fileInfoFromResponse
     * @static
     * @private
     */
    private static function _fileInfoFromResponse ($response, $dataLimit = 65536, $closeFile = true) {
        $url = $response['url'];
        $file = tmpfile();
        $path = stream_get_meta_data($file)['uri'];
        fwrite($file, substr($response['body'], 0, $dataLimit));

        $getID3 = new Audio_getID3();
        $metaData = $getID3->analyze($path);
        getid3_lib::CopyTagsToComments($metaData);

        $title = Q::ifset($metaData, 'comments', 'title', 0, null);
        $artist = Q::ifset($metaData, 'comments', 'artist', 0, null);

        $name = ($artist ? $artist.': ' : '') . $title;

        if ($name) {
            $metaData['comments']['name'] = $name;
        } else {
            // try to get name from headers
            $contentDisposition = Q::ifset($response, 'headers', 'content-disposition', '');
            $fileName = self::getFilenameFromDisposition($contentDisposition);
            if ($fileName) {
                $name = pathinfo($fileName, PATHINFO_FILENAME);
            }

            if ($name) {
                $metaData['comments']['name'] = $name;
            } else {
                // try to get name from url string
                $name = pathinfo($url, PATHINFO_FILENAME);
                if ($name) {
                    $metaData['comments']['name'] = $name;
                } else {
                    $metaData['comments']['name'] = null;
                }
            }
        }

        if ($closeFile) {
			@fclose($file);
		} else {
			$metaData['fileHandler'] = $file;
		}

        return $metaData;
    }
    /**
     * Get file name from Content-Disposition header raw
     * @method getFilenameFromDisposition
     * @static
     * @param {string} $contentDisposition
     * @return string
     */
    static function getFilenameFromDisposition ($contentDisposition) {
        // Get the filename.
        $filename = null;

        $value = trim( $contentDisposition );

        if ( strpos( $value, ';' ) === false ) {
            return null;
        }

        list( $type, $attr_parts ) = explode( ';', $value, 2 );

        $attr_parts = explode( ';', $attr_parts );
        $attributes = array();

        foreach ( $attr_parts as $part ) {
            if ( strpos( $part, '=' ) === false ) {
                continue;
            }

            list( $key, $value ) = explode( '=', $part, 2 );

            $attributes[ trim( $key ) ] = trim( $value );
        }

        if ( empty( $attributes['filename'] ) ) {
            return null;
        }

        $filename = trim( $attributes['filename'] );

        // Unquote quoted filename, but after trimming.
        if ( substr( $filename, 0, 1 ) === '"' && substr( $filename, -1, 1 ) === '"' ) {
            $filename = substr( $filename, 1, -1 );
        }

        return $filename;
    }
	/**
	 * Create Websites/webpage stream from params
	 * May return existing stream for this url (fetched without acceess checks)
	 * @method createStream
	 * @static
	 * @param {array} $params
	 * @param {string} [$params.asUserId=null] The user who would be create stream. If null - logged user id.
	 * @param {string} [$params.publisherId=null] Stream publisher id. If null - logged in user.
	 * @param {string} [$params.url]
	 * @param {string} [quotaName='Websites/webpage/chat'] Default quota name. Can be:
	 * 	Websites/webpage/conversation - create Websites/webpage stream for conversation about webpage
	 * 	Websites/webpage/chat - create Websites/webpage stream from chat to cache webpage.
	 * @param {bool} [$skipAccess=false] Whether to skip access in Streams::create and quota checking.
	 * @throws Exception
	 * @return Streams_Stream
	 */
	static function createStream ($params, $quotaName='Websites/webpage/chat', $skipAccess=false) {
		$url = Q::ifset($params, 'url', null);
		
		// add scheme to url if not exist
		if (parse_url($url, PHP_URL_SCHEME) === null) {
			$url = 'https://'.$url;
		}

		if (!Q_Valid::url($url)) {
			throw new Exception("Invalid URL");
		}

		$siteData = self::scrape($url);

		$urlParsed = parse_url($url);
		$user = Users::loggedInUser();
		$asUserId = Q::ifset($params, "asUserId", Q::ifset($user, 'id', null));
		$publisherId = Q::ifset($params, "publisherId", Q::ifset($user, 'id', null));

		$streamType = self::getStreamType($url);

		// check if stream for this url has been already created
		// and if yes, return it
		if ($webpageStream = self::fetchStream($url)) {
			return $webpageStream;
		}

		$quota = null;
		if (!$skipAccess && $quotaName) {
		// check quota
			$roles = Users::roles();
			$quota = Users_Quota::check($asUserId, '', $quotaName, true, 1, array_keys($roles));
		}

		$streamsStream = new Streams_Stream();
		$title = Q::ifset($siteData, 'title', substr($url, strrpos($url, '/') + 1));
		$title = $title ? mb_substr($title, 0, $streamsStream->maxSize_title(), "UTF-8") : '';

		$keywords = Q::ifset($siteData, 'keywords', null);
		$description = mb_substr(Q::ifset($siteData, 'description', ''), 0, $streamsStream->maxSize_content(), "UTF-8");
		$copyright = Q::ifset($siteData, 'copyright', null);
		$iconBig = self::normalizeHref(Q::ifset($siteData, 'iconBig', null), $url);
		$iconSmall = self::normalizeHref(Q::ifset($siteData, 'iconSmall', null), $url);
		$contentType = Q::ifset($siteData, 'headers', 'Content-Type', 'text/html'); // content type by default text/html
		$contentType = explode(';', $contentType)[0];
		$streamIcon = null;

		// special interest stream for websites/webpage stream
		$port = Q::ifset($urlParsed, 'port', null);
		$host = $urlParsed['host'];
		$interestTitle = 'Websites: '.$host.($port ? ':'.$port : '');
		// insofar as user created Websites/webpage stream, need to complete all actions related to interest created from client
		Q::event('Streams/interest/post', array(
			'title' => $interestTitle,
			'userId' => $publisherId
		));
		$interestPublisherId = Q_Response::getSlot('publisherId');
		$interestStreamName = Q_Response::getSlot('streamName');

		$interestStream = Streams_Stream::fetch(null, $interestPublisherId, $interestStreamName);

		if ($contentType != 'text/html') {
			// trying to get icon
			Q_Config::load(WEBSITES_PLUGIN_CONFIG_DIR.DS.'mime-types.json');
			$extension = Q_Config::get('mime-types', $contentType, '_blank');
			$urlPrefix = '{{baseUrl}}/{{Streams}}/img/icons/files';
			$streamIcon = file_exists(STREAMS_PLUGIN_FILES_DIR.DS.'Streams'.DS.'icons'.DS.'files'.DS.$extension)
				? "$urlPrefix/$extension"
				: "$urlPrefix/_blank";
		}

		// set icon for interest stream
		if ($interestStream instanceof Streams_Stream
		&& !Users::isCustomIcon($interestStream->icon)) {
			$result = null;

			if (Q_Valid::url($iconSmall)) {
				try {
					if (pathinfo($iconSmall, PATHINFO_EXTENSION) == 'svg') {
						$directory = $interestStream->iconDirectory();
						Q_Utils::canWriteToPath($directory, null, true);
						$fileName = $directory.DS.'icon.svg';
						$svg = Websites_Fetch::get($iconSmall, array('maxBytes' => 1048576));
						if ($svg['status'] !== 200 || $svg['truncated']) {
							throw new Q_Exception("Could not fetch the icon");
						}
						file_put_contents($fileName, $svg['body']);
						$head = APP_FILES_DIR.DS.Q::app().DS.'uploads';
						$tail = str_replace(DS, '/', substr($fileName, strlen($head)));
						$interestStream->icon = '{{baseUrl}}/Q/uploads' . $tail;
					} else {
						// Fetched here, through the checks, and handed over as
						// a file: Users::importIcon would fetch a URL itself.
						$result = self::_withFetchedFile($iconSmall, function ($iconFile) use ($interestStream) {
							return Users::importIcon($interestStream, array(
								'32.png' => $iconFile
							), $interestStream->iconDirectory());
						});
					}
				} catch (Exception $e) {

				}
			}

			if (empty($result) && $streamIcon) {
				$interestStream->icon = $streamIcon;
				$interestStream->setAttribute('iconSize', 40);
			} else {
				$interestStream->setAttribute('iconSize', 32);
			}

			$interestStream->save();
		}

		$streamName = $streamType."/".self::normalizeUrl($url);

		$td = trim($description);
		$streamParams = array(
            'name' => $streamName,
            'title' => trim($title),
			'icon' => $streamIcon,
            'content' => $td ? $td : "",
            'attributes' => array(
                'url' => $url,
                'urlParsed' => $urlParsed,
                'host' => $host,
                'port' => $port,
                'copyright' => $copyright,
                'contentType' => $contentType,
				'interest' => array(
					'publisherId' => $interestStream->publisherId,
					'streamName' => $interestStream->name
				),
                'lang' => Q::ifset($siteData, 'lang', 'en')
            ),
            'skipAccess' => $skipAccess
        );
		$relatedParams = array(
            'publisherId' => $interestPublisherId,
            'streamName' => $interestStreamName,
            'type' => $streamType.'/interest'
        );

		if ($streamType == 'Websites/webpage') {
            $webpageStream = Streams::create($asUserId, $publisherId, $streamType, $streamParams, $relatedParams);
		} else {
            $streamParams['publisherId'] = $publisherId;
            $streamParams['streamName'] = $streamName;

            $webpageStream = Q::event($streamType.'/post', array(
                'streamParams' => $streamParams,
                'relatedParams' => $relatedParams
            ));
        }

		// try to import icon from $iconBig
		// (fetched here through Websites_Fetch and handed over as a file,
		// since Streams::importIcon would fetch a URL itself unchecked)
		if (Q_Valid::url($iconBig)) {
			try {
				self::_withFetchedFile($iconBig, function ($iconFile) use ($webpageStream) {
					return Streams::importIcon($webpageStream->publisherId, $webpageStream->name, $iconFile, "Websites/image");
				});
			} catch (Exception $e) {
				// no icon, as when the old unchecked fetch failed
			}
		}

		// grant access to this stream for logged user
		$streamsAccess = new Streams_Access();
		$streamsAccess->publisherId = $webpageStream->publisherId;
		$streamsAccess->streamName = $webpageStream->name;
		$streamsAccess->ofUserId = $asUserId;
		$streamsAccess->readLevel = Streams::$READ_LEVEL['max'];
		$streamsAccess->writeLevel = Streams::$WRITE_LEVEL['max'];
		$streamsAccess->adminLevel = Streams::$ADMIN_LEVEL['max'];
		$streamsAccess->save();

		// if publisher not community, subscribe publisher to this stream
		if (!Users::isCommunityId($publisherId)) {
			$webpageStream->subscribe(array('userId' => $publisherId));
		}

		// handle with keywords
		if (!empty($keywords)) {
			$delimiter = preg_match("/,/", $keywords) ? ',' : ' ';
			foreach (explode($delimiter, $keywords) as $keyword) {
				$keywordInterestStream = Streams::getInterest(trim($keyword));
				if ($keywordInterestStream instanceof Streams_Stream) {
					$webpageStream->relateTo($keywordInterestStream, $webpageStream->type.'/keyword', $webpageStream->publisherId, array(
						'skipAccess' => true
					));
				}
			}
		}

		// set quota
		if (!$skipAccess && $quota instanceof Users_Quota) {
			$quota->used();
		}

		return $webpageStream;
	}

	/**
	 * Get stream interests in one array with items having properties
	 *  {publisherId, streamName, title}
	 * @method getInterests
	 * @static
	 * @param Streams_Stream $stream Websites/webpage stream
	 * @return array
	 */
	static function getInterests($stream)
	{
		$rows = Streams_Stream::select('ss.publisherId, ss.name as streamName, ss.title', 'ss')
			->join(Streams_relatedTo::table(true, 'srt'), array(
				'srt.toStreamName' => 'ss.name',
				'srt.toPublisherId' => 'ss.publisherId'
			))->where(array(
				//'srt.fromPublisherId' => $stream->publisherId,
				'srt.fromStreamName' => $stream->name,
				'srt.type' => $stream->type.'/interest'
			))
			->orderBy('srt.weight', false)
			->fetchDbRows();

		return reset($rows);
	}
	/**
	 * Get stream interests in one array with items having properties
	 *  {publisherId, streamName, title}
	 * @method getKeywords
	 * @static
	 * @param Streams_Stream $stream Websites/webpage stream
	 * @return array
	 */
	static function getKeywords($stream)
	{
		$rows = Streams_Stream::select('ss.publisherId, ss.name, ss.title', 'ss')
			->join(Streams_relatedTo::table(true, 'srt'), array(
				'srt.toStreamName' => 'ss.name',
				'srt.toPublisherId' => 'ss.publisherId'
			))->where(array(
				'srt.fromPublisherId' => $stream->publisherId,
				'srt.fromStreamName' => $stream->name,
				'srt.type' => $stream->type.'/keyword'
			))
			->orderBy('srt.weight', false)
			->fetchDbRows();

		return $rows;
	}

	/**
	 * Load a URL in headless Chrome, inject analyze.js and cssprobe.js,
	 * and return computed styles, fonts, dominant colors, and nav heuristics.
	 * Also crawls CSS (server-side) to follow @import and collect @font-face blocks.
	 *
	 * @method analyze
	 * @static
	 * @param {string} $url The webpage URL to analyze.
	 * @return {array} Analysis result object with the following keys:
	 *
	 *   {
	 *     title: {string}          The page title.
	 *     url: {string}            Final navigated URL (after redirects).
	 *
	 *     stylesBySelector: {object}   Map of sample selectors (e.g. "h1", "p") to computed style subsets.
	 *     fonts: {array<string>}       List of font-family strings observed on page elements.
	 *     dominantColors: {array<object>}  List of {hex, count} for common colors seen (text/bg/borders).
	 *     rootThemeColors: {object}    Map of CSS variable name → resolved hex color (from :root).
	 *
	 *     navCandidates: {array<object>}  Candidate nav elements scored by heuristics.
	 *         Each candidate: {
	 *           selector: {string}   CSS path to element.
	 *           score: {number}      Heuristic score.
	 *           rect: {object}       {top, left, width, height} bounding box.
	 *           links: {array<object>}  Array of {text, href}.
	 *         }
	 *     detectedNav: {object|null} The best nav candidate, same shape as above.
	 *
	 *     colorRoles: {object} {
	 *       foreground: {array<object>}   Top foreground text colors {hex, count}.
	 *       background: {array<object>}   Top background colors {hex, count}.
	 *     }
	 *
	 *     _assets: {object} {
	 *       cssUrls: {array<string>}      Stylesheet hrefs discovered.
	 *       fetchedCss: {object}          Map of cssUrl → byte length fetched server-side.
	 *       fontFaces: {array<object>}    Parsed @font-face blocks:
	 *           { family: {string}, style: {string}, weight: {string}, src: {array<string>} }
	 *       fontFiles: {array<string>}    Absolute or data: URLs of font files.
	 *     }
	 *
	 *     largestBlocks: {array<object>}  Largest visual blocks scanned with {selector, rect, color, backgroundColor}.
	 *
	 *     _analyzer: {object} {
	 *       path: {string}   Path to analyze.js used.
	 *       ts: {number}     Unix timestamp when run.
	 *     }
	 *   }
	 *
	 * @throws Exception If Chrome is not reachable or scripts not found.
	 */
	public static function analyze($url, $options = array())
	{
		$parts = explode('#', $url);
		$url = reset($parts);
		if (parse_url($url, PHP_URL_SCHEME) === null) {
			$url = 'https://' . $url;
		}
		if (!Q_Valid::url($url)) {
			throw new Exception("Invalid URL");
		}
		// Refuse non-http(s) and private/loopback targets before handing the
		// URL to Chrome. This checks the first hop only: Chrome
		// follows redirects and loads subresources itself, so the Chrome
		// container's own network policy has to do the rest.
		Websites_Fetch::check($url);

		if (!defined('WEBSITES_PLUGIN_WEB_DIR')) {
			throw new Exception('WEBSITES_PLUGIN_WEB_DIR is not defined');
		}
		$analyzerPath = WEBSITES_PLUGIN_WEB_DIR . DS . 'js' . DS . 'analyze.js';
		$cssProbePath = WEBSITES_PLUGIN_WEB_DIR . DS . 'js' . DS . 'cssprobe.js';

		foreach (array($analyzerPath, $cssProbePath) as $path) {
			if (!is_file($path)) {
				throw new Exception("Analyzer script not found at: " . $path);
			}
		}

		$analyzerJs = file_get_contents($analyzerPath);
		$cssProbeJs = file_get_contents($cssProbePath);
		if (!$analyzerJs || !$cssProbeJs) {
			throw new Exception("Failed to read analyzer scripts");
		}

		$browser = self::_chromeConnect();

		try {
			$page = $browser->createPage();

			// Set viewport if specified
			if (!empty($options['viewport'])) {
				$vp = $options['viewport'];
				try {
					$page->setViewport((int)$vp['width'], (int)$vp['height'])->await();
				} catch (Exception $e) {
					// Some chrome-php versions don't expose setViewport;
					// fall through. The analyzer still works at default size.
				}
				if ($vp['width'] <= 480) {
					try {
						$page->setUserAgent(
							'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) '
							. 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 '
							. 'Mobile/15E148 Safari/604.1'
						);
					} catch (Exception $e) {}
				}
			}

			$page->navigate($url)->waitForNavigation();

			// Wait for fonts/async CSS to finish loading
			$waitMs = isset($options['waitMs']) ? (int)$options['waitMs'] : 3000;
			usleep($waitMs * 1000);

			// Run analyze.js
			$wrapped = '(function(){' . $analyzerJs . '})()';
			$evaluation = $page->evaluate($wrapped);
			$evaluation->waitForResponse(30000);
			$result = $evaluation->getReturnValue();

			if (!is_array($result)) {
				$evaluation = $page->evaluate('window.__WebsitesAnalyze ? window.__WebsitesAnalyze() : null;');
				$evaluation->waitForResponse(20000);
				$result = $evaluation->getReturnValue();
			}
			if (!is_array($result)) {
				$out = array(
					'url' => $url,
					'error' => 'Analyzer returned non-array result',
					'raw' => $result
				);
				try { $page->close(); } catch (Exception $e) {}
				return $out;
			}

			// Run cssprobe.js
			$probeEval = $page->evaluate($cssProbeJs);
			$probeEval->waitForResponse(12000);
			$probeVal = $probeEval->getReturnValue();

			$sheetUrls = (is_array($probeVal) && isset($probeVal['css']) && is_array($probeVal['css']))
				? $probeVal['css'] : array();
			$colorRoles = (is_array($probeVal) && isset($probeVal['colors']) && is_array($probeVal['colors']))
				? $probeVal['colors'] : array('foreground' => array(), 'background' => array());

			// Server-side CSS crawl
			$seen = array();
			$fetched = array();
			$faces = array();
			$fontSet = array();
			for ($i = 0; $i < count($sheetUrls); $i++) {
				self::_crawlCss($sheetUrls[$i], $seen, $fetched, $faces, $fontSet);
			}
			$fontList = array();
			foreach ($fontSet as $fu => $t) {
				$fontList[] = $fu;
			}

			$result['_assets'] = array(
				'cssUrls' => $sheetUrls,
				'fetchedCss' => $fetched,
				'fontFaces' => $faces,
				'fontFiles' => $fontList
			);
			$result['colorRoles'] = array(
				'foreground' => isset($colorRoles['foreground']) ? $colorRoles['foreground'] : array(),
				'background' => isset($colorRoles['background']) ? $colorRoles['background'] : array()
			);
			$result['_analyzer'] = array(
				'path' => $analyzerPath,
				'cssProbe' => $cssProbePath,
				'viewport' => isset($options['viewport']) ? $options['viewport'] : null,
				'ts' => time()
			);

			try { $page->close(); } catch (Exception $e) {}
			return $result;

		} catch (Exception $e) {
			if ($page) {
				try { $page->close(); } catch (Exception $e2) {}
			}
			throw $e;
		}
	}

	/**
	 * Private helper: connect to an already-running headless Chrome
	 * (e.g., Docker container bound to 127.0.0.1:9222), or launch one locally.
	 *
	 * Reads CHROME_HOST/CHROME_PORT if present.
	 *
	 * @return \HeadlessChromium\Browser
	 * @throws Exception
	 */
	private static function _chromeConnect()
	{
		if (!class_exists('HeadlessChromium\\BrowserFactory')) {
			throw new Exception("HeadlessChromium library not installed. Run composer require chrome-php/chrome.");
		}

		$host = getenv('CHROME_HOST') ? getenv('CHROME_HOST') : '127.0.0.1';
		$port = getenv('CHROME_PORT') ? (int)getenv('CHROME_PORT') : 9222;
		$versionUrl = 'http://' . $host . ':' . $port . '/json/version';

		$factory = new BrowserFactory();
		$connectOptions = array(
			'sendSyncDefaultTimeout' => 20000 // ms
			// 'debugLogger' => 'php://stdout',
		);

		// Fetch via curl if available, else file_get_contents
		$metaJson = null;
		if (function_exists('curl_init')) {
			$ch = curl_init($versionUrl);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			$metaJson = curl_exec($ch);
			curl_close($ch);
		} else {
			$ctx = stream_context_create(array('http' => array('timeout' => 5)));
			$metaJson = @file_get_contents($versionUrl, false, $ctx);
		}

		$meta = $metaJson ? json_decode($metaJson, true) : null;
		if (is_array($meta) && isset($meta['webSocketDebuggerUrl'])) {
			return BrowserFactory::connectToBrowser($meta['webSocketDebuggerUrl'], $connectOptions);
		}

		try {
			return $factory->createBrowser(array_merge($connectOptions, array(
				'headless' => true,
				'noSandbox' => true,
				'startupTimeout' => 60,
				'windowSize' => array(1920, 1080),
			)));
		} catch (Exception $e) {
			$reason = 'Cannot reach Chrome DevTools at ' . $versionUrl;
			if (!is_array($meta)) {
				$reason .= ' and failed to launch local Chrome';
			} else {
				$reason .= ' and no valid websocket endpoint was returned';
			}
			throw new Exception($reason . ': ' . $e->getMessage(), 0, $e);
		}
	}

	// Resolve a possibly-relative URL against a base URL (handles protocol-relative, root, ../)
	private static function _absUrl($base, $rel)
	{
		if (!$rel) return $rel;
		$p = parse_url($base);
		if (preg_match('#^https?:#i', $rel)) return $rel;
		if (strpos($rel, '//') === 0) return $p['scheme'].':'.$rel;

		$host = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
		if ($rel[0] === '/') return $host.$rel;

		$path = isset($p['path']) ? $p['path'] : '/';
		$dir  = $host . rtrim(dirname($path), '/').'/';
		$full = $dir.$rel;

		$parts = explode('/', $full);
		$out = array();
		$i = 0; for ($i=0; $i<count($parts); $i++) {
			$seg = $parts[$i];
			if ($seg === '' || $seg === '.') continue;
			if ($seg === '..') { if (!empty($out)) array_pop($out); continue; }
			$out[] = $seg;
		}
		return '/'.implode('/', $out);
	}

	// Fetch a URL body through Websites_Fetch (http(s) only, no private
	// targets, TLS verified). False if refused or unreachable.
	private static function _fetch($url, $timeout)
	{
		try {
			$response = Websites_Fetch::get($url, array('timeout' => $timeout));
		} catch (Exception $e) {
			return false;
		}
		return ($response['status'] === 200 && !$response['truncated'])
			? $response['body']
			: false;
	}

	/**
	 * Recursively crawl CSS starting from $cssUrl:
	 *  - follow @import
	 *  - collect @font-face blocks (family, weight, style, src[] urls)
	 *  - collect every font file url (absolute or data:)
	 *
	 * @param string $cssUrl
	 * @param array  &$seen     set of visited css urls
	 * @param array  &$fetched  map cssUrl => byte length
	 * @param array  &$faces    list of parsed font-face blocks
	 * @param array  &$fontSet  set of font file urls (url => true)
	 */
	private static function _crawlCss($cssUrl, &$seen, &$fetched, &$faces, &$fontSet)
	{
		if (isset($seen[$cssUrl])) return;
		$seen[$cssUrl] = true;

		$css = self::_fetch($cssUrl, 12);
		if (!is_string($css) || $css === '') return;

		$fetched[$cssUrl] = strlen($css);

		// 1) Follow @import (url(...) or "...")
		if (preg_match_all('#@import\s+(?:url\(\s*([^\)]+)\s*\)|([\'"])(.*?)\2)\s*[^;]*;#i', $css, $imports, PREG_SET_ORDER)) {
			$i = 0; for ($i=0; $i<count($imports); $i++) {
				$raw = isset($imports[$i][1]) && $imports[$i][1] ? $imports[$i][1] : $imports[$i][3];
				$raw = trim($raw, " \t\n\r\0\x0B\"'");
				$child = self::_absUrl($cssUrl, $raw);
				self::_crawlCss($child, $seen, $fetched, $faces, $fontSet);
			}
		}

		// 2) Parse @font-face blocks
		if (preg_match_all('#@font-face\s*\{(.*?)\}#is', $css, $blocks, PREG_SET_ORDER)) {
			$j = 0; for ($j=0; $j<count($blocks); $j++) {
				$block = $blocks[$j][1];

				// Extract descriptors
				$family = null; $style = null; $weight = null; $srcRaw = null;

				if (preg_match('#font-family\s*:\s*([^;]+);#i', $block, $m)) {
					$family = trim($m[1]);
					$family = trim($family, "\"' \t\r\n");
				}
				if (preg_match('#font-style\s*:\s*([^;]+);#i', $block, $m)) {
					$style = trim($m[1]);
				}
				if (preg_match('#font-weight\s*:\s*([^;]+);#i', $block, $m)) {
					$weight = trim($m[1]);
				}
				if (preg_match('#src\s*:\s*([^;]+);#is', $block, $m)) {
					$srcRaw = $m[1];
				}

				// Extract all url(...) inside src (or whole block, to catch multiple src locations)
				$urlsBlock = $srcRaw ? $srcRaw : $block;
				$srcs = array();
				if (preg_match_all('#url\(\s*([^\)]+)\s*\)#i', $urlsBlock, $uMatches)) {
					$k = 0; for ($k=0; $k<count($uMatches[1]); $k++) {
						$u = trim($uMatches[1][$k], " \t\n\r\0\x0B\"'");
						if (stripos($u, 'data:') === 0) {
							// data: URI — keep as is
							$srcs[] = $u;
							$fontSet[$u] = true;
						} else {
							// resolve relative to current CSS file
							$abs = self::_absUrl($cssUrl, $u);
							$srcs[] = $abs;
							$fontSet[$abs] = true;
						}
					}
				}

				$faces[] = array(
					'family' => $family,
					'style'  => $style,
					'weight' => $weight,
					'src'    => $srcs
				);
			}
		}
	}

	/**
	 * Generate a variable-only CSS file from analyze() output.
	 *
	 * @method generateThemeCss
	 * @static
	 * @param {array} $analysis Output of Websites_Webpage::analyze()
	 * @param {array} [$options]
	 * @param {string} [$options.scope=':root']
	 * @param {boolean} [$options.includeFonts=true]
	 * @param {integer} [$options.maxFonts=6]
	 * @param {string} [$options.baseFontFamily]
	 * @return {string} CSS text
	 */
	public static function generateThemeCss($analysis, $options = array())
	{
		$scope = isset($options['scope']) ? $options['scope'] : ':root';
		$includeFonts = array_key_exists('includeFonts', $options)
			? (bool)$options['includeFonts'] : true;
		$maxFonts = isset($options['maxFonts']) ? (int)$options['maxFonts'] : 6;
		$baseFontFamily = isset($options['baseFontFamily'])
			? $options['baseFontFamily']
			: 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

		$get = function ($arr) {
			$args = func_get_args();
			array_shift($args);
			foreach ($args as $k) {
				if (!is_array($arr) || !array_key_exists($k, $arr)) return null;
				$arr = $arr[$k];
			}
			return $arr;
		};

		$q = function ($s) {
			$s = trim($s);
			if ($s === '') return $s;
			if (preg_match('/^[a-zA-Z0-9\-]+$/', $s)) return $s;
			return '"' . str_replace('"', '\\"', $s) . '"';
		};

		$normalizeFamily = function ($family) use ($q, $baseFontFamily) {
			if (!$family) return $baseFontFamily;
			$primary = trim(strtok($family, ','));
			$primary = trim($primary, "\"' \t\r\n");
			if (!$primary) return $baseFontFamily;
			return $q($primary) . ', ' . $baseFontFamily;
		};

		// Colors
		$fg = $get($analysis, 'bodyForeground');
		if (!$fg) $fg = $get($analysis, 'colorRoles', 'foreground', 0, 'hex');
		if (!$fg) $fg = '#222222';

		$bg = $get($analysis, 'bodyBackground');
		if (!$bg) $bg = $get($analysis, 'colorRoles', 'background', 0, 'hex');
		if (!$bg) $bg = '#ffffff';

		$brand = $get($analysis, 'brandColor');
		if (!$brand) $brand = $get($analysis, 'colorRoles', 'foreground', 1, 'hex');
		if (!$brand) $brand = '#4a90e2';

		$link = $get($analysis, 'linkColor');
		if (!$link) $link = $brand;

		$accent2 = $get($analysis, 'colorRoles', 'foreground', 2, 'hex');
		if (!$accent2) $accent2 = '#e67e22';

		$nav = $get($analysis, 'detectedNav');
		$navFg = is_array($nav) && isset($nav['color']) && $nav['color'] ? $nav['color'] : $fg;
		$navBg = is_array($nav) && isset($nav['backgroundColor']) && $nav['backgroundColor'] ? $nav['backgroundColor'] : $bg;

		// Fonts
		$bodyFamily = $normalizeFamily($get($analysis, 'bodyFont'));
		$headingFamily = $normalizeFamily($get($analysis, 'headingFont'));
		if (!$get($analysis, 'bodyFont') && !$get($analysis, 'headingFont')) {
			// Fallback to legacy 'fonts' array
			$fonts = $get($analysis, 'fonts');
			if (is_array($fonts) && count($fonts)) {
				$bodyFamily = $normalizeFamily($fonts[0]);
				$headingFamily = $bodyFamily;
			}
		}

		$bodyWeight = $get($analysis, 'bodyFontWeight');
		$headingWeight = $get($analysis, 'headingFontWeight');
		if (!$bodyWeight) $bodyWeight = '400';
		if (!$headingWeight) $headingWeight = '700';

		$vars = "/* Theme variables generated " . date('c') . " */\n";
		$vars .= $scope . " {\n";
		// New names
		$vars .= "  --theme-fg: {$fg};\n";
		$vars .= "  --theme-bg: {$bg};\n";
		$vars .= "  --theme-brand: {$brand};\n";
		$vars .= "  --theme-link: {$link};\n";
		$vars .= "  --theme-nav-bg: {$navBg};\n";
		$vars .= "  --theme-nav-fg: {$navFg};\n";
		$vars .= "  --theme-font-body: {$bodyFamily};\n";
		$vars .= "  --theme-font-heading: {$headingFamily};\n";
		$vars .= "  --theme-font-weight-body: {$bodyWeight};\n";
		$vars .= "  --theme-font-weight-heading: {$headingWeight};\n";
		// Aliases for backward compatibility with the previous version
		$vars .= "  --theme-accent-1: {$brand};\n";
		$vars .= "  --theme-accent-2: {$accent2};\n";
		$vars .= "  --theme-font: {$bodyFamily};\n";
		$vars .= "}\n\n";

		$fontBlocks = array();
		if ($includeFonts) {
			$faces = $get($analysis, '_assets', 'fontFaces');
			if (is_array($faces)) {
				$c = 0;
				foreach ($faces as $face) {
					if ($c++ >= $maxFonts) break;
					$fam = $q((string)$get($face, 'family'));
					if (!$fam) continue;
					$style = $get($face, 'style') ? $get($face, 'style') : 'normal';
					$weight = $get($face, 'weight') ? $get($face, 'weight') : '400';
					$srcs = $get($face, 'src');
					$urls = array();
					if (is_array($srcs)) {
						foreach ($srcs as $u) {
							if (!is_string($u) || !$u) continue;
							$urls[] = "url('" . $u . "')";
						}
					}
					if (count($urls)) {
						$fontBlocks[] = "@font-face {\n"
							. "  font-family: {$fam};\n"
							. "  font-style: {$style};\n"
							. "  font-weight: {$weight};\n"
							. "  src: " . implode(",\n       ", $urls) . ";\n"
							. "}";
					}
				}
			}
		}
		if (count($fontBlocks)) $vars .= implode("\n\n", $fontBlocks) . "\n";

		return $vars;
	}

	/**
	 * Get the filesystem path to the theme CSS for a URL+formFactor.
	 * If the file doesn't exist, or if $options['reanalyze'] is true, scrapes
	 * inline (blocking), writes the file, then returns the path.
	 *
	 * @method getThemeCssPath
	 * @static
	 * @param {string} $url The customer URL whose theme to extract
	 * @param {string} $formFactor One of 'mobile', 'tablet', 'desktop'
	 * @param {array} [$options]
	 * @param {boolean} [$options.reanalyze=false] Force a fresh scrape
	 * @return {string} Filesystem path to the generated CSS file
	 * @throws Exception If scrape fails
	 */
	static function getThemeCssPath($url, $formFactor, $options = array())
	{
		$reanalyze = !empty($options['reanalyze']);
		$dir = self::themeDir($url);
		$cssPath = $dir . DS . $formFactor . '.css';
		$errPath = $dir . DS . $formFactor . '.error';

		if (!is_dir($dir)) {
			Q_Utils::canWriteToPath($dir, null, true);
		}

		// Respect a recent failure marker unless explicitly re-analyzing
		if (!$reanalyze && is_file($errPath)) {
			$errTtl = (int)Q_Config::get('Websites', 'theme', 'errorCacheSeconds', 300);
			$age = time() - filemtime($errPath);
			if ($age < $errTtl) {
				throw new Exception(
					"Websites_Webpage: recent scrape failure for $url ($formFactor); "
					. "wait " . ($errTtl - $age) . "s or pass reanalyze=1"
				);
			}
			@unlink($errPath);
		}

		if (!is_file($cssPath) || $reanalyze) {
			try {
				self::_doScrapeAndWrite($url, $formFactor, $cssPath);
			} catch (Exception $e) {
				// Record the failure so we don't immediately retry
				@file_put_contents($errPath, $e->getMessage());
				throw $e;
			}
		}

		return $cssPath;
	}

	/**
	 * Compute the per-URL theme directory.
	 *
	 * Default: $uploads/Websites/theme/$host/
	 *
	 * One theme per site is the right default — restaurants and small businesses
	 * have a single brand across all pages. Per-page theming is rare enough to
	 * not warrant complicating the default key.
	 *
	 * To override for the rare multi-tenant case (one host, multiple brands),
	 * hook 'Websites/Webpage/themeDir' with 'before' and return a different
	 * absolute path. The hook receives the full $url so it can route based on
	 * path, query, subdomain, or anything else it cares about.
	 *
	 * @method themeDir
	 * @static
	 * @param {string} $url
	 * @return {string} Absolute filesystem path (may not yet exist)
	 */
	static function themeDir($url)
	{
		$result = Q::event('Websites/Webpage/themeDir', compact('url'), 'before');
		if (isset($result)) {
			return $result;
		}

		$parts = parse_url($url);
		$host = isset($parts['host']) ? strtolower($parts['host']) : 'unknown';
		$host = preg_replace('/[^a-z0-9.\-]/', '', $host);
		if ($host === '') $host = 'unknown';

		return APP_FILES_DIR . DS . Q::app() . DS . 'uploads'
			. DS . 'Websites' . DS . 'theme'
			. DS . $host;
	}

	/**
	 * Internal: perform the scrape and write the CSS file.
	 * @method _doScrapeAndWrite
	 * @static
	 * @protected
	 * @param {string} $url
	 * @param {string} $formFactor
	 * @param {string} $cssPath
	 */
	protected static function _doScrapeAndWrite($url, $formFactor, $cssPath)
	{
		$viewports = Q_Config::get('Websites', 'theme', 'viewports', array(
			'mobile' => array('width' => 375, 'height' => 812),
			'tablet' => array('width' => 768, 'height' => 1024),
			'desktop' => array('width' => 1440, 'height' => 900)
		));
		if (empty($viewports[$formFactor])) {
			throw new Exception("Websites_Webpage: unknown formFactor: $formFactor");
		}

		$analysis = self::analyze($url, array(
			'viewport' => $viewports[$formFactor]
		));

		$scope = Q_Config::get('Websites', 'theme', 'scope', ':root');
		$css = self::generateThemeCss($analysis, array(
			'scope' => $scope,
			'includeFonts' => true,
			'maxFonts' => (int)Q_Config::get('Websites', 'theme', 'maxFonts', 6)
		));

		$dir = dirname($cssPath);
		if (!is_dir($dir)) {
			Q_Utils::canWriteToPath($dir, null, true);
		}
		if (false === @file_put_contents($cssPath, $css, LOCK_EX)) {
			throw new Exception("Websites_Webpage: could not write $cssPath");
		}
	}
}
