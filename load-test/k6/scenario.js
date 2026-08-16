import http from 'k6/http';
import { check } from 'k6';
import { Counter, Trend } from 'k6/metrics';

// TARGET_URL, VUS e SUSTAIN são passados via env var pelo run-benchmark.sh,
// assim o mesmo script gera carga idêntica contra os dois serviços.
const BASE_URL = __ENV.TARGET_URL || 'http://localhost:9501';
const VUS = Number(__ENV.VUS || 300);
const SUSTAIN = __ENV.SUSTAIN || '60s';

export const options = {
  scenarios: {
    load: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '20s', target: VUS },
        { duration: SUSTAIN, target: VUS },
        { duration: '10s', target: 0 },
      ],
      gracefulRampDown: '5s',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
  },
  // Por padrão o k6 só resume p(90)/p(95); p99 é o que mais importa para
  // comparar cauda de latência entre os dois modelos de concorrência.
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const businessErrors = new Counter('risk_analysis_errors');
const businessLatency = new Trend('risk_business_processing_ms');

function randomApplicant() {
  const id = `A-${Math.floor(Math.random() * 1_000_000)}`;
  return JSON.stringify({
    applicant_id: id,
    age: Math.floor(Math.random() * 60) + 18,
    income: Math.round((Math.random() * 15000 + 1000) * 100) / 100,
    credit_score: Math.floor(Math.random() * 550) + 300,
    loan_amount: Math.round((Math.random() * 50000 + 500) * 100) / 100,
    existing_debt: Math.round(Math.random() * 20000 * 100) / 100,
    employment_years: Math.round(Math.random() * 20 * 10) / 10,
  });
}

export default function () {
  const payload = randomApplicant();
  const params = { headers: { 'Content-Type': 'application/json' } };
  const res = http.post(`${BASE_URL}/analyze`, payload, params);

  const ok = check(res, {
    'status é 200': (r) => r.status === 200,
  });

  if (!ok) {
    businessErrors.add(1);
    return;
  }

  try {
    const body = JSON.parse(res.body);
    if (typeof body.processing_time_ms === 'number') {
      businessLatency.add(body.processing_time_ms);
    }
  } catch (e) {
    businessErrors.add(1);
  }
}
