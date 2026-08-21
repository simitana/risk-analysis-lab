<?php

declare(strict_types=1);

/**
 * Regra de negócio do serviço de análise de risco.
 *
 * Combina fatores financeiros do solicitante com uma simulação Monte Carlo
 * (carga de CPU deliberada) e o histórico do solicitante vindo do Redis
 * (carga de I/O deliberada), para expor tanto o comportamento sob
 * concorrência de I/O quanto sob concorrência de CPU.
 *
 * Esta regra é replicada 1:1 nos outros dois serviços — qualquer alteração
 * aqui deve ser espelhada em kotlin-coroutines/.../RiskCalculator.kt e em
 * php-phalcon-swoole/src/RiskCalculator.php.
 */
final class RiskCalculator
{
    private const MONTE_CARLO_ITERATIONS = 2000;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function validate(array $payload): array
    {
        $required = ['applicant_id', 'age', 'income', 'credit_score', 'loan_amount', 'existing_debt', 'employment_years'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("campo obrigatório ausente: {$field}");
            }
        }
        if ((float) $payload['income'] <= 0) {
            throw new InvalidArgumentException('income deve ser maior que zero');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function calculate(array $a, ?float $previousScore): array
    {
        $income = (float) $a['income'];
        $debtToIncome = min(1.0, ((float) $a['existing_debt']) / $income);
        $loanToIncome = min(2.0, ((float) $a['loan_amount']) / $income) / 2.0;
        $creditFactor = (850 - (int) $a['credit_score']) / 550.0;
        $employmentFactor = 1.0 / (1.0 + (float) $a['employment_years']);
        $age = (int) $a['age'];
        $ageFactor = ($age < 21 || $age > 70) ? 0.3 : 0.0;

        $baseRisk =
            $debtToIncome * 0.30 +
            $loanToIncome * 0.20 +
            $creditFactor * 0.30 +
            $employmentFactor * 0.10 +
            $ageFactor * 0.10;

        // Simulação Monte Carlo: carga de CPU deliberada.
        $sum = 0.0;
        for ($i = 0; $i < self::MONTE_CARLO_ITERATIONS; $i++) {
            $noise = (mt_rand(-1000, 1000) / 1000.0) * 0.05;
            $sample = max(0.0, min(1.0, $baseRisk + $noise));
            $sum += $sample;
        }
        $simulatedRisk = $sum / self::MONTE_CARLO_ITERATIONS;

        $score = $simulatedRisk * 100.0;
        if ($previousScore !== null) {
            $score = ($score * 0.8) + ($previousScore * 0.2);
        }
        $score = max(0.0, min(100.0, $score));

        return [
            'applicant_id' => $a['applicant_id'],
            'risk_score' => round($score, 2),
            'risk_level' => self::level($score),
            'factors' => [
                'debt_to_income' => round($debtToIncome, 4),
                'loan_to_income' => round($loanToIncome, 4),
                'credit_factor' => round($creditFactor, 4),
                'employment_factor' => round($employmentFactor, 4),
                'age_factor' => $ageFactor,
                'used_history' => $previousScore !== null,
            ],
            'processed_at' => date(DATE_ATOM),
        ];
    }

    private static function level(float $score): string
    {
        return match (true) {
            $score < 25 => 'LOW',
            $score < 50 => 'MEDIUM',
            $score < 75 => 'HIGH',
            default => 'CRITICAL',
        };
    }
}
