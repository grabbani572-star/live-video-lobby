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
    :root{--green:#00a884;--green-dark:#008f72;--ink:#111b21;--muted:#667781;--line:#e9edef;--panel:#fff;--page:#f0f2f5;--soft:#f7f9fa;--shadow:0 2px 10px rgba(17,27,33,.07)}
    *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink)}
    body{background:var(--page);min-height:100vh;display:flex;flex-direction:column}
    button,input{font:inherit}button{cursor:pointer;border:0}button:disabled{opacity:.55;cursor:not-allowed}
    .topbar{height:66px;flex:0 0 66px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 clamp(14px,3vw,34px);box-shadow:0 1px 4px rgba(0,0,0,.03);z-index:2}
    .brand{display:flex;align-items:center;gap:12px;min-width:0}.brand-mark{width:40px;height:40px;border-radius:14px;background:var(--green);color:#fff;display:grid;place-items:center;font-size:21px;font-weight:800}.brand h1{font-size:17px;line-height:1.15;margin:0;font-weight:750;letter-spacing:-.3px}.brand small{display:block;color:var(--muted);font-size:11px;margin-top:4px}
    .top-actions{display:flex;align-items:center;gap:12px}.connection{display:inline-flex;align-items:center;gap:7px;border:1px solid #d8f2e9;background:#effbf6;color:#087e5b;border-radius:999px;padding:7px 11px;font-size:12px;font-weight:650}.connection:before{content:"";width:7px;height:7px;border-radius:50%;background:#18b77b}.connection.offline{background:#fff7ed;border-color:#fed7aa;color:#c2410c}.connection.offline:before{background:#f97316}
    #hangup-btn{background:#e5484d;color:white;padding:10px 15px;border-radius:999px;font-size:13px;font-weight:700;box-shadow:0 3px 8px #e5484d33}
    .app-shell{width:min(1440px,100%);margin:0 auto;padding:20px;display:grid;grid-template-columns:320px minmax(0,1fr);gap:16px;flex:1;min-height:0}
    .panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);min-width:0;overflow:hidden}
    .people-panel{display:flex;flex-direction:column;min-height:520px}.panel-head{padding:19px 18px 14px;border-bottom:1px solid var(--line)}.panel-title{display:flex;justify-content:space-between;align-items:center;gap:10px}.panel-title h2{font-size:16px;margin:0;font-weight:750}.count-pill{background:#e8f7f2;color:#078365;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:750}
    .search-wrap{position:relative;margin-top:14px}.search-wrap input{width:100%;border:1px solid #e5eaed;background:#f6f8f9;border-radius:10px;padding:11px 12px 11px 36px;outline:none;font-size:13px;color:var(--ink)}.search-wrap input:focus,#msg-input:focus{border-color:#8bd8c5;box-shadow:0 0 0 3px #00a88416}.search-icon{position:absolute;left:12px;top:9px;color:#809098;font-size:17px}
    .user-list{list-style:none;padding:8px;margin:0;overflow:auto;flex:1}.user-item{display:flex;align-items:center;gap:11px;padding:11px 10px;border-radius:12px;margin:2px 0;min-width:0;transition:background .15s}.user-item:hover{background:#f4f7f7}.user-avatar{width:42px;height:42px;flex:0 0 42px;border-radius:50%;display:grid;place-items:center;background:#d9f4eb;color:#087e5b;font-weight:800;font-size:14px;text-transform:uppercase}.user-meta{min-width:0;flex:1}.user-name{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px;font-weight:700}.user-sub{display:block;color:var(--muted);font-size:11px;margin-top:4px}.user-item .btn-call{background:#e7f7f1;color:#078365;border-radius:9px;padding:8px 10px;font-size:12px;font-weight:750}.user-item .btn-call:hover{background:#d4f1e6}.empty-users{color:var(--muted);font-size:13px;text-align:center;padding:30px 12px}
    .main-panel{display:flex;flex-direction:column;min-height:520px}.chat-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 19px;border-bottom:1px solid var(--line);background:#fff}.chat-heading{display:flex;align-items:center;gap:11px;min-width:0}.chat-heading .brand-mark{width:38px;height:38px;border-radius:50%;font-size:18px}.chat-heading h2{font-size:14px;margin:0;font-weight:750}.chat-heading p{font-size:11px;color:var(--muted);margin:4px 0 0}.chat-head-note{font-size:11px;color:var(--muted);white-space:nowrap}
    .workspace{padding:16px;display:grid;grid-template-columns:minmax(0,1fr) minmax(250px,.72fr);gap:15px;flex:1;min-height:0;background:#f8fafb}.video-area{min-width:0;display:flex;flex-direction:column;gap:10px}.video-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.video-box{position:relative;background:#17252b;border-radius:14px;overflow:hidden;aspect-ratio:4/3;border:1px solid #dbe3e5;min-width:0}.video-box video{display:block;width:100%;height:100%;object-fit:cover}.video-box:after{content:"";position:absolute;inset:auto 0 0;height:55px;background:linear-gradient(transparent,rgba(0,0,0,.55));pointer-events:none}.video-placeholder{position:absolute;inset:0;display:grid;place-items:center;color:#d8e4e7;font-size:12px;text-align:center;padding:12px;pointer-events:none}.video-placeholder span{display:block;font-size:30px;margin-bottom:8px}.label{position:absolute;z-index:1;bottom:10px;left:10px;color:#fff;background:#111b21a8;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:650;max-width:85%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .video-hint{background:#fff;border:1px dashed #d6e2e4;color:var(--muted);padding:11px 13px;border-radius:11px;font-size:11px;line-height:1.5}.chat-column{display:flex;flex-direction:column;min-height:320px;min-width:0;background:#fff;border:1px solid var(--line);border-radius:13px;overflow:hidden}.chat-column-head{padding:12px 14px;border-bottom:1px solid var(--line);font-size:12px;font-weight:750;display:flex;justify-content:space-between;align-items:center}.chat-column-head span:last-child{font-size:10px;color:var(--muted);font-weight:500}.chat-box{flex:1;min-height:230px;max-height:480px;overflow-y:auto;padding:14px 12px;background-color:#f7f5f0;background-image:radial-gradient(#dfe6e3 0.6px,transparent .6px);background-size:15px 15px;display:flex;flex-direction:column;gap:8px}.msg{align-self:flex-start;max-width:88%;background:#fff;padding:8px 10px;border-radius:4px 12px 12px 12px;box-shadow:0 1px 1px #0000000d;font-size:12px;line-height:1.45;overflow-wrap:anywhere;white-space:pre-wrap}.msg strong{display:block;color:#078365;font-size:10px;margin-bottom:3px}.msg .time{display:block;text-align:right;color:#829097;font-size:9px;margin-top:4px}.msg.mine{align-self:flex-end;background:#d9fdd3;border-radius:12px 4px 12px 12px}.msg.mine strong{color:#28745c}.chat-input{display:flex;gap:8px;padding:11px;border-top:1px solid var(--line);background:#fff}.chat-input input{min-width:0;flex:1;border:1px solid #e4e9eb;background:#f7f9fa;border-radius:999px;padding:11px 15px;outline:none;font-size:12px}.btn-send{background:var(--green);color:#fff;border-radius:999px;padding:0 17px;font-size:12px;font-weight:750}.btn-send:hover{background:var(--green-dark)}
    .modal-overlay{position:fixed;inset:0;background:rgba(11,24,29,.58);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:999;padding:16px}.modal{background:#fff;color:var(--ink);padding:25px;border-radius:20px;text-align:center;width:min(360px,100%);box-shadow:0 20px 70px #0003}.modal h3{color:var(--ink)!important;font-size:18px}.modal p{color:var(--muted)!important;font-size:13px;line-height:1.5}.modal-actions{display:flex;gap:10px;justify-content:center;margin-top:20px}.modal-actions button{border-radius:999px;padding:11px 20px;font-size:13px;font-weight:750}.modal .btn-call{background:var(--green);color:white}.modal .btn-end{background:#fee2e2;color:#b91c1c}
    @media(max-width:900px){.app-shell{grid-template-columns:260px minmax(0,1fr);padding:12px;gap:12px}.workspace{grid-template-columns:1fr}.chat-column{min-height:300px}.chat-box{max-height:340px}}
    @media(max-width:650px){.topbar{height:60px;flex-basis:60px;padding:0 13px}.brand-mark{width:36px;height:36px;border-radius:12px}.brand h1{font-size:15px}.brand small{font-size:10px}.connection{padding:6px 8px;font-size:10px}.top-actions{gap:7px}#hangup-btn{padding:8px 10px;font-size:11px}.app-shell{display:flex;flex-direction:column;padding:9px;gap:9px}.people-panel{min-height:0;max-height:235px}.panel-head{padding:12px 13px 10px}.panel-title h2{font-size:14px}.search-wrap{margin-top:9px}.search-wrap input{padding-top:9px;padding-bottom:9px}.user-list{display:flex;gap:5px;overflow-x:auto;overflow-y:hidden;padding:7px}.user-item{flex:0 0 150px;padding:8px;background:#f7f9fa;margin:0}.user-avatar{width:34px;height:34px;flex-basis:34px;font-size:12px}.user-item .btn-call{padding:7px;font-size:10px}.user-sub{font-size:10px}.main-panel{min-height:0;flex:1}.chat-head{padding:11px 13px}.workspace{padding:10px;gap:10px}.video-grid{gap:7px}.video-box{aspect-ratio:4/3;border-radius:11px}.label{bottom:7px;left:7px;font-size:10px}.video-hint{padding:8px 10px}.chat-column{min-height:280px}.chat-box{min-height:180px;max-height:34vh}.chat-input{padding:8px}.chat-input input{padding:10px 13px}.btn-send{padding:0 14px}.chat-head-note{display:none}}
    @media(max-width:380px){.video-grid{grid-template-columns:1fr}.video-box{aspect-ratio:16/10}.connection{max-width:118px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}}
  </style>
</head>
<body>
<header class="topbar">
  <div class="brand"><div class="brand-mark" aria-hidden="true">✆</div><div><h1>Live Lobby</h1><small>Video calls & community chat</small></div></div>
  <div class="top-actions"><span id="my-status" class="connection" role="status" aria-live="polite">Connecting...</span><button id="hangup-btn" type="button" hidden>End call</button></div>
</header>
<main class="app-shell">
  <aside class="panel people-panel">
    <div class="panel-head"><div class="panel-title"><h2>People online</h2><span class="count-pill"><span id="online-count">0</span> online</span></div><div class="search-wrap"><span class="search-icon" aria-hidden="true">⌕</span><input id="user-search" type="search" placeholder="Search people..." autocomplete="off" aria-label="Search online people"></div></div>
    <ul id="user-list" class="user-list"><li class="empty-users">Waiting for visitors...</li></ul>
  </aside>
  <section class="panel main-panel">
    <div class="chat-head"><div class="chat-heading"><div class="brand-mark" aria-hidden="true">◎</div><div><h2>Lobby conversation</h2><p>Messages are visible to everyone in this lobby</p></div></div><span class="chat-head-note">Be kind · Stay connected</span></div>
    <div class="workspace">
      <section class="video-area" aria-label="Video call area">
        <div class="video-grid">
          <div class="video-box"><video id="local-video" autoplay playsinline muted></video><div class="video-placeholder" id="local-placeholder"><div><span>◉</span>Your camera preview</div></div><div class="label" id="local-label">You</div></div>
          <div class="video-box"><video id="remote-video" autoplay playsinline></video><div class="video-placeholder" id="remote-placeholder"><div><span>▣</span>Waiting for a call</div></div><div class="label" id="remote-label">Remote visitor</div></div>
        </div>
        <div class="video-hint">Select a person from the online list and tap <b>Call</b>. Allow camera and microphone access when your browser asks.</div>
      </section>
      <section class="chat-column" aria-label="Lobby chat">
        <div class="chat-column-head"><span>💬 Messages</span><span>Live lobby chat</span></div>
        <div id="chat-box" class="chat-box" aria-live="polite" aria-relevant="additions text"></div>
        <form id="chat-form" class="chat-input"><input type="text" id="msg-input" maxlength="1000" placeholder="Type a message..." autocomplete="off" required aria-label="Message"><button type="submit" class="btn-send" aria-label="Send message">Send ➤</button></form>
      </section>
    </div>
  </section>
</main>
<div id="ring-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="incoming-title"><div class="modal"><div style="font-size:42px" aria-hidden="true">📞</div><h3 id="incoming-title" style="margin:8px 0">Incoming call</h3><p id="caller-text" style="margin:0">Someone is calling you...</p><div class="modal-actions"><button id="accept-btn" class="btn-call" type="button">Accept</button><button id="reject-btn" class="btn-end" type="button">Decline</button></div></div></div>
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
  let currentUsers = [];

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
    $('local-placeholder').style.display = 'none';
    return localStream;
  }

  // Ask for media access at entry, as in the original application.
  enableMedia().catch(error => {
    statusEl.textContent = 'Camera unavailable · chat available';
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
      $('remote-placeholder').style.display = 'none';
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
      statusEl.textContent = 'Online · ' + myName;
      statusEl.classList.remove('offline');
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
      statusEl.textContent = 'Reconnecting...';
      statusEl.classList.add('offline');
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
      statusEl.textContent = 'Sync issue · retrying';
      statusEl.classList.add('offline');
      console.warn('Heartbeat:', error);
    } finally {
      heartbeatInProgress = false;
    }
  }

  function renderUsers(users) {
    currentUsers = users;
    const list = $('user-list');
    $('online-count').textContent = String(users.length);
    list.replaceChildren();
    const query = ($('user-search').value || '').trim().toLowerCase();
    const visibleUsers = users.filter(user => user && typeof user.peerId === 'string' && cleanDisplayName(user.name, 'Guest').toLowerCase().includes(query));

    if (!visibleUsers.length) {
      const empty = document.createElement('li');
      empty.className = 'empty-users';
      empty.textContent = query ? 'No matching visitors.' : 'No visitors online yet. Keep this page open to appear here.';
      list.appendChild(empty);
      return;
    }

    visibleUsers.forEach(user => {
      const li = document.createElement('li');
      li.className = 'user-item';
      const isMe = user.peerId === myPeerId;
      const displayName = cleanDisplayName(user.name, 'Guest');
      const avatar = document.createElement('span');
      avatar.className = 'user-avatar';
      avatar.textContent = displayName.slice(0, 1).toUpperCase();
      const meta = document.createElement('span');
      meta.className = 'user-meta';
      const name = document.createElement('span');
      name.className = 'user-name';
      name.textContent = displayName + (isMe ? ' (You)' : '');
      const sub = document.createElement('span');
      sub.className = 'user-sub';
      sub.textContent = isMe ? 'This is you' : 'Available to call';
      meta.append(name, sub);
      li.append(avatar, meta);
      if (!isMe) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn-call';
        button.textContent = 'Call';
        button.addEventListener('click', () => startCall(user.peerId, displayName));
        li.appendChild(button);
      }
      list.appendChild(li);
    });
  }

  $('user-search').addEventListener('input', () => renderUsers(currentUsers));

  function renderMessages(messages) {
    const chatBox = $('chat-box');
    const wasAtBottom = chatBox.scrollHeight - chatBox.scrollTop <= chatBox.clientHeight + 30;
    const signature = messages.map(message => message && message.id ? message.id : '').join('|');

    chatBox.replaceChildren();

    messages.forEach(message => {
      if (!message || typeof message.text !== 'string') return;

      const row = document.createElement('div');
      row.className = 'msg' + (cleanDisplayName(message.sender, 'Guest') === myName ? ' mine' : '');

      const sender = document.createElement('strong');
      sender.textContent = cleanDisplayName(message.sender, 'Guest');

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
