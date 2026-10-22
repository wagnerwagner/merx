<?php

$config = Kirby\PhpCs\Config::create();

return $config
	->setRules([
		...$config->getRules(),
		'trailing_comma_in_multiline' => [
			'elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters'],
		],
	])
	->setFinder(
		PhpCsFixer\Finder::create()
			->exclude('dependencies')
			->in(__DIR__),
	);
