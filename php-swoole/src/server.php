<?php

declare(strict_types=1);

require __DIR__ . '/RiskCalculator.php';

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Coroutine\Channel;

$host = getenv('HOST') ?: '0.0.0.0';
$port = (int) (getenv('PORT') ?: 9501);
$workers = (int) (getenv('SWOOLE_WORKERS') ?: swoole_cpu_num());
$redisHost = getenv('REDIS_HOST') ?: 'redis';
$redisPort = (int) (getenv('REDIS_PORT') ?: 6379);
$redisPoolSize = (int) (getenv('REDIS_POOL_SIZE') ?: 16);

$server = new Server($host, $port);
$server->set([
    'worker_num' => $workers,
    'enable_coroutine' => true,
    'http_compression' => false,
    // O client Swoole\Coroutine\Redis nativo foi removido a partir do
    // Swoole 5.x. A forma atual é usar o phpredis (ext-redis) normal e
    // deixar o runtime do worker "hookar" as chamadas de I/O bloqueantes
    // (incluindo o phpredis) para rodarem como coroutine por baixo dos
    // panos, sem travar o worker inteiro.
    'hook_flags' => SWOOLE_HOOK_ALL,
]);

/**
 * Pool de conexões Redis por worker, coroutine-safe via Channel.
 *
 * Abrir uma conexão TCP nova a cada request (connect + close) esgota as
 * portas efêmeras do container sob carga alta ("Cannot assign requested
 * address"). O pool mantém N conexões persistentes por worker; pop()
 * bloqueia a coroutine atual até haver uma conexão livre, sem travar o
 * worker inteiro.
 */
$redisPool = new Channel($redisPoolSize);

$server->on('workerStart', function () use ($redisPool, $redisPoolSize, $redisHost, $redisPort) {
    for ($i = 0; $i < $redisPoolSize; $i++) {
        $redis = new \Redis();
        $redis->connect($redisHost, $redisPort);
        $redisPool->push($redis);
    }
});

$server->on('request', function (Request $req, Response $res) use ($redisPool) {
    $uri = $req->server['request_uri'] ?? '/';
    $method = $req->server['request_method'] ?? 'GET';

    if ($uri === '/health') {
        $res->header('Content-Type', 'application/json');
        $res->end(json_encode(['status' => 'ok', 'engine' => 'php-swoole']));
        return;
    }

    if ($method !== 'POST' || $uri !== '/analyze') {
        $res->status(404);
        $res->end(json_encode(['error' => 'not found']));
        return;
    }

    $start = microtime(true);

    $payload = json_decode($req->getContent() ?: '', true);
    if (!is_array($payload)) {
        $res->status(400);
        $res->end(json_encode(['error' => 'invalid json body']));
        return;
    }

    try {
        $applicant = RiskCalculator::validate($payload);
    } catch (InvalidArgumentException $e) {
        $res->status(422);
        $res->end(json_encode(['error' => $e->getMessage()]));
        return;
    }

    $redis = $redisPool->pop();
    try {
        if (!$redis->isConnected()) {
            $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379));
        }

        $historyKey = "risk:history:{$applicant['applicant_id']}";
        $previousScoreRaw = $redis->get($historyKey);
        $previousScore = $previousScoreRaw !== false ? (float) $previousScoreRaw : null;

        $result = RiskCalculator::calculate($applicant, $previousScore);

        $redis->setex($historyKey, 3600, (string) $result['risk_score']);
    } finally {
        $redisPool->push($redis);
    }

    $result['processing_time_ms'] = round((microtime(true) - $start) * 1000, 3);
    $result['engine'] = 'php-swoole';

    $res->header('Content-Type', 'application/json');
    $res->end(json_encode($result));
});

$server->start();
