package com.riskanalysis

import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import kotlin.random.Random

/**
 * Regra de negócio do serviço de análise de risco.
 *
 * Combina fatores financeiros do solicitante com uma simulação Monte Carlo
 * (carga de CPU deliberada) e o histórico do solicitante vindo do Redis
 * (carga de I/O deliberada), para expor tanto o comportamento sob
 * concorrência de I/O quanto sob concorrência de CPU.
 *
 * Espelha 1:1 a regra em php-swoole/src/RiskCalculator.php e em
 * php-phalcon-swoole/src/RiskCalculator.php.
 */
object RiskCalculator {
    private const val MONTE_CARLO_ITERATIONS = 2000

    fun calculate(a: AnalyzeRequest, previousScore: Double?): AnalyzeResponse {
        val debtToIncome = minOf(1.0, a.existingDebt / a.income)
        val loanToIncome = minOf(2.0, a.loanAmount / a.income) / 2.0
        val creditFactor = (850 - a.creditScore) / 550.0
        val employmentFactor = 1.0 / (1.0 + a.employmentYears)
        val ageFactor = if (a.age < 21 || a.age > 70) 0.3 else 0.0

        val baseRisk =
            debtToIncome * 0.30 +
                loanToIncome * 0.20 +
                creditFactor * 0.30 +
                employmentFactor * 0.10 +
                ageFactor * 0.10

        // Simulação Monte Carlo: carga de CPU deliberada.
        var sum = 0.0
        repeat(MONTE_CARLO_ITERATIONS) {
            val noise = (Random.nextInt(-1000, 1001) / 1000.0) * 0.05
            val sample = (baseRisk + noise).coerceIn(0.0, 1.0)
            sum += sample
        }
        val simulatedRisk = sum / MONTE_CARLO_ITERATIONS

        var score = simulatedRisk * 100.0
        if (previousScore != null) {
            score = (score * 0.8) + (previousScore * 0.2)
        }
        score = score.coerceIn(0.0, 100.0)

        return AnalyzeResponse(
            applicantId = a.applicantId,
            riskScore = round(score, 2),
            riskLevel = level(score),
            factors = Factors(
                debtToIncome = round(debtToIncome, 4),
                loanToIncome = round(loanToIncome, 4),
                creditFactor = round(creditFactor, 4),
                employmentFactor = round(employmentFactor, 4),
                ageFactor = ageFactor,
                usedHistory = previousScore != null,
            ),
            processedAt = OffsetDateTime.now().format(DateTimeFormatter.ISO_OFFSET_DATE_TIME),
        )
    }

    private fun level(score: Double) = when {
        score < 25 -> "LOW"
        score < 50 -> "MEDIUM"
        score < 75 -> "HIGH"
        else -> "CRITICAL"
    }

    private fun round(v: Double, decimals: Int): Double {
        val factor = Math.pow(10.0, decimals.toDouble())
        return Math.round(v * factor) / factor
    }
}
