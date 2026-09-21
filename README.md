# PHP-WebRTC

PHP-WebRTC is a lightweight, browser-based video calling application built with **PHP, JavaScript, WebRTC, and file-based signaling**.

The project is intentionally simple: there is no database, no framework, and no external signaling server. PHP handles a small temporary signaling queue while WebRTC establishes the actual peer-to-peer video connection between users.

---

## Features

- 🎥 Peer-to-peer video calling with WebRTC
- 📞 Unique call/room IDs
- 🔗 Shareable call links
- ✅ Incoming call prompt
- ❌ Accept or decline incoming calls
- 📷 Camera toggle
- 🔄 Front/rear camera switching on supported devices
- 📱 Responsive mobile and desktop interface
- 🌐 STUN support for NAT traversal
- 🗂️ File-based PHP signaling
- 🧹 Automatic expiration of old signaling messages
- 🚫 No database required
- 🚫 No account required

---

## How It Works

PHP-WebRTC uses **WebRTC** for the actual video connection. PHP is only used for **signaling**.

The general flow is:

```text
User A
   │
   │ Opens unique call URL
   ▼
home.php
   │
   │ join
   ▼
PHP signaling queue
   │
   ▼
User B
   │
   │ Receives join
   ▼
Creates WebRTC Offer
   │
   │ offer
   ▼
PHP signaling queue
   │
   ▼
User B
   │
   │ Accept
   ▼
Creates WebRTC Answer
   │
   │ answer
   ▼
PHP signaling queue
   │
   ▼
User A
   │
   ▼
WebRTC connection established
   │
   ▼
Direct peer-to-peer video
```

Once WebRTC is established, the video does not travel through PHP. PHP only helps the two browsers exchange:

- **Offer**
- **Answer**
- **ICE candidates**
- **Join events**
- **Leave events**
- **Decline events**

---

## Project Structure

A minimal installation looks like this:

```text
PHP-WebRTC/
│
├── home.php
│
├── data/
│   ├── .gitkeep
│   └── .htaccess
│
├── .gitignore
│
└── README.md
```

### `home.php`
The main application file containing:
- PHP signaling API
- HTML interface
- CSS
- WebRTC JavaScript
- Room generation
- Call controls

*Note: The project is intentionally kept as a single-file application for simplicity.*

### `data/`
Temporary signaling storage. PHP creates JSON files inside this directory for each active room. 

*Example:* `data/.webrtc_room_call_a83f21d94c7e12ab.json`

These files contain signaling events (not video recordings) and should not be committed to Git.

- **`data/.gitkeep`**: Keeps the otherwise-empty `data/` directory tracked in Git.
- **`data/.htaccess`**: Prevents users from directly accessing signaling JSON files on Apache web servers:
  ```apache
  <FilesMatch "\.json$">
      Require all denied
  </FilesMatch>
  ```

---

## Configuration Constants

The PHP application defines several constants near the top of `home.php`:

```php
const MESSAGE_FILE_PREFIX = '.webrtc_room_';
const MESSAGE_DIR = __DIR__ . '/data/';
const ROOM_PATTERN = '/^[a-zA-Z0-9_-]{3,64}$/';
const MESSAGE_TTL = 300;
```

### `MESSAGE_FILE_PREFIX`
Defines the prefix used when PHP creates a signaling file. For room `call_a83f21d94c7e12ab`, the file becomes `.webrtc_room_call_a83f21d94c7e12ab.json`. The leading dot hides the file on Unix-like systems and distinguishes PHP-WebRTC's files from other data.

### `MESSAGE_DIR`
Specifies where signaling files are stored (`/home.php-directory/data/`). PHP-WebRTC creates this directory automatically if it does not exist.

### `ROOM_PATTERN`
Regex controlling valid room IDs. A room ID can contain `a-z`, `A-Z`, `0-9`, `_`, and `-`, between 3 and 64 characters long.
- **Valid:** `abc`, `meeting-123`, `call_a83f21d94c7e12ab`, `my_test_room`
- **Invalid:** `ab`, `hello world`, `room/name`, `room@example.com`

This prevents arbitrary strings from being used as filesystem paths.

### `MESSAGE_TTL`
Controls signaling message retention in seconds (`300` = 5 minutes). Messages older than 5 minutes are deleted when room signaling data is read to keep temporary files from growing indefinitely.

---

## Room IDs & Client IDs

### Room IDs
PHP-WebRTC automatically generates a unique room ID when `home.php` is opened without a room parameter.

```text
home.php  ──►  home.php?room=call_a83f21d94c7e12ab
```

Generated via `'call_' . bin2hex(random_bytes(8))`, the ID is inserted into the address bar using `window.history.replaceState(...)` so users can directly copy and share the URL.

### Client IDs
While Room IDs identify the call, Client IDs identify individual browsers within that call using `crypto.randomUUID()` (with a fallback implementation if unavailable). A Client ID is not a user account—it simply allows the signaling server to prevent sending a browser its own messages.

---

## Signaling API

PHP-WebRTC relies on two lightweight PHP endpoints:

### Send
`?action=send&room=ROOM_ID&client=CLIENT_ID`

Used by the browser to post signaling events.

**Request Body:**
```json
{
    "event": "offer",
    "data": {}
}
```

### Poll
`?action=poll&room=ROOM_ID&client=CLIENT_ID`

Used by browsers to retrieve pending signaling messages belonging to other clients in the room.

### Supported Signaling Events
- **`join`**: Sent when a user enters a call room to notify other participants.
- **`offer`**: Contains the WebRTC session offer created by the caller (triggers the "Incoming call" prompt on the recipient's device).
- **`answer`**: Contains the WebRTC answer generated after accepting a call.
- **`candidate`**: Contains ICE candidates to help determine direct network communication routes.
- **`leave`**: Sent when a participant hangs up or leaves, prompting the peer connection to close.
- **`decline`**: Sent when a recipient declines an incoming call, returning the caller to the ready state.

---

## WebRTC Configuration

PHP-WebRTC uses standard `RTCPeerConnection` for video streaming.

### Media Stream Settings
Currently configured for video+audio calls:
```javascript
{
    video: {
        facingMode: currentFacingMode,
        width: { ideal: 1280 },
        height: { ideal: 720 },
        frameRate: { ideal: 30, max: 30 }
    },
    audio: true
}
```

### STUN Servers
Configured with public STUN servers for NAT traversal:
```javascript
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
```

#### STUN vs. TURN

- **STUN (Used currently):** Direct peer-to-peer route discovery.
  ```text
  Browser A ───────────── Browser B
               │
            STUN helps
          discover routes
  ```
- **TURN (Recommended for production):** Relays media when direct P2P connections are blocked by strict firewalls.
  ```text
  Browser A ─── TURN Server ─── Browser B
                    │
               relays traffic
  ```

---

## Environment & Deployment Requirements

### Local Development
Run the built-in PHP development server:
```bash
php -S localhost:8000
```
Navigate to `http://localhost:8000/home.php`. 

*Note: Modern browsers require an **HTTPS** context for media device (camera) access in non-localhost environments.*

### Production Requirements
- **PHP** runtime environment
- **HTTPS** certificate (SSL)
- **Writable** `data/` directory
- **Modern Browser** with WebRTC support
- *(Recommended)* Apache/Nginx web server, directory protection, TURN server, rate limiting, and monitoring.

---

## Security & Data Privacy

- **Link-Based Room Access:** Anyone with the call URL can join the room. Treat call links as private invitations.
- **Signaling Data Protection:** The generated JSON files contain temporary metadata (client IDs, timestamp, signaling payloads) and must not be publicly accessible via HTTP. Ensure web server rules block access to `data/*.json`.

Example JSON payload stored in `data/`:
```json
{
    "id": "...",
    "client": "...",
    "event": "offer",
    "data": {},
    "time": 1760000000
}
```

---

## Current Limitations & Potential Improvements

| Area | Current Limitation | Potential Improvement |
| :--- | :--- | :--- |
| **Signaling** | File polling | WebSockets, Redis, Database, server-side cleanup jobs |
| **WebRTC** | Video-only, STUN-only | Audio support, TURN integration, screen sharing, reconnection handling |
| **UX** | Basic call interface | Call duration, mute/unmute toggles, fullscreen controls, accessibility |
| **Security** | Possession-based room access | Password protection, link expiration, rate limiting, CSRF protection |

---

## Contributing

Contributions are welcome! Please follow these guidelines:

1. Understand the signaling flow and maintain a lightweight stack without unnecessary external dependencies.
2. Ensure runtime files in `/data/*.json` are not committed to Git.
3. Verify changes across desktop and mobile devices.

### Suggested Workflow

1. Create a feature branch:
   ```bash
   git checkout -b feature/your-feature
   ```
2. Test end-to-end call paths:
   - **Accept flow:** `Join` ➔ `Call` ➔ `Incoming prompt` ➔ `Accept` ➔ `Video established`
   - **Decline flow:** `Join` ➔ `Call` ➔ `Decline` ➔ `Caller notified`
   - **Lifecycle:** `Refresh / Leave room`
3. Commit and push:
   ```bash
   git add .
   git commit -m "Add your feature description"
   git push origin feature/your-feature
   ```

### Recommended `.gitignore`

```gitignore
/data/*.json
!/data/.gitkeep

.env
.env.*
!.env.example

.DS_Store
Thumbs.db

.vscode/
.idea/
```

---

## Architecture Summary

```text
┌──────────────────────┐
│      Browser A       │
│                      │
│  WebRTC Peer A       │
└──────────┬───────────┘
           │
           │ signaling
           ▼
┌──────────────────────┐
│      PHP Server      │
│                      │
│  home.php            │
│  └── signaling API   │
│                      │
│  data/*.json         │
└──────────┬───────────┘
           │
           │ signaling
           ▼
┌──────────────────────┐
│      Browser B       │
│                      │
│  WebRTC Peer B       │
└──────────────────────┘

After WebRTC connection established:

Browser A ◄══════════════════► Browser B
             P2P media
```

---

## Philosophy

PHP-WebRTC is intentionally small. The goal is to keep the codebase clean, easy to audit, deployable anywhere, and simple to experiment with—without forcing a complex infrastructure overhead. Prefer simple solutions whenever adding new features.