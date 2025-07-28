/*
 * Pusher never sends a client‑event back to its author, so we can’t
 * measure a true RTT without adding custom server‑side hooks
 * (for example Soketi’s webhooks could emit an ACK)
 *
 * Instead we assume all VUs run on the same host (shared clock) and
 * measure latencies this way:
 *
 * - broadcast_latency
 *     ts  = Date.now() // before ws.send
 *     latency = now() – ts // on the first receiving client
 *
 * - connection_latency
 *     Date.now() – connectStart
 *
 * - subscription_latency
 *     Date.now() – subscribeSent // between pusher:subscribe and pusher_internal:subscription_succeeded
 */


// Grafana recommends using experimental websockets
// https://grafana.com/docs/k6/latest/javascript-api/k6-experimental/websockets/
import { WebSocket } from 'k6/experimental/websockets';
import { setTimeout, clearTimeout, setInterval, clearInterval } from 'k6/timers';
import { Trend, Counter } from 'k6/metrics';
import { check, sleep } from 'k6';
import { randomIntBetween } from 'https://jslib.k6.io/k6-utils/1.3.0/index.js';
import crypto from 'k6/crypto';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

const env = (k, d) => __ENV[k] ?? d;
const cfg = {
    key: env('PUSHER_KEY', 'app-key'),
    secret: env('PUSHER_SECRET', 'app-secret'),
    cluster: env('PUSHER_CLUSTER','mt1'),
    channel: env('CHANNEL', 'private-benchmark'),

    vus: parseInt(__ENV.VUS || ''),
    duration: __ENV.DURATION,

    maxVUs: parseInt(env('MAX_VUS','300'),10),
    time: parseInt(env('TIME' ,'120'),10),

    senderRatio: parseFloat(env('SENDER_RATIO','0.20')),
    sendProb: parseFloat(env('SEND_RATE', '0.40')),
    tickMs: parseInt(env('TICK_MS','200'),10),
    lifeMs: parseInt(env('LIFE_MS','45000'),10),

    wsUrl: env('WS_URL',
        `ws://localhost:${ env('WS_PORT', '8080')}/app/${env('PUSHER_KEY','app-key')}`
        + '?protocol=7&client=k6&version=0.1'),
    debug: __ENV.DEBUG === '1',
};

const log = (lvl,msg)=>{ if(lvl!=='debug'||cfg.debug) console.log(`[${lvl}] ${msg}`); };

function rampStages(time,peak){
    return [
        { duration:`${time/3}s`, target:(peak/4)|0 },
        { duration:`${time/2}s`, target: peak },
        { duration:`${time/6}s`, target: 0 },
    ];
}

function buildOptions() {
    if (cfg.vus && cfg.duration) {
        return {
            scenarios: {
                constant: {
                    executor: 'constant-vus',
                    vus: cfg.vus,
                    duration: cfg.duration,
                },
            },
            thresholds: { checks: ['rate>0.99'] },
        };
    }

    return {
        scenarios: {
            ramp: {
                executor: 'ramping-vus',
                startVUs: Math.max(1, (cfg.maxVUs / 10) | 0),
                stages: rampStages(cfg.time, cfg.maxVUs),
                gracefulStop: '2m',
                gracefulRampDown: '2m',
            },
        },
        thresholds: {
            checks: ['rate>0.99'],
            handshake_latency: ['p(95)<1500'],
            subscription_latency: ['p(95)<500'],
            broadcast_latency: ['p(95)<500', 'p(99)<1000'],
            events_received: ['count>0'],
            connection_errors: ['count==0'],
        },
    };
}

export const options = buildOptions();

const WS_OPEN = 1;
const connTrend = new Trend('handshake_latency',   true);
const subTrend = new Trend('subscription_latency', true);
const broadTrend = new Trend('broadcast_latency',    true);
const sentCnt = new Counter('events_sent');
const recvCnt = new Counter('events_received');
const connErrCnt = new Counter('connection_errors');

const uid = () => `${__VU}-${uuidv4()}`;

export default function () {
    sleep(randomIntBetween(2, 10) / 5);

    let sendersMod = (1 / cfg.senderRatio) | 0;
    const isSender = __VU % sendersMod === 0;
    const tConnectStart = Date.now();
    const ws = new WebSocket(cfg.wsUrl);

    ws.addEventListener('error', e=>{
        connErrCnt.add(1);
        check(false,{ 'ws connected':()=>false });
    });

    ws.addEventListener('open', () => {
        check(true, { 'ws connected': v => v });

        let subStart = 0;
        let sendLoopID = null;
        const closeID  = setTimeout(() => ws.close(), cfg.lifeMs);

        ws.addEventListener('message', ({data})=>{
            let msg; try { msg=JSON.parse(data); } catch { return;}

            switch(msg.event){
                // keep‑alive
                case 'pusher:ping':
                    if (ws.readyState === WS_OPEN) {
                        ws.send(JSON.stringify({ event:'pusher:pong' }));
                    }
                    break;

                case 'pusher:connection_established': {
                    connTrend.add(Date.now() - tConnectStart);
                    let connData = JSON.parse(msg.data);
                    const { socket_id } = connData;

                    const sig = crypto.hmac('sha256', cfg.secret, `${socket_id}:${cfg.channel}`,'hex');
                    subStart = Date.now();
                    ws.send(
                        JSON.stringify({
                            event: 'pusher:subscribe',
                            data: {
                                channel: cfg.channel,
                                auth: `${cfg.key}:${sig}`
                            }
                        })
                    );
                    break;
                }

                //  confirmed subscription
                case 'pusher_internal:subscription_succeeded': {
                    if (subStart) subTrend.add(Date.now() - subStart);
                    check(true, { 'subscribed': v => v });

                    //  loop only for senders
                    if (isSender) {
                        sendLoopID = setInterval(()=> {
                            if (ws.readyState !== WS_OPEN) return;
                            // emulate random sending and pausing
                            if (Math.random() > cfg.sendProb) return;

                            // check the case when the server is down
                            try {
                                const payload = { id: uid(), ts: Date.now(), from: __VU };
                                ws.send(JSON.stringify({
                                    event: 'client-broadcast',
                                    channel: cfg.channel,
                                    data: payload,
                                }));

                                sentCnt.add(1);
                            } catch (e) {
                                clearInterval(sendLoopID);
                            }
                        }, cfg.tickMs);
                    }
                    break;
                }

                // every client receives other broadcasts, but not own
                case 'client-broadcast': {
                    recvCnt.add(1);
                    let broadData = msg.data;
                    if (broadData.ts) broadTrend.add(Date.now() - Number(broadData.ts));
                    break;
                }

                case 'pusher:error':
                    log('error',`pusher error ${msg.data.code}: ${msg.data.message}`);
                    break;

                default:
                    if (cfg.debug) console.log(`Unhandled event: ${msg.event}`);
            }
        });

        // cleans timeouts and intervals when closing socket
        ws.addEventListener('close', ()=>{
            if (sendLoopID) clearInterval(sendLoopID);
            clearTimeout(closeID);
        });

        setTimeout(() => {}, randomIntBetween(80,160));
    });
}
