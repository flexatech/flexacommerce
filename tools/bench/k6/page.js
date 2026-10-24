// One scenario, one store, one run (doc 10 §6.3). bench.sh sets the env:
//   PATH_URI  path to request, HOST  store host name, RUN  run label,
//   N  measured requests, VUS  concurrent users.
// Warm-up requests carry RUN=<label>-warm so summarize.php drops them.
import http from 'k6/http';
import { check } from 'k6';

const vus = Number(__ENV.VUS || 1);

export const options = {
  scenarios: {
    measure: {
      executor: 'shared-iterations',
      vus,
      iterations: Number(__ENV.N || 500),
      maxDuration: '30m',
    },
  },
  maxRedirects: 0,
  discardResponseBodies: true,
  summaryTrendStats: ['med', 'p(95)', 'p(99)'],
};

export default function () {
  const res = http.get(`http://nginx${__ENV.PATH_URI}`, {
    headers: { Host: __ENV.HOST, 'X-Bench-Run': __ENV.RUN },
  });
  check(res, { 'status 200': (r) => r.status === 200 });
}
