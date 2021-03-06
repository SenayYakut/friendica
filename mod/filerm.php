<?php

use Friendica\App;
use Friendica\Core\Logger;
use Friendica\Core\System;
use Friendica\Model\FileTag;

function filerm_content(App $a)
{
	if (! local_user())
	{
		killme();
	}

	$term = unxmlify(trim($_GET['term']));
	$cat = unxmlify(trim($_GET['cat']));

	$category = (($cat) ? true : false);

	if ($category)
	{
		$term = $cat;
	}

	$item_id = (($a->argc > 1) ? intval($a->argv[1]) : 0);

	Logger::log('filerm: tag ' . $term . ' item ' . $item_id);

	if ($item_id && strlen($term))
	{
		FileTag::unsaveFile(local_user(), $item_id, $term, $category);
	}

	killme();
}
