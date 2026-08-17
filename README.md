# Risk Analysis Lab — PHP Swoole vs Kotlin Coroutines

Laboratório de benchmark comparando dois serviços de **análise de risco de crédito**
com a **mesma regra de negócio**, implementados em dois modelos de concorrência
diferentes:

- **PHP + Swoole** — coroutines cooperativas (event loop single-thread por
  worker, com N workers/processos).
- **Kotlin + Ktor + kotlinx.coroutines** — coroutines sobre um pool de threads
  (`Dispatchers.IO` e `Dispatchers.Default`), escalando nativamente entre
  núcleos.

Cada serviço expõe `POST /analyze`, faz uma chamada de I/O real ao Redis
(histórico do solicitante) e roda uma simulação Monte Carlo (carga de CPU) para
compor o score de risco — assim o teste expõe tanto o comportamento sob I/O
concorrente quanto sob CPU concorrente, que é onde os dois modelos mais divergem.

Toda a infraestrutura roda isolada via Docker, com CPU/memória limitadas de
forma **idêntica** para os dois serviços (`docker-compose.yml`), para a
comparação ser justa.

## Arquitetura

```
                         ┌──────────────────┐
              ┌─────────▶│  php-swoole:9501 │──┐
              │          └──────────────────┘  │
   k6 (carga) ┤                                 ├──▶ redis:6379
              │          ┌──────────────────┐  │     (histórico
              └─────────▶│ kotlin-coroutines│──┘      do solicitante)
                         │      :9502        │
                         └──────────────────┘
```

O k6 roda contra **um serviço por vez** (nunca os dois simultaneamente), para
eles não competirem por CPU/rede do host durante a medição.

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

## Como rodar

Requer Docker + Docker Compose v2 (usa `deploy.resources.limits`, que o plugin
`docker compose` respeita mesmo fora do modo swarm).

```bash
docker compose up -d --build
curl -s -X POST http://localhost:9501/analyze -H 'Content-Type: application/json' \
  -d '{"applicant_id":"A-1","age":34,"income":5400,"credit_score":620,"loan_amount":15000,"existing_debt":8000,"employment_years":3.5}'
curl -s -X POST http://localhost:9502/analyze -H 'Content-Type: application/json' \
  -d '{"applicant_id":"A-1","age":34,"income":5400,"credit_score":620,"loan_amount":15000,"existing_debt":8000,"employment_years":3.5}'
```

## Rodando o benchmark de carga

```bash
# padrão: 300 VUs simultâneos, 60s sustentados (~90s de teste por serviço)
bash load-test/run-benchmark.sh

# customizado
VUS=500 SUSTAIN=120s bash load-test/run-benchmark.sh
```

O script sobe `redis` + os dois serviços, espera os health checks, e roda o
**mesmo script k6** (`load-test/k6/scenario.js`, payload aleatório por
iteração) sequencialmente contra cada serviço. Os resultados brutos (JSON do
`k6 summary-export`) ficam em `load-test/results/` (não versionados — variam
por hardware).

## Resultado de referência

Obtido nesta máquina (2 vCPUs / 512 MB por serviço, limite idêntico via
`docker-compose.yml`), com `VUS=300`, `SUSTAIN=60s`:

| Métrica              | PHP Swoole | Kotlin Coroutines |
|-----------------------|-----------:|-------------------:|
| Requests totais        | 341.457    | 717.610            |
| Throughput médio       | 3.793 req/s | 7.973 req/s        |
| Latência p95           | 100,8 ms   | 60,9 ms             |
| Latência p99           | 124,6 ms   | 77,3 ms             |
| Taxa de erro           | 0%         | 0%                  |

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
├── docker-compose.yml       # redis + os dois serviços, recursos idênticos
├── php-swoole/               # serviço PHP + Swoole (coroutines cooperativas)
├── kotlin-coroutines/        # serviço Kotlin + Ktor (coroutines sobre threads)
└── load-test/
    ├── k6/scenario.js        # carga sintética, payload aleatório
    ├── run-benchmark.sh      # orquestra build + health check + k6 + resumo
    └── results/               # JSONs de saída do k6 (gerados a cada run)
```
