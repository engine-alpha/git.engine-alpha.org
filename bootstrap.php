<?php

namespace App;

use Aerys\Host;
use Aerys\Request;
use Aerys\Response;
use Amp\Artax\Client;
use Amp\Artax\Cookie\NullCookieJar;
use Amp\Beanstalk\BeanstalkClient;
use Amp\Cache\ArrayCache;
use Auryn\Injector;
use Kelunik\StatsD\StatsD;
use function Amp\info;
use function Amp\repeat;

$config = json_decode(file_get_contents(__DIR__ . "/config.json"), true);

$injector = new Injector;

$injector->define(StatsD::class, [
    ":server" => "127.0.0.1",
    ":port" => 8125,
]);

$injector->define(HookSecret::class, [
    ":secret" => $config["github.secret"],
]);

$injector->define(BeanstalkClient::class, [
    ":uri" => $config["beanstalk"],
]);

$stats = $injector->make(StatsD::class);

$router = \Aerys\router()
    ->route("POST", "hook/{owner}/{repository}", $injector->make(Hook::class));

(new Host)
    ->name("git.engine-alpha.org")
    ->expose("*", $config["app.port"] ?? 80)
    ->use(function () use ($stats) {
        $stats->increment("aerys.git.request");
    })
    ->use($router);

$http = new Client(new NullCookieJar);
$cache = new ArrayCache;

$router = \Aerys\router()
    ->route("GET", "latest{path:(?:/.*)?}", function (Request $request, Response $response, array $args) use ($http, $cache) {
        $version = yield $cache->get("latest.version");
        $versionUrl = "http://engine-alpha.org/wiki/Vorlage:Download-Version?action=raw";

        if (!$version) {
            /** @var \Amp\Artax\Response $httpResponse */
            $httpResponse = yield $http->request($versionUrl);
            $version = trim($httpResponse->getBody());

            $cache->set("latest.version", $version, 3600);
        }

        $path = empty($args["path"]) ? "/" : $args["path"];

        $response->setStatus(302);
        $response->setHeader("location", "/{$version}{$path}");
        $response->end();
    });

(new Host)
    ->name("docs.engine-alpha.org")
    ->expose("*", $config["app.port"] ?? 80)
    ->use(function () use ($stats) {
        $stats->increment("aerys.docs.request");
    })
    ->use($router);

repeat(function () use ($stats) {
    $info = info();

    foreach (["immediately", "once", "repeat", "on_writable", "on_signal"] as $event) {
        foreach (["enabled", "disabled"] as $type) {
            $stats->gauge("amp.watchers.{$event}.{$type}", $info[$event][$type]);
        }
    }
}, 10000);