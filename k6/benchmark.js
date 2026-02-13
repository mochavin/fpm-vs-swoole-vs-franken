import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.2/index.js';

// ─── Custom Metrics ──────────────────────────────────────
const errorRate = new Rate('errors');
const healthDuration = new Trend('health_duration', true);
const listPostsDuration = new Trend('list_posts_duration', true);
const singlePostDuration = new Trend('single_post_duration', true);
const createPostDuration = new Trend('create_post_duration', true);
const heavyDuration = new Trend('heavy_duration', true);

// ─── Configuration ───────────────────────────────────────
const BASE_URL = __ENV.BASE_URL || 'http://localhost:8001';

export const options = {
  scenarios: {
    // Scenario 1: Health check (pure throughput, no DB)
    health_check: {
      executor: 'constant-vus',
      vus: 5000,
      duration: '30s',
      exec: 'healthCheck',
      tags: { scenario: 'health_check' },
    },

    // Scenario 2: Read posts (DB reads with ORM)
    read_posts: {
      executor: 'ramping-vus',
      startVUs: 1000,
      stages: [
        { duration: '15s', target: 500 },
        { duration: '30s', target: 1000 },
        { duration: '15s', target: 0 },
      ],
      exec: 'readPosts',
      startTime: '35s',
      tags: { scenario: 'read_posts' },
    },

    // Scenario 3: Single post read
    single_post: {
      executor: 'constant-vus',
      vus: 3000,
      duration: '30s',
      exec: 'singlePost',
      startTime: '100s',
      tags: { scenario: 'single_post' },
    },

    // Scenario 4: Create posts (DB writes + validation)
    write_posts: {
      executor: 'constant-vus',
      vus: 2000,
      duration: '30s',
      exec: 'writePosts',
      startTime: '135s',
      tags: { scenario: 'write_posts' },
    },

    // Scenario 5: CPU-heavy computation
    heavy_compute: {
      executor: 'constant-vus',
      vus: 2000,
      duration: '30s',
      exec: 'heavyCompute',
      startTime: '170s',
      tags: { scenario: 'heavy_compute' },
    },
  },

  thresholds: {
    http_req_failed: ['rate<0.10'],           // <10% errors
    http_req_duration: ['p(95)<5000'],         // 95th percentile < 5s
    health_duration: ['p(95)<500'],            // Health endpoint
    list_posts_duration: ['p(95)<2000'],       // List posts
    single_post_duration: ['p(95)<1000'],      // Single post
    create_post_duration: ['p(95)<2000'],      // Create post
    heavy_duration: ['p(95)<5000'],            // Heavy compute
  },
};

// ─── Scenario Functions ──────────────────────────────────

export function healthCheck() {
  const res = http.get(`${BASE_URL}/api/health`);

  healthDuration.add(res.timings.duration);
  errorRate.add(res.status !== 200);

  check(res, {
    'health: status 200': (r) => r.status === 200,
    'health: has status field': (r) => {
      try {
        return JSON.parse(r.body).status === 'ok';
      } catch {
        return false;
      }
    },
  });

  sleep(0.1);
}

export function readPosts() {
  const res = http.get(`${BASE_URL}/api/posts`);

  listPostsDuration.add(res.timings.duration);
  errorRate.add(res.status !== 200);

  check(res, {
    'list posts: status 200': (r) => r.status === 200,
    'list posts: has data': (r) => {
      try {
        return JSON.parse(r.body).data.length > 0;
      } catch {
        return false;
      }
    },
  });

  sleep(0.2);
}

export function singlePost() {
  const postId = Math.floor(Math.random() * 100) + 1;
  const res = http.get(`${BASE_URL}/api/posts/${postId}`);

  singlePostDuration.add(res.timings.duration);
  errorRate.add(res.status !== 200);

  check(res, {
    'single post: status 200': (r) => r.status === 200,
    'single post: has data': (r) => {
      try {
        return JSON.parse(r.body).data.id !== undefined;
      } catch {
        return false;
      }
    },
  });

  sleep(0.1);
}

export function writePosts() {
  const payload = JSON.stringify({
    title: `Benchmark Post ${Date.now()}`,
    body: 'This is a benchmark test post created by k6 load testing. Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
  });

  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
  };

  const res = http.post(`${BASE_URL}/api/posts`, payload, params);

  createPostDuration.add(res.timings.duration);
  errorRate.add(res.status !== 201);

  check(res, {
    'create post: status 201': (r) => r.status === 201,
    'create post: has data': (r) => {
      try {
        return JSON.parse(r.body).data.id !== undefined;
      } catch {
        return false;
      }
    },
  });

  sleep(0.3);
}

export function heavyCompute() {
  const res = http.get(`${BASE_URL}/api/heavy`);

  heavyDuration.add(res.timings.duration);
  errorRate.add(res.status !== 200);

  check(res, {
    'heavy: status 200': (r) => r.status === 200,
    'heavy: has result': (r) => {
      try {
        return JSON.parse(r.body).fibonacci_30 !== undefined;
      } catch {
        return false;
      }
    },
  });

  sleep(0.5);
}

// ─── Summary Handler ─────────────────────────────────────

export function handleSummary(data) {
  const runtime = __ENV.RUNTIME || 'unknown';
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');

  const result = {
    stdout: textSummary(data, { indent: '  ', enableColors: true }),
  };

  // Also save JSON results
  result[`/results/${runtime}_${timestamp}.json`] = JSON.stringify(data, null, 2);

  return result;
}
