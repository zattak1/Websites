<?php

/**
 * @module Websites
 */
class Websites_Exception_UnsafeUrl extends Q_Exception_UnsafeUrl
{
	/**
	 * Raised when a URL the server was asked to fetch is not http(s), or its
	 * host resolves to a private, loopback, link-local, shared, reserved or
	 * multicast address (directly or through a redirect).
	 * @class Websites_Exception_UnsafeUrl
	 * @constructor
	 * @extends Q_Exception_UnsafeUrl
	 */
};

// No {{reason}}: which check failed is logged, never sent to the client,
// or Websites/scrape would tell a member which internal hosts exist (ro#1035)
Q_Exception::add('Websites_Exception_UnsafeUrl', 'Refusing to fetch {{url}}');
