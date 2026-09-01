<?php

function Websites_1_0_8_Streams_mysql()
{
	$communityId = Users::communityId();
	Streams::create($communityId, $communityId, 'Streams/category', array(
        'name' => 'Websites/bios'
    ));
}

Websites_1_0_8_Streams_mysql();