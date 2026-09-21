<?php

    const MESSAGE_FILE_PREFIX = '.webrtc_room_';
    const MESSAGE_DIR = __DIR__ . '/data/';
    const ROOM_PATTERN = '/^[a-zA-Z0-9_-]{3,64}$/';
    const MESSAGE_TTL = 300;

    function roomFile(string $room): string
    {
        if (!is_dir(MESSAGE_DIR)) {
            mkdir(MESSAGE_DIR, 0755, true);
        }

        return MESSAGE_DIR . MESSAGE_FILE_PREFIX . $room . '.json';
    }

    function cleanMessages(array $messages): array
    {
        $cutoff = time() - MESSAGE_TTL;

        return array_values(array_filter(
            $messages,
            static fn($message) =>
                isset($message['time']) &&
                $message['time'] >= $cutoff
        ));
    }

    function readMessages(string $room): array
    {
        $file = roomFile($room);

        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);

        if ($content === false || $content === '') {
            return [];
        }

        $messages = json_decode($content, true);

        if (!is_array($messages)) {
            return [];
        }

        $messages = cleanMessages($messages);

        file_put_contents(
            $file,
            json_encode($messages, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        return $messages;
    }

    function writeMessages(string $room, array $messages): void
    {
        $file = roomFile($room);

        file_put_contents(
            $file,
            json_encode($messages, JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    function getRoom(): ?string
    {
        $room = $_GET['room'] ?? null;

        if (!is_string($room) || !preg_match(ROOM_PATTERN, $room)) {
            return null;
        }

        return $room;
    }

    $action = $_GET['action'] ?? null;

    if ($action === 'send') {
        header('Content-Type: application/json; charset=utf-8');

        $room = getRoom();
        $client = $_GET['client'] ?? '';

        if (!$room || !is_string($client) || $client === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid room or client.'
            ]);
            exit;
        }

        $input = file_get_contents('php://input');
        $payload = json_decode($input ?: '', true);

        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid JSON payload.'
            ]);
            exit;
        }

        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? null;

        $allowed = [
            'join',
            'offer',
            'answer',
            'candidate',
            'leave',
            'decline'
        ];

        if (!is_string($event) || !in_array($event, $allowed, true)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid event.'
            ]);
            exit;
        }

        $messages = readMessages($room);

        $messages[] = [
            'id' => bin2hex(random_bytes(8)),
            'client' => $client,
            'event' => $event,
            'data' => $data,
            'time' => time()
        ];

        writeMessages($room, $messages);

        echo json_encode([
            'success' => true
        ]);

        exit;
    }

    if ($action === 'poll') {
        header('Content-Type: application/json; charset=utf-8');

        $room = getRoom();
        $client = $_GET['client'] ?? '';

        if (!$room || !is_string($client) || $client === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid room or client.'
            ]);
            exit;
        }

        $messages = readMessages($room);

        $messages = array_values(array_filter(
            $messages,
            static fn($message) =>
                isset($message['client']) &&
                $message['client'] !== $client
        ));

        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Generate a unique room/call ID for a new call
    |--------------------------------------------------------------------------
    */

    $room = isset($_GET['room']) &&
            is_string($_GET['room']) &&
            preg_match(ROOM_PATTERN, $_GET['room'])
        ? $_GET['room']
        : 'call_' . bin2hex(random_bytes(8));

    ?>
    <!DOCTYPE html>
    <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta
                name="viewport"
                content="width=device-width, initial-scale=1.0, viewport-fit=cover"
            >

            <title>PeerCall</title>

            <style>
                @charset "utf-8";

                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                :root {
                    --bg: #08080b;
                    --panel: rgba(18, 18, 23, .92);
                    --panel-border: rgba(255, 255, 255, .1);
                    --text: #fff;
                    --muted: rgba(255, 255, 255, .55);
                    --danger: #ff4d67;
                    --success: #35d07f;
                }

                html,
                body {
                    width: 100%;
                    height: 100%;
                    overflow: hidden;
                    background: var(--bg);
                    color: var(--text);
                    font-family:
                        Inter,
                        ui-sans-serif,
                        system-ui,
                        -apple-system,
                        BlinkMacSystemFont,
                        "Segoe UI",
                        sans-serif;
                }

                body {
                    position: relative;
                }

                button {
                    border: 0;
                    font: inherit;
                }

                .app {
                    position: relative;
                    width: 100%;
                    height: 100dvh;
                    overflow: hidden;
                    background:
                        radial-gradient(
                            circle at 50% 30%,
                            rgba(255,255,255,.045),
                            transparent 40%
                        ),
                        #08080b;
                }

                ===============
                | Remote video
                ===============

                .remote-video {
                    position: absolute;
                    inset: 0;
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    background: #050507;
                }

                ===============
                | Local video
                ===============

                .local-video {
                    position: absolute;
                    z-index: 20;
                    top: 82px;
                    right: 24px;
                    width: min(260px, 27vw);
                    aspect-ratio: 16 / 9;
                    object-fit: cover;
                    background: #111116;
                    border: 1px solid rgba(255,255,255,.14);
                    border-radius: 18px;
                    box-shadow: 0 15px 45px rgba(0,0,0,.45);
                    transform: scaleX(-1);
                }

                ===============
                | Top bar
                ===============

                .topbar {
                    position: absolute;
                    z-index: 30;
                    top: 0;
                    left: 0;
                    right: 0;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding:
                        max(20px, env(safe-area-inset-top))
                        24px
                        20px;
                    pointer-events: none;
                }

                .brand {
                    font-size: 18px;
                    font-weight: 700;
                    letter-spacing: -.03em;
                    pointer-events: auto;
                }

                .top-right {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    pointer-events: auto;
                }

                .room-pill,
                .status-pill {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    min-height: 38px;
                    padding: 0 13px;
                    border: 1px solid var(--panel-border);
                    border-radius: 12px;
                    background: rgba(10,10,14,.72);
                    backdrop-filter: blur(14px);
                }

                .room-pill {
                    max-width: 260px;
                }

                .room-id {
                    max-width: 180px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                    color: rgba(255,255,255,.68);
                    font-size: 12px;
                }

                .copy-button {
                    width: 28px;
                    height: 28px;
                    display: grid;
                    place-items: center;
                    border-radius: 8px;
                    background: rgba(255,255,255,.08);
                    color: #fff;
                    cursor: pointer;
                }

                .copy-button:hover {
                    background: rgba(255,255,255,.14);
                }

                .status-pill {
                    color: rgba(255,255,255,.65);
                    font-size: 12px;
                }

                .status-dot {
                    width: 7px;
                    height: 7px;
                    border-radius: 50%;
                    background: rgba(255,255,255,.35);
                }

                .status-pill.connected .status-dot {
                    background: var(--success);
                    box-shadow: 0 0 12px rgba(53,208,127,.7);
                }

                .status-pill.calling .status-dot {
                    background: #ffd45c;
                    box-shadow: 0 0 12px rgba(255,212,92,.7);
                }

                .status-pill.error .status-dot {
                    background: var(--danger);
                    box-shadow: 0 0 12px rgba(255,77,103,.7);
                }

                ===============
                | Empty state
                ===============

                .empty-state {
                    position: absolute;
                    z-index: 5;
                    inset: 0;
                    display: grid;
                    place-items: center;
                    padding: 100px 24px 170px;
                    text-align: center;
                    pointer-events: none;
                }

                .empty-content {
                    max-width: 420px;
                }

                .empty-icon {
                    width: 72px;
                    height: 72px;
                    margin: 0 auto 22px;
                    display: grid;
                    place-items: center;
                    border-radius: 22px;
                    background: rgba(255,255,255,.06);
                    border: 1px solid rgba(255,255,255,.08);
                    font-size: 28px;
                }

                .empty-state h1 {
                    margin-bottom: 10px;
                    font-size: clamp(26px, 5vw, 40px);
                    letter-spacing: -.045em;
                }

                .empty-state p {
                    color: var(--muted);
                    line-height: 1.6;
                    font-size: 14px;
                }

                ===============
                | Controls
                ===============

                .controls {
                    position: absolute;
                    z-index: 40;
                    left: 50%;
                    bottom: max(28px, env(safe-area-inset-bottom));
                    transform: translateX(-50%);
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    padding: 10px;
                    border: 1px solid rgba(255,255,255,.1);
                    border-radius: 20px;
                    background: rgba(12,12,16,.84);
                    backdrop-filter: blur(18px);
                    box-shadow: 0 20px 60px rgba(0,0,0,.4);
                }

                .control-button {
                    width: 48px;
                    height: 48px;
                    display: grid;
                    place-items: center;
                    border-radius: 14px;
                    background: rgba(255,255,255,.08);
                    color: #fff;
                    cursor: pointer;
                    transition:
                        transform .15s ease,
                        background .15s ease;
                }

                .control-button:hover {
                    background: rgba(255,255,255,.14);
                }

                .control-button:active {
                    transform: scale(.95);
                }

                .control-button.primary {
                    background: #fff;
                    color: #08080b;
                }

                .control-button.danger {
                    background: var(--danger);
                    color: #fff;
                }

                .control-button.hidden {
                    display: none;
                }

                ===============
                | Error
                ===============

                .error-message {
                    position: absolute;
                    z-index: 100;
                    left: 50%;
                    top: 85px;
                    transform: translateX(-50%);
                    display: none;
                    width: min(460px, calc(100% - 40px));
                    padding: 12px 16px;
                    border: 1px solid rgba(255,77,103,.3);
                    border-radius: 12px;
                    background: rgba(50,10,17,.92);
                    color: #ffb3bf;
                    font-size: 13px;
                    text-align: center;
                    backdrop-filter: blur(12px);
                }

                .error-message.show {
                    display: block;
                }

                ===============
                | Toast
                ===============

                .toast {
                    position: absolute;
                    z-index: 200;
                    left: 50%;
                    bottom: 110px;
                    transform: translate(-50%, 15px);
                    opacity: 0;
                    pointer-events: none;
                    padding: 11px 16px;
                    border: 1px solid rgba(255,255,255,.1);
                    border-radius: 12px;
                    background: rgba(20,20,25,.92);
                    color: rgba(255,255,255,.85);
                    font-size: 13px;
                    backdrop-filter: blur(14px);
                    transition:
                        opacity .2s ease,
                        transform .2s ease;
                }

                .toast.show {
                    opacity: 1;
                    transform: translate(-50%, 0);
                }

                ===============
                | Incoming call
                ===============

                .incoming-call {
                    position: fixed;
                    z-index: 250;
                    inset: 0;
                    display: none;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                    background: rgba(0,0,0,.55);
                    backdrop-filter: blur(12px);
                }

                .incoming-call.show {
                    display: flex;
                }

                .incoming-card {
                    width: min(360px, 100%);
                    padding: 28px;
                    text-align: center;
                    border: 1px solid rgba(255,255,255,.12);
                    border-radius: 24px;
                    background: rgba(18,18,23,.94);
                    box-shadow: 0 25px 80px rgba(0,0,0,.5);
                }

                .incoming-icon {
                    width: 64px;
                    height: 64px;
                    margin: 0 auto 18px;
                    display: grid;
                    place-items: center;
                    border-radius: 20px;
                    background: rgba(255,255,255,.09);
                    font-size: 25px;
                }

                .incoming-card h2 {
                    margin: 0 0 7px;
                    font-size: 22px;
                }

                .incoming-card p {
                    margin: 0 0 24px;
                    color: rgba(255,255,255,.55);
                    font-size: 14px;
                }

                .incoming-actions {
                    display: flex;
                    gap: 10px;
                }

                .incoming-actions button {
                    flex: 1;
                    height: 46px;
                    border-radius: 13px;
                    font-weight: 600;
                    cursor: pointer;
                }

                .decline-btn {
                    background: rgba(255,255,255,.08);
                    color: #fff;
                }

                .accept-btn {
                    background: #fff;
                    color: #08080b;
                }

                ===============
                | Mobile
                ===============

                @media (max-width: 700px) {
                    .topbar {
                        padding-left: 16px;
                        padding-right: 16px;
                    }

                    .brand {
                        font-size: 16px;
                    }

                    .status-pill {
                        display: none;
                    }

                    .room-pill {
                        max-width: 210px;
                    }

                    .room-id {
                        max-width: 125px;
                    }

                    .local-video {
                        top: auto;
                        right: 16px;
                        bottom: 105px;
                        width: 34vw;
                        min-width: 120px;
                        border-radius: 14px;
                    }

                    .controls {
                        bottom: max(18px, env(safe-area-inset-bottom));
                        width: max-content;
                    }

                    .control-button {
                        width: 46px;
                        height: 46px;
                    }

                    .empty-state {
                        padding-bottom: 150px;
                    }
                }

                @media (max-width: 430px) {
                    .room-pill {
                        max-width: 165px;
                    }

                    .room-id {
                        max-width: 85px;
                    }

                    .controls {
                        gap: 8px;
                        padding: 8px;
                    }

                    .control-button {
                        width: 44px;
                        height: 44px;
                    }
                }
            </style>
        </head>

        <body>

        <div class="app">

            <video
                id="remoteVideo"
                class="remote-video"
                autoplay
                playsinline
            ></video>

            <video
                id="localVideo"
                class="local-video"
                autoplay
                muted
                playsinline
            ></video>

            <div class="topbar">

                <div class="brand">
                    PeerCall
                </div>

                <div class="top-right">

                    <div class="room-pill">

                        <span class="room-id" id="roomId">
                            <?= htmlspecialchars($room, ENT_QUOTES, 'UTF-8') ?>
                        </span>

                        <button
                            class="copy-button"
                            id="copyButton"
                            type="button"
                            title="Copy call link"
                            aria-label="Copy call link"
                        >
                            ⧉
                        </button>

                    </div>

                    <div class="status-pill" id="statusPill">

                        <span class="status-dot"></span>

                        <span id="statusText">
                            Ready
                        </span>

                    </div>

                </div>

            </div>


            <div class="empty-state" id="emptyState">

                <div class="empty-content">

                    <div class="empty-icon">
                        ◉
                    </div>

                    <h1>
                        Ready when you are
                    </h1>

                    <p id="hint">
                        Share the call link with someone, then start a video call.
                    </p>

                </div>

            </div>


            <div class="error-message" id="errorMessage"></div>


            <div class="controls">

                <button
                    class="control-button hidden"
                    id="cameraButton"
                    type="button"
                    title="Toggle camera"
                    aria-label="Toggle camera"
                >
                    ◉
                </button>

                <button
                    class="control-button hidden"
                    id="micButton"
                    type="button"
                    title="Toggle mic"
                    aria-label="Toggle mic"
                >
                    🎤
                </button>

                <button
                    class="control-button primary"
                    id="callButton"
                    type="button"
                    title="Start call"
                    aria-label="Start call"
                >
                    ☎
                </button>

                <button
                    class="control-button danger hidden"
                    id="hangupButton"
                    type="button"
                    title="Hang up"
                    aria-label="Hang up"
                >
                    ✕
                </button>

                <button
                    class="control-button hidden"
                    id="flipButton"
                    type="button"
                    title="Flip camera"
                    aria-label="Flip camera"
                >
                    ↻
                </button>

            </div>


            <!-- Incoming call -->

            <div class="incoming-call" id="incomingCall">

                <div class="incoming-card">

                    <div class="incoming-icon">
                        ☎
                    </div>

                    <h2>
                        Incoming call
                    </h2>

                    <p>
                        Someone is calling you.
                    </p>

                    <div class="incoming-actions">

                        <button
                            class="decline-btn"
                            id="declineButton"
                            type="button"
                        >
                            Decline
                        </button>

                        <button
                            class="accept-btn"
                            id="acceptButton"
                            type="button"
                        >
                            Accept
                        </button>

                    </div>

                </div>

            </div>


            <div class="toast" id="toast"></div>

        </div>


        <script>
            const ROOM = <?= json_encode($room) ?>;
            ===============
            | Put the generated room ID into the URL
            ===============
            if (!new URLSearchParams(window.location.search).get('room')) {
                const url = new URL(window.location.href);
                url.searchParams.set('room', ROOM);
                window.history.replaceState({}, '', url);
            }

            ===============
            | Client identity
            ===============
            const CLIENT_ID =
                (crypto.randomUUID)
                    ? crypto.randomUUID()
                    : Math.random().toString(36).slice(2) + Date.now();

            ===============
            | DOM
            ===============
            const remoteVideo = document.getElementById('remoteVideo');
            const localVideo = document.getElementById('localVideo');
            const callButton = document.getElementById('callButton');
            const hangupButton = document.getElementById('hangupButton');
            const cameraButton = document.getElementById('cameraButton');
            const flipButton = document.getElementById('flipButton');
            const copyButton = document.getElementById('copyButton');
            const micButton = document.getElementById('micButton');
            const statusPill = document.getElementById('statusPill');
            const statusText = document.getElementById('statusText');
            const emptyState = document.getElementById('emptyState');
            const hint = document.getElementById('hint');
            const errorMessage = document.getElementById('errorMessage');
            const toast = document.getElementById('toast');
            const incomingCall = document.getElementById('incomingCall');
            const acceptButton = document.getElementById('acceptButton');
            const declineButton = document.getElementById('declineButton');

            ===============
            | WebRTC state
            ===============
            let localStream = null;
            let peerConnection = null;
            let pollTimer = null;
            let processedMessages = new Set();
            let pendingCandidates = [];
            let remoteDescriptionReady = false;
            let isCalling = false;
            let isConnected = false;
            let pendingOffer = null;
            let incomingCaller = false;
            let currentFacingMode = 'user';

            ===============
            | ICE servers
            ===============
            const ICE_SERVERS = {
                iceServers: [
                    {
                        urls: [
                            'stun:stun.l.google.com:19302',
                            'stun:stun1.l.google.com:19302'
                        ]
                    },
                    {
                        urls: 'stun:stun.cloudflare.com:3478'
                    }
                ]
            };

            ===============
            | UI helpers
            ===============
            function setStatus(text, state = '') {
                statusText.textContent = text;
                statusPill.classList.remove(
                    'connected',
                    'calling',
                    'error'
                );
                if (state) {
                    statusPill.classList.add(state);
                }
            }

            function showError(message) {
                errorMessage.textContent = message;
                errorMessage.classList.add('show');
                setStatus('Error', 'error');
            }

            function hideError() {
                errorMessage.textContent = '';
                errorMessage.classList.remove('show');
            }

            function showToast(message) {
                toast.textContent = message;
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 2200);
            }

            ===============
            | Camera
            ===============
            async function startCamera() {
                if (localStream) {
                    return localStream;
                }
                try {
                    localStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: currentFacingMode,
                            width: { ideal: 1280 },
                            height: { ideal: 720 },
                            frameRate: { ideal: 30, max: 30 }
                        },
                        audio: true
                    });
                    localVideo.srcObject = localStream;
                    cameraButton.classList.remove('hidden');
                    flipButton.classList.remove('hidden');
                    micButton.classList.remove('hidden');
                    return localStream;
                } catch (error) {
                    showError(
                        'Camera access failed. Please allow camera permission and try again.'
                    );
                    throw error;
                }
            }

            async function flipCamera() {
                if (!localStream) {
                    return;
                }
                const newFacingMode =
                    currentFacingMode === 'user'
                        ? 'environment'
                        : 'user';
                try {
                    const newStream =
                        await navigator.mediaDevices.getUserMedia({
                            video: {
                                facingMode: newFacingMode,
                                width: { ideal: 1280 },
                                height: { ideal: 720 },
                                frameRate: { ideal: 30, max: 30 }
                            },
                            audio: true
                        });
                    const newTrack = newStream.getVideoTracks()[0];
                    const oldTrack = localStream.getVideoTracks()[0];
                    if (peerConnection) {
                        const sender = peerConnection
                            .getSenders()
                            .find(
                                item =>
                                    item.track &&
                                    item.track.kind === 'video'
                            );
                        if (sender) {
                            await sender.replaceTrack(newTrack);
                        }
                    }
                    oldTrack.stop();
                    localStream.removeTrack(oldTrack);
                    localStream.addTrack(newTrack);
                    localVideo.srcObject = localStream;
                    currentFacingMode = newFacingMode;
                } catch (error) {
                    showError(
                        'Could not switch camera: ' + error.message
                    );
                }
            }

            function toggleCamera() {
                if (!localStream) {
                    return;
                }
                const track = localStream.getVideoTracks()[0];
                if (!track) {
                    return;
                }
                track.enabled = !track.enabled;
                cameraButton.textContent =
                    track.enabled ? '◉' : '○';
            }

            function toggleMic() {
                if (!localStream) {
                    return;
                }
                const track = localStream.getAudioTracks()[0];
                if (!track) {
                    return;
                }
                track.enabled = !track.enabled;
                micButton.textContent =
                    track.enabled ? '🎤' : '🔇';
                // Optionally, you can change the button text or icon to reflect the mic state
            }

            ===============
            | WebRTC connection
            ===============
            function createPeerConnection() {
                if (peerConnection) {
                    peerConnection.close();
                }
                peerConnection =
                    new RTCPeerConnection(ICE_SERVERS);
                remoteDescriptionReady = false;
                pendingCandidates = [];
                if (localStream) {
                    localStream
                        .getTracks()
                        .forEach(track => {
                            peerConnection.addTrack(
                                track,
                                localStream
                            );
                        });
                }
                peerConnection.ontrack = event => {
                    if (event.streams && event.streams[0]) {
                        remoteVideo.srcObject =
                            event.streams[0];
                        emptyState.style.display = 'none';
                    }
                };

                peerConnection.onicecandidate = async event => {
                    if (!event.candidate) {
                        return;
                    }
                    try {
                        await sendSignal(
                            'candidate',
                            event.candidate
                        );
                    } catch (error) {
                        console.error(
                            'ICE candidate error:',
                            error
                        );
                    }
                };

                peerConnection.onconnectionstatechange = () => {
                    const state =
                        peerConnection.connectionState;
                    if (state === 'connected') {
                        isConnected = true;
                        isCalling = false;
                        setStatus(
                            'Connected',
                            'connected'
                        );
                        hint.textContent =
                            'Video call connected';
                        callButton.classList.add('hidden');
                        hangupButton.classList.remove('hidden');
                        emptyState.style.display = 'none';
                    } else if (
                        state === 'failed' ||
                        state === 'disconnected' ||
                        state === 'closed'
                    ) {
                        isConnected = false;
                        if (state !== 'closed') {
                            setStatus('Disconnected');
                            hint.textContent =
                                'The connection was lost';
                        }
                    }
                };

                peerConnection.oniceconnectionstatechange = () => {
                    if (
                        peerConnection.iceConnectionState === 'failed'
                    ) {
                        setStatus(
                            'Connection failed',
                            'error'
                        );
                    }
                };

                return peerConnection;
            }

            ===============
            | ICE helper
            ===============
            function waitForIce(pc) {
                return new Promise(resolve => {
                    if (pc.iceGatheringState === 'complete') {
                        resolve();
                        return;
                    }
                    const timeout =
                        setTimeout(resolve, 5000);
                    pc.addEventListener(
                        'icegatheringstatechange',
                        function handler() {
                            if (
                                pc.iceGatheringState === 'complete'
                            ) {
                                clearTimeout(timeout);
                                pc.removeEventListener(
                                    'icegatheringstatechange',
                                    handler
                                );
                                resolve();
                            }
                        }
                    );
                });
            }

            ===============
            | Signaling
            ===============
            async function sendSignal(event, data = null) {
                const response =
                    await fetch(
                        '?action=send&room=' +
                        encodeURIComponent(ROOM) +
                        '&client=' +
                        encodeURIComponent(CLIENT_ID),
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type':
                                    'application/json'
                            },
                            body: JSON.stringify({
                                event,
                                data
                            })
                        }
                    );
                if (!response.ok) {
                    throw new Error(
                        'Signaling request failed.'
                    );
                }
                return response.json();
            }

            async function poll() {
                try {
                    const response =
                        await fetch(
                            '?action=poll&room=' +
                            encodeURIComponent(ROOM) +
                            '&client=' +
                            encodeURIComponent(CLIENT_ID) +
                            '&_=' +
                            Date.now(),
                            {
                                cache: 'no-store'
                            }
                        );
                    if (!response.ok) {
                        throw new Error('Polling failed.');
                    }
                    const result =
                        await response.json();
                    if (
                        result.success &&
                        Array.isArray(result.messages)
                    ) {
                        for (const message of result.messages) {
                            if (!message.id) {
                                continue;
                            }
                            if (processedMessages.has(message.id)) {
                                continue;
                            }
                            processedMessages.add(message.id);
                            await processMessage(message);
                        }
                    }
                } catch (error) {
                    console.error(
                        'Polling error:',
                        error
                    );
                }
            }

            ===============
            | Process signaling messages
            ===============
            async function processMessage(message) {
                switch (message.event) {
                    case 'join':
                        if (
                            !isCalling &&
                            !isConnected
                        ) {
                            await createOffer();
                        }
                        break;

                    case 'offer':
                        await handleOffer(
                            message.data
                        );
                        break;

                    case 'answer':
                        await handleAnswer(
                            message.data
                        );
                        break;

                    case 'candidate':
                        await handleCandidate(
                            message.data
                        );
                        break;

                    case 'leave':
                        handleLeave();
                        break;

                    case 'decline':
                        handleDecline();
                        break;
                }
            }

            ===============
            | Start outgoing call
            ===============
            async function createOffer() {
                if (isCalling || isConnected) {
                    return;
                }
                try {
                    hideError();
                    isCalling = true;
                    setStatus(
                        'Calling...',
                        'calling'
                    );
                    hint.textContent =
                        'Calling the other person...';
                    await startCamera();
                    const pc =
                        createPeerConnection();
                    const offer =
                        await pc.createOffer();
                    await pc.setLocalDescription(
                        offer
                    );
                    await waitForIce(pc);
                    await sendSignal(
                        'offer',
                        pc.localDescription
                    );
                } catch (error) {
                    isCalling = false;
                    showError(
                        'Could not start the call: ' +
                        error.message
                    );
                }
            }

            ===============
            | Incoming offer
            ===============
            async function handleOffer(offer) {
                if (!offer) {
                    return;
                }
                if (isConnected) {
                    return;
                }
                pendingOffer = offer;
                incomingCaller = true;
                setStatus(
                    'Incoming call...',
                    'calling'
                );
                hint.textContent =
                    'Someone is calling you';
                incomingCall.classList.add('show');
            }

            ===============
            | Accept incoming call
            ===============
            async function acceptCall() {
                if (!pendingOffer) {
                    return;
                }
                incomingCall.classList.remove('show');
                try {
                    hideError();
                    setStatus(
                        'Connecting...',
                        'calling'
                    );
                    hint.textContent =
                        'Accepting peer connection';
                    await startCamera();
                    const pc =
                        createPeerConnection();
                    await pc.setRemoteDescription(
                        new RTCSessionDescription(
                            pendingOffer
                        )
                    );
                    remoteDescriptionReady = true;
                    await flushCandidates();
                    const answer =
                        await pc.createAnswer();
                    await pc.setLocalDescription(
                        answer
                    );
                    await waitForIce(pc);
                    await sendSignal(
                        'answer',
                        pc.localDescription
                    );
                    pendingOffer = null;
                    incomingCaller = false;
                } catch (error) {
                    pendingOffer = null;
                    incomingCaller = false;
                    showError(
                        'Could not answer the call: ' +
                        error.message
                    );
                }
            }

            ===============
            | Decline incoming call
            ===============
            async function declineCall() {
                pendingOffer = null;
                incomingCaller = false;
                incomingCall.classList.remove('show');
                try {
                    await sendSignal('decline');
                } catch (_) {
                    // Ignore signaling errors while declining.
                }
                setStatus('Call declined');
                hint.textContent =
                    'Waiting for another person to join';
                callButton.classList.remove('hidden');
                hangupButton.classList.add('hidden');
            }

            ===============
            | Handle answer
            ===============
            async function handleAnswer(answer) {
                if (!answer || !peerConnection) {
                    return;
                }
                try {
                    await peerConnection.setRemoteDescription(
                        new RTCSessionDescription(answer)
                    );
                    remoteDescriptionReady = true;
                    await flushCandidates();
                } catch (error) {
                    showError(
                        'Could not establish the connection: ' +
                        error.message
                    );
                }
            }

            ===============
            | ICE candidates
            ===============
            async function handleCandidate(candidate) {
                if (!candidate) {
                    return;
                }
                if (
                    !peerConnection ||
                    !remoteDescriptionReady
                ) {
                    pendingCandidates.push(candidate);
                    return;
                }
                try {
                    await peerConnection.addIceCandidate(
                        new RTCIceCandidate(candidate)
                    );
                } catch (error) {
                    console.error(
                        'Could not add ICE candidate:',
                        error
                    );
                }
            }

            async function flushCandidates() {
                if (
                    !peerConnection ||
                    !remoteDescriptionReady
                ) {
                    return;
                }
                const candidates =
                    pendingCandidates;
                pendingCandidates = [];
                for (const candidate of candidates) {
                    try {
                        await peerConnection.addIceCandidate(
                            new RTCIceCandidate(candidate)
                        );
                    } catch (error) {
                        console.error(
                            'Could not flush ICE candidate:',
                            error
                        );
                    }
                }
            }

            ===============
            | Declined call
            ===============
            function handleDecline() {
                isCalling = false;
                isConnected = false;
                pendingOffer = null;
                incomingCaller = false;
                incomingCall.classList.remove('show');
                if (peerConnection) {
                    peerConnection.close();
                    peerConnection = null;
                }
                remoteDescriptionReady = false;
                pendingCandidates = [];
                setStatus('Call declined');
                hint.textContent =
                    'The other person declined the call';
                callButton.classList.remove('hidden');
                hangupButton.classList.add('hidden');
            }

            ===============
            | Remote peer left
            ===============
            function handleLeave() {
                isCalling = false;
                isConnected = false;
                pendingOffer = null;
                incomingCaller = false;
                incomingCall.classList.remove('show');
                if (peerConnection) {
                    peerConnection.close();
                    peerConnection = null;
                }
                remoteDescriptionReady = false;
                pendingCandidates = [];
                remoteVideo.srcObject = null;
                setStatus('Disconnected');
                hint.textContent =
                    'The other person left the call';
                callButton.classList.remove('hidden');
                hangupButton.classList.add('hidden');
                emptyState.style.display = 'grid';
            }

            ===============
            | Hang up
            ===============
            async function hangUp() {
                try {
                    await sendSignal('leave');
                } catch (_) {
                    // Ignore signaling errors during hangup.
                }
                if (peerConnection) {
                    peerConnection.close();
                    peerConnection = null;
                }
                remoteVideo.srcObject = null;
                isCalling = false;
                isConnected = false;
                pendingOffer = null;
                incomingCaller = false;
                incomingCall.classList.remove('show');
                remoteDescriptionReady = false;
                pendingCandidates = [];
                setStatus('Ready');
                hint.textContent =
                    'Share the call link with someone, then start a video call.';
                callButton.classList.remove('hidden');
                hangupButton.classList.add('hidden');
                emptyState.style.display = 'grid';
            }

            ===============
            | Copy call URL
            ===============
            async function copyRoomLink() {
                try {
                    await navigator.clipboard.writeText(
                        window.location.href
                    );
                    showToast(
                        'Call link copied'
                    );
                } catch (error) {
                    showToast(
                        'Could not copy call link'
                    );
                }
            }

            ===============
            | Event listeners
            ===============
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
            micButton.addEventListener(
                'click',
                toggleMic
            );
            flipButton.addEventListener(
                'click',
                flipCamera
            );
            copyButton.addEventListener(
                'click',
                copyRoomLink
            );
            acceptButton.addEventListener(
                'click',
                acceptCall
            );
            declineButton.addEventListener(
                'click',
                declineCall
            );

            ===============
            | Start
            ===============
            async function start() {
                setStatus('Ready');
                hint.textContent =
                    'Share the call link with someone, then start a video call.';
                pollTimer =
                    setInterval(
                        poll,
                        1000
                    );
                await poll();
                try {
                    await sendSignal('join');
                } catch (error) {
                    showError(
                        'Could not join the call room: ' +
                        error.message
                    );
                }
            }

            ===============
            | Cleanup
            ===============
            window.addEventListener(
                'beforeunload',
                () => {
                    const payload =
                        JSON.stringify({
                            event: 'leave',
                            data: null
                        });
                    const url =
                        '?action=send&room=' +
                        encodeURIComponent(ROOM) +
                        '&client=' +
                        encodeURIComponent(CLIENT_ID);
                    try {
                        navigator.sendBeacon(
                            url,
                            new Blob(
                                [payload],
                                {
                                    type: 'application/json'
                                }
                            )
                        );
                    } catch (_) {
                        // Ignore cleanup errors.
                    }
                }
            );

            start();
        </script>

        </body>
    </html>