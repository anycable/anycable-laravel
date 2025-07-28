/*
 * Connection Avalanche Benchmark
 *
 * Simulates a "thundering herd" problem where all clients connect
 * and subscribe to a public channel simultaneously, then disconnect
 * once subscription confirmation is received.
 *
 * Metrics measured:
 * - connection_latency: time to establish WebSocket connection
 * - subscription_latency: time from subscribe to confirmation
 * - success_rate: percentage of successful connections/subscriptions
 */

// Grafana recommends using experimental websockets
// https://grafana.com/docs/k6/latest/javascript-api/k6-experimental/websockets/
import { WebSocket } from 'k6/experimental/websockets';
import { setTimeout, clearTimeout } from 'k6/timers';
import { Trend, Counter, Rate } from 'k6/metrics';
import { check } from 'k6';

const env = (k, d) => __ENV[k] ?? d;
const cfg = {
    key: env('PUSHER_KEY', 'app-key'),
    secret: env('PUSHER_SECRET', 'app-secret'),
    cluster: env('PUSHER_CLUSTER','mt1'),
    // Use public channel for avalanche test
    channel: env('CHANNEL', 'public-avalanche'),

    vus: parseInt(__ENV.VUS || '100'),
    duration: __ENV.DURATION || '30s',
    iterations: parseInt(__ENV.ITERATIONS || '1'),

    // Timeout for connection attempts
    connectionTimeout: parseInt(env('CONNECTION_TIMEOUT', '10000'), 10),

    wsUrl: env('WS_URL',
        `ws://localhost:${ env('WS_PORT', '8080')}/app/${env('PUSHER_KEY','app-key')}`
        + '?protocol=7&client=k6-avalanche&version=0.1'),
    debug: __ENV.DEBUG === '1',
};

const log = (lvl,msg)=>{ if(lvl!=='debug'||cfg.debug) console.log(`[${lvl}] ${msg}`); };

export const options = {
    scenarios: {
        avalanche: {
            executor: 'per-vu-iterations',
            vus: cfg.vus,
            iterations: cfg.iterations,
            // No graceful ramp-up - all connect at once
            gracefulStop: '5s',
        },
    },
    thresholds: {
        checks: ['rate>0.95'], // 95% success rate
        connection_latency: ['p(95)<2000', 'p(99)<5000'],
        subscription_latency: ['p(95)<1000', 'p(99)<3000'],
        connection_success_rate: ['rate>0.95'],
        subscription_success_rate: ['rate>0.95'],
        connection_errors: ['count<' + Math.floor(cfg.vus * 0.05)], // Less than 5% errors
    },
};

const WS_OPEN = 1;
const connTrend = new Trend('connection_latency', true);
const subTrend = new Trend('subscription_latency', true);
const connSuccessRate = new Rate('connection_success_rate');
const subSuccessRate = new Rate('subscription_success_rate');
const connErrCnt = new Counter('connection_errors');
const totalConnections = new Counter('total_connections');
const totalSubscriptions = new Counter('total_subscriptions');

export default function () {
    // No sleep - create avalanche effect by connecting immediately
    const tConnectStart = Date.now();
    const ws = new WebSocket(cfg.wsUrl);

    totalConnections.add(1);

    let connected = false;
    let subscribed = false;
    let subStart = 0;

    // Set overall timeout for this VU
    const timeoutID = setTimeout(() => {
        if (!subscribed) {
            log('error', `VU ${__VU}: Timeout - closing connection`);
            connErrCnt.add(1);
        }
        if (ws.readyState === WS_OPEN) {
            ws.close();
        }
    }, cfg.connectionTimeout);

    ws.addEventListener('error', e => {
        connErrCnt.add(1);
        connSuccessRate.add(false);
        if (!subscribed) {
            subSuccessRate.add(false);
        }
        log('error', `VU ${__VU}: WebSocket error: ${e.error}`);
        clearTimeout(timeoutID);
    });

    ws.addEventListener('open', () => {
        connected = true;
        connSuccessRate.add(true);
        connTrend.add(Date.now() - tConnectStart);

        check(true, { 'connection_established': v => v });
        log('debug', `VU ${__VU}: Connected in ${Date.now() - tConnectStart}ms`);
    });

    ws.addEventListener('message', ({data}) => {
        let msg;
        try {
            msg = JSON.parse(data);
        } catch (e) {
            log('error', `VU ${__VU}: Failed to parse message: ${data}`);
            return;
        }

        switch(msg.event) {
            case 'pusher:ping':
                if (ws.readyState === WS_OPEN) {
                    ws.send(JSON.stringify({ event: 'pusher:pong' }));
                }
                break;

            case 'pusher:connection_established': {
                log('debug', `VU ${__VU}: Connection established, subscribing to ${cfg.channel}`);

                subStart = Date.now();
                totalSubscriptions.add(1);

                // Subscribe to public channel (no auth required)
                ws.send(
                    JSON.stringify({
                        event: 'pusher:subscribe',
                        data: {
                            channel: cfg.channel
                        }
                    })
                );
                break;
            }

            case 'pusher_internal:subscription_succeeded': {
                subscribed = true;
                const subscriptionTime = Date.now() - subStart;
                subTrend.add(subscriptionTime);
                subSuccessRate.add(true);

                check(true, { 'subscription_succeeded': v => v });
                log('debug', `VU ${__VU}: Subscribed in ${subscriptionTime}ms - closing connection`);

                // Mission accomplished - close the connection
                clearTimeout(timeoutID);
                ws.close();
                break;
            }

            case 'pusher_internal:subscription_error':
            case 'pusher:subscription_error': {
                subSuccessRate.add(false);
                log('error', `VU ${__VU}: Subscription failed: ${JSON.stringify(msg.data)}`);
                clearTimeout(timeoutID);
                ws.close();
                break;
            }

            case 'pusher:error':
                log('error', `VU ${__VU}: Pusher error ${msg.data?.code}: ${msg.data?.message}`);
                if (!subscribed) {
                    subSuccessRate.add(false);
                }
                break;

            default:
                if (cfg.debug) {
                    log('debug', `VU ${__VU}: Unhandled event: ${msg.event}`);
                }
        }
    });

    ws.addEventListener('close', (event) => {
        clearTimeout(timeoutID);

        if (!connected) {
            connSuccessRate.add(false);
        }
        if (!subscribed && connected) {
            subSuccessRate.add(false);
        }

        log('debug', `VU ${__VU}: Connection closed (code: ${event.code}, connected: ${connected}, subscribed: ${subscribed})`);
    });
}
