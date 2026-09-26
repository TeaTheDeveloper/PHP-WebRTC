<?php

declare(strict_types=1);

$rating = isset($_GET['rating']) ? (int) $_GET['rating'] : 0;
$rating = max(0, min(5, $rating));

$submitted = false;

if ($rating > 0) {
    $ratingsFile = __DIR__ . '/data/ratings.json';

    $ratings = [];

    if (is_file($ratingsFile)) {
        $contents = file_get_contents($ratingsFile);

        if ($contents !== false && trim($contents) !== '') {
            $decoded = json_decode($contents, true);

            if (is_array($decoded)) {
                $ratings = $decoded;
            }
        }
    }

    $ratings[] = [
        'rating' => $rating,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'timestamp' => date('c')
    ];

    file_put_contents(
        $ratingsFile,
        json_encode($ratings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    $submitted = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b0d12">
    <title>Call Ended | PeerCall</title>
    <style>
        * {
            box-sizing: border-box;
        }
        html,
        body {
            margin: 0;
            min-height: 100%;
        }
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            color: #fff;
            background:
                radial-gradient(circle at top, rgba(80, 100, 255, .14), transparent 35%),
                #0b0d12;
            font-family:
                Inter, -apple-system, BlinkMacSystemFont,
                "Segoe UI", sans-serif;
        }
        .card {
            width: min(100%, 430px);
            padding: 38px 30px 30px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, .09);
            border-radius: 24px;
            background: rgba(20, 23, 31, .82);
            box-shadow:
                0 24px 70px rgba(0, 0, 0, .38),
                inset 0 1px 0 rgba(255, 255, 255, .04);
            backdrop-filter: blur(18px);
        }
        .icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 22px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, .07);
            font-size: 27px;
        }
        h1 {
            margin: 0;
            font-size: 28px;
            letter-spacing: -.6px;
        }
        .message {
            margin: 10px auto 28px;
            max-width: 320px;
            color: #9da3b2;
            line-height: 1.6;
            font-size: 14px;
        }
        .rating {
            padding: 18px;
            margin-bottom: 22px;
            border-radius: 16px;
            background: rgba(255, 255, 255, .035);
        }
        .rating-title {
            margin-bottom: 12px;
            color: #dfe2ea;
            font-size: 13px;
        }
        .stars {
            display: flex;
            justify-content: center;
            gap: 6px;
        }
        .stars a {
            color: #555b69;
            text-decoration: none;
            font-size: 28px;
            line-height: 1;
            transition: transform .15s ease, color .15s ease;
        }
        .stars a:hover {
            color: #ffd166;
            transform: translateY(-2px);
        }
        .stars a.active {
            color: #ffd166;
        }
        .actions {
            display: grid;
            gap: 10px;
        }
        .button {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, .09);
            color: #fff;
            background: rgba(255, 255, 255, .06);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: background .15s ease, transform .15s ease;
        }
        .button:hover {
            background: rgba(255, 255, 255, .1);
            transform: translateY(-1px);
        }
        .button.primary {
            border-color: transparent;
            background: #fff;
            color: #0b0d12;
        }
        .button.primary:hover {
            background: #e9ebef;
        }
        .footer {
            margin-top: 22px;
            color: #626978;
            font-size: 11px;
        }
        @media (max-width: 480px) {
            .card {
                padding: 32px 22px 24px;
            }
            h1 {
                font-size: 25px;
            }
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="icon">✓</div>

        <h1>Call ended</h1>

        <p class="message">
            <?= $submitted
                ? 'Thanks for your feedback. We appreciate it.'
                : 'Thanks for using PeerCall. Hope the conversation went well.' ?>
        </p>

        <section class="rating">
            <div class="rating-title">
                <?= $submitted ? 'Your rating was recorded.' : 'How was your call?' ?>
            </div>

            <div class="stars" aria-label="Rate your call">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <a
                        href="?rating=<?= $i ?>"
                        class="<?= $i <= $rating ? 'active' : '' ?>"
                        aria-label="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>"
                    >★</a>
                <?php endfor; ?>
            </div>
        </section>

        <div class="actions">
            <a class="button primary" href="peer-call">
                Start another call
            </a>

            <a class="button" href="https://api.whatsapp.com/send/?phone=17328896894&text=Hi%2C+I%27d+like+to+share+some+feedback+about+PeerCall.&type=phone_number&app_absent=0">
                Contact / Feedback
            </a>
        </div>

        <div class="footer">
            PeerCall · Simple peer-to-peer calling
        </div>
    </main>
</body>
</html>
