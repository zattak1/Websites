<?php

function Websites_0_9_3_Streams_mysql()
{
	echo "Adding Websites/advert/xxx streams...\n";

	$suffixes = array('units', 'placements', 'creatives', 'campaigns');

	$q = Users_User::select('*')
		->orderBy('id', true)
		->nextChunk(array(
			'chunkSize' => 100,
			'index' => 'id'
		));

	$i = 0;
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);

	while ($rows) {

		foreach ($rows as $r) {
			$userId = $r['id'];

			foreach ($suffixes as $suffix) {
				$streamName = "Websites/advert/$suffix";

				if (Streams_Stream::select()
					->where(array(
						'publisherId' => $userId,
						'name' => $streamName
					))
					->fetchDbRow()
				) {
					continue;
				}

				Streams::create(
					$userId,
					$userId,
					'Streams/category',
					array('name' => $streamName)
				);
			}

			++$i;
			echo "\033[100D";
			echo "Added streams for $i users";
		}

		// advance cursor
		$last = end($rows);
		$q->lastChunkValue = $last['id'];
		$q->nextChunk();

		$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	}

	echo PHP_EOL;
}

Websites_0_9_3_Streams_mysql();