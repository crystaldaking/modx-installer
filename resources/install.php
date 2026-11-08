<?php

// Runs only in the PHP container. Secrets arrive over stdin, never in argv or the webroot.
declare(strict_types=1);

$config = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (is_file('/var/www/html/core/config/config.inc.php')) {
    fwrite(STDERR, "MODX configuration already exists. Refusing to reinstall over existing data.\n");
    exit(1);
}
$settings = [
    'database_type' => 'mysql', 'database_server' => 'db',
    'database' => $config['DB_NAME'], 'database_user' => $config['DB_USER'],
    'database_password' => $config['DB_PASSWORD'],
    'database_connection_charset' => 'utf8mb4', 'database_charset' => 'utf8mb4',
    'database_collation' => 'utf8mb4_unicode_ci', 'table_prefix' => 'modx_',
    'http_host' => 'localhost:'.$config['HTTP_PORT'], 'https_port' => '443',
    'inplace' => '1', 'unpacked' => '0', 'language' => $config['MODX_LANGUAGE'] ?? 'en',
    'cmsadmin' => $config['ADMIN_USER'], 'cmspassword' => $config['ADMIN_PASSWORD'],
    'cmsadminemail' => $config['ADMIN_EMAIL'], 'core_path' => '/var/www/html/core/',
    'context_mgr_path' => '/var/www/html/manager/', 'context_mgr_url' => '/manager/',
    'context_connectors_path' => '/var/www/html/connectors/', 'context_connectors_url' => '/connectors/',
    'context_web_path' => '/var/www/html/', 'context_web_url' => '/',
    'remove_setup_directory' => '0',
];
$xml = new DOMDocument('1.0', 'UTF-8');
$root = $xml->appendChild($xml->createElement('modx'));
foreach ($settings as $key => $value) {
    $root->appendChild($xml->createElement($key))->appendChild($xml->createTextNode($value));
}
$file = tempnam(sys_get_temp_dir(), 'modx-install-');
if ($file === false) {
    throw new RuntimeException('Cannot create a private installer config.');
}
chmod($file, 0600);
register_shutdown_function(static function () use ($file): void { @unlink($file); });
file_put_contents($file, $xml->saveXML());
// MODX calls exit() even on some failures. The host always verifies the result separately.
chdir('/var/www/html/setup');
$_SERVER['argv'] = ['index.php', '--installmode=new', '--config='.$file];
$_SERVER['argc'] = count($_SERVER['argv']);
$argv = $_SERVER['argv'];
$argc = $_SERVER['argc'];
require '/var/www/html/setup/index.php';
