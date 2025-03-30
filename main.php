#!/usr/bin/env php
<?php

/**
 * @file
 * The application entrypoint.
 */

use ClayFreeman\Twitch\EventSub\RelayCommand;
use Composer\InstalledVersions;
use Symfony\Component\Console\Application;

require_once $_composer_autoload_path ?? __DIR__ . '/vendor/autoload.php';

$package = 'clayfreeman/twitch-eventsub-relay';
$version = InstalledVersions::getPrettyVersion($package);

$app = new Application('twitch-eventsub-relay', $version);

$command = new RelayCommand();
$command->setName('twitch-eventsub-relay');

$app->add($command);
$app->setDefaultCommand($command->getName(), TRUE);

$app->run();
