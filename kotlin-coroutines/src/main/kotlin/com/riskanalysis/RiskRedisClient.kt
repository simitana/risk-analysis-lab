package com.riskanalysis

import io.lettuce.core.RedisClient
import io.lettuce.core.RedisURI
import io.lettuce.core.api.StatefulRedisConnection
import io.lettuce.core.api.async.RedisAsyncCommands
import kotlinx.coroutines.future.await

/**
 * Cliente Redis assíncrono baseado em RedisFuture + kotlinx-coroutines-jdk8
 * (`.await()`). A chamada suspende a coroutine sem bloquear a thread do
 * dispatcher — o equivalente ao cliente Redis por coroutine usado no lado
 * PHP Swoole.
 */
class RiskRedisClient(host: String, port: Int) {
    private val client: RedisClient = RedisClient.create(RedisURI.Builder.redis(host, port).build())
    private val connection: StatefulRedisConnection<String, String> = client.connect()
    private val commands: RedisAsyncCommands<String, String> = connection.async()

    suspend fun get(key: String): String? = commands.get(key).await()

    suspend fun setex(key: String, seconds: Long, value: String) {
        commands.setex(key, seconds, value).await()
    }

    fun close() {
        connection.close()
        client.shutdown()
    }
}
