<?php
require_once __DIR__ . '/../vendor/autoload.php';
$options = [
      'cluster' => 'ap1',
      'useTLS' => true
  ];
$pusher = new Pusher\Pusher(
      '94c733c57de196bc6fcb',
      '73909772ee0d8293998a',
      '1880468',
      $options
  );
?>
