<?php
require 'vendor/autoload.php';

use Revolt\EventLoop;

EventLoop::defer(function () {
    echo "Amp loop is working!\n";
});

EventLoop::run();
