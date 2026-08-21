<?php

declare(strict_types=1);

/**
 * Bridge de Request: substitui a leitura de superglobais do
 * Phalcon\Http\Request (php://input, $_SERVER, $_GET/$_POST) — que no
 * Swoole não existem por requisição, só por processo — por leitura da
 * Swoole\Http\Request da coroutine atual, via CoroutineRequestContext.
 *
 * Fica registrada como serviço COMPARTILHADO no DI (um único objeto por
 * worker, nunca recriado por requisição — é a parte "zero bootstrap" da
 * ponte). Isso só é seguro porque esta classe não guarda nenhum estado
 * próprio: cada método sempre revalida CoroutineRequestContext::get() e
 * lê os dados da coroutine que está chamando naquele instante. Note que
 * o Phalcon\Http\Request original NÃO é seguro para isso — ele cacheia
 * getRawBody() em $this->rawBody na primeira chamada, o que travaria
 * todas as requisições do worker no corpo da primeira. É por isso que
 * getRawBody() é sobrescrito aqui sem chamar a implementação do pai.
 *
 * Limitação conhecida: Phalcon\Http\Request::hasHeader() e hasServer() são
 * `final` — não dá para sobrescrevê-los aqui. Eles continuam lendo $_SERVER
 * real (vazio/parado por processo sob Swoole, não por coroutine), então
 * ficam incorretos se algum handler de rota vier a chamá-los. Nenhuma rota
 * atual usa esses dois métodos; se precisar, use getHeader()/getServer()
 * (sobrescritos e corretos) com uma verificação de string vazia em vez de
 * hasHeader()/hasServer().
 */
final class SwooleRequest extends \Phalcon\Http\Request
{
    public function getRawBody(): string
    {
        return CoroutineRequestContext::get()->getContent() ?: '';
    }

    public function getMethod(): string
    {
        return strtoupper(CoroutineRequestContext::get()->getMethod() ?: 'GET');
    }

    public function getHeader(string $header): string
    {
        $key = strtolower($header);
        return CoroutineRequestContext::get()->header[$key] ?? '';
    }

    public function getServer(string $name): string|null
    {
        $key = strtolower($name);
        return CoroutineRequestContext::get()->server[$key] ?? null;
    }
}
