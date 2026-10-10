<?php
	
function Websites_webpage_response_start($params)
{
	Q_Valid::nonce(true);

	$userId = Q::ifset($params, 'userId', Users::loggedInUser(true)->id);
	$currentCommunity = Users::currentCommunityId(true);

	$r = array_merge($_REQUEST, $params);

	$message = Q::ifset($r, 'message', null);

	// ro#931: only the url comes from the request. createStream() also reads
	// "asUserId" and "publisherId" from its params, which may come only from
	// a server-side caller's $params (see Websites/webpage POST).
	$stream = Websites_Webpage::createStream(array_merge(
		Q::take($params, array('asUserId', 'publisherId')),
		array('url' => Q::ifset($r, 'data', 'url', null))
	), 'Websites/webpage/conversation');

	if (!$stream) {
		throw new Exception("stream not found");
	}

	$communityId = Q::ifset($r, 'categoryStream', 'publisherId', $currentCommunity);
	$mainChatCategory = Q::ifset($r, 'categoryStream', 'streamName', 'Streams/chats/main');
	$chatRelationType = Q::ifset($r, 'relationType', 'Websites/webpage');

	// if this stream already related, exit
	if (!Streams_RelatedTo::select()->where(array(
		'toPublisherId' => $communityId,
		'toStreamName' => $mainChatCategory,
		'type' => $chatRelationType,
		'fromPublisherId' => $stream->publisherId,
		'fromStreamName' => $stream->name
	))->fetchDbRows()) {
		// ro#931: the category is the caller's choice, so relate as the
		// acting user with the access check, not skipAccess (see
		// Websites/webpage POST).
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

	return array(
		'publisherId' => $stream->publisherId,
		'streamName' => $stream->name
	);
}