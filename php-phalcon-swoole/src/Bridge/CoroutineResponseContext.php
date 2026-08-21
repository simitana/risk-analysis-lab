<?php

declare(strict_types=1);

/**
 * Segundo slot por coroutine (irmão de CoroutineRequestContext), guardando
 * a \Phalcon\Http\Response que o handler de rota atual construiu.
 *
 * Existe por causa de um comportamento real do Phalcon\Mvc\Micro::handle():
 * ele só grava o valor devolvido pelo handler na propriedade interna que
 * getReturnedValue() lê quando a ROTA CASA (branch "matchedRoute !== null").
 * No branch de notFound, o valor de retorno do notFoundHandler fica só numa
 * variável local do handle() — getReturnedValue() nunca é atualizado ali, e
 * volta a devolver o valor da última rota casada (ou null). Um
 * responseHandler que confie em getReturnedValue() (o jeito "óbvio" de usar
 * a API) fica quebrado especificamente para 404 — funciona em todo o resto.
 *
 * Por isso os handlers de rota (incluindo o notFound) publicam a Response
 * aqui diretamente, e o responseHandler configurado em PhalconApp lê daqui
 * em vez de $app->getReturnedValue().
 */
final class CoroutineResponseContext
{
    private const KEY = 'phalcon_response';

    public static function set(\Phalcon\Http\ResponseInterface $response): void
    {
        \Swoole\Coroutine::getContext()[self::KEY] = $response;
    }

    public static function get(): ?\Phalcon\Http\ResponseInterface
    {
        return \Swoole\Coroutine::getContext()[self::KEY] ?? null;
    }
}
