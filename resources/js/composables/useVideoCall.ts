import { ref } from 'vue';
import { useVideoCallStore, type CallType } from '@/stores/videocall';
import { videoCallService } from '@/services/videocall.service';
import { useAuthStore } from '@/stores/auth';
import { toast } from 'vue-sonner';

/**
 * Core WebRTC composable encapsulating the full lifecycle of a peer-to-peer call.
 * 
 * This is a SINGLETON — all components share the same WebRTC connection and state.
 * The module-level variables ensure only one peer connection exists at a time.
 */

let peerConnection: RTCPeerConnection | null = null;
let ringtoneAudio: HTMLAudioElement | null = null;
let ringtoneTimeout: ReturnType<typeof setTimeout> | null = null;
const pendingCandidates = ref<RTCIceCandidateInit[]>([]);
let initialized = false;

export function useVideoCall() {
  const store = useVideoCallStore();
  const authStore = useAuthStore();

  // ============================================================================
  // Media
  // ============================================================================

  async function acquireMedia(callType: CallType): Promise<MediaStream | null> {
    try {
      const constraints: MediaStreamConstraints = {
        audio: true,
        video: callType === 'video' ? { width: { ideal: 1280 }, height: { ideal: 720 } } : false,
      };
      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      store.setLocalStream(stream);
      return stream;
    } catch (err: any) {
      console.error('[VideoCall] Failed to acquire media:', err);
      const msg =
        err.name === 'NotAllowedError'
          ? 'Camera/microphone permission denied. Please allow access in your browser settings.'
          : 'Could not access camera or microphone.';
      store.setError(msg);
      toast.error('Media Access Failed', { description: msg });
      return null;
    }
  }

  function stopMedia() {
    if (store.localStream) {
      store.localStream.getTracks().forEach((t) => t.stop());
      store.setLocalStream(null);
    }
  }

  // ============================================================================
  // Peer Connection
  // ============================================================================

  async function createPeerConnection(chatId: string, callId: string): Promise<RTCPeerConnection> {
    // Fetch ICE servers (STUN + optional TURN)
    let iceServers: RTCIceServer[] = [{ urls: 'stun:stun.cloudflare.com:3478' }];

    try {
      const creds = await videoCallService.getTurnCredentials(chatId);
      iceServers = creds.ice_servers;
    } catch (err) {
      console.warn('[VideoCall] Using fallback STUN-only config:', err);
    }

    const pc = new RTCPeerConnection({ iceServers });

    // Add local tracks to the connection
    if (store.localStream) {
      store.localStream.getTracks().forEach((track) => {
        pc.addTrack(track, store.localStream!);
      });
    }

    // Handle incoming remote tracks
    pc.ontrack = (event) => {
      console.log('[VideoCall] Remote track received:', event.track.kind);
      if (event.streams[0]) {
        store.setRemoteStream(event.streams[0]);
      }
    };

    // Trickle ICE candidates to the remote peer
    pc.onicecandidate = (event) => {
      if (event.candidate) {
        videoCallService.sendSignal(chatId, callId, 'ice-candidate', {
          candidate: event.candidate.candidate,
          sdpMid: event.candidate.sdpMid,
          sdpMLineIndex: event.candidate.sdpMLineIndex,
        }).catch((err) => console.warn('[VideoCall] Failed to send ICE candidate:', err));
      }
    };

    // Connection state tracking
    pc.onconnectionstatechange = () => {
      console.log('[VideoCall] Connection state:', pc.connectionState);
      switch (pc.connectionState) {
        case 'connected':
          store.setState('connected');
          stopRingtone();
          break;
        case 'disconnected':
        case 'failed':
          handleCallFailed(chatId, callId);
          break;
        case 'closed':
          break;
      }
    };

    pc.oniceconnectionstatechange = () => {
      console.log('[VideoCall] ICE state:', pc.iceConnectionState);
    };

    peerConnection = pc;
    return pc;
  }

  // ============================================================================
  // Outgoing Call Flow
  // ============================================================================

  async function startCall(chatId: string, callType: CallType, remoteUser: { publicId: string; name: string; avatar: string | null }) {
    if (store.isCallActive) {
      toast.warning('You are already in a call');
      return;
    }

    store.setState('initiating');

    // Acquire media first
    const stream = await acquireMedia(callType);
    if (!stream) {
      store.reset();
      return;
    }

    try {
      // Tell server to notify the other user
      const { call_id } = await videoCallService.initiateCall(chatId, callType);

      store.setCall({
        callId: call_id,
        chatId,
        callType,
        remoteUser,
        isOutgoing: true,
        startedAt: null,
      });

      store.setState('ringing');
      playRingtone('outgoing');

      // Set a 45-second ring timeout
      ringtoneTimeout = setTimeout(() => {
        if (store.callState === 'ringing') {
          endCall('timeout');
        }
      }, 45000);

      // Create peer connection and generate offer
      const pc = await createPeerConnection(chatId, call_id);
      const offer = await pc.createOffer();
      await pc.setLocalDescription(offer);

      // Send offer via signaling
      await videoCallService.sendSignal(chatId, call_id, 'offer', {
        type: offer.type,
        sdp: offer.sdp,
      });

    } catch (err: any) {
      console.error('[VideoCall] Failed to start call:', err);
      toast.error('Failed to start call');
      cleanup();
    }
  }

  // ============================================================================
  // Incoming Call Flow
  // ============================================================================

  function handleIncomingCall(data: {
    call_id: string;
    call_type: CallType;
    caller_public_id: string;
    caller_name: string;
    caller_avatar: string | null;
    chat_id: string;
  }) {
    // Ignore our own events
    if (data.caller_public_id === authStore.user?.public_id) return;

    // If already in a call, auto-decline
    if (store.isCallActive) {
      videoCallService.endCall(data.chat_id, data.call_id, 'declined').catch(() => {});
      return;
    }

    store.setCall({
      callId: data.call_id,
      chatId: data.chat_id,
      callType: data.call_type,
      remoteUser: {
        publicId: data.caller_public_id,
        name: data.caller_name,
        avatar: data.caller_avatar,
      },
      isOutgoing: false,
      startedAt: null,
    });

    store.setState('ringing');
    playRingtone('incoming');

    // Auto-decline after 45 seconds
    ringtoneTimeout = setTimeout(() => {
      if (store.callState === 'ringing' && !store.currentCall?.isOutgoing) {
        declineCall();
      }
    }, 45000);
  }

  async function acceptCall() {
    if (!store.currentCall) return;

    const { chatId, callId, callType } = store.currentCall;

    stopRingtone();
    store.setState('connecting');

    const stream = await acquireMedia(callType);
    if (!stream) {
      endCall('failed');
      return;
    }

    try {
      await createPeerConnection(chatId, callId);

      // Flush pending ICE candidates that arrived before PC was created
      for (const candidate of pendingCandidates.value) {
        await peerConnection!.addIceCandidate(new RTCIceCandidate(candidate));
      }
      pendingCandidates.value = [];

    } catch (err) {
      console.error('[VideoCall] Failed to accept call:', err);
      endCall('failed');
    }
  }

  function declineCall() {
    if (!store.currentCall) return;
    videoCallService.endCall(store.currentCall.chatId, store.currentCall.callId, 'declined').catch(() => {});
    cleanup();
  }

  // ============================================================================
  // Signal Handling (from broadcast events)
  // ============================================================================

  async function handleSignal(data: {
    call_id: string;
    signal_type: 'offer' | 'answer' | 'ice-candidate';
    signal_data: any;
    sender_public_id: string;
  }) {
    // Ignore our own signals
    if (data.sender_public_id === authStore.user?.public_id) return;

    // Only process signals for the current call
    if (!store.currentCall || store.currentCall.callId !== data.call_id) return;

    const { signal_type, signal_data } = data;

    switch (signal_type) {
      case 'offer':
        if (peerConnection) {
          await peerConnection.setRemoteDescription(new RTCSessionDescription(signal_data));
          const answer = await peerConnection.createAnswer();
          await peerConnection.setLocalDescription(answer);

          await videoCallService.sendSignal(
            store.currentCall.chatId,
            store.currentCall.callId,
            'answer',
            { type: answer.type, sdp: answer.sdp },
          );
        }
        break;

      case 'answer':
        if (peerConnection) {
          await peerConnection.setRemoteDescription(new RTCSessionDescription(signal_data));
          store.setState('connecting');
        }
        break;

      case 'ice-candidate':
        if (peerConnection && peerConnection.remoteDescription) {
          await peerConnection.addIceCandidate(new RTCIceCandidate(signal_data));
        } else {
          // Queue candidates until remote description is set
          pendingCandidates.value.push(signal_data);
        }
        break;
    }
  }

  function handleCallEnded(data: { call_id: string; ender_public_id: string; reason: string }) {
    if (data.ender_public_id === authStore.user?.public_id) return;
    if (!store.currentCall || store.currentCall.callId !== data.call_id) return;

    switch (data.reason) {
      case 'declined':
        toast.info(`${store.currentCall.remoteUser.name} declined the call`);
        break;
      case 'timeout':
        toast.info('Call was not answered');
        break;
      case 'hangup':
        toast.info('Call ended');
        break;
      default:
        toast.info('Call ended');
    }

    cleanup();
  }

  // ============================================================================
  // Call Control
  // ============================================================================

  async function endCall(reason: 'hangup' | 'declined' | 'timeout' | 'failed' = 'hangup') {
    if (store.currentCall) {
      videoCallService.endCall(store.currentCall.chatId, store.currentCall.callId, reason).catch(() => {});
    }
    cleanup();
  }

  function handleCallFailed(chatId: string, callId: string) {
    toast.error('Call connection lost');
    videoCallService.endCall(chatId, callId, 'failed').catch(() => {});
    cleanup();
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
    } catch (e) {
      // Audio not available
    }
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
  // Cleanup
  // ============================================================================

  function cleanup() {
    stopRingtone();
    stopMedia();

    if (peerConnection) {
      peerConnection.close();
      peerConnection = null;
    }

    pendingCandidates.value = [];
    store.reset();
  }

  // ============================================================================
  // Global Event Listener Setup (call once from AppLayout)
  // ============================================================================

  function setupGlobalListeners() {
    if (initialized) return;
    initialized = true;

    function onIncomingCall(e: Event) {
      handleIncomingCall((e as CustomEvent).detail);
    }
    function onCallSignal(e: Event) {
      handleSignal((e as CustomEvent).detail);
    }
    function onCallEnded(e: Event) {
      handleCallEnded((e as CustomEvent).detail);
    }

    window.addEventListener('videocall:incoming', onIncomingCall);
    window.addEventListener('videocall:signal', onCallSignal);
    window.addEventListener('videocall:ended', onCallEnded);
  }

  return {
    // Actions
    startCall,
    acceptCall,
    declineCall,
    endCall,
    handleIncomingCall,
    handleSignal,
    handleCallEnded,
    cleanup,
    setupGlobalListeners,
  };
}
