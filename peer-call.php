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

    function appendMessage(string $room, array $message): void
    {
        $file = roomFile($room);

        $handle = fopen($file, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Could not open signaling file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Could not lock signaling file.');
            }

            rewind($handle);
            $content = stream_get_contents($handle);
            $messages = json_decode($content ?: '', true);

            if (!is_array($messages)) {
                $messages = [];
            }

            $messages = cleanMessages($messages);
            $messages[] = $message;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite(
                $handle,
                json_encode($messages, JSON_UNESCAPED_SLASHES)
            );
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
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
        $target = $payload['target'] ?? null;

        if ($target !== null && (!is_string($target) || $target === '')) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid target.'
            ]);
            exit;
        }

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

        appendMessage($room, [
            'id' => bin2hex(random_bytes(8)),
            'client' => $client,
            'target' => $target,
            'event' => $event,
            'data' => $data,
            'time' => time()
        ]);

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
                $message['client'] !== $client &&
                (
                    !isset($message['target']) ||
                    $message['target'] === null ||
                    $message['target'] === $client
                )
        ));

        echo json_encode([
            'success' => true,
            'messages' => $messages
        ]);

        exit;
    }


    /*=============== Generate a unique room/call ID for a new call ===============*/

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

            <!-- Future product name -->
            <title>PeerCall</title>
            <!-- Future product name -->

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

                /*=============== Remote video ===============*/

                .remote-videos {
                    position: absolute;
                    inset: 0;
                    z-index: 1;
                    display: grid;
                    grid-template-columns: repeat(
                        auto-fit,
                        minmax(min(100%, 420px), 1fr)
                    );
                    grid-auto-rows: minmax(0, 1fr);
                    gap: 2px;
                    background: #050507;
                }

                .remote-video {
                    width: 100%;
                    height: 100%;
                    min-width: 0;
                    min-height: 0;
                    object-fit: cover;
                    background: #050507;
                }

                /*=============== Local video ===============*/

                .local-video {
                    touch-action: none;
                    position: absolute;
                    z-index: 20;
                    top: 82px;
                    right: 24px;
                    width: min(260px, 27vw);
                    object-fit: cover;
                    background: #111116;
                    border: 1px solid rgba(255,255,255,.14);
                    border-radius: 18px;
                    box-shadow: 0 15px 45px rgba(0,0,0,.45);
                    transform: scaleX(-1);
                }

                /*=============== Top bar ===============*/

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

                /*=============== Empty state ===============*/

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

                /*=============== Controls ===============*/

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

                /*=============== Error ===============*/

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

                /*=============== Toast ===============*/

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

                /*=============== Incoming call ===============*/

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

                /*=============== Mobile ===============*/

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

            <div
                id="remoteVideos"
                class="remote-videos"
            ></div>

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
            /*=============== Put the generated room ID into the URL ===============*/
            if (!new URLSearchParams(window.location.search).get('room')) {
                const url = new URL(window.location.href);
                url.searchParams.set('room', ROOM);
                window.history.replaceState({}, '', url);
            }

            /*=============== Client identity ===============*/
            const CLIENT_ID =
                sessionStorage.getItem('peercall_client_id') ||
                (
                    crypto.randomUUID
                        ? crypto.randomUUID()
                        : Math.random().toString(36).slice(2) + Date.now()
                );

            sessionStorage.setItem(
                'peercall_client_id',
                CLIENT_ID
            );

            const SESSION_STARTED_AT = Date.now();
            const ROOM_PARAM = new URLSearchParams(
                window.location.search
            ).get('room');
            const HOST_STORAGE_KEY =
                'peercall_host_' + ROOM;
            const isHost =
                !ROOM_PARAM ||
                localStorage.getItem(HOST_STORAGE_KEY) === '1';

            if (!ROOM_PARAM) {
                localStorage.setItem(
                    HOST_STORAGE_KEY,
                    '1'
                );
            }

            let hostClientId = isHost ? CLIENT_ID : null;
            let roomEnded = false;

            /*=============== DOM ===============*/
            const remoteVideosContainer =
                document.getElementById('remoteVideos');
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

            /*=============== WebRTC state ===============*/
            let localStream = null;
            const peerConnections = new Map();
            const remoteVideos = new Map();
            let pollTimer = null;
            let processedMessages = new Set();
            let isCalling = false;
            let pendingOffer = null;
            let incomingCaller = false;
            let currentFacingMode = 'user';

            /*=============== ICE servers ===============*/
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

            /*=============== UI helpers ===============*/
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

            /*=============== Camera ===============*/
            async function startCamera() {
                if (localStream) {
                    return localStream;
                }
                try {
                    localStream = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: currentFacingMode,
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
                                frameRate: { ideal: 30, max: 30 }
                            },
                            audio: true
                        });
                    const newTrack = newStream.getVideoTracks()[0];
                    const oldTrack = localStream.getVideoTracks()[0];
                    peerConnections.forEach(peer => {
                        const sender = peer.pc
                            .getSenders()
                            .find(
                                item =>
                                    item.track &&
                                    item.track.kind === 'video'
                            );

                        if (sender) {
                            sender.replaceTrack(newTrack)
                                .catch(() => {});
                        }
                    });
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

            /*=============== WebRTC connection ===============*/
            function createRemoteVideo(peerId) {
                if (remoteVideos.has(peerId)) {
                    return remoteVideos.get(peerId);
                }

                const video = document.createElement('video');
                video.className = 'remote-video';
                video.autoplay = true;
                video.playsInline = true;
                video.dataset.peerId = peerId;

                remoteVideos.set(peerId, video);
                remoteVideosContainer.appendChild(video);
                emptyState.style.display = 'none';

                return video;
            }

            function removeRemoteVideo(peerId) {
                const video = remoteVideos.get(peerId);

                if (video) {
                    video.srcObject = null;
                    video.remove();
                    remoteVideos.delete(peerId);
                }

                if (remoteVideos.size === 0) {
                    emptyState.style.display = 'grid';
                }
            }

            function updateCallState() {
                const connectedPeers = Array.from(
                    peerConnections.values()
                ).filter(
                    peer => peer.pc.connectionState === 'connected'
                ).length;

                if (connectedPeers > 0) {
                    isCalling = false;
                    stopCallSounds();
                    setStatus(
                        connectedPeers === 1
                            ? 'Connected'
                            : connectedPeers + ' connected',
                        'connected'
                    );
                    hint.textContent =
                        connectedPeers === 1
                            ? 'Video call connected'
                            : 'Video call connected with ' +
                              connectedPeers +
                              ' people';
                    callButton.classList.add('hidden');
                    hangupButton.classList.remove('hidden');
                    emptyState.style.display = 'none';
                    return;
                }

                const connectingPeers = Array.from(
                    peerConnections.values()
                ).filter(peer =>
                    peer.pc.connectionState === 'new' ||
                    peer.pc.connectionState === 'connecting'
                ).length;

                if (connectingPeers > 0) {
                    setStatus('Connecting...', 'calling');
                    hint.textContent =
                        'Connecting to call participants...';
                    return;
                }

                if (remoteVideos.size === 0) {
                    setStatus('Ready');
                    callButton.classList.toggle(
                        'hidden',
                        !isHost || roomEnded
                    );
                    hangupButton.classList.add('hidden');
                }
            }

            function closePeerConnection(peerId) {
                const peer = peerConnections.get(peerId);

                if (peer) {
                    peer.pc.onicecandidate = null;
                    peer.pc.ontrack = null;
                    peer.pc.onconnectionstatechange = null;
                    peer.pc.close();
                    peerConnections.delete(peerId);
                }

                removeRemoteVideo(peerId);
                updateCallState();
            }

            function createPeerConnection(peerId) {
                const existing = peerConnections.get(peerId);

                if (existing) {
                    return existing.pc;
                }

                const pc = new RTCPeerConnection(ICE_SERVERS);
                const peer = {
                    pc,
                    remoteDescriptionReady: false,
                    pendingCandidates: []
                };

                peerConnections.set(peerId, peer);

                if (localStream) {
                    localStream
                        .getTracks()
                        .forEach(track => {
                            pc.addTrack(track, localStream);
                        });
                }

                pc.ontrack = event => {
                    if (event.streams && event.streams[0]) {
                        const video = createRemoteVideo(peerId);
                        video.srcObject = event.streams[0];
                        emptyState.style.display = 'none';
                    }
                };

                pc.onicecandidate = async event => {
                    if (!event.candidate) {
                        return;
                    }

                    try {
                        await sendSignal(
                            'candidate',
                            event.candidate,
                            peerId
                        );
                    } catch (error) {
                        console.error(
                            'ICE candidate error:',
                            error
                        );
                    }
                };

                pc.onconnectionstatechange = () => {
                    const state = pc.connectionState;

                    if (state === 'connected') {
                        stopCallSounds();
                        updateCallState();
                    } else if (
                        state === 'failed' ||
                        state === 'closed'
                    ) {
                        closePeerConnection(peerId);
                    } else {
                        updateCallState();
                    }
                };

                pc.oniceconnectionstatechange = () => {
                    if (pc.iceConnectionState === 'failed') {
                        setStatus(
                            'Connection failed',
                            'error'
                        );
                    }
                };

                return pc;
            }

            /*=============== ICE helper ===============*/
            function waitForIce(pc) {
                return new Promise(resolve => {
                    if (pc.iceGatheringState === 'complete') {
                        resolve();
                        return;
                    }

                    const timeout = setTimeout(
                        resolve,
                        5000
                    );

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

            /*=============== Signaling ===============*/
            async function sendSignal(
                event,
                data = null,
                target = null
            ) {
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
                                data,
                                target
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
                        for (
                            const message
                            of result.messages
                        ) {
                            if (!message.id) {
                                continue;
                            }

                            if (
                                processedMessages.has(
                                    message.id
                                )
                            ) {
                                continue;
                            }

                            processedMessages.add(
                                message.id
                            );

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

            /*=============== Process signaling messages ===============*/
            async function processMessage(message) {
                switch (message.event) {
                    case 'join':
                        if (
                            message.data &&
                            message.data.role === 'host' &&
                            message.client
                        ) {
                            hostClientId = message.client;
                        }

                        if (
                            isHost &&
                            !roomEnded &&
                            message.client &&
                            message.client !== CLIENT_ID &&
                            message.data &&
                            Number(message.data.joinedAt) >=
                                SESSION_STARTED_AT
                        ) {
                            await createOffer(
                                message.client
                            );
                        }
                        break;

                    case 'offer':
                        await handleOffer(
                            message.client,
                            message.data
                        );
                        break;

                    case 'answer':
                        await handleAnswer(
                            message.client,
                            message.data
                        );
                        break;

                    case 'candidate':
                        await handleCandidate(
                            message.client,
                            message.data
                        );
                        break;

                    case 'leave':
                        handleLeave(
                            message.client,
                            message.data
                        );
                        break;

                    case 'decline':
                        handleDecline(message.client);
                        break;
                }
            }

            /*=============== Start outgoing call ===============*/
            async function createOffer(peerId) {
                if (!peerId || peerId === CLIENT_ID) {
                    return;
                }

                const existing = peerConnections.get(peerId);

                if (
                    existing &&
                    (
                        existing.pc.connectionState === 'connected' ||
                        existing.pc.connectionState === 'connecting'
                    )
                ) {
                    return;
                }

                try {
                    hideError();
                    isCalling = true;

                    if (isHost) {
                        playCallingSound();
                        setStatus(
                            'Calling...',
                            'calling'
                        );
                        hint.textContent =
                            'Waiting for participants to join...';
                    } else {
                        setStatus(
                            'Connecting...',
                            'calling'
                        );
                        hint.textContent =
                            'Connecting to the host...';
                    }

                    await startCamera();

                    const pc =
                        createPeerConnection(peerId);

                    const offer =
                        await pc.createOffer();

                    await pc.setLocalDescription(
                        offer
                    );

                    await waitForIce(pc);

                    await sendSignal(
                        'offer',
                        pc.localDescription,
                        peerId
                    );
                } catch (error) {
                    isCalling = false;
                    stopCallingSound();

                    showError(
                        'Could not start the call: ' +
                        error.message
                    );
                }
            }

            /*=============== Incoming offer ===============*/
            async function handleOffer(
                peerId,
                offer
            ) {
                if (!peerId || !offer) {
                    return;
                }

                try {
                    hideError();
                    await startCamera();

                    const pc =
                        createPeerConnection(peerId);

                    await pc.setRemoteDescription(
                        new RTCSessionDescription(
                            offer
                        )
                    );

                    const peer =
                        peerConnections.get(peerId);

                    if (!peer) {
                        return;
                    }

                    peer.remoteDescriptionReady = true;
                    await flushCandidates(peerId);

                    const answer =
                        await pc.createAnswer();

                    await pc.setLocalDescription(
                        answer
                    );

                    await waitForIce(pc);

                    await sendSignal(
                        'answer',
                        pc.localDescription,
                        peerId
                    );

                    stopRingingSound();
                    updateCallState();
                } catch (error) {
                    showError(
                        'Could not answer the call: ' +
                        error.message
                    );
                }
            }

            /*=============== Accept incoming call ===============*/
            async function acceptCall() {
                incomingCall.classList.remove('show');
                stopRingingSound();

                if (pendingOffer) {
                    const peerId =
                        pendingOffer.peerId;
                    const offer =
                        pendingOffer.offer;

                    pendingOffer = null;
                    await handleOffer(
                        peerId,
                        offer
                    );
                }
            }

            /*=============== Decline incoming call ===============*/
            async function declineCall() {
                const peerId =
                    pendingOffer &&
                    pendingOffer.peerId;

                pendingOffer = null;
                incomingCaller = false;
                incomingCall.classList.remove('show');
                stopRingingSound();
                stopCallingSound();

                try {
                    await sendSignal(
                        'decline',
                        null,
                        peerId || null
                    );
                } catch (_) {
                    // Ignore signaling errors while declining.
                }

                setStatus('Call declined');
                hint.textContent =
                    'Waiting for another person to join';
                updateCallState();
            }

            /*=============== Handle answer ===============*/
            async function handleAnswer(
                peerId,
                answer
            ) {
                if (!peerId || !answer) {
                    return;
                }

                const peer =
                    peerConnections.get(peerId);

                if (!peer) {
                    return;
                }

                try {
                    await peer.pc.setRemoteDescription(
                        new RTCSessionDescription(
                            answer
                        )
                    );

                    peer.remoteDescriptionReady = true;
                    await flushCandidates(peerId);
                    updateCallState();
                } catch (error) {
                    showError(
                        'Could not establish the connection: ' +
                        error.message
                    );
                }
            }

            /*=============== ICE candidates ===============*/
            async function handleCandidate(
                peerId,
                candidate
            ) {
                if (!peerId || !candidate) {
                    return;
                }

                const peer =
                    peerConnections.get(peerId);

                if (!peer) {
                    return;
                }

                if (!peer.remoteDescriptionReady) {
                    peer.pendingCandidates.push(
                        candidate
                    );
                    return;
                }

                try {
                    await peer.pc.addIceCandidate(
                        new RTCIceCandidate(candidate)
                    );
                } catch (error) {
                    console.error(
                        'Could not add ICE candidate:',
                        error
                    );
                }
            }

            async function flushCandidates(peerId) {
                const peer =
                    peerConnections.get(peerId);

                if (
                    !peer ||
                    !peer.remoteDescriptionReady
                ) {
                    return;
                }

                const candidates =
                    peer.pendingCandidates;

                peer.pendingCandidates = [];

                for (const candidate of candidates) {
                    try {
                        await peer.pc.addIceCandidate(
                            new RTCIceCandidate(
                                candidate
                            )
                        );
                    } catch (error) {
                        console.error(
                            'Could not flush ICE candidate:',
                            error
                        );
                    }
                }
            }

            /*=============== Declined call ===============*/
            function handleDecline(peerId) {
                if (peerId) {
                    closePeerConnection(peerId);
                } else {
                    Array.from(
                        peerConnections.keys()
                    ).forEach(closePeerConnection);
                }

                stopCallingSound();
                updateCallState();
            }

            /*=============== Remote peer left ===============*/
            function handleLeave(peerId, data = null) {
                const hostEnded =
                    data &&
                    data.hostEnded === true &&
                    peerId === hostClientId;

                if (hostEnded) {
                    roomEnded = true;
                }

                if (peerId) {
                    closePeerConnection(peerId);
                } else {
                    Array.from(
                        peerConnections.keys()
                    ).forEach(closePeerConnection);
                }

                if (remoteVideos.size === 0) {
                    setStatus('Disconnected');
                    hint.textContent = roomEnded
                        ? 'The host ended the call'
                        : 'The other participants left the call';

                    if (isHost && !roomEnded) {
                        callButton.classList.remove('hidden');
                    } else {
                        callButton.classList.add('hidden');
                    }

                    hangupButton.classList.add('hidden');
                    emptyState.style.display = 'grid';
                }

                updateCallState();
            }

            /*=============== Hang up ===============*/
            async function hangUp() {
                const hostEnded = isHost;

                if (hostEnded) {
                    roomEnded = true;
                }

                try {
                    await sendSignal(
                        'leave',
                        {
                            hostEnded
                        }
                    );
                } catch (_) {
                    // Ignore signaling errors during hangup.
                }

                Array.from(
                    peerConnections.keys()
                ).forEach(closePeerConnection);

                remoteVideos.forEach(video => {
                    video.srcObject = null;
                    video.remove();
                });
                remoteVideos.clear();

                isCalling = false;
                incomingCall.classList.remove('show');
                pendingOffer = null;
                incomingCaller = false;

                stopCallSounds();
                setStatus('Ready');
                hint.textContent = isHost
                    ? 'Call ended. Start another call or share the link again.'
                    : 'Waiting for the host to start a call.';
                callButton.classList.toggle(
                    'hidden',
                    !isHost
                );
                hangupButton.classList.add('hidden');
                emptyState.style.display = 'grid';
            }

            /*=============== Copy call URL ===============*/
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

            /*=============== Event listeners ===============*/
            callButton.addEventListener(
                'click',
                async () => {
                    if (!isHost) {
                        return;
                    }

                    try {
                        hideError();
                        roomEnded = false;
                        await startCamera();

                        isCalling = true;
                        playCallingSound();
                        setStatus(
                            'Calling...',
                            'calling'
                        );
                        hint.textContent =
                            'Waiting for participants to join...';
                        callButton.classList.add('hidden');
                        hangupButton.classList.remove('hidden');

                        await sendSignal(
                            'join',
                            {
                                joinedAt: Date.now(),
                                role: 'host'
                            }
                        );

                        showToast(
                            'Call link is ready'
                        );
                    } catch (error) {
                        showError(
                            'Could not start the call: ' +
                            error.message
                        );
                    }
                }
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

            /*=============== Start ===============*/
            async function start() {
                setStatus('Ready');

                if (isHost) {
                    hint.textContent =
                        'Share the call link. Participants will connect automatically.';
                } else {
                    hint.textContent =
                        'Waiting for the host to start the call.';
                    callButton.classList.add('hidden');
                }

                pollTimer =
                    setInterval(
                        poll,
                        10000
                    );

                await poll();

                try {
                    if (isHost) {
                        await startCamera();

                        isCalling = true;
                        playCallingSound();
                        setStatus(
                            'Calling...',
                            'calling'
                        );
                        hint.textContent =
                            'Waiting for participants to join...';
                        callButton.classList.add('hidden');
                        hangupButton.classList.remove('hidden');

                        await sendSignal(
                            'join',
                            {
                                joinedAt: Date.now(),
                                role: 'host'
                            }
                        );
                    } else {
                        await sendSignal(
                            'join',
                            {
                                joinedAt: Date.now(),
                                role: 'participant'
                            }
                        );
                    }
                } catch (error) {
                    showError(
                        'Could not join the call room: ' +
                        error.message
                    );
                }
            }

            /*=============== Call sounds ===============*/
            const callingSound = new Audio('assets/sounds/calling.mp3');
            const ringingSound = new Audio('assets/sounds/ringing.mp3');

            callingSound.loop = true;
            ringingSound.loop = true;

            function playCallingSound() {
                callingSound.currentTime = 0;
                callingSound.play().catch(() => {});
            }

            function stopCallingSound() {
                callingSound.pause();
                callingSound.currentTime = 0;
            }

            function playRingingSound() {
                ringingSound.currentTime = 0;
                ringingSound.play().catch(() => {});
            }

            function stopRingingSound() {
                ringingSound.pause();
                ringingSound.currentTime = 0;
            }

            function stopCallSounds() {
                stopCallingSound();
                stopRingingSound();
            }

            /*=============== Toggle camera ===============*/
            localVideo.addEventListener('click', () => {
                const localSrc = localVideo.srcObject;
                const firstRemoteVideo =
                    remoteVideosContainer.querySelector(
                        '.remote-video'
                    );

                if (
                    !localSrc ||
                    !firstRemoteVideo ||
                    !firstRemoteVideo.srcObject
                ) {
                    return;
                }

                const remoteSrc =
                    firstRemoteVideo.srcObject;

                localVideo.srcObject = remoteSrc;
                firstRemoteVideo.srcObject = localSrc;
            });

            /*=============== Draggable local video ===============*/
            let isDraggingVideo = false;
            let dragOffsetX = 0;
            let dragOffsetY = 0;

            localVideo.addEventListener(
                'touchstart',
                event => {
                    if (!localVideo.srcObject) {
                        return;
                    }

                    const touch = event.touches[0];
                    const rect = localVideo.getBoundingClientRect();

                    dragOffsetX = touch.clientX - rect.left;
                    dragOffsetY = touch.clientY - rect.top;
                    isDraggingVideo = false;
                },
                { passive: true }
            );

            localVideo.addEventListener(
                'touchmove',
                event => {
                    const touch = event.touches[0];

                    isDraggingVideo = true;

                    const maxX =
                        window.innerWidth - localVideo.offsetWidth;

                    const maxY =
                        window.innerHeight - localVideo.offsetHeight;

                    const x = Math.max(
                        0,
                        Math.min(
                            touch.clientX - dragOffsetX,
                            maxX
                        )
                    );

                    const y = Math.max(
                        0,
                        Math.min(
                            touch.clientY - dragOffsetY,
                            maxY
                        )
                    );

                    localVideo.style.left = x + 'px';
                    localVideo.style.top = y + 'px';
                    localVideo.style.right = 'auto';
                    localVideo.style.bottom = 'auto';

                    event.preventDefault();
                },
                { passive: false }
            );

            localVideo.addEventListener(
                'touchend',
                () => {
                    if (!isDraggingVideo) {
                        const localSrc = localVideo.srcObject;
                        const firstRemoteVideo =
                            remoteVideosContainer.querySelector(
                                '.remote-video'
                            );

                        if (
                            localSrc &&
                            firstRemoteVideo &&
                            firstRemoteVideo.srcObject
                        ) {
                            const remoteSrc =
                                firstRemoteVideo.srcObject;

                            localVideo.srcObject = remoteSrc;
                            firstRemoteVideo.srcObject = localSrc;
                        }
                    }

                    isDraggingVideo = false;
                }
            );

            /*=============== Cleanup ===============*/
            window.addEventListener(
                'beforeunload',
                () => {
                    const payload =
                        JSON.stringify({
                            event: 'leave',
                            data: {
                                hostEnded: isHost
                            }
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