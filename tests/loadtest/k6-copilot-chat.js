/**
 * k6 load test for the Clinical Co-Pilot chat endpoint (PUNCH_LIST.md 3.2, [ER-9]).
 *
 * Per VU, per iteration: replays the same login -> select-patient -> extract-CSRF
 * -> ask-a-question flow tests/bruno/clinical-copilot/01-auth and
 * 02-copilot-chat/01-happy-path.bru already verified against a running instance
 * (see that collection's README for the request/response shapes this mirrors).
 * Each VU keeps its own cookie jar so login/session state never leaks across VUs.
 *
 * Usage:
 *   k6 run tests/loadtest/k6-copilot-chat.js \
 *     -e BASE_URL=http://localhost:8300 \
 *     -e USERNAME=admin -e PASSWORD=pass -e PATIENT_ID=1 \
 *     -e STAGE_10VU_DURATION=2m -e STAGE_50VU_DURATION=2m \
 *     --summary-export=tests/loadtest/results/<name>.json \
 *     | tee tests/loadtest/results/<name>.txt
 *
 * For the small real-API smoke passes (5-10 sequential requests, not
 * concurrent), override VUS/stages via SMOKE=1 -- see README.md.
 *
 * For the 3.2 acceptance criteria (p50/p95/p99 and error rate recorded
 * SEPARATELY at 10 and 50 concurrent users, not blended into one ramp), run
 * with LEVEL_VUS=10 and then again with LEVEL_VUS=50 -- each holds a constant
 * VU count for LEVEL_DURATION after a short ramp-up, so each run's summary is
 * that level's own numbers, not an average across the transition between
 * them. See README.md for the exact invocations used to produce
 * tests/loadtest/results/.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8300';
const USERNAME = __ENV.USERNAME || 'admin';
const PASSWORD = __ENV.PASSWORD || 'pass';
const PATIENT_ID = __ENV.PATIENT_ID || '1';
const SMOKE = __ENV.SMOKE === '1';

const chatLatency = new Trend('copilot_chat_duration', true);
const chatErrors = new Rate('copilot_chat_errors');
const sessionSetupErrors = new Counter('session_setup_errors');

const QUESTIONS = [
  'What active problems does this patient have?',
  'Is this patient on any medications?',
  'What were the results of the most recent A1c?',
  'What was the most recent encounter for this patient?',
];

const LEVEL_VUS = __ENV.LEVEL_VUS ? Number(__ENV.LEVEL_VUS) : null;

export const options = SMOKE
  ? {
      scenarios: {
        smoke: {
          executor: 'per-vu-iterations',
          vus: 1,
          iterations: Number(__ENV.SMOKE_REQUESTS || 8),
          maxDuration: '5m',
        },
      },
      thresholds: {
        copilot_chat_errors: ['rate<0.5'],
      },
    }
  : LEVEL_VUS
    ? {
        // Constant-VU: the summary this produces IS that level's own
        // p50/p95/p99 and error rate, per 3.2's acceptance criteria.
        scenarios: {
          level: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
              { duration: __ENV.LEVEL_RAMP || '20s', target: LEVEL_VUS },
              { duration: __ENV.LEVEL_DURATION || '2m', target: LEVEL_VUS },
            ],
            gracefulRampDown: '20s',
          },
        },
        thresholds: {
          copilot_chat_errors: ['rate<0.9'],
        },
      }
    : {
        // Combined ramp 0 -> 10 -> 50 -> 0: useful to see the degradation
        // transition, but NOT the source of 3.2's per-level numbers (those
        // come from two separate LEVEL_VUS=10 / LEVEL_VUS=50 runs).
        scenarios: {
          ramp: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
              { duration: __ENV.STAGE_10VU_RAMP || '30s', target: 10 },
              { duration: __ENV.STAGE_10VU_DURATION || '2m', target: 10 },
              { duration: __ENV.STAGE_50VU_RAMP || '30s', target: 50 },
              { duration: __ENV.STAGE_50VU_DURATION || '2m', target: 50 },
              { duration: '30s', target: 0 },
            ],
            gracefulRampDown: '30s',
          },
        },
        thresholds: {
          copilot_chat_errors: ['rate<0.9'],
        },
      };

function establishSession(jar) {
  const params = { jar, redirects: 5 };

  const loginPage = http.get(`${BASE_URL}/interface/login/login.php?site=default`, params);
  if (loginPage.status !== 200) {
    sessionSetupErrors.add(1);
    return null;
  }

  const loginRes = http.post(
    `${BASE_URL}/interface/main/main_screen.php?auth=login&site=default`,
    {
      authUser: USERNAME,
      clearPass: PASSWORD,
      new_login_session_management: '1',
      languageChoice: '1',
    },
    params,
  );
  if (loginRes.status >= 400 || loginRes.body.indexOf('login_screen.php?error=1') !== -1) {
    sessionSetupErrors.add(1);
    return null;
  }

  const demographics = http.get(
    `${BASE_URL}/interface/patient_file/summary/demographics.php?set_pid=${PATIENT_ID}`,
    params,
  );
  if (demographics.status !== 200) {
    sessionSetupErrors.add(1);
    return null;
  }

  const match = demographics.body.match(/id="copilot-csrf"\s+value="([^"]+)"/);
  if (!match) {
    sessionSetupErrors.add(1);
    return null;
  }

  return match[1];
}

export default function () {
  const jar = http.cookieJar();
  const csrfToken = establishSession(jar);

  if (!csrfToken) {
    chatErrors.add(1);
    sleep(1);
    return;
  }

  const question = QUESTIONS[Math.floor(Math.random() * QUESTIONS.length)];
  const res = http.post(
    `${BASE_URL}/interface/modules/custom_modules/oe-module-clinical-copilot/public/ajax.php`,
    { csrf_token: csrfToken, question },
    { jar },
  );

  chatLatency.add(res.timings.duration);

  const ok = check(res, {
    'status is 200': (r) => r.status === 200,
    'has reply': (r) => {
      try {
        return typeof r.json('reply') === 'string';
      } catch (e) {
        return false;
      }
    },
  });
  chatErrors.add(!ok);

  sleep(1);
}
