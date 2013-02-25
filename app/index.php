<?php

require_once __DIR__.'/../vendor/autoload.php';

require_once __DIR__.'/lib/Report.php';

$app = new Silex\Application();

$app['debug'] = true;
ini_set('display_errors','on');

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));

$app->get('/', function() use ($app){

	$report = new Report;
	$report->load(__DIR__ . '/reports/report.xml');
	$report->compute();
	echo $report->renderAsHTML();

	//$report->sendAsExcel();

	return $app['twig']->render('index.html.twig', array());
})->bind('home');

$app->run();