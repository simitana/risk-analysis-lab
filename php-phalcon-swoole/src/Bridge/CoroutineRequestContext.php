<?php

declare(strict_types=1);

/**
 * Associa a Swoole\Http\Request "crua" da requisição atual à coroutine que
 * está processando-a, usando Swoole\Coroutine::getContext() — um array
 * isolado por coroutine, e não por worker/processo.
 *
 * Isso é o que permite ao worker manter UM único objeto SwooleRequest
 * (bridge) compartilhado por todas as requisições concorrentes do worker,
 * sem elas pisarem umas nas outras: cada coroutine lê o contexto que É DELA.
 */
final class CoroutineRequestContext
{
    private const KEY = 'swoole_http_request';

    public static function set(\Swoole\Http\Request $request): void
    {
        \Swoole\Coroutine::getContext()[self::KEY] = $request;
    }

    public static function get(): \Swoole\Http\Request
    {
        $request = \Swoole\Coroutine::getContext()[self::KEY] ?? null;
        if (!$request instanceof \Swoole\Http\Request) {
            // Só acontece se algo chamar a bridge fora de uma coroutine de
            // requisição (bug de integração, não de request do usuário).
            throw new \RuntimeException('nenhuma Swoole\Http\Request associada à coroutine atual');
        }

        return $request;
    }
}
