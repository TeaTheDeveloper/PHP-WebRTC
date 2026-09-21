<?php
    declare(strict_types=1);

    const MESSAGE_FILE_PREFIX = '.webrtc_room_';
    const MESSAGE_DIR = __DIR__ . '/data/';
    const ROOM_PATTERN = '/^[a-zA-Z0-9_-]{3,64}$/';
    const MESSAGE_TTL = 300;

    function jsonResponse(array $data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    function getRoom(): string {
        $room = $_GET['room'] ?? $_POST['room'] ?? 'default';
        if (!is_string($room) || !preg_match(ROOM_PATTERN, $room)) {
            jsonResponse(['ok' => false, 'error' => 'Invalid room.'], 400);
        }
        return $room;
    }

    function getClientId(): string {
        $client = $_GET['client'] ?? $_POST['client'] ?? '';
        if (!is_string($client) || !preg_match('/^[a-zA-Z0-9_-]{8,100}$/', $client)) {
            jsonResponse(['ok' => false, 'error' => 'Invalid client ID.'], 400);
        }
        return $client;
    }

    function roomFile(string $room): string {
        if (!is_dir(MESSAGE_DIR)) {
            mkdir(MESSAGE_DIR, 0755, true);
        }

        return MESSAGE_DIR . MESSAGE_FILE_PREFIX . $room . '.json';
    }

    /* ───────────────────────────── Signaling: send ───────────────────────────── */

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'send') {
        $room = getRoom();
        $client = getClientId();
        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            jsonResponse(['ok' => false, 'error' => 'Empty request.'], 400);
        }

        $input = json_decode($raw, true);
        if (!is_array($input)) {
            jsonResponse(['ok' => false, 'error' => 'Invalid JSON.'], 400);
        }

        $event = $input['event'] ?? null;
        $data = $input['data'] ?? null;
        $allowed = ['join', 'offer', 'answer', 'candidate', 'leave'];

        if (!is_string($event) || !in_array($event, $allowed, true)) {
            jsonResponse(['ok' => false, 'error' => 'Invalid signaling event.'], 400);
        }

        $file = roomFile($room);
        $messages = [];

        if (is_file($file)) {
            $contents = file_get_contents($file);
            if ($contents !== false && trim($contents) !== '') {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) $messages = $decoded;
            }
        }

        $cutoff = time() - MESSAGE_TTL;
        $messages = array_values(array_filter($messages, static function ($message) use ($cutoff) {
            return is_array($message)
                && isset($message['time'])
                && (int)$message['time'] >= $cutoff;
        }));

        $messages[] = [
            'id' => bin2hex(random_bytes(16)),
            'client' => $client,
            'event' => $event,
            'data' => $data,
            'time' => time()
        ];

        if (file_put_contents(
            $file,
            json_encode($messages, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) === false) {
            jsonResponse(['ok' => false, 'error' => 'Unable to write signaling data.'], 500);
        }

        jsonResponse(['ok' => true]);
    }

    /* ───────────────────────────── Signaling: poll ───────────────────────────── */

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll') {
        $room = getRoom();
        $client = getClientId();
        $file = roomFile($room);

        if (!is_file($file)) {
            jsonResponse(['ok' => true, 'messages' => []]);
        }

        $contents = file_get_contents($file);
        if ($contents === false || trim($contents) === '') {
            jsonResponse(['ok' => true, 'messages' => []]);
        }

        $messages = json_decode($contents, true);
        if (!is_array($messages)) {
            jsonResponse(['ok' => true, 'messages' => []]);
        }

        $cutoff = time() - MESSAGE_TTL;

        $messages = array_values(array_filter($messages, static function ($message) use ($client, $cutoff) {
            return is_array($message)
                && isset($message['client'], $message['time'])
                && $message['client'] !== $client
                && (int)$message['time'] >= $cutoff;
        }));

        jsonResponse(['ok' => true, 'messages' => $messages]);
    }

    /* ───────────────────────────── Page ───────────────────────────── */

    $room = isset($_GET['room']) && is_string($_GET['room']) && preg_match(ROOM_PATTERN, $_GET['room'])
        ? $_GET['room']
        : 'default';

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,user-scalable=no">
    <meta name="theme-color" content="#050507">
    <title>PeerCall</title>

    <style>
    *{box-sizing:border-box}
    html,body{width:100%;height:100%;margin:0;overflow:hidden;background:#050507;color:#fff;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
    body{user-select:none;-webkit-user-select:none}
    button{font:inherit;border:0}
    .app{position:relative;width:100%;height:100dvh;min-height:100vh;background:#050507;overflow:hidden}
    .video-stage{position:absolute;inset:0;background:#08080b}
    video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#08080b}
    #localVideo{z-index:2;display:block;transform:scaleX(-1)}
    #remoteVideo{z-index:1;display:none}
    .remote-active #localVideo{position:absolute;top:24px;right:20px;left:auto;width:clamp(110px,20vw,230px);height:clamp(150px,28vw,300px);border:1px solid rgba(255,255,255,.18);border-radius:18px;box-shadow:0 14px 40px rgba(0,0,0,.4);object-fit:cover}
    .remote-active #remoteVideo{display:block}
    .remote-active .local-placeholder{display:none}

    .vignette{position:absolute;z-index:3;inset:0;pointer-events:none;background:linear-gradient(180deg,rgba(0,0,0,.48),transparent 25%,transparent 62%,rgba(0,0,0,.7))}
    .topbar{position:absolute;z-index:10;top:0;left:0;right:0;padding:18px max(18px,env(safe-area-inset-left)) 0 max(18px,env(safe-area-inset-right));display:flex;align-items:center;justify-content:space-between;pointer-events:none}
    .brand{display:flex;align-items:center;gap:10px;font-weight:700;letter-spacing:-.02em}
    .brand-mark{width:34px;height:34px;border-radius:11px;display:grid;place-items:center;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.14);backdrop-filter:blur(16px);font-size:15px}
    .brand span{font-size:15px}
    .room-pill{display:flex;align-items:center;gap:8px;max-width:52vw;padding:8px 11px;border-radius:999px;background:rgba(10,10,14,.55);border:1px solid rgba(255,255,255,.12);backdrop-filter:blur(16px);font-size:12px;color:rgba(255,255,255,.8);pointer-events:auto}
    .room-name{max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#fff;font-weight:600}
    .copy-btn{width:27px;height:27px;padding:0;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.1);color:#fff;cursor:pointer}
    .copy-btn:hover{background:rgba(255,255,255,.18)}

    .status-wrap{position:absolute;z-index:10;top:70px;left:50%;transform:translateX(-50%);pointer-events:none}
    .status{display:flex;align-items:center;gap:8px;padding:7px 11px;border-radius:999px;background:rgba(10,10,14,.5);border:1px solid rgba(255,255,255,.1);backdrop-filter:blur(14px);font-size:12px;color:rgba(255,255,255,.78);white-space:nowrap}
    .status-dot{width:7px;height:7px;border-radius:50%;background:#aaa}
    .status-dot.ready{background:#62d98b}
    .status-dot.calling{background:#f3c85b;box-shadow:0 0 0 4px rgba(243,200,91,.12)}
    .status-dot.connected{background:#55e28a;box-shadow:0 0 0 4px rgba(85,226,138,.12)}
    .status-dot.error{background:#ff6262}

    .local-label{position:absolute;z-index:5;top:calc(24px + clamp(150px,28vw,300px) + 8px);right:20px;display:none;font-size:10px;color:rgba(255,255,255,.65)}
    .remote-active .local-label{display:block}

    .empty-state{position:absolute;z-index:4;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:30px;pointer-events:none}
    .empty-inner{max-width:390px}
    .camera-icon{width:68px;height:68px;margin:0 auto 18px;border-radius:22px;display:grid;place-items:center;background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.1);font-size:26px}
    .empty-state h1{margin:0 0 8px;font-size:clamp(22px,4vw,34px);letter-spacing:-.04em}
    .empty-state p{margin:0;color:rgba(255,255,255,.55);font-size:14px;line-height:1.5}
    .remote-active .empty-state{display:none}

    .bottom{position:absolute;z-index:10;left:0;right:0;bottom:0;padding:20px max(18px,env(safe-area-inset-right)) max(20px,env(safe-area-inset-bottom)) max(18px,env(safe-area-inset-left));display:flex;flex-direction:column;align-items:center;gap:14px}
    .controls{display:flex;align-items:center;justify-content:center;gap:12px;padding:8px 10px;border-radius:22px;background:rgba(8,8,11,.68);border:1px solid rgba(255,255,255,.1);backdrop-filter:blur(20px);box-shadow:0 18px 50px rgba(0,0,0,.3)}
    .control{width:48px;height:48px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.1);color:#fff;cursor:pointer;transition:.18s transform,.18s background}
    .control:hover{background:rgba(255,255,255,.17);transform:translateY(-1px)}
    .control:active{transform:scale(.94)}
    .control.primary{width:58px;height:58px;background:#fff;color:#08080b}
    .control.danger{background:#e54848;color:#fff}
    .control:disabled{opacity:.45;cursor:not-allowed;transform:none}
    .control svg{width:21px;height:21px;fill:currentColor}
    .hint{font-size:11px;color:rgba(255,255,255,.42);text-align:center}

    .toast{position:fixed;z-index:300;left:50%;bottom:105px;transform:translate(-50%,20px);padding:9px 13px;border-radius:999px;background:rgba(20,20,24,.9);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:12px;opacity:0;pointer-events:none;transition:.2s}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    .error{position:absolute;z-index:200;left:18px;right:18px;bottom:115px;display:none;padding:13px 15px;border-radius:14px;background:rgba(155,25,25,.9);border:1px solid rgba(255,130,130,.2);font-size:13px;line-height:1.4}

    @media(max-width:600px){
        .topbar{padding-top:max(14px,env(safe-area-inset-top))}
        .brand span{display:none}
        .room-pill{max-width:58vw}
        .status-wrap{top:64px}
        .remote-active #localVideo{top:auto;right:14px;bottom:115px;width:108px;height:148px;border-radius:15px}
        .local-label{top:auto;right:18px;bottom:101px;font-size:9px}
        .bottom{padding-bottom:max(14px,env(safe-area-inset-bottom))}
        .controls{gap:8px;padding:7px 8px;border-radius:20px}
        .control{width:44px;height:44px}
        .control.primary{width:54px;height:54px}
        .hint{font-size:10px}
    }

    @media(min-width:1000px){
        .bottom{padding-bottom:28px}
        .controls{gap:14px}
    }
    </style>
    </head>

    <body>

    <div class="app" id="app">

        <div class="video-stage">
            <video id="remoteVideo" autoplay playsinline></video>
            <video id="localVideo" autoplay muted playsinline></video>
            <div class="vignette"></div>
        </div>

        <div class="topbar">
            <div class="brand">
                <div class="brand-mark">P</div>
                <span>PeerCall</span>
            </div>

            <div class="room-pill">
                <span>Room</span>
                <span class="room-name" id="roomName"><?= htmlspecialchars($room, ENT_QUOTES, 'UTF-8') ?></span>
                <button class="copy-btn" id="copyButton" title="Copy room link">
                    <svg viewBox="0 0 24 24"><path d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12V1zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11v14z"/></svg>
                </button>
            </div>
        </div>

        <div class="status-wrap">
            <div class="status">
                <span class="status-dot" id="statusDot"></span>
                <span id="status">Starting camera...</span>
            </div>
        </div>

        <div class="empty-state">
            <div class="empty-inner">
                <div class="camera-icon">⌁</div>
                <h1>Ready for a private call</h1>
                <p>Share this room with another device. Your video connects directly through WebRTC.</p>
            </div>
        </div>

        <div class="local-label">You</div>

        <div class="error" id="error"></div>

        <div class="bottom">
            <div class="controls">
                <button class="control" id="cameraButton" title="Toggle camera">
                    <svg viewBox="0 0 24 24"><path d="M17 10.5V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3.5l4 2.5v-8l-4 2.5z"/></svg>
                </button>

                <button class="control primary" id="callButton" title="Start call">
                    <svg viewBox="0 0 24 24"><path d="M6.62 10.79a15.46 15.46 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.01-.24c1.12.37 2.33.56 3.58.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.72 21 3 13.28 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.19 2.46.56 3.58a1 1 0 0 1-.25 1.01l-2.19 2.2z"/></svg>
                </button>

                <button class="control danger" id="hangupButton" title="End call" style="display:none">
                    <svg viewBox="0 0 24 24"><path d="M6.62 10.79a15.46 15.46 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.01-.24c1.12.37 2.33.56 3.58.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.72 21 3 13.28 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.19 2.46.56 3.58a1 1 0 0 1-.25 1.01l-2.19 2.2z" transform="rotate(135 12 12)"/></svg>
                </button>

                <button class="control" id="flipButton" title="Switch camera">
                    <svg viewBox="0 0 24 24"><path d="M17.65 6.35A7.95 7.95 0 0 0 12 4V1L8 5l4 4V6c1.66 0 3.14.69 4.22 1.78A5.94 5.94 0 0 1 18 12h2a7.97 7.97 0 0 0-2.35-5.65zM6 12c0-1.66.69-3.14 1.78-4.22L6.37 6.37A7.95 7.95 0 0 0 4 12c0 2.21.9 4.21 2.35 5.65A7.95 7.95 0 0 0 12 20v3l4-4-4-4v3c-1.66 0-3.14-.69-4.22-1.78A5.94 5.94 0 0 1 6 12z"/></svg>
                </button>
            </div>

            <div class="hint" id="hint">Waiting for another person to join</div>
        </div>

        <div class="toast" id="toast">Copied</div>

    </div>

    <script>
    'use strict';

    const ROOM = <?= json_encode($room) ?>;
    const SIGNAL_BASE = window.location.pathname;
    const POLL_INTERVAL = 500;

    const ICE_CONFIG = {
        iceServers: [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun.cloudflare.com:3478' }
        ]
    };

    const app = document.getElementById('app');
    const localVideo = document.getElementById('localVideo');
    const remoteVideo = document.getElementById('remoteVideo');
    const statusEl = document.getElementById('status');
    const statusDot = document.getElementById('statusDot');
    const hint = document.getElementById('hint');
    const errorEl = document.getElementById('error');
    const callButton = document.getElementById('callButton');
    const hangupButton = document.getElementById('hangupButton');
    const cameraButton = document.getElementById('cameraButton');
    const flipButton = document.getElementById('flipButton');
    const copyButton = document.getElementById('copyButton');
    const toast = document.getElementById('toast');

    function clientId() {
        if (crypto?.randomUUID) return crypto.randomUUID().replace(/-/g, '');
        return Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    const CLIENT_ID = clientId();

    let localStream = null;
    let peerConnection = null;
    let pollTimer = null;
    let polling = false;
    let processed = new Set();
    let pendingCandidates = [];
    let remoteDescriptionReady = false;
    let isCalling = false;
    let isConnected = false;
    let currentFacingMode = 'user';

    function setStatus(message, type = '') {
        statusEl.textContent = message;
        statusDot.className = 'status-dot ' + type;
    }

    function showError(message) {
        console.error(message);
        errorEl.textContent = message;
        errorEl.style.display = 'block';
        setStatus('Something went wrong', 'error');
    }

    function hideError() {
        errorEl.style.display = 'none';
    }

    function showToast(message) {
        toast.textContent = message;
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 1600);
    }

    async function sendSignal(event, data = null) {
        const response = await fetch(
            `${SIGNAL_BASE}?action=send&room=${encodeURIComponent(ROOM)}&client=${encodeURIComponent(CLIENT_ID)}`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event, data })
            }
        );

        if (!response.ok) throw new Error(`Signaling HTTP ${response.status}`);

        const result = await response.json();

        if (!result.ok) throw new Error(result.error || 'Signaling failed');

        return result;
    }

    async function poll() {
        if (polling) return;
        polling = true;

        try {
            const response = await fetch(
                `${SIGNAL_BASE}?action=poll&room=${encodeURIComponent(ROOM)}&client=${encodeURIComponent(CLIENT_ID)}&_=${Date.now()}`,
                { cache: 'no-store' }
            );

            if (!response.ok) throw new Error(`Polling HTTP ${response.status}`);

            const result = await response.json();

            if (result.ok && Array.isArray(result.messages)) {
                for (const message of result.messages) {
                    await processMessage(message);
                }
            }
        } catch (error) {
            console.error('Polling:', error);
        } finally {
            polling = false;
            pollTimer = setTimeout(poll, POLL_INTERVAL);
        }
    }

    async function processMessage(message) {
        if (!message?.id || processed.has(message.id)) return;

        processed.add(message.id);

        if (processed.size > 500) {
            processed.delete(processed.values().next().value);
        }

        console.log('[SIGNAL]', message.event);

        switch (message.event) {
            case 'join':
                if (!isCalling && !isConnected) await createOffer();
                break;
            case 'offer':
                await handleOffer(message.data);
                break;
            case 'answer':
                await handleAnswer(message.data);
                break;
            case 'candidate':
                await handleCandidate(message.data);
                break;
            case 'leave':
                handleLeave();
                break;
        }
    }

    function createPeerConnection() {
        if (peerConnection) return peerConnection;

        peerConnection = new RTCPeerConnection(ICE_CONFIG);

        if (localStream) {
            localStream.getTracks().forEach(track => {
                peerConnection.addTrack(track, localStream);
            });
        }

        peerConnection.onicecandidate = async event => {
            if (!event.candidate) return;

            try {
                await sendSignal('candidate', event.candidate.toJSON());
            } catch (error) {
                console.error('ICE send:', error);
            }
        };

        peerConnection.ontrack = async event => {
            remoteVideo.srcObject = event.streams?.[0] || remoteVideo.srcObject;

            if (!remoteVideo.srcObject && event.track) {
                remoteVideo.srcObject = new MediaStream([event.track]);
            }

            app.classList.add('remote-active');

            try {
                await remoteVideo.play();
            } catch (_) {}

            setStatus('Connected', 'connected');
            hint.textContent = 'Peer-to-peer connection active';
        };

        peerConnection.onconnectionstatechange = () => {
            if (!peerConnection) return;

            const state = peerConnection.connectionState;

            if (state === 'connecting') {
                setStatus('Connecting...', 'calling');
            } else if (state === 'connected') {
                isConnected = true;
                setStatus('Connected', 'connected');
                hint.textContent = 'Peer-to-peer connection active';
                callButton.style.display = 'none';
                hangupButton.style.display = 'grid';
            } else if (state === 'disconnected') {
                isConnected = false;
                setStatus('Connection interrupted', 'calling');
            } else if (state === 'failed') {
                isConnected = false;
                setStatus('Connection failed', 'error');
                hint.textContent = 'Try starting the call again';
            } else if (state === 'closed') {
                isConnected = false;
                setStatus('Call ended');
            }
        };

        return peerConnection;
    }

    async function createOffer() {
        if (isCalling || isConnected) return;

        isCalling = true;
        hideError();
        setStatus('Calling...', 'calling');
        hint.textContent = 'Connecting to the other device';

        try {
            const pc = createPeerConnection();
            const offer = await pc.createOffer();

            await pc.setLocalDescription(offer);
            await waitForIce(pc);

            await sendSignal('offer', pc.localDescription);
        } catch (error) {
            isCalling = false;
            showError('Could not start the call: ' + error.message);
        }
    }

    async function handleOffer(offer) {
        try {
            hideError();
            setStatus('Incoming call...', 'calling');
            hint.textContent = 'Accepting peer connection';

            const pc = createPeerConnection();

            await pc.setRemoteDescription(
                new RTCSessionDescription(offer)
            );

            remoteDescriptionReady = true;
            await flushCandidates();

            const answer = await pc.createAnswer();

            await pc.setLocalDescription(answer);
            await waitForIce(pc);

            await sendSignal('answer', pc.localDescription);
        } catch (error) {
            showError('Could not answer the call: ' + error.message);
        }
    }

    async function handleAnswer(answer) {
        if (!peerConnection) return;

        try {
            await peerConnection.setRemoteDescription(
                new RTCSessionDescription(answer)
            );

            remoteDescriptionReady = true;
            await flushCandidates();

            setStatus('Connecting...', 'calling');
        } catch (error) {
            showError('Could not process the answer: ' + error.message);
        }
    }

    async function handleCandidate(data) {
        if (!data) return;

        const candidate = new RTCIceCandidate(data);

        if (!peerConnection || !remoteDescriptionReady) {
            pendingCandidates.push(candidate);
            return;
        }

        try {
            await peerConnection.addIceCandidate(candidate);
        } catch (error) {
            console.error('ICE candidate:', error);
        }
    }

    async function flushCandidates() {
        if (!peerConnection) return;

        while (pendingCandidates.length) {
            try {
                await peerConnection.addIceCandidate(
                    pendingCandidates.shift()
                );
            } catch (error) {
                console.error('Pending ICE:', error);
            }
        }
    }

    function waitForIce(pc) {
        return new Promise(resolve => {
            if (pc.iceGatheringState === 'complete') {
                resolve();
                return;
            }

            const check = () => {
                if (pc.iceGatheringState === 'complete') {
                    pc.removeEventListener('icegatheringstatechange', check);
                    resolve();
                }
            };

            pc.addEventListener('icegatheringstatechange', check);
            setTimeout(resolve, 5000);
        });
    }

    function handleLeave() {
        if (peerConnection) {
            peerConnection.close();
            peerConnection = null;
        }

        remoteVideo.srcObject = null;
        app.classList.remove('remote-active');

        remoteDescriptionReady = false;
        pendingCandidates = [];
        isConnected = false;
        isCalling = false;

        callButton.style.display = 'grid';
        hangupButton.style.display = 'none';

        setStatus('Waiting for another person...');
        hint.textContent = 'Waiting for another person to join';
    }

    async function hangUp() {
        try {
            await sendSignal('leave');
        } catch (_) {}

        handleLeave();
    }

    async function startCamera() {
        if (!navigator.mediaDevices?.getUserMedia) {
            throw new Error(
                'Camera access requires HTTPS or localhost.'
            );
        }

        localStream = await navigator.mediaDevices.getUserMedia({
            video: {
                width: { ideal: 1280 },
                height: { ideal: 720 },
                facingMode: currentFacingMode
            },
            audio: false
        });

        localVideo.srcObject = localStream;
        await localVideo.play();

        setStatus('Ready', 'ready');
    }

    async function switchCamera() {
        if (!localStream) return;

        const videoTrack = localStream.getVideoTracks()[0];
        if (!videoTrack) return;

        currentFacingMode =
            currentFacingMode === 'user'
                ? 'environment'
                : 'user';

        try {
            const newStream =
                await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: currentFacingMode
                    },
                    audio: false
                });

            const newTrack =
                newStream.getVideoTracks()[0];

            const oldTrack =
                localStream.getVideoTracks()[0];

            const sender =
                peerConnection
                    ?.getSenders()
                    .find(s => s.track?.kind === 'video');

            if (sender) {
                await sender.replaceTrack(newTrack);
            }

            oldTrack?.stop();

            localStream.removeTrack(oldTrack);
            localStream.addTrack(newTrack);

            localVideo.srcObject = localStream;
        } catch (error) {
            currentFacingMode =
                currentFacingMode === 'user'
                    ? 'environment'
                    : 'user';

            console.error(
                'Camera switch:',
                error
            );
        }
    }

    function toggleCamera() {
        const track =
            localStream?.getVideoTracks()[0];

        if (!track) return;

        track.enabled = !track.enabled;

        cameraButton.style.opacity =
            track.enabled ? '1' : '.5';

        showToast(
            track.enabled
                ? 'Camera on'
                : 'Camera off'
        );
    }

    async function copyRoomLink() {
        try {
            await navigator.clipboard.writeText(
                window.location.href
            );

            showToast('Room link copied');
        } catch (_) {
            showToast('Copy failed');
        }
    }

    callButton.addEventListener(
        'click',
        createOffer
    );

    hangupButton.addEventListener(
        'click',
        hangUp
    );

    cameraButton.addEventListener(
        'click',
        toggleCamera
    );

    flipButton.addEventListener(
        'click',
        switchCamera
    );

    copyButton.addEventListener(
        'click',
        copyRoomLink
    );

    window.addEventListener(
        'beforeunload',
        () => {
            try {
                navigator.sendBeacon(
                    `${SIGNAL_BASE}?action=send&room=${encodeURIComponent(ROOM)}&client=${encodeURIComponent(CLIENT_ID)}`,
                    JSON.stringify({
                        event: 'leave',
                        data: null
                    })
                );
            } catch (_) {}

            if (pollTimer) clearTimeout(pollTimer);
            if (peerConnection) peerConnection.close();

            localStream?.getTracks().forEach(
                track => track.stop()
            );
        }
    );

    async function start() {
        try {
            await startCamera();
            poll();

            await sendSignal('join');

            setStatus(
                'Waiting for another person...',
                'ready'
            );
        } catch (error) {
            showError(
                error.message ||
                'Unable to start the camera.'
            );
        }
    }

    start();
    </script>

    </body>
    </html>