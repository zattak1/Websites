<?php

/**
 * @module Websites
 */
class Websites_Exception_UnsafeUrl extends Q_Exception
{
	/**
	 * Raised when a URL the server was asked to fetch is not http(s), or its
	 * host resolves to a private, loopback, link-local, shared, reserved or
	 * multicast address (directly or through a redirect).
	 * @class Websites_Exception_UnsafeUrl
	 * @constructor
	 * @extends Q_Exception
	 */

	/**
	 * Why the URL was refused, for logs and tests. Not in the message or
	 * params, which reach the client.
	 * @property $reason
	 * @type string
	 */
	public $reason = null;
};

// No {{reason}}: which check failed is logged, never sent to the client,
// or a link preview would tell a user which internal hosts exist.
Q_Exception::add('Websites_Exception_UnsafeUrl', 'Refusing to fetch {{url}}');
