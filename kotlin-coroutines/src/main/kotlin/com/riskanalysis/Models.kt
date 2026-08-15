package com.riskanalysis

import kotlinx.serialization.Serializable

/**
 * Contrato de request/response idêntico ao serviço PHP Swoole (payload em
 * snake_case via JsonNamingStrategy.SnakeCase configurado em Application.kt).
 * Qualquer alteração aqui deve ser espelhada em RiskCalculator.php.
 */
@Serializable
data class AnalyzeRequest(
    val applicantId: String,
    val age: Int,
    val income: Double,
    val creditScore: Int,
    val loanAmount: Double,
    val existingDebt: Double,
    val employmentYears: Double,
) {
    fun validationError(): String? {
        if (income <= 0) return "income deve ser maior que zero"
        return null
    }
}

@Serializable
data class Factors(
    val debtToIncome: Double,
    val loanToIncome: Double,
    val creditFactor: Double,
    val employmentFactor: Double,
    val ageFactor: Double,
    val usedHistory: Boolean,
)

@Serializable
data class AnalyzeResponse(
    val applicantId: String,
    val riskScore: Double,
    val riskLevel: String,
    val factors: Factors,
    val processedAt: String,
    val processingTimeMs: Double = 0.0,
    val engine: String = "kotlin-coroutines",
)
