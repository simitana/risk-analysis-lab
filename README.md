# Risk Analysis Lab — PHP Swoole vs Kotlin Coroutines vs PHP Phalcon+Swoole

Laboratório de benchmark comparando três serviços de **análise de risco de crédito**
com a **mesma regra de negócio**, implementados em modelos de concorrência
diferentes:

- **PHP + Swoole** — coroutines cooperativas (event loop single-thread por
  worker, com N workers/processos).
- **Kotlin + Ktor + kotlinx.coroutines** — coroutines sobre um pool de threads
  (`Dispatchers.IO` e `Dispatchers.Default`), escalando nativamente entre
  núcleos.
- **PHP + Phalcon (Micro) + Swoole** — mesmo modelo de worker cooperativo do
  Swoole "puro" acima, mas rodando o framework Phalcon dentro do worker via
  uma bridge própria (`php-phalcon-swoole/src/Bridge/`): o DI container e as
  rotas do Phalcon são montados **uma vez por worker** (`workerStart`), não a
  cada requisição, eliminando o bootstrap de framework que aconteceria em
  PHP-FPM tradicional. Ver seção "PHP Phalcon+Swoole: a bridge" abaixo.

Cada serviço expõe `POST /analyze`, faz uma chamada de I/O real ao Redis
(histórico do solicitante) e roda uma simulação Monte Carlo (carga de CPU) para
compor o score de risco — assim o teste expõe tanto o comportamento sob I/O
concorrente quanto sob CPU concorrente, que é onde os modelos mais divergem.

Toda a infraestrutura roda isolada via Docker, com CPU/memória limitadas de
forma **idêntica** para os três serviços (`docker-compose.yml`), para a
comparação ser justa.

## Arquitetura

```
                         ┌───────────────────────┐
              ┌─────────▶│    php-swoole:9501    │──┐
              │          └───────────────────────┘  │
              │          ┌───────────────────────┐  │
   k6 (carga) ┼─────────▶│  kotlin-coroutines    │──┼──▶ redis:6379
              │          │       :9502            │  │    (histórico
              │          └───────────────────────┘  │     do solicitante)
              │          ┌───────────────────────┐  │
              └─────────▶│ php-phalcon-swoole    │──┘
                         │       :9503            │
                         └───────────────────────┘
```

O k6 roda contra **um serviço por vez** (nunca simultaneamente), para eles
não competirem por CPU/rede do host durante a medição.

## Regra de negócio (idêntica nos dois serviços)

`POST /analyze` recebe os dados do solicitante e calcula um score de risco
(0–100) combinando:

- **Fatores financeiros**: dívida/renda, empréstimo/renda, score de crédito,
  tempo de emprego, faixa etária.
- **Simulação Monte Carlo** (2000 iterações) em cima desses fatores — carga de
  **CPU** deliberada, para expor como cada modelo de concorrência lida com
  trabalho computacional dentro de um request.
- **Histórico do solicitante no Redis** (`GET`/`SETEX`) — carga de **I/O**
  deliberada, ponderando 20% do score final pelo score anterior.

A lógica está implementada em:
- `php-swoole/src/RiskCalculator.php`
- `kotlin-coroutines/src/main/kotlin/com/riskanalysis/RiskCalculator.kt`
- `php-phalcon-swoole/src/RiskCalculator.php` (cópia 1:1 da versão PHP acima)

## Como rodar

Requer Docker + Docker Compose v2 (usa `deploy.resources.limits`, que o plugin
`docker compose` respeita mesmo fora do modo swarm).

```bash
docker compose up -d --build
curl -s -X POST http://localhost:9501/analyze -H 'Content-Type: application/json' \
  -d '{"applicant_id":"A-1","age":34,"income":5400,"credit_score":620,"loan_amount":15000,"existing_debt":8000,"employment_years":3.5}'
curl -s -X POST http://localhost:9502/analyze -H 'Content-Type: application/json' \
  -d '{"applicant_id":"A-1","age":34,"income":5400,"credit_score":620,"loan_amount":15000,"existing_debt":8000,"employment_years":3.5}'
curl -s -X POST http://localhost:9503/analyze -H 'Content-Type: application/json' \
  -d '{"applicant_id":"A-1","age":34,"income":5400,"credit_score":620,"loan_amount":15000,"existing_debt":8000,"employment_years":3.5}'
```

## PHP Phalcon+Swoole: a bridge

`php-swoole` (o serviço "puro") já roda em worker mode — o event loop do
Swoole por si só já elimina o modelo "um processo por requisição" do
PHP-FPM. O que ele **não** tem é um framework por cima: o `server.php` faz
roteamento e parsing manualmente.

`php-phalcon-swoole` soma o Phalcon (Micro) a esse mesmo modelo de worker,
mas só o **DI container e a tabela de rotas** são montados por worker,
uma vez, em `workerStart` (`php-phalcon-swoole/src/Bridge/PhalconApp.php`).
Nenhuma requisição reconstrói isso — cada uma só percorre `$app->handle()`.

A parte não trivial é fazer o Phalcon (desenhado para o modelo
requisição-por-processo do PHP-FPM, com `$_SERVER`/`php://input` únicos por
execução) funcionar corretamente num worker que processa muitas requisições
**concorrentes na mesma memória** via coroutines. Dois pontos onde isso quebra
se não for tratado (ambos resolvidos em `php-phalcon-swoole/src/Bridge/`):

- **Leitura da requisição**: `Phalcon\Http\Request::getRawBody()` lê
  `php://input` e cacheia o resultado no próprio objeto. Sob Swoole isso
  travaria todas as requisições do worker no corpo da *primeira* — não existe
  `php://input` por coroutine. `SwooleRequest` sobrescreve esses métodos para
  ler da `Swoole\Http\Request` da coroutine atual, via
  `Swoole\Coroutine::getContext()` (`CoroutineRequestContext`), sem guardar
  nada em `$this`.
- **Objeto de resposta**: `Phalcon\Di\Injectable::__get()` (usado por
  `$app->request`/`$app->response`) sempre resolve via
  `container->getShared()`, que cacheia a *primeira* instância resolvida para
  sempre — inclusive para serviços registrados como não-compartilhados. Por
  isso os handlers de rota nunca usam `$app->response`: cada um cria seu
  próprio `new \Phalcon\Http\Response()`, isolado por já viver só na pilha de
  chamadas daquela requisição.

Vale reproduzir o benchmark para este serviço com `bash
load-test/run-benchmark.sh` e comparar: a expectativa de teoria de
concorrência é que ele **não** deva se comportar melhor que `php-swoole` sob
a carga CPU-bound do Monte Carlo — ambos são workers Swoole cooperativos, e
o gargalo ali é paralelismo real de CPU, não custo de bootstrap por
requisição (que é justamente o que a bridge elimina). Onde a bridge deveria
aparecer é em cargas com **muitas requisições pequenas e I/O-bound**, ou ao
comparar contra uma hipotética versão do mesmo serviço rodando sob PHP-FPM
(fora do escopo deste laboratório, que só compara os três serviços acima).

## Rodando o benchmark de carga

```bash
# padrão: 300 VUs simultâneos, 60s sustentados (~90s de teste por serviço)
bash load-test/run-benchmark.sh

# customizado
VUS=500 SUSTAIN=120s bash load-test/run-benchmark.sh
```

O script sobe `redis` + os três serviços, espera os health checks, e roda o
**mesmo script k6** (`load-test/k6/scenario.js`, payload aleatório por
iteração) sequencialmente contra cada serviço. Os resultados brutos (JSON do
`k6 summary-export`) ficam em `load-test/results/` (não versionados — variam
por hardware).

## Resultado de referência

Obtido nesta máquina (2 vCPUs / 512 MB por serviço, limite idêntico via
`docker-compose.yml`), com `VUS=300`, `SUSTAIN=60s`:

| Métrica              | PHP Swoole | Kotlin Coroutines | PHP Phalcon+Swoole |
|-----------------------|-----------:|-------------------:|--------------------:|
| Requests totais        | 341.457    | 717.610            | *a rodar*            |
| Throughput médio       | 3.793 req/s | 7.973 req/s        | *a rodar*            |
| Latência p95           | 100,8 ms   | 60,9 ms             | *a rodar*            |
| Latência p99           | 124,6 ms   | 77,3 ms             | *a rodar*            |
| Taxa de erro           | 0%         | 0%                  | *a rodar*            |

*(as três primeiras colunas vieram de uma execução real de
`load-test/run-benchmark.sh` nesta máquina; a coluna do Phalcon+Swoole ainda
não foi medida desde que ele entrou no lab — rode o script de novo para
preencher, `run_k6` já inclui os três serviços.)*

**Kotlin/coroutines entregou ~2,1x mais throughput e cauda de latência bem
menor**, sob a mesma carga, mesmos limites de CPU/memória e mesma regra de
negócio. Isso não é sobre I/O — os dois modelos lidam bem com I/O concorrente
(o Redis nunca foi o gargalo). A diferença aparece porque a simulação Monte
Carlo é **CPU-bound**:

- No **Swoole**, cada worker é um event loop cooperativo single-thread. A
  simulação Monte Carlo roda de forma síncrona dentro do worker e **bloqueia
  o loop inteiro** até terminar — nenhuma outra coroutine daquele worker
  progride nesse meio-tempo. Mais workers ajudam (mais processos == mais
  paralelismo real do SO), mas o worker individual não paraleliza CPU.
- No **Kotlin**, o cálculo é despachado explicitamente para
  `Dispatchers.Default`, cujo pool de threads escala com o número de núcleos
  disponíveis — o trabalho de CPU é genuinamente paralelizado pela JVM/SO
  entre threads, não apenas intercalado cooperativamente.

Coroutines cooperativas (Swoole) são ótimas para I/O-bound puro; para cargas
com componente de CPU real dentro do request — como um modelo de risco de
verdade —, um modelo com paralelismo real de threads (Kotlin/JVM) tende a
escalar melhor. Vale reproduzir com `SWOOLE_WORKERS` mais alto (variável de
ambiente do `php-swoole`) para ver o quanto mais workers reduz essa diferença.

## Estrutura do projeto

```
risk-analysis-lab/
├── docker-compose.yml       # redis + os três serviços, recursos idênticos
├── php-swoole/               # serviço PHP + Swoole (coroutines cooperativas)
├── kotlin-coroutines/        # serviço Kotlin + Ktor (coroutines sobre threads)
├── php-phalcon-swoole/       # serviço PHP + Phalcon (Micro) sobre Swoole,
│   └── src/Bridge/           #   worker mode + bridge própria (ver seção acima)
└── load-test/
    ├── k6/scenario.js        # carga sintética, payload aleatório
    ├── run-benchmark.sh      # orquestra build + health check + k6 + resumo
    └── results/               # JSONs de saída do k6 (gerados a cada run)
```
