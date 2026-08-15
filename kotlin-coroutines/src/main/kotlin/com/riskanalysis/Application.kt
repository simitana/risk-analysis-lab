package com.riskanalysis

import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.serialization.kotlinx.json.json
import io.ktor.server.application.install
import io.ktor.server.engine.embeddedServer
import io.ktor.server.netty.Netty
import io.ktor.server.plugins.contentnegotiation.ContentNegotiation
import io.ktor.server.request.receive
import io.ktor.server.response.respond
import io.ktor.server.response.respondText
import io.ktor.server.routing.get
import io.ktor.server.routing.post
import io.ktor.server.routing.routing
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNamingStrategy

fun main() {
    val port = System.getenv("PORT")?.toIntOrNull() ?: 9502
    val redisHost = System.getenv("REDIS_HOST") ?: "redis"
    val redisPort = System.getenv("REDIS_PORT")?.toIntOrNull() ?: 6379

    val redisClient = RiskRedisClient(redisHost, redisPort)

    Runtime.getRuntime().addShutdownHook(Thread { redisClient.close() })

    embeddedServer(Netty, port = port, host = "0.0.0.0") {
        install(ContentNegotiation) {
            json(
                Json {
                    ignoreUnknownKeys = true
                    namingStrategy = JsonNamingStrategy.SnakeCase
                },
            )
        }

        routing {
            get("/health") {
                call.respondText(
                    """{"status":"ok","engine":"kotlin-coroutines"}""",
                    ContentType.Application.Json,
                )
            }

            post("/analyze") {
                val start = System.nanoTime()

                val request = try {
                    call.receive<AnalyzeRequest>()
                } catch (e: Exception) {
                    call.respond(HttpStatusCode.BadRequest, mapOf("error" to "invalid json body"))
                    return@post
                }

                val validationError = request.validationError()
                if (validationError != null) {
                    call.respond(HttpStatusCode.UnprocessableEntity, mapOf("error" to validationError))
                    return@post
                }

                // I/O real via cliente Redis assíncrono (suspend, não bloqueia a thread).
                val historyKey = "risk:history:${request.applicantId}"
                val previousScore = redisClient.get(historyKey)?.toDoubleOrNull()

                // Simulação Monte Carlo (CPU-bound) despachada explicitamente para
                // Dispatchers.Default, cujo pool escala com o número de núcleos.
                val result = withContext(Dispatchers.Default) {
                    RiskCalculator.calculate(request, previousScore)
                }

                redisClient.setex(historyKey, 3600, result.riskScore.toString())

                val elapsedMs = (System.nanoTime() - start) / 1_000_000.0
                call.respond(result.copy(processingTimeMs = elapsedMs))
            }
        }
    }.start(wait = true)
}
