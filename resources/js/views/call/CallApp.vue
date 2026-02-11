<script setup lang="ts">
/**
 * CallApp.vue — Standalone call page (Google Meet style).
 *
 * This component runs in its own browser window/tab, completely independent
 * of the main SPA. It bootstraps its own Echo connection for signaling
 * and manages the full WebRTC lifecycle internally.
 *
 * Data is passed via sessionStorage from the parent window.
 */
import 'webrtc-adapter';
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { startEcho, stopEcho } from '@/echo';
import { videoCallService } from '@/services/videocall.service';

// ============================================================================
// Types
// ============================================================================

interface CallData {
  callId: string;
  chatId: string;
  callType: 'audio' | 'video';
  direction: 'outgoing' | 'incoming';
  remoteUser: {
    publicId: string;
    name: string;
    avatar: string | null;
  };
  // For incoming calls — the saved offer
  pendingOffer?: RTCSessionDescriptionInit | null;
  pendingCandidates?: RTCIceCandidateInit[];
  // The current user's public ID (to filter own events)
  selfPublicId: string;
}

// ============================================================================
// State
// ============================================================================

const callData = ref<CallData | null>(null);
const callState = ref<'initializing' | 'ringing' | 'connecting' | 'connected' | 'ended' | 'error'>('initializing');
const error = ref<string | null>(null);
const isMuted = ref(false);
const isCameraOff = ref(false);
const videoFallback = ref(false); // true if camera was unavailable
const callDuration = ref(0);

const localStream = ref<MediaStream | null>(null);
const remoteStream = ref<MediaStream | null>(null);

const localVideoRef = ref<HTMLVideoElement | null>(null);
const remoteVideoRef = ref<HTMLVideoElement | null>(null);
const remoteAudioRef = ref<HTMLAudioElement | null>(null);

let peerConnection: RTCPeerConnection | null = null;
let pendingIceCandidates: RTCIceCandidateInit[] = [];
let durationTimer: ReturnType<typeof setInterval> | null = null;
let echoChannel: any = null;
let broadcastChannel: BroadcastChannel | null = null;
let ringtoneAudio: HTMLAudioElement | null = null;
let ringtoneTimeout: ReturnType<typeof setTimeout> | null = null;

// ============================================================================
// Computed
// ============================================================================

const isVideoCall = computed(() => callData.value?.callType === 'video' && !videoFallback.value);

const stateLabel = computed(() => {
  switch (callState.value) {
    case 'initializing': return 'Starting call...';
    case 'ringing': return 'Ringing...';
    case 'connecting': return 'Connecting...';
    case 'connected': return formattedDuration.value;
    case 'ended': return 'Call ended';
    case 'error': return error.value || 'Error';
    default: return '';
  }
});

const formattedDuration = computed(() => {
  const mins = Math.floor(callDuration.value / 60);
  const secs = callDuration.value % 60;
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
});

const remoteInitials = computed(() => {
  const name = callData.value?.remoteUser.name || '?';
  return name
    .split(' ')
    .map((w) => w[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
});

// ============================================================================
// SDP Sanitizer
// ============================================================================

function sanitizeSdp(sdp: string | undefined): string {
  if (!sdp) return '';
  const result = sdp.replace(/a=ssrc:[^\r\n]*(\r?\n|$)/g, '');
  if (result !== sdp) {
    const removed = (sdp.match(/^a=ssrc:/gm) || []).length;
    console.log(`[Call] SDP sanitize: removed ${removed} a=ssrc lines`);
  }
  return result;
}

// ============================================================================
// Video element bindings
// ============================================================================

watch(localStream, async (stream) => {
  await nextTick();
  if (localVideoRef.value && stream) {
    localVideoRef.value.srcObject = stream;
  }
});

watch(remoteStream, async (stream) => {
  await nextTick();
  if (remoteVideoRef.value && stream) {
    remoteVideoRef.value.srcObject = stream;
  }
  // Also bind to audio element for audio-only calls
  if (remoteAudioRef.value && stream) {
    remoteAudioRef.value.srcObject = stream;
  }
});

// ============================================================================
// Media
// ============================================================================

async function acquireMedia(callType: 'audio' | 'video'): Promise<MediaStream | null> {
  console.log('[Call] acquireMedia, type:', callType);
  try {
    if (callType === 'video') {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          audio: true,
          video: { width: { ideal: 1280 }, height: { ideal: 720 } },
        });
        console.log('[Call] Got video+audio stream');
        localStream.value = stream;
        return stream;
      } catch (videoErr: any) {
        console.warn('[Call] Camera unavailable, falling back to audio-only:', videoErr.name);
        videoFallback.value = true;
        // Fall through to audio-only below
      }
    }

    const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    console.log('[Call] Got audio-only stream');
    localStream.value = stream;
    return stream;
  } catch (err: any) {
    console.error('[Call] Failed to acquire media:', err.name, err.message);
    error.value = err.name === 'NotAllowedError'
      ? 'Microphone permission denied. Please allow access.'
      : 'Could not access microphone.';
    callState.value = 'error';
    return null;
  }
}

// ============================================================================
// Peer Connection
// ============================================================================

async function createPeerConnection(): Promise<RTCPeerConnection> {
  const { chatId, callId } = callData.value!;
  console.log('[Call] createPeerConnection for:', callId);

  let iceServers: RTCIceServer[] = [
    { urls: 'stun:stun.cloudflare.com:3478' },
    { urls: 'stun:stun.l.google.com:19302' },
  ];

  try {
    const creds = await videoCallService.getTurnCredentials(chatId);
    iceServers = creds.ice_servers;
    console.log('[Call] Got ICE servers:', iceServers.length);
  } catch (err) {
    console.warn('[Call] Using fallback STUN-only:', err);
  }

  const pc = new RTCPeerConnection({ iceServers });
  console.log('[Call] RTCPeerConnection created');

  // Add local tracks
  if (localStream.value) {
    localStream.value.getTracks().forEach((track) => {
      pc.addTrack(track, localStream.value!);
    });
    console.log('[Call] Added', localStream.value.getTracks().length, 'tracks');
  }

  // Remote tracks
  pc.ontrack = (event) => {
    console.log('[Call] Remote track:', event.track.kind);
    if (event.streams[0]) {
      remoteStream.value = event.streams[0];
    }
  };

  // ICE candidates → send via signaling
  pc.onicecandidate = (event) => {
    if (event.candidate) {
      videoCallService.sendSignal(chatId, callId, 'ice-candidate', {
        candidate: event.candidate.candidate,
        sdpMid: event.candidate.sdpMid,
        sdpMLineIndex: event.candidate.sdpMLineIndex,
      }).catch(() => {});
    }
  };

  // Connection state
  pc.onconnectionstatechange = () => {
    console.log('[Call] Connection state:', pc.connectionState);
    switch (pc.connectionState) {
      case 'connected':
        console.log('[Call] ✅ CONNECTED');
        callState.value = 'connected';
        stopRingtone();
        startDurationTimer();
        postToParent({ type: 'state', state: 'connected' });
        break;
      case 'disconnected':
      case 'failed':
        console.error('[Call] Connection', pc.connectionState);
        handleCallFailed();
        break;
    }
  };

  pc.oniceconnectionstatechange = () => {
    console.log('[Call] ICE state:', pc.iceConnectionState);
  };

  peerConnection = pc;
  return pc;
}

// ============================================================================
// Outgoing Call Flow
// ============================================================================

async function startOutgoingCall() {
  const data = callData.value!;
  console.log('[Call] Starting outgoing call to', data.remoteUser.name);

  callState.value = 'ringing';
  playRingtone('outgoing');

  // Ring timeout
  ringtoneTimeout = setTimeout(() => {
    if (callState.value === 'ringing') {
      console.log('[Call] Ring timeout (45s)');
      endCall('timeout');
    }
  }, 45000);

  try {
    const pc = await createPeerConnection();
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    console.log('[Call] Offer created, sending...');

    await videoCallService.sendSignal(data.chatId, data.callId, 'offer', {
      type: offer.type,
      sdp: offer.sdp,
    });
    console.log('[Call] Offer sent');
  } catch (err) {
    console.error('[Call] Failed to start outgoing call:', err);
    error.value = 'Failed to start call';
    callState.value = 'error';
  }
}

// ============================================================================
// Incoming Call Flow
// ============================================================================

async function startIncomingCall() {
  const data = callData.value!;
  console.log('[Call] Starting incoming call from', data.remoteUser.name);

  callState.value = 'connecting';

  try {
    const pc = await createPeerConnection();

    // Apply the saved offer
    if (data.pendingOffer) {
      const sanitized = sanitizeSdp(data.pendingOffer.sdp);
      await pc.setRemoteDescription({ type: data.pendingOffer.type!, sdp: sanitized });
      console.log('[Call] Remote description (offer) set');

      // Flush saved ICE candidates
      if (data.pendingCandidates?.length) {
        console.log('[Call] Flushing', data.pendingCandidates.length, 'saved ICE candidates');
        for (const c of data.pendingCandidates) {
          await pc.addIceCandidate(new RTCIceCandidate(c));
        }
      }

      // Also flush any that arrived via Echo during setup
      if (pendingIceCandidates.length) {
        console.log('[Call] Flushing', pendingIceCandidates.length, 'echo ICE candidates');
        for (const c of pendingIceCandidates) {
          await pc.addIceCandidate(new RTCIceCandidate(c));
        }
        pendingIceCandidates = [];
      }

      // Create and send answer
      const answer = await pc.createAnswer();
      await pc.setLocalDescription(answer);
      console.log('[Call] Answer created, sending...');

      await videoCallService.sendSignal(data.chatId, data.callId, 'answer', {
        type: answer.type,
        sdp: answer.sdp,
      });
      console.log('[Call] ✅ Answer sent');
    } else {
      console.warn('[Call] No pending offer! Waiting for offer via Echo...');
      callState.value = 'connecting';
    }
  } catch (err) {
    console.error('[Call] Failed to start incoming call:', err);
    error.value = 'Failed to connect call';
    callState.value = 'error';
  }
}

// ============================================================================
// Signal Handling (from Echo)
// ============================================================================

async function handleSignal(event: any) {
  if (event.sender_public_id === callData.value?.selfPublicId) return;
  if (event.call_id !== callData.value?.callId) return;

  const { signal_type, signal_data } = event;
  console.log('[Call] Signal:', signal_type);

  try {
    switch (signal_type) {
      case 'offer':
        if (peerConnection) {
          const sanitized = sanitizeSdp(signal_data.sdp);
          await peerConnection.setRemoteDescription({ type: signal_data.type, sdp: sanitized });
          const answer = await peerConnection.createAnswer();
          await peerConnection.setLocalDescription(answer);
          await videoCallService.sendSignal(
            callData.value!.chatId, callData.value!.callId,
            'answer', { type: answer.type, sdp: answer.sdp },
          );
        }
        break;

      case 'answer':
        if (peerConnection) {
          const sanitized = sanitizeSdp(signal_data.sdp);
          await peerConnection.setRemoteDescription({ type: signal_data.type, sdp: sanitized });
          console.log('[Call] ✅ Answer applied');

          // Flush pending ICE candidates
          if (pendingIceCandidates.length) {
            for (const c of pendingIceCandidates) {
              await peerConnection.addIceCandidate(new RTCIceCandidate(c));
            }
            pendingIceCandidates = [];
          }
          callState.value = 'connecting';
        }
        break;

      case 'ice-candidate':
        if (peerConnection?.remoteDescription) {
          await peerConnection.addIceCandidate(new RTCIceCandidate(signal_data));
        } else {
          pendingIceCandidates.push(signal_data);
        }
        break;
    }
  } catch (err) {
    console.error('[Call] Signal error:', signal_type, err);
  }
}

function handleCallEndedEvent(event: any) {
  if (event.ender_public_id === callData.value?.selfPublicId) return;
  if (event.call_id !== callData.value?.callId) return;
  console.log('[Call] Remote party ended call:', event.reason);
  callState.value = 'ended';
  postToParent({ type: 'state', state: 'ended', reason: event.reason });
  closeAfterDelay();
}

// ============================================================================
// Echo Setup
// ============================================================================

function setupEcho() {
  const echo = startEcho();
  if (!echo || !callData.value) {
    console.error('[Call] Failed to start Echo');
    return;
  }

  const channelName = `dm.${callData.value.chatId}`;
  console.log('[Call] Subscribing to Echo channel:', channelName);

  echoChannel = echo.private(channelName);
  echoChannel
    .listen('.CallSignal', (event: any) => handleSignal(event))
    .listen('.CallEnded', (event: any) => handleCallEndedEvent(event));
}

// ============================================================================
// BroadcastChannel (sync with parent)
// ============================================================================

function setupBroadcastChannel() {
  broadcastChannel = new BroadcastChannel('worksphere-call');
  broadcastChannel.onmessage = (event) => {
    if (event.data?.type === 'end-call') {
      endCall('hangup');
    }
  };
}

function postToParent(msg: Record<string, any>) {
  broadcastChannel?.postMessage({ ...msg, callId: callData.value?.callId });
}

// ============================================================================
// Call Controls
// ============================================================================

function toggleMute() {
  isMuted.value = !isMuted.value;
  localStream.value?.getAudioTracks().forEach((t) => {
    t.enabled = !isMuted.value;
  });
}

function toggleCamera() {
  isCameraOff.value = !isCameraOff.value;
  localStream.value?.getVideoTracks().forEach((t) => {
    t.enabled = !isCameraOff.value;
  });
}

async function endCall(reason: 'hangup' | 'timeout' | 'failed' = 'hangup') {
  console.log('[Call] endCall:', reason);
  if (callData.value && callState.value !== 'ended') {
    videoCallService.endCall(callData.value.chatId, callData.value.callId, reason).catch(() => {});
  }
  callState.value = 'ended';
  postToParent({ type: 'state', state: 'ended', reason });
  cleanup();
  closeAfterDelay();
}

function handleCallFailed() {
  if (callData.value) {
    videoCallService.endCall(callData.value.chatId, callData.value.callId, 'failed').catch(() => {});
  }
  callState.value = 'ended';
  error.value = 'Connection lost';
  postToParent({ type: 'state', state: 'ended', reason: 'failed' });
  closeAfterDelay();
}

// ============================================================================
// Ringtone
// ============================================================================

function playRingtone(type: 'incoming' | 'outgoing') {
  try {
    ringtoneAudio = new Audio(type === 'incoming' ? '/static/sounds/inbound-call.mp3' : '/static/sounds/outbound-call.mp3');
    ringtoneAudio.loop = true;
    ringtoneAudio.volume = 0.5;
    ringtoneAudio.play().catch(() => {});
  } catch { /* noop */ }
}

function stopRingtone() {
  if (ringtoneAudio) {
    ringtoneAudio.pause();
    ringtoneAudio.currentTime = 0;
    ringtoneAudio = null;
  }
  if (ringtoneTimeout) {
    clearTimeout(ringtoneTimeout);
    ringtoneTimeout = null;
  }
}

// ============================================================================
// Duration Timer
// ============================================================================

function startDurationTimer() {
  callDuration.value = 0;
  durationTimer = setInterval(() => callDuration.value++, 1000);
}

// ============================================================================
// Cleanup & Close
// ============================================================================

function cleanup() {
  stopRingtone();

  if (localStream.value) {
    localStream.value.getTracks().forEach((t) => t.stop());
    localStream.value = null;
  }

  if (peerConnection) {
    peerConnection.close();
    peerConnection = null;
  }

  if (durationTimer) {
    clearInterval(durationTimer);
    durationTimer = null;
  }

  pendingIceCandidates = [];
}

function closeAfterDelay() {
  setTimeout(() => {
    window.close();
  }, 2000);
}

// ============================================================================
// Initialization
// ============================================================================

onMounted(async () => {
  console.log('[Call] Mounted, reading sessionStorage...');

  // Read call data from sessionStorage
  const raw = sessionStorage.getItem('callData');
  if (!raw) {
    error.value = 'No call data found. This window may have been opened incorrectly.';
    callState.value = 'error';
    return;
  }

  try {
    callData.value = JSON.parse(raw);
    sessionStorage.removeItem('callData');
  } catch {
    error.value = 'Invalid call data.';
    callState.value = 'error';
    return;
  }

  const data = callData.value!;
  console.log('[Call] Call data:', { callId: data.callId, direction: data.direction, type: data.callType, remote: data.remoteUser.name });

  // Set window title
  document.title = `Call — ${data.remoteUser.name}`;

  // Setup communication channels
  setupBroadcastChannel();
  setupEcho();

  // Acquire media
  const stream = await acquireMedia(data.callType);
  if (!stream) return;

  // Start the appropriate flow
  if (data.direction === 'outgoing') {
    await startOutgoingCall();
  } else {
    await startIncomingCall();
  }
});

onBeforeUnmount(() => {
  cleanup();
  if (echoChannel) {
    echoChannel.stopListening('.CallSignal');
    echoChannel.stopListening('.CallEnded');
  }
  stopEcho();
  broadcastChannel?.close();
});

// Handle window close
window.addEventListener('beforeunload', () => {
  if (callState.value !== 'ended') {
    endCall('hangup');
  }
});
</script>

<template>
  <div class="call-container">
    <!-- Background gradient -->
    <div class="call-bg" />

    <!-- Error State -->
    <div v-if="callState === 'error'" class="call-center-content">
      <div class="error-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10" />
          <line x1="15" y1="9" x2="9" y2="15" />
          <line x1="9" y1="9" x2="15" y2="15" />
        </svg>
      </div>
      <p class="error-text">{{ error }}</p>
      <button class="btn-close-window" @click="window.close()">Close Window</button>
    </div>

    <!-- Call Ended State -->
    <div v-else-if="callState === 'ended'" class="call-center-content">
      <div class="ended-icon">
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91" />
          <line x1="23" y1="1" x2="1" y2="23" />
        </svg>
      </div>
      <p class="ended-text">Call ended</p>
      <p class="ended-sub">{{ error || 'Closing window...' }}</p>
    </div>

    <!-- Active Call State -->
    <template v-else>
      <!-- Remote Video (full screen) -->
      <video
        v-if="remoteStream && isVideoCall"
        ref="remoteVideoRef"
        autoplay
        playsinline
        class="remote-video"
      />

      <!-- Audio-only: avatar display -->
      <div v-else class="call-center-content">
        <div class="avatar-container">
          <img
            v-if="callData?.remoteUser.avatar"
            :src="callData.remoteUser.avatar"
            :alt="callData?.remoteUser.name"
            class="avatar-img"
          />
          <div v-else class="avatar-fallback">
            {{ remoteInitials }}
          </div>
          <!-- Pulsing ring while connecting -->
          <div v-if="callState !== 'connected'" class="avatar-pulse" />
        </div>
        <p class="remote-name">{{ callData?.remoteUser.name }}</p>
        <p class="state-label">{{ stateLabel }}</p>
      </div>

      <!-- Hidden audio element for audio-only calls -->
      <audio ref="remoteAudioRef" autoplay />

      <!-- Local Video PiP -->
      <div
        v-if="localStream && isVideoCall && !isCameraOff"
        class="local-video-pip"
      >
        <video
          ref="localVideoRef"
          autoplay
          muted
          playsinline
          class="local-video"
        />
      </div>

      <!-- Top bar (video calls) -->
      <div v-if="isVideoCall && remoteStream" class="top-bar">
        <div class="top-bar-info">
          <span class="status-dot" :class="callState === 'connected' ? 'dot-green' : 'dot-amber'" />
          <span class="remote-name-small">{{ callData?.remoteUser.name }}</span>
        </div>
        <span class="duration-label">{{ stateLabel }}</span>
      </div>

      <!-- Controls bar -->
      <div class="controls-bar">
        <!-- Mute -->
        <button
          class="ctrl-btn"
          :class="{ active: isMuted }"
          :title="isMuted ? 'Unmute' : 'Mute'"
          @click="toggleMute"
        >
          <!-- Mic / MicOff -->
          <svg v-if="!isMuted" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
            <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
            <line x1="12" y1="19" x2="12" y2="22" />
          </svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="2" y1="2" x2="22" y2="22" />
            <path d="M18.89 13.23A7.12 7.12 0 0 0 19 12v-2" />
            <path d="M5 10v2a7 7 0 0 0 12 5.29" />
            <path d="M15 9.34V5a3 3 0 0 0-5.68-1.33" />
            <path d="M9 9v3a3 3 0 0 0 5.12 2.12" />
            <line x1="12" y1="19" x2="12" y2="22" />
          </svg>
        </button>

        <!-- Camera (video calls only) -->
        <button
          v-if="callData?.callType === 'video' && !videoFallback"
          class="ctrl-btn"
          :class="{ active: isCameraOff }"
          :title="isCameraOff ? 'Turn Camera On' : 'Turn Camera Off'"
          @click="toggleCamera"
        >
          <svg v-if="!isCameraOff" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5" />
            <rect x="2" y="6" width="14" height="12" rx="2" />
          </svg>
          <svg v-else xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.66 6H14a2 2 0 0 1 2 2v2.5l5.248-3.062A.5.5 0 0 1 22 7.87v8.196" />
            <path d="M16 16a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2" />
            <line x1="2" y1="2" x2="22" y2="22" />
          </svg>
        </button>

        <!-- End Call -->
        <button
          class="ctrl-btn end-call"
          title="End Call"
          @click="endCall('hangup')"
        >
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91" />
            <line x1="23" y1="1" x2="1" y2="23" />
          </svg>
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.call-container {
  position: relative;
  width: 100dvw;
  height: 100dvh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.call-bg {
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at 50% 0%, rgba(59, 130, 246, 0.08) 0%, transparent 60%),
    radial-gradient(ellipse at 80% 100%, rgba(124, 58, 237, 0.06) 0%, transparent 50%),
    #0a0a0f;
  z-index: 0;
}

/* ── Center content (avatar / error / ended) ── */
.call-center-content {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

.avatar-container {
  position: relative;
  width: 120px;
  height: 120px;
}

.avatar-img {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid rgba(255, 255, 255, 0.15);
}

.avatar-fallback {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40px;
  font-weight: 600;
  color: white;
  border: 3px solid rgba(255, 255, 255, 0.15);
}

.avatar-pulse {
  position: absolute;
  inset: -4px;
  border-radius: 50%;
  border: 2px solid rgba(59, 130, 246, 0.4);
  animation: pulse-ring 2s ease-out infinite;
}

@keyframes pulse-ring {
  0% { transform: scale(1); opacity: 1; }
  100% { transform: scale(1.3); opacity: 0; }
}

.remote-name {
  font-size: 24px;
  font-weight: 600;
  color: white;
  margin: 0;
}

.state-label {
  font-size: 14px;
  color: rgba(255, 255, 255, 0.5);
  margin: 0;
  animation: fade-pulse 2s ease-in-out infinite;
}

@keyframes fade-pulse {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}

/* ── Remote Video ── */
.remote-video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  z-index: 0;
}

/* ── Local Video PiP ── */
.local-video-pip {
  position: absolute;
  bottom: 100px;
  right: 24px;
  width: 200px;
  aspect-ratio: 16/9;
  border-radius: 12px;
  overflow: hidden;
  border: 2px solid rgba(255, 255, 255, 0.15);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
  z-index: 10;
}

.local-video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transform: scaleX(-1);
}

/* ── Top bar ── */
.top-bar {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 24px;
  background: linear-gradient(to bottom, rgba(0, 0, 0, 0.6), transparent);
  z-index: 10;
}

.top-bar-info {
  display: flex;
  align-items: center;
  gap: 8px;
}

.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.dot-green { background: #22c55e; box-shadow: 0 0 8px rgba(34, 197, 94, 0.5); }
.dot-amber { background: #f59e0b; box-shadow: 0 0 8px rgba(245, 158, 11, 0.5); }

.remote-name-small {
  font-size: 14px;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.9);
}

.duration-label {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.6);
  font-variant-numeric: tabular-nums;
}

/* ── Controls bar ── */
.controls-bar {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 24px;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.7), transparent);
  z-index: 10;
}

.ctrl-btn {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.12);
  color: white;
  transition: all 0.15s ease;
  backdrop-filter: blur(8px);
}

.ctrl-btn:hover {
  background: rgba(255, 255, 255, 0.2);
  transform: scale(1.05);
}

.ctrl-btn.active {
  background: #ef4444;
  color: white;
}

.ctrl-btn.end-call {
  background: #dc2626;
  width: 60px;
  height: 60px;
}

.ctrl-btn.end-call:hover {
  background: #b91c1c;
}

/* ── Error & Ended states ── */
.error-icon, .ended-icon {
  color: rgba(255, 255, 255, 0.4);
  margin-bottom: 8px;
}

.error-text, .ended-text {
  font-size: 18px;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.8);
  margin: 0;
}

.ended-sub {
  font-size: 13px;
  color: rgba(255, 255, 255, 0.4);
  margin: 0;
}

.btn-close-window {
  margin-top: 12px;
  padding: 8px 20px;
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.15);
  background: rgba(255, 255, 255, 0.08);
  color: white;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-close-window:hover {
  background: rgba(255, 255, 255, 0.15);
}
</style>
