<?php

/** Generate isolated browser fixtures from the real WordPress renderers. */
declare(strict_types=1);

require dirname(__DIR__) . '/Integration/bootstrap.php';

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Integrations\Integration;
use JotformBridge\Plugin;
use JotformBridge\Rendering\Assets;
use JotformBridge\Tests\Integration\Runtime;

Runtime::newRequest();
$plugin = Plugin::instance();
$questions = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/Jotform/form-questions.json'), true);
add_filter('pre_http_request', static function ($pre, $args, $url) use ($questions) {
    if (strpos($url, '/questions') === false) {
        throw new RuntimeException('Unexpected HTTP request in browser fixture generator.');
    }
    return ['headers' => [], 'body' => json_encode($questions), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => []];
}, 10, 3);
$rules = [
    ['action' => 'show', 'target' => 'message', 'source' => 'preferred_contact', 'operator' => 'equals', 'value' => 'E-mail'],
    ['action' => 'require', 'target' => 'address.city', 'source' => 'preferred_contact', 'operator' => 'equals', 'value' => 'Phone'],
    ['action' => 'show', 'target' => 'topics_of', 'source' => 'preferred_contact', 'operator' => 'equals', 'value' => 'Phone'],
];
$plugin->schemas()->sync('240000000000001');
$plugin->integrations()->save(Integration::fromInput(['name' => 'Contact', 'slug' => 'contact', 'form_id' => '240000000000001', 'mode' => 'auto', 'conditions' => $rules]));
$html = jotform_bridge_render('contact');
$data = wp_scripts()->get_data(Assets::HANDLE, 'data');
$root = dirname(__DIR__, 2);
file_put_contents(dirname(__DIR__) . '/_output/conditional-frontend.html', '<!doctype html><html lang="en"><meta charset="utf-8"><title>Conditional form smoke test</title>' . $html . '<script>' . $data . '</script><script>' . file_get_contents($root . '/jotform-bridge/assets/frontend.js') . '</script></html>');

$users = get_users(['role' => 'administrator', 'number' => 1]);
wp_set_current_user($users[0]->ID);
require_once ABSPATH . 'wp-admin/includes/template.php';
$_GET = ['page' => 'jotform-bridge', 'view' => 'edit', 'integration' => 'contact'];
$page = new IntegrationsPage($plugin->integrations(), $plugin->forms(), $plugin->schemas(), $plugin->templates(), $plugin->compatibility());
ob_start();
$page->render();
$admin = ob_get_clean();
file_put_contents(dirname(__DIR__) . '/_output/conditional-admin.html', '<!doctype html><html lang="en"><meta charset="utf-8"><title>Conditional rules smoke test</title><style>' . file_get_contents($root . '/jotform-bridge/assets/admin.css') . '</style>' . $admin . '<script>window.jotformBridgeAdmin = {messages: {}};</script><script>' . file_get_contents($root . '/jotform-bridge/assets/admin.js') . '</script></html>');
echo "Browser fixtures generated in tests/_output/. All upstream requests were mocked.\n";
