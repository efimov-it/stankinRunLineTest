<?php
$meta_tags = json_decode(file_get_contents('Engine/meta.json'), true);
foreach ($meta_tags as &$tag) {
	$content = "<meta ";
	foreach ($tag as $key => $value) {
		$content .= $key . '="' . $value . '" ';
	}
	$tag = $content . '>';
}
$meta_tags = join("\n", $meta_tags);

require_once 'index.html';
