/**
 * useVideoCall — Parent-side composable for the popup call architecture.
 *
 * This composable NO LONGER manages WebRTC directly. Instead it:
 * 1. Receives incoming call events (from Echo via CustomEvent)
 * 2. Opens the standalone call page in a popup window
 * 3. Passes call data via sessionStorage
 * 4. Listens to BroadcastChannel for state updates from the popup
 *
 * All WebRTC logic lives in CallApp.vue (the popup).
 */
import { ref, onBeforeUnmount } from 'vue';
import { useVideoCallStore, type CallType } from '@/stores/videocall';
import { videoCallService } from '@/services/videocall.service';
import { useAuthStore } from '@/stores/auth';
import { toast } from 'vue-sonner';

// Singleton state
let initialized = false;
let callPopup: Window | null = null;
let broadcastChannel: BroadcastChannel | null = null;
let ringtoneAudio: HTMLAudioElement | null = null;
let ringtoneTimeout: ReturnType<typeof setTimeout> | null = null;

// Pending offer + ICE candidates for incoming calls (received before user accepts)
const pendingOffer = ref<RTCSessionDescriptionInit | null>(null);
const pendingCandidates = ref<RTCIceCandidateInit[]>([]);

export function useVideoCall() {
  const store = useVideoCallStore();
  const authStore = useAuthStore();

  // ============================================================================
  // Popup Window Management
  // ============================================================================

  function openCallPopup(callId: string) {
    const width = 480;
    const height = 640;
    const left = window.screenX + window.outerWidth - width - 24;
    const top = window.screenY + 80;

    callPopup = window.open(
      `/call/${callId}`,
      `worksphere-call-${callId}`,
      `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no,toolbar=no,menubar=no,location=no,status=no`,
    );

    if (!callPopup) {
      toast.error('Popup Blocked', {
        description: 'Please allow popups for this site to make calls.',
      });
      cleanup();
      return;
    }

    // Monitor popup close
    const checkInterval = setInterval(() => {
      if (callPopup?.closed) {
        clearInterval(checkInterval);
        handlePopupClosed();
      }
    }, 1000);
  }

  function handlePopupClosed() {
    console.log('[VideoCall] Popup was closed');
    callPopup = null;
    cleanup();
  }

  // ============================================================================
  // BroadcastChannel (receive state from popup)
  // ============================================================================

  function ensureBroadcastChannel() {
    if (broadcastChannel) return;
    broadcastChannel = new BroadcastChannel('worksphere-call');
    broadcastChannel.onmessage = (event) => {
      const msg = event.data;
      if (!msg) return;

      console.log('[VideoCall] BroadcastChannel message:', msg);

      switch (msg.type) {
        case 'state':
          if (msg.state === 'connected') {
            store.setState('connected');
            stopRingtone();
          } else if (msg.state === 'ended') {
            if (msg.reason === 'declined') {
              toast.info(`${store.currentCall?.remoteUser.name || 'User'} declined the call`);
            } else if (msg.reason === 'timeout') {
              toast.info('Call was not answered');
            } else {
              toast.info('Call ended');
            }
            cleanup();
          }
          break;
      }
    };
  }

  // ============================================================================
  // Outgoing Call
  // ============================================================================

  async function startCall(chatId: string, callType: CallType, remoteUser: { publicId: string; name: string; avatar: string | null }) {
    console.log('[VideoCall] startCall:', { chatId, callType, remoteUser: remoteUser.name });

    if (store.isCallActive) {
      toast.warning('You are already in a call');
      return;
    }

    store.setState('initiating');

    try {
      // Tell server to notify the other user
      const { call_id } = await videoCallService.initiateCall(chatId, callType);
      console.log('[VideoCall] Call initiated, callId:', call_id);

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

      // Ring timeout
      ringtoneTimeout = setTimeout(() => {
        if (store.callState === 'ringing') {
          endCall('timeout');
        }
      }, 45000);

      // Store call data for the popup to read
      sessionStorage.setItem('callData', JSON.stringify({
        callId: call_id,
        chatId,
        callType,
        direction: 'outgoing',
        remoteUser,
        selfPublicId: authStore.user?.public_id,
      }));

      ensureBroadcastChannel();
      openCallPopup(call_id);

    } catch (err) {
      console.error('[VideoCall] Failed to start call:', err);
      toast.error('Failed to start call');
      cleanup();
    }
  }

  // ============================================================================
  // Incoming Call Handling
  // ============================================================================

  function handleIncomingCall(data: {
    call_id: string;
    call_type: CallType;
    caller_public_id: string;
    caller_name: string;
    caller_avatar: string | null;
    chat_id: string;
  }) {
    console.log('[VideoCall] handleIncomingCall:', data);

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

  function handleSignal(data: {
    call_id: string;
    signal_type: 'offer' | 'answer' | 'ice-candidate';
    signal_data: any;
    sender_public_id: string;
  }) {
    // Ignore our own signals
    if (data.sender_public_id === authStore.user?.public_id) return;
    if (!store.currentCall || store.currentCall.callId !== data.call_id) return;

    // If the popup is open, it handles signals via its own Echo subscription.
    // But we still need to buffer offer + ICE candidates that arrive BEFORE
    // the user clicks Accept (i.e., before the popup opens).
    if (callPopup && !callPopup.closed) return; // popup handles it

    const { signal_type, signal_data } = data;

    switch (signal_type) {
      case 'offer':
        console.log('[VideoCall] Saving pending offer (user has not accepted yet)');
        pendingOffer.value = signal_data;
        break;
      case 'ice-candidate':
        console.log('[VideoCall] Queuing ICE candidate (popup not open yet)');
        pendingCandidates.value.push(signal_data);
        break;
      case 'answer':
        // Rare: answer arrives before popup — ignore (popup will handle renegotiation)
        break;
    }
  }

  async function acceptCall() {
    if (!store.currentCall) return;
    const { callId, chatId, callType, remoteUser } = store.currentCall;

    stopRingtone();
    store.setState('connecting');

    // Store call data for the popup, INCLUDING the pending offer and candidates
    sessionStorage.setItem('callData', JSON.stringify({
      callId,
      chatId,
      callType,
      direction: 'incoming',
      remoteUser,
      pendingOffer: pendingOffer.value,
      pendingCandidates: pendingCandidates.value,
      selfPublicId: authStore.user?.public_id,
    }));

    // Clear pending data
    pendingOffer.value = null;
    pendingCandidates.value = [];

    ensureBroadcastChannel();
    openCallPopup(callId);
  }

  function declineCall() {
    if (!store.currentCall) return;
    videoCallService.endCall(store.currentCall.chatId, store.currentCall.callId, 'declined').catch(() => {});
    cleanup();
  }

  function handleCallEnded(data: { call_id: string; ender_public_id: string; reason: string }) {
    if (data.ender_public_id === authStore.user?.public_id) return;
    if (!store.currentCall || store.currentCall.callId !== data.call_id) return;

    // If popup is open, it handles this via its own Echo subscription
    if (callPopup && !callPopup.closed) return;

    switch (data.reason) {
      case 'declined':
        toast.info(`${store.currentCall.remoteUser.name} declined the call`);
        break;
      case 'timeout':
        toast.info('Call was not answered');
        break;
      default:
        toast.info('Call ended');
    }
    cleanup();
  }

  // ============================================================================
  // Call Controls (from parent side)
  // ============================================================================

  async function endCall(reason: 'hangup' | 'declined' | 'timeout' | 'failed' = 'hangup') {
    if (store.currentCall) {
      videoCallService.endCall(store.currentCall.chatId, store.currentCall.callId, reason).catch(() => {});
    }

    // Tell popup to close
    broadcastChannel?.postMessage({ type: 'end-call' });

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
  // Cleanup
  // ============================================================================

  function cleanup() {
    stopRingtone();
    pendingOffer.value = null;
    pendingCandidates.value = [];
    callPopup = null;
    store.reset();
  }

  // ============================================================================
  // Global Event Listener Setup (called once from AppLayout)
  // ============================================================================

  function setupGlobalListeners() {
    if (initialized) return;
    initialized = true;
    console.log('[VideoCall] Global listeners initialized (popup architecture)');

    window.addEventListener('videocall:incoming', (e: Event) => {
      handleIncomingCall((e as CustomEvent).detail);
    });
    window.addEventListener('videocall:signal', (e: Event) => {
      handleSignal((e as CustomEvent).detail);
    });
    window.addEventListener('videocall:ended', (e: Event) => {
      handleCallEnded((e as CustomEvent).detail);
    });

    ensureBroadcastChannel();
  }

  return {
    startCall,
    acceptCall,
    declineCall,
    endCall,
    handleIncomingCall,
    handleSignal,
    handleCallEnded: handleCallEnded,
    cleanup,
    setupGlobalListeners,
  };
}
