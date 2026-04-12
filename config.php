<?php

$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
           (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') 
           ? "https" : "http";

$host = $_SERVER['HTTP_HOST'];
$base_url = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) 
            ? "$protocol://$host/connectxion" 
            : "$protocol://$host";

$clean_host = explode(':', $host)[0];

if ($clean_host === 'localhost' || $clean_host === '127.0.0.1') {
    $socket_url = "http://localhost:3000";
    $node_api_url = "http://localhost:3000/api/notify";
} else {
    
    $socket_url = getenv('SOCKET_URL') ?: "https://" . getenv('RAILWAY_PUBLIC_DOMAIN'); 
    $node_api_url = getenv('NODE_API_URL') ?: "http://" . getenv('RAILWAY_PRIVATE_DOMAIN') . ":3000/api/notify";
}

if (!defined('BASE_URL')) define('BASE_URL', $base_url);
if (!defined('SOCKET_URL')) define('SOCKET_URL', $socket_url);

if (!defined('NODE_API_URL')) define('NODE_API_URL', $node_api_url);
?>
