import http from 'k6/http';
import { check, sleep } from 'k6';

// Quick smoke test to verify a runtime is responding
// Usage: k6 run -e BASE_URL=http://localhost:8001 /scripts/smoke.js

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8001';

export const options = {
  vus: 1,
  iterations: 1,
};

export default function () {
  // Test health endpoint
  const healthRes = http.get(`${BASE_URL}/api/health`);
  check(healthRes, {
    'health: status 200': (r) => r.status === 200,
  });
  console.log(`Health: ${healthRes.status} - ${healthRes.body}`);

  sleep(0.5);

  // Test posts endpoint
  const postsRes = http.get(`${BASE_URL}/api/posts`);
  check(postsRes, {
    'posts: status 200': (r) => r.status === 200,
  });
  console.log(`Posts: ${postsRes.status} - ${postsRes.body.substring(0, 100)}...`);

  sleep(0.5);

  // Test single post
  const singleRes = http.get(`${BASE_URL}/api/posts/1`);
  check(singleRes, {
    'single post: status 200': (r) => r.status === 200,
  });
  console.log(`Single Post: ${singleRes.status}`);

  sleep(0.5);

  // Test create post
  const createRes = http.post(
    `${BASE_URL}/api/posts`,
    JSON.stringify({ title: 'Smoke Test', body: 'Smoke test body' }),
    { headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' } }
  );
  check(createRes, {
    'create post: status 201': (r) => r.status === 201,
  });
  console.log(`Create Post: ${createRes.status}`);

  sleep(0.5);

  // Test heavy endpoint
  const heavyRes = http.get(`${BASE_URL}/api/heavy`);
  check(heavyRes, {
    'heavy: status 200': (r) => r.status === 200,
  });
  console.log(`Heavy: ${heavyRes.status} - ${heavyRes.body}`);
}
