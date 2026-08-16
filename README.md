# Risk Analysis Lab — PHP Swoole vs Kotlin Coroutines

Laboratório de benchmark comparando dois serviços de **análise de risco de crédito**
com a **mesma regra de negócio**, implementados em dois modelos de concorrência
diferentes:

- **PHP + Swoole** — coroutines cooperativas (event loop single-thread
  por worker, com N workers).
- **Kotlin + Ktor + kotlinx.coroutines** — coroutines sobre um pool de threads
  (dispatchers `IO` e `Default`), escalando nativamente entre núcleos.

Cada serviço expõe `POST /analyze`, faz uma chamada de I/O real ao Redis
(histórico do solicitante) e roda uma simulação Monte Carlo (carga de CPU) para
compor o score de risco — assim o teste expõe tanto o comportamento sob I/O
concorrente quanto sob CPU concorrente, que é onde os dois modelos mais divergem.

Toda a infraestrutura roda isolada via Docker, com CPU/memória limitadas de
forma **idêntica** para os dois serviços, para a comparação ser justa.

> Documentação completa de arquitetura, como rodar e como interpretar os
> resultados será adicionada nos próximos commits, à medida que cada peça for
> implementada.
