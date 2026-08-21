<?php

declare(strict_types=1);

require __DIR__ . '/RiskCalculator.php';
require __DIR__ . '/Bridge/CoroutineRequestContext.php';
require __DIR__ . '/Bridge/CoroutineResponseContext.php';
require __DIR__ . '/Bridge/SwooleRequest.php';
require __DIR__ . '/Bridge/PhalconApp.php';

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Coroutine\Channel;

$host = getenv('HOST') ?: '0.0.0.0';
$port = (int) (getenv('PORT') ?: 9503);
$workers = (int) (getenv('SWOOLE_WORKERS') ?: swoole_cpu_num());
$redisHost = getenv('REDIS_HOST') ?: 'redis';
$redisPort = (int) (getenv('REDIS_PORT') ?: 6379);
$redisPoolSize = (int) (getenv('REDIS_POOL_SIZE') ?: 16);

$server = new Server($host, $port);
$server->set([
    'worker_num' => $workers,
    'enable_coroutine' => true,
    'http_compression' => false,
    // Igual ao php-swoole "puro": deixa o runtime hookar I/O bloqueante
    // (phpredis incluso) para rodar como coroutine sem travar o worker.
    'hook_flags' => SWOOLE_HOOK_ALL,
]);

/**
 * Pool de conexões Redis por worker (idêntico em desenho ao php-swoole
 * "puro" — ver comentário lá) e o app Phalcon Micro, ambos construídos
 * UMA VEZ em workerStart e retidos em memória pelo resto da vida do
 * processo. Isso é o "worker mode com bridge própria" pedido: nenhuma
 * requisição reconstrói DI, rotas ou conexões — só passa por elas.
 */
$redisPool = new Channel($redisPoolSize);
$app = null; // \Phalcon\Mvc\Micro, atribuído no workerStart

$server->on('workerStart', function () use ($redisPool, $redisPoolSize, $redisHost, $redisPort, &$app) {
    for ($i = 0; $i < $redisPoolSize; $i++) {
        $redis = new \Redis();
        $redis->connect($redisHost, $redisPort);
        $redisPool->push($redis);
    }

    $app = PhalconApp::build($redisPool);
});

$server->on('request', function (Request $req, Response $res) use (&$app) {
    $uri = $req->server['request_uri'] ?? '/';

    // Associa a Swoole\Http\Request atual à coroutine que está processando
    // esta requisição. É o que permite ao SwooleRequest (bridge, instância
    // única por worker) resolver getRawBody()/getHeader()/etc corretamente
    // por requisição, sem depender de superglobais que não existem por
    // coroutine no SAPI do Swoole.
    CoroutineRequestContext::set($req);

    $returned = $app->handle($uri);

    if (!$returned instanceof \Phalcon\Http\ResponseInterface) {
        // Não deveria acontecer com o responseHandler configurado em
        // PhalconApp::build() — todo handler de rota devolve uma Response.
        $res->status(500);
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode(['error' => 'internal: unexpected handler return type']));
        return;
    }

    $res->status($returned->getStatusCode() ?? 200);
    foreach ($returned->getHeaders()->toArray() as $name => $value) {
        if ($value !== null && $value !== '') {
            $res->header((string) $name, (string) $value);
        }
    }
    $res->end($returned->getContent());
});

$server->start();
