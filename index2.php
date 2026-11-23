<?php
declare(strict_types=1);

/**
 * Live Visitor Lobby — PHP + JSON state + PeerJS
 * Requirements: PHP 7.4+, HTTPS, and a writable private storage directory.
 */

const USER_TIMEOUT_SECONDS = 15;
const MAX_USERS = 500;
const MAX_MESSAGES = 40;
const MAX_NAME_LENGTH = 40;
const MAX_MESSAGE_LENGTH = 1000;

// Prefer storage outside the public web root. If hosting permissions prevent
// that, use a protected subfolder beside this script and deny direct web access.
$documentRoot = realpath(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : __DIR__);
$privateParent = ($documentRoot !== false) ? dirname($documentRoot) : dirname(__DIR__);
$dataDirectory = $privateParent . DIRECTORY_SEPARATOR . 'live_lobby_private_data';

if ((!is_dir($dataDirectory) && !@mkdir($dataDirectory, 0700, true)) || !is_writable($dataDirectory)) {
    $dataDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'lobby_private';
    if (!is_dir($dataDirectory) && !@mkdir($dataDirectory, 0700, true)) {
        http_response_code(500);
        exit('Storage setup failed. Please allow PHP write permission for this folder.');
    }
    if (!is_writable($dataDirectory)) {
        http_response_code(500);
        exit('Storage folder is not writable. Please check folder permissions.');
    }
    // Apache protection. On Nginx, deny access to /lobby_private/ in server config.
    $denyFile = $dataDirectory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\nDeny from all\n");
    }
    $indexFile = $dataDirectory . DIRECTORY_SEPARATOR . 'index.php';
    if (!file_exists($indexFile)) {
        @file_put_contents($indexFile, "<?php http_response_code(404); exit; ?>");
    }
}

$stateFile = $dataDirectory . DIRECTORY_SEPARATOR . 'lobby_state.json';
$lockFile  = $dataDirectory . DIRECTORY_SEPARATOR . 'lobby_state.lock';

function jsonResponse(array $data, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function cleanString($value, int $maxLength): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    // Avoid accepting an unbounded input when mbstring is unavailable.
    return substr($value, 0, $maxLength * 4);
}

function validPeerId($value): string
{
    if (!is_string($value)) {
        return '';
    }

    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
    return is_string($id) ? substr($id, 0, 100) : '';
}

function defaultState(): array
{
    return ['users' => [], 'messages' => []];
}

function readStateFromHandle($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle);

    if (!is_string($raw) || $raw === '') {
        return defaultState();
    }

    $state = json_decode($raw, true);

    if (!is_array($state)) {
        return defaultState();
    }

    return [
        'users' => isset($state['users']) && is_array($state['users']) ? $state['users'] : [],
        'messages' => isset($state['messages']) && is_array($state['messages']) ? $state['messages'] : [],
    ];
}

/**
 * All read-modify-write operations use the same lock file. This prevents
 * concurrent PHP requests from overwriting one another's updates.
 */
function updateState(string $stateFile, string $lockFile, callable $callback)
{
    $lock = @fopen($lockFile, 'c+');
    if ($lock === false) {
        throw new RuntimeException('Cannot open state lock file.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock lobby state.');
        }

        $stateHandle = @fopen($stateFile, 'c+');
        if ($stateHandle === false) {
            throw new RuntimeException('Cannot open lobby state file.');
        }

        try {
            $state = readStateFromHandle($stateHandle);
            $result = $callback($state);

            $encoded = json_encode(
                $state,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($encoded === false) {
                throw new RuntimeException('Could not encode lobby state.');
            }

            rewind($stateHandle);
            if (!ftruncate($stateHandle, 0) || fwrite($stateHandle, $encoded) === false) {
                throw new RuntimeException('Could not save lobby state.');
            }
            fflush($stateHandle);

            return $result;
        } finally {
            fclose($stateHandle);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function removeInactiveUsers(array &$state, int $now): void
{
    foreach ($state['users'] as $peerId => $user) {
        $lastSeen = is_array($user) ? (int)($user['last_seen'] ?? 0) : 0;
        if ($now - $lastSeen > USER_TIMEOUT_SECONDS) {
            unset($state['users'][$peerId]);
        }
    }
}

/* ------------------------------ API ------------------------------------ */

if (isset($_GET['action'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'error' => 'POST method required.'], 405);
    }

    $rawInput = file_get_contents('php://input');
    if (!is_string($rawInput) || strlen($rawInput) > 16000) {
        jsonResponse(['ok' => false, 'error' => 'Request is too large.'], 413);
    }

    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        jsonResponse(['ok' => false, 'error' => 'Invalid JSON request.'], 400);
    }

    $action = is_string($_GET['action']) ? $_GET['action'] : '';
    $now = time();

    try {
        if ($action === 'heartbeat') {
            $peerId = validPeerId($input['peerId'] ?? null);
            $name = cleanString($input['name'] ?? 'Guest', MAX_NAME_LENGTH);
            if ($peerId === '') {
                jsonResponse(['ok' => false, 'error' => 'Invalid peer ID.'], 400);
            }

            $result = updateState($stateFile, $lockFile, function (&$state) use ($peerId, $name, $now) {
                removeInactiveUsers($state, $now);

                if (!isset($state['users'][$peerId]) && count($state['users']) >= MAX_USERS) {
                    return ['ok' => false, 'error' => 'Lobby is full.'];
                }

                $state['users'][$peerId] = [
                    'peerId' => $peerId,
                    'name' => $name !== '' ? $name : 'Guest',
                    'last_seen' => $now,
                ];

                return [
                    'ok' => true,
                    'users' => array_values($state['users']),
                    'messages' => array_slice($state['messages'], -MAX_MESSAGES),
                ];
            });

            jsonResponse($result, ($result['ok'] ?? false) ? 200 : 429);
        }

        if ($action === 'message') {
            $peerId = validPeerId($input['peerId'] ?? null);
            $message = cleanString($input['text'] ?? null, MAX_MESSAGE_LENGTH);

            if ($peerId === '' || $message === '') {
                jsonResponse(['ok' => false, 'error' => 'A valid user and message are required.'], 400);
            }

            $result = updateState($stateFile, $lockFile, function (&$state) use ($peerId, $message, $now) {
                removeInactiveUsers($state, $now);

                if (!isset($state['users'][$peerId])) {
                    return ['ok' => false, 'error' => 'Your lobby session expired. Refresh the page.'];
                }

                $state['messages'][] = [
                    'id' => bin2hex(random_bytes(8)),
                    'sender' => (string)($state['users'][$peerId]['name'] ?? 'Guest'),
                    'text' => $message,
                    'time' => date('H:i', $now),
                ];

                $state['messages'] = array_slice($state['messages'], -MAX_MESSAGES);
                return ['ok' => true];
            });

            jsonResponse($result, ($result['ok'] ?? false) ? 200 : 401);
        }

        if ($action === 'leave') {
            $peerId = validPeerId($input['peerId'] ?? null);
            if ($peerId !== '') {
                updateState($stateFile, $lockFile, function (&$state) use ($peerId) {
                    unset($state['users'][$peerId]);
                    return true;
                });
            }
            jsonResponse(['ok' => true]);
        }

        jsonResponse(['ok' => false, 'error' => 'Unknown action.'], 404);
    } catch (Throwable $error) {
        error_log('Lobby API error: ' . $error->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Server storage error.'], 500);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#0f172a">
  <title>Live Lobby — Video Call & Chat</title>
  <style>
    * { box-sizing: border-box; font-family: system-ui, -apple-system, sans-serif; }
    body { margin: 0; background: #0f172a; color: #f8fafc; min-height: 100vh; display: flex; flex-direction: column; }
    header { background: #1e293b; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; border-bottom: 1px solid #334155; }
    header h2 { margin: 0; font-size: 18px; color: #38bdf8; }
    #my-status { font-size: 13px; color: #94a3b8; }
    .container { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 16px; padding: 16px; flex: 1; }
    .card { min-width: 0; background: #1e293b; border-radius: 12px; padding: 16px; border: 1px solid #334155; display: flex; flex-direction: column; }
    .card h3 { margin: 0 0 12px; font-size: 15px; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; }
    .user-list { list-style: none; padding: 0; margin: 0; flex: 1; overflow-y: auto; }
    .user-item { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 10px; background: #0f172a; border-radius: 8px; margin-bottom: 8px; overflow-wrap: anywhere; }
    .user-name { min-width: 0; }
    .dot { height: 9px; width: 9px; background: #22c55e; border-radius: 50%; display: inline-block; margin-right: 6px; flex: 0 0 auto; }
    button { border: 0; border-radius: 6px; padding: 8px 12px; font-weight: 600; cursor: pointer; transition: background .2s, opacity .2s; }
    button:disabled { cursor: not-allowed; opacity: .55; }
    .btn-call { background: #22c55e; color: #052e16; }
    .btn-call:hover:not(:disabled) { background: #16a34a; color: #fff; }
    .btn-end { background: #ef4444; color: #fff; }
    .btn-send { background: #38bdf8; color: #0f172a; }
    .video-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
    .video-box { position: relative; background: #020617; border-radius: 10px; overflow: hidden; aspect-ratio: 16 / 9; border: 1px solid #334155; }
    video { display: block; width: 100%; height: 100%; object-fit: cover; }
    .label { position: absolute; bottom: 8px; left: 8px; background: rgba(0,0,0,.65); padding: 3px 8px; border-radius: 4px; font-size: 12px; }
    .chat-box { flex: 1; background: #0f172a; border-radius: 8px; padding: 10px; overflow-y: auto; min-height: 150px; max-height: 300px; margin-bottom: 10px; font-size: 14px; overflow-wrap: anywhere; }
    .msg { margin-bottom: 8px; line-height: 1.4; white-space: pre-wrap; }
    .msg strong { color: #38bdf8; }
    .msg .time { font-size: 11px; color: #64748b; margin-left: 6px; }
    .chat-input { display: flex; gap: 8px; }
    .chat-input input { min-width: 0; flex: 1; padding: 10px 12px; border-radius: 6px; border: 1px solid #334155; background: #0f172a; color: #fff; }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.75); display: none; align-items: center; justify-content: center; z-index: 999; padding: 16px; }
    .modal { background: #1e293b; padding: 24px; border-radius: 14px; text-align: center; width: min(320px, 100%); border: 2px solid #22c55e; animation: pulse 1.2s infinite; }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(34,197,94,.6); } 70% { box-shadow: 0 0 0 15px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
    .modal-actions { display: flex; gap: 12px; justify-content: center; margin-top: 18px; }
    @media (max-width: 768px) { .container { grid-template-columns: 1fr; } }
    @media (max-width: 600px) { .video-grid { grid-template-columns: 1fr; } header { align-items: flex-start; } }
  </style>
</head>
<body>
<header>
  <h2>Live Visitor Call &amp; Chat</h2>
  <div>
    <span id="my-status" role="status" aria-live="polite">Initializing...</span>
    <button id="hangup-btn" class="btn-end" type="button" hidden>End Call</button>
  </div>
</header>

<main class="container">
  <section class="card">
    <h3>Online Visitors (<span id="online-count">0</span>)</h3>
    <ul id="user-list" class="user-list">
      <li style="color:#64748b;font-size:13px;">Waiting for visitors...</li>
    </ul>
  </section>

  <section class="card">
    <div class="video-grid">
      <div class="video-box">
        <video id="local-video" autoplay playsinline muted></video>
        <div class="label" id="local-label">You</div>
      </div>
      <div class="video-box">
        <video id="remote-video" autoplay playsinline></video>
        <div class="label" id="remote-label">Remote User</div>
      </div>
    </div>

    <h3>Live Lobby Message</h3>
    <div id="chat-box" class="chat-box" aria-live="polite"></div>
    <form id="chat-form" class="chat-input">
      <input type="text" id="msg-input" maxlength="1000" placeholder="Send a message to visitors..." autocomplete="off" required>
      <button type="submit" class="btn-send">Send</button>
    </form>
  </section>
</main>

<div id="ring-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="incoming-title">
  <div class="modal">
    <div style="font-size:36px;" aria-hidden="true">📞</div>
    <h3 id="incoming-title" style="color:#fff;margin:8px 0;">Incoming Call</h3>
    <p id="caller-text" style="color:#cbd5e1;margin:0;">Someone is calling you...</p>
    <div class="modal-actions">
      <button id="accept-btn" class="btn-call" type="button">Accept</button>
      <button id="reject-btn" class="btn-end" type="button">Decline</button>
    </div>
  </div>
</div>

<script src="https://unpkg.com/peerjs@1.5.4/dist/peerjs.min.js"></script>
<script>
(() => {
  'use strict';

  const API = window.location.pathname || 'index.php';
  const HEARTBEAT_MS = 4000;
  let myName = (window.prompt('Enter your display name:', 'Visitor-' + Math.floor(100 + Math.random() * 900)) || '').trim();
  if (!myName) myName = 'Visitor-' + Math.floor(100 + Math.random() * 900);
  myName = myName.slice(0, 40);

  let myPeerId = null;
  let localStream = null;
  let currentCall = null;
  let pendingCall = null;
  let heartbeatTimer = null;
  let heartbeatInProgress = false;
  let callBusy = false;
  let lastMessageSignature = '';

  const $ = id => document.getElementById(id);
  const localVideo = $('local-video');
  const remoteVideo = $('remote-video');
  const hangupBtn = $('hangup-btn');
  const ringModal = $('ring-modal');
  const statusEl = $('my-status');

  $('local-label').textContent = myName + ' (You)';

  async function apiRequest(action, payload, keepalive = false) {
    const response = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      cache: 'no-store',
      keepalive
    });

    const data = await response.json();
    if (!response.ok || data.ok === false) {
      throw new Error(data.error || `Request failed (${response.status})`);
    }
    return data;
  }

  async function enableMedia() {
    if (localStream && localStream.getTracks().some(track => track.readyState === 'live')) {
      return localStream;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      throw new Error('Camera and microphone require HTTPS (or localhost) and a supported browser.');
    }

    localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    localVideo.srcObject = localStream;
    return localStream;
  }

  // Ask for media access at entry, as in the original application.
  enableMedia().catch(error => {
    statusEl.textContent = 'Camera unavailable — chat is still available';
    console.warn('Media access:', error);
  });

  if (typeof Peer === 'undefined') {
    statusEl.textContent = 'PeerJS could not load. Chat may still work.';
  }

  const peer = typeof Peer !== 'undefined' ? new Peer() : null;

  function setCallUI(isActive, remoteName = 'Remote User') {
    hangupBtn.hidden = !isActive;
    $('remote-label').textContent = remoteName;
  }

  function cleanupCall(callToClose = null) {
    const oldCall = callToClose || currentCall;
    if (oldCall && currentCall === oldCall) currentCall = null;

    callBusy = false;
    if (oldCall && oldCall.open) {
      try { oldCall.close(); } catch (error) { console.warn(error); }
    }

    remoteVideo.srcObject = null;
    setCallUI(false);
  }

  function attachCallEvents(call, remoteName) {
    if (!call) {
      cleanupCall();
      statusEl.textContent = 'Could not start the call.';
      return;
    }

    if (currentCall && currentCall !== call) {
      cleanupCall(currentCall);
    }

    currentCall = call;
    callBusy = true;
    setCallUI(true, remoteName);

    call.on('stream', stream => {
      remoteVideo.srcObject = stream;
      remoteVideo.play().catch(() => {});
      $('remote-label').textContent = remoteName;
    });

    call.on('close', () => {
      if (currentCall === call) cleanupCall(call);
    });

    call.on('error', error => {
      console.warn('Call error:', error);
      if (currentCall === call) cleanupCall(call);
      statusEl.textContent = 'Call connection failed.';
    });
  }

  function startCall(targetPeerId, targetName) {
    if (!peer || !myPeerId) return;
    if (callBusy) {
      alert('You are already in a call or receiving a call.');
      return;
    }

    enableMedia().then(stream => {
      if (callBusy) return;
      callBusy = true;
      $('remote-label').textContent = 'Calling ' + targetName + '...';

      const call = peer.call(targetPeerId, stream, {
        metadata: { callerName: myName }
      });

      if (!call) {
        cleanupCall();
        alert('Unable to start the call. Please try again.');
        return;
      }
      attachCallEvents(call, targetName);
    }).catch(error => {
      alert(error.message || 'Please allow camera and microphone access first.');
    });
  }

  if (peer) {
    peer.on('open', id => {
      myPeerId = id;
      statusEl.textContent = 'Online as: ' + myName;
      sendHeartbeat();
      if (heartbeatTimer) clearInterval(heartbeatTimer);
      heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_MS);
    });

    peer.on('call', call => {
      if (callBusy || pendingCall) {
        call.close();
        return;
      }

      pendingCall = call;
      const callerName = cleanDisplayName(call.metadata && call.metadata.callerName, 'Another Visitor');
      $('caller-text').textContent = callerName + ' is requesting a video call.';
      ringModal.style.display = 'flex';
    });

    peer.on('disconnected', () => {
      statusEl.textContent = 'Connection lost. Reconnecting...';
      if (!peer.destroyed) {
        try { peer.reconnect(); } catch (error) { console.warn(error); }
      }
    });

    peer.on('error', error => {
      console.warn('PeerJS error:', error);
      if (error.type === 'peer-unavailable') {
        statusEl.textContent = 'That visitor is no longer available.';
      } else {
        statusEl.textContent = 'Connection issue: ' + error.type;
      }
    });

    peer.on('close', () => {
      statusEl.textContent = 'Disconnected from calling service.';
      cleanupCall();
    });
  }

  function cleanDisplayName(value, fallback) {
    return typeof value === 'string' && value.trim()
      ? value.trim().slice(0, 40)
      : fallback;
  }

  $('accept-btn').addEventListener('click', async () => {
    const call = pendingCall;
    if (!call) return;

    $('accept-btn').disabled = true;
    try {
      const stream = await enableMedia();
      pendingCall = null;
      ringModal.style.display = 'none';
      call.answer(stream);
      attachCallEvents(call, cleanDisplayName(call.metadata && call.metadata.callerName, 'Remote User'));
    } catch (error) {
      // If local media is unavailable, still try to receive the caller's stream.
      pendingCall = null;
      ringModal.style.display = 'none';
      try {
        call.answer();
        attachCallEvents(call, cleanDisplayName(call.metadata && call.metadata.callerName, 'Remote User'));
        statusEl.textContent = 'Call connected without your camera/microphone.';
      } catch (answerError) {
        call.close();
        statusEl.textContent = 'Could not accept the call.';
      }
    } finally {
      $('accept-btn').disabled = false;
    }
  });

  $('reject-btn').addEventListener('click', () => {
    if (pendingCall) {
      pendingCall.close();
      pendingCall = null;
    }
    ringModal.style.display = 'none';
  });

  hangupBtn.addEventListener('click', () => cleanupCall());

  async function sendHeartbeat() {
    if (!myPeerId || heartbeatInProgress) return;
    heartbeatInProgress = true;

    try {
      const data = await apiRequest('heartbeat', { peerId: myPeerId, name: myName });
      renderUsers(Array.isArray(data.users) ? data.users : []);
      renderMessages(Array.isArray(data.messages) ? data.messages : []);
    } catch (error) {
      statusEl.textContent = 'Lobby sync issue — retrying...';
      console.warn('Heartbeat:', error);
    } finally {
      heartbeatInProgress = false;
    }
  }

  function renderUsers(users) {
    const list = $('user-list');
    $('online-count').textContent = String(users.length);
    list.replaceChildren();

    if (!users.length) {
      const empty = document.createElement('li');
      empty.textContent = 'Waiting for visitors...';
      empty.style.color = '#64748b';
      empty.style.fontSize = '13px';
      list.appendChild(empty);
      return;
    }

    users.forEach(user => {
      if (!user || typeof user.peerId !== 'string') return;

      const li = document.createElement('li');
      li.className = 'user-item';

      const name = document.createElement('span');
      name.className = 'user-name';

      const dot = document.createElement('span');
      dot.className = 'dot';
      name.appendChild(dot);

      const isMe = user.peerId === myPeerId;
      name.appendChild(document.createTextNode(
        cleanDisplayName(user.name, 'Guest') + (isMe ? ' (You)' : '')
      ));
      li.appendChild(name);

      if (!isMe) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn-call';
        button.textContent = 'Call';
        button.addEventListener('click', () => startCall(user.peerId, cleanDisplayName(user.name, 'Visitor')));
        li.appendChild(button);
      }

      list.appendChild(li);
    });
  }

  function renderMessages(messages) {
    const chatBox = $('chat-box');
    const wasAtBottom = chatBox.scrollHeight - chatBox.scrollTop <= chatBox.clientHeight + 30;
    const signature = messages.map(message => message && message.id ? message.id : '').join('|');

    chatBox.replaceChildren();

    messages.forEach(message => {
      if (!message || typeof message.text !== 'string') return;

      const row = document.createElement('div');
      row.className = 'msg';

      const sender = document.createElement('strong');
      sender.textContent = cleanDisplayName(message.sender, 'Guest') + ': ';

      const body = document.createElement('span');
      body.textContent = message.text;

      const time = document.createElement('span');
      time.className = 'time';
      time.textContent = typeof message.time === 'string' ? message.time : '';

      row.append(sender, body, time);
      chatBox.appendChild(row);
    });

    if (wasAtBottom || signature !== lastMessageSignature) {
      chatBox.scrollTop = chatBox.scrollHeight;
    }
    lastMessageSignature = signature;
  }

  $('chat-form').addEventListener('submit', async event => {
    event.preventDefault();

    const input = $('msg-input');
    const message = input.value.trim();
    if (!message || !myPeerId) return;

    input.disabled = true;
    try {
      await apiRequest('message', { peerId: myPeerId, text: message });
      input.value = '';
      await sendHeartbeat();
    } catch (error) {
      alert(error.message || 'Could not send the message.');
    } finally {
      input.disabled = false;
      input.focus();
    }
  });

  function leaveLobby() {
    if (heartbeatTimer) clearInterval(heartbeatTimer);
    if (pendingCall) {
      try { pendingCall.close(); } catch (_) {}
      pendingCall = null;
    }
    cleanupCall();

    if (myPeerId && navigator.sendBeacon) {
      const payload = new Blob(
        [JSON.stringify({ peerId: myPeerId })],
        { type: 'application/json' }
      );
      navigator.sendBeacon(`${API}?action=leave`, payload);
    }

    if (localStream) {
      localStream.getTracks().forEach(track => track.stop());
      localStream = null;
    }
    if (peer && !peer.destroyed) peer.destroy();
  }

  window.addEventListener('pagehide', leaveLobby);
})();
</script>
</body>
</html>
