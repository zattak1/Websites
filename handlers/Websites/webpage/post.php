<?php
	
function Websites_webpage_post($params)
{
	Q_Valid::nonce(true);

	$request = array_merge($_REQUEST, $params);

	// add scheme to url if not exist
	$parts = explode('#', $request['url']);
	$request['url'] = reset($parts);
	if (parse_url($request['url'], PHP_URL_SCHEME) === null) {
		$request['url'] = 'http://'.$request['url'];
	}
	// ro#931: createStream() reads "asUserId" (who gets max access to the
	// stream) and "publisherId" (who publishes it) from its params, so the
	// merged request let any caller create a webpage stream as any user.
	// Those two may come only from a server-side caller's $params; the
	// request supplies the url alone.
	$stream = Websites_Webpage::createStream(array_merge(
		Q::take($params, array('asUserId', 'publisherId')),
		array('url' => $request['url'])
	));

	Q_Response::setSlot('publisherId', $stream->publisherId);
	Q_Response::setSlot('streamName', $stream->name);

	// this action for conversation composer
	// need to relate webpage stream to Streams/chats/main and create first chat message
	if (Q::ifset($request, 'action', null) == 'start') {
		$message = Q::ifset($request, 'message', null);
		if (!$stream) {
			throw new Exception("stream not found");
		}

		// ro#931: the acting user is the logged-in one (or a server-side
		// caller's $params), never a request "userId", which let the caller
		// author the first message as anyone.
		$userId = Q::ifset($params, 'userId', Users::loggedInUser(true)->id);
		$currentCommunity = Users::currentCommunityId(true);

		$communityId = Q::ifset($request, 'categoryStream', 'publisherId', $currentCommunity);
		$mainChatCategory = Q::ifset($request, 'categoryStream', 'streamName', 'Streams/chats/main');
		$chatRelationType = Q::ifset($request, 'categoryStream', 'relationType', 'Websites/webpage');

		// if this stream already related, exit
		if (!Streams_RelatedTo::select()->where(array(
			'toPublisherId' => $communityId,
			'toStreamName' => $mainChatCategory,
			'type' => $chatRelationType,
			'fromPublisherId' => $stream->publisherId,
			'fromStreamName' => $stream->name
		))->fetchDbRows()) {
			// ro#931: the category is the caller's choice, so relate as the
			// acting user WITH the access check (write level "relate" on the
			// category); skipAccess let a caller relate into any publisher's
			// category. Both in-tree callers still pass: a community's
			// Streams/chats/main is public writeLevel 23
			// (Communities/config/streams.json), and Streams/user/urls is
			// the caller's own.
			Streams::relate(
				$userId,
				$communityId,
				$mainChatCategory,
				$chatRelationType,
				$stream->publisherId,
				$stream->name,
				array(
					'weight' => time()
				)
			);
		}

		// if $message not empty, set it as first message to chat
		if (!empty($message)) {
			Streams_Message::post(
				$userId,
				$stream->publisherId,
				$stream->name,
				array(
					'type' => "Streams/chat/message",
					'content' => $message
				)
			);
		}
	}

	return $stream;
}