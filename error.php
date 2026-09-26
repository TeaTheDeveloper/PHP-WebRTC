<?php
http_response_code((int) ($_GET['code'] ?? http_response_code()));

$code = http_response_code();

$messages = [
    400 => 'The request could not be understood.',
    401 => 'You are not authorized to access this page.',
    403 => 'You do not have permission to access this page.',
    404 => 'The page you are looking for could not be found.',
    500 => 'Something went wrong on the server.',
    502 => 'The server received an invalid response.',
    503 => 'The service is temporarily unavailable.',
    504 => 'The server took too long to respond.'
];

$message = $messages[$code] ?? 'Something went wrong. Please try again.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PeerCall — <?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #08080b;
            --panel: rgba(18, 18, 23, .92);
            --border: rgba(255,255,255,.1);
            --text: #fff;
            --muted: rgba(255,255,255,.55);
        }

        html, body {
            width: 100%;
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system,
                BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            display: grid;
            place-items: center;
            padding: 24px;
            background:
                radial-gradient(
                    circle at 50% 30%,
                    rgba(255,255,255,.045),
                    transparent 40%
                ),
                var(--bg);
        }

        .card {
            width: min(420px, 100%);
            padding: 34px 28px;
            text-align: center;
            border: 1px solid var(--border);
            border-radius: 24px;
            background: var(--panel);
            box-shadow: 0 25px 80px rgba(0,0,0,.5);
            backdrop-filter: blur(14px);
        }

        .icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 20px;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 20px;
            background: rgba(255,255,255,.06);
            color: rgba(255,255,255,.8);
            font-size: 24px;
            font-weight: 700;
        }

        .code {
            margin-bottom: 8px;
            color: rgba(255,255,255,.4);
            font-size: 12px;
            letter-spacing: .12em;
        }

        h1 {
            margin-bottom: 10px;
            font-size: clamp(26px, 6vw, 36px);
            letter-spacing: -.045em;
        }

        p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            margin-top: 24px;
            padding: 0 20px;
            border-radius: 13px;
            background: #fff;
            color: #08080b;
            font-weight: 600;
            text-decoration: none;
        }

        a:hover { opacity: .9; }
    </style>
</head>
<body>
    <main class="card">
        <div class="icon">!</div>
        <div class="code">ERROR <?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?></div>
        <h1>Something went wrong</h1>
        <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="peer-call">Back to PeerCall</a>
    </main>
</body>
</html>
