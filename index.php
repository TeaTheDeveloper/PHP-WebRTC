<?php
/**
 * PeerCall home – create or join
 */
const ROOM_PATTERN = '/^[a-zA-Z0-9_-]{3,64}$/';

$error = null;
$joinValue = '';

/**
 * Accept a bare room id or a full call URL and return the room id.
 */
function extractRoomId(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    // Full URL: https://…/call?room=xxx  or  …/call?room=xxx
    if (preg_match('#https?://#i', $input) || str_contains($input, 'room=')) {
        $parts = parse_url($input);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['room']) && is_string($query['room'])) {
                $input = $query['room'];
            }
        }
        // Also handle …/call?room=xxx&host=1 fragments already covered by parse_str
    }

    if (preg_match(ROOM_PATTERN, $input)) {
        return $input;
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'join') {
        $joinValue = trim((string) ($_POST['room'] ?? ''));
        $room = extractRoomId($joinValue);

        if ($room === null) {
            $error = 'Enter a valid room ID or paste a full PeerCall link.';
        } else {
            $url = 'call?room=' . rawurlencode($room);
            header('Location: ' . $url);
            exit;
        }
    }
}

// Create is handled client-side (new tab) so the home page stays open.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>PeerCall</title>
    <link href="https://fonts.googleapis.com/css?family=Quicksand:400,600,700" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg: #08080b;
            --text: #fff;
            --muted: rgba(255, 255, 255, 0.55);
            --border: rgba(255, 255, 255, 0.1);
            --danger: #ff4d67;
        }

        html, body {
            min-height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: Quicksand, Inter, system-ui, -apple-system, sans-serif;
        }

        body {
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(40px, env(safe-area-inset-top)) 24px max(48px, env(safe-area-inset-bottom));
            background:
                radial-gradient(circle at 50% 30%, rgba(255,255,255,0.045), transparent 40%),
                #08080b;
        }

        .card {
            width: 100%;
            max-width: 420px;
            text-align: center;
        }

        .icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 22px;
            display: grid;
            place-items: center;
            border-radius: 22px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            font-size: 28px;
        }

        h1 {
            margin-bottom: 10px;
            font-size: clamp(26px, 5vw, 40px);
            font-weight: 700;
            letter-spacing: -0.045em;
        }

        .subtitle {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 36px;
        }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            height: 50px;
            border: 0;
            border-radius: 14px;
            font: inherit;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: transform 0.15s ease, background 0.15s ease;
        }

        .btn:active { transform: scale(0.97); }

        .btn-primary {
            background: #fff;
            color: #08080b;
        }

        .btn-primary:hover { background: #f2f2f2; }

        .btn-outline {
            background: rgba(255,255,255,0.08);
            color: #fff;
            border: 1px solid var(--border);
        }

        .btn-outline:hover { background: rgba(255,255,255,0.12); }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 18px 0;
            color: rgba(255,255,255,0.4);
            font-size: 11px;
            letter-spacing: 0.06em;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: rgba(255,255,255,0.12);
        }

        .field {
            width: 100%;
            height: 50px;
            padding: 0 16px;
            margin-bottom: 12px;
            border: 1px solid transparent;
            border-radius: 14px;
            background: rgba(255,255,255,0.06);
            color: #fff;
            font: inherit;
            font-size: 14px;
            outline: none;
        }

        .field::placeholder { color: rgba(255,255,255,0.35); }

        .field:focus {
            border-color: rgba(255,255,255,0.18);
        }

        .error {
            margin-bottom: 16px;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid rgba(255,77,103,0.3);
            background: rgba(50,10,17,0.92);
            color: #ffb3bf;
            font-size: 13px;
            text-align: center;
        }

        .hint {
            margin-top: 14px;
            color: rgba(255,255,255,0.4);
            font-size: 12px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">◉</div>
        <h1>PeerCall</h1>
        <p class="subtitle">Private peer-to-peer video calls.</p>

        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <button class="btn btn-primary" type="button" id="createBtn">
            Create call
        </button>

        <div class="divider">OR</div>

        <form method="post" action="" id="joinForm">
            <input type="hidden" name="action" value="join">
            <input
                class="field"
                type="text"
                name="room"
                id="roomInput"
                placeholder="Room ID or paste call link"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                value="<?= htmlspecialchars($joinValue, ENT_QUOTES, 'UTF-8') ?>"
            >
            <button class="btn btn-outline" type="submit">
                Join call
            </button>
        </form>

        <p class="hint">
            Paste a full link like<br>
            <code style="color:rgba(255,255,255,0.55)">…/call?room=call_abc123</code>
            <br>or just the room ID.
        </p>
    </div>

    <script>
        function randomRoomId() {
            const bytes = new Uint8Array(6);
            crypto.getRandomValues(bytes);
            return 'call_' + Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        }

        /** Same idea as the app: bare id or full URL → room id */
        function extractRoomId(input) {
            input = (input || '').trim();
            if (!input) return null;

            try {
                if (/^https?:\/\//i.test(input) || input.includes('room=')) {
                    const url = input.includes('://')
                        ? new URL(input)
                        : new URL(input, window.location.origin);
                    const room = url.searchParams.get('room');
                    if (room) input = room;
                }
            } catch (_) {
                // fall through to pattern check
            }

            if (/^[a-zA-Z0-9_-]{3,64}$/.test(input)) return input;
            return null;
        }

        document.getElementById('createBtn').addEventListener('click', () => {
            const room = randomRoomId();
            // host=1 so call treats this tab as host even though room is in the URL
            const url = 'call?room=' + encodeURIComponent(room) + '&host=1';
            window.open(url, '_blank', 'noopener,noreferrer');
        });

        document.getElementById('joinForm').addEventListener('submit', (e) => {
            const raw = document.getElementById('roomInput').value;
            const room = extractRoomId(raw);
            if (!room) {
                e.preventDefault();
                alert('Enter a valid room ID or paste a full PeerCall link.');
                return;
            }
            // Normalize field to bare room id before POST
            document.getElementById('roomInput').value = room;
        });

        // Prefill from ?room= on the home URL
        const prefill = new URLSearchParams(location.search).get('room');
        if (prefill && !document.getElementById('roomInput').value) {
            document.getElementById('roomInput').value = prefill;
        }
    </script>
</body>
</html>