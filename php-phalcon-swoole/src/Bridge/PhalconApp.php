<?php

declare(strict_types=1);

/**
 * Monta o \Phalcon\Mvc\Micro (DI container + rotas) UMA VEZ por worker,
 * dentro do evento workerStart do Swoole. É essa montagem — resolver o DI,
 * registrar rotas, compilar o dispatcher do Micro — que em PHP-FPM
 * tradicional acontece a cada requisição (bootstrap completo do framework
 * por request); aqui ela acontece uma vez e fica retida em memória
 * enquanto o worker viver.
 *
 * Cada requisição HTTP subsequente só percorre PhalconApp::$app->handle(),
 * que reaproveita o DI e as rotas já montados.
 *
 * Cuidado de concorrência (ver README da pasta): o serviço 'request' pode
 * ser compartilhado (é stateless, ver SwooleRequest), mas o 'response' NÃO
 * pode — Phalcon\Di\Injectable::__get() sempre resolve $app->request e
 * $app->response via container->getShared(), que cacheia a PRIMEIRA
 * instância resolvida para sempre, mesmo que o serviço tenha sido
 * registrado como não-compartilhado com $di->set(). Por isso os handlers
 * abaixo nunca usam $app->response — cada um monta sua própria Response
 * via respond(), isolada por coroutine.
 *
 * Sobre o responseHandler: ver o docblock de CoroutineResponseContext para
 * o porquê de ele NÃO usar $app->getReturnedValue() (funciona para rota
 * casada, mas não para notFound — bug real do Micro upstream).
 */
final class PhalconApp
{
    /**
     * @param \Swoole\Coroutine\Channel $redisPool pool de conexões Redis do worker (ver server.php)
     */
    public static function build(\Swoole\Coroutine\Channel $redisPool): \Phalcon\Mvc\Micro
    {
        $di = new \Phalcon\Di\FactoryDefault();
        // Não-static pelo mesmo motivo dos handlers de rota abaixo: o DI do
        // Phalcon também tenta fazer bindTo() em closures de definição de
        // serviço, e closures estáticas não são bind-áveis.
        $di->setShared('request', fn () => new SwooleRequest());

        $app = new \Phalcon\Mvc\Micro($di);

        // O handler de resposta padrão do Micro chama Response::send(), que
        // usa header()/echo nativos do PHP — sem efeito nenhum no client
        // HTTP real dentro do Swoole (não há SAPI clássico aqui). Troca por
        // um handler que só devolve a Response que o handler de rota já
        // publicou em CoroutineResponseContext, sem chamar send(); quem
        // efetivamente escreve na conexão TCP é o server.php.
        $app->setResponseHandler(fn () => CoroutineResponseContext::get());

        // Nota: os handlers de rota do Micro NÃO podem ser `static function` —
        // o Micro faz $handler->bindTo($this) para permitir $this->request
        // dentro deles, e closures estáticas não são bind-áveis (o dispatch
        // quebra silenciosamente sob Swoole, sem esse comentário para lembrar).
        $app->get('/health', function () {
            return self::respond(200, ['status' => 'ok', 'engine' => 'php-phalcon-swoole']);
        });

        $app->post('/analyze', function () use ($app, $redisPool) {
            $start = microtime(true);

            try {
                $payload = $app->request->getJsonRawBody(true);
            } catch (\Throwable $e) {
                // Phalcon\Http\Request::getJsonRawBody() lança em JSON
                // inválido (json_decode() "puro" só devolveria null) — sem
                // esse catch, um corpo malformado derruba o worker inteiro
                // (fatal não capturado dentro da coroutine de requisição).
                return self::respond(400, ['error' => 'invalid json body']);
            }

            if (!is_array($payload)) {
                return self::respond(400, ['error' => 'invalid json body']);
            }

            try {
                $applicant = RiskCalculator::validate($payload);
            } catch (\InvalidArgumentException $e) {
                return self::respond(422, ['error' => $e->getMessage()]);
            }

            $redis = $redisPool->pop();
            try {
                $historyKey = "risk:history:{$applicant['applicant_id']}";
                $previousScoreRaw = $redis->get($historyKey);
                $previousScore = $previousScoreRaw !== false ? (float) $previousScoreRaw : null;

                $result = RiskCalculator::calculate($applicant, $previousScore);

                $redis->setex($historyKey, 3600, (string) $result['risk_score']);
            } finally {
                $redisPool->push($redis);
            }

            $result['processing_time_ms'] = round((microtime(true) - $start) * 1000, 3);
            $result['engine'] = 'php-phalcon-swoole';

            return self::respond(200, $result);
        });

        $app->notFound(function () {
            return self::respond(404, ['error' => 'not found']);
        });

        return $app;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function respond(int $status, array $body): \Phalcon\Http\Response
    {
        $response = new \Phalcon\Http\Response();
        $response->setStatusCode($status);
        $response->setJsonContent($body);
        // Publica no slot da coroutine atual — é daqui que o
        // responseHandler (acima) e o server.php leem a resposta final,
        // não do retorno normal do handler (ver CoroutineResponseContext).
        CoroutineResponseContext::set($response);
        return $response;
    }
}
