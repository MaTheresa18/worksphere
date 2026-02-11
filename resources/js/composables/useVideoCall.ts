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

// Pending signals (offer + ICE candidates) for incoming calls (received before user accepts)
const pendingSignals = ref<any[]>([]);

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

    console.log('[VideoCall] Opening popup for call:', callId);
    console.log('[VideoCall] Popup dimensions:', { width, height, left, top });

    callPopup = window.open(
      `/call/${callId}`,
      `worksphere-call-${callId}`,
      `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=no,toolbar=no,menubar=no,location=no,status=no`,
    );

    if (!callPopup) {
      console.error('[VideoCall] ❌ Popup blocked by browser');
      toast.error('Popup Blocked', {
        description: 'Please allow popups for this site to make calls.',
      });
      cleanup();
      return;
    }

    console.log('[VideoCall] Popup opened successfully');

    // Monitor popup close
    const checkInterval = setInterval(() => {
      if (callPopup?.closed) {
        console.log('[VideoCall] Popup window closed detected via interval');
        clearInterval(checkInterval);
        handlePopupClosed();
      }
    }, 1000);
  }

  function handlePopupClosed() {
    console.log('[VideoCall] handlePopupClosed: cleaning up state');
    callPopup = null;
    cleanup();
  }

  // ============================================================================
  // BroadcastChannel (receive state from popup)
  // ============================================================================

  function ensureBroadcastChannel() {
    if (broadcastChannel) return;
    console.log('[VideoCall] Initializing BroadcastChannel "worksphere-call"');
    broadcastChannel = new BroadcastChannel('worksphere-call');
    broadcastChannel.onmessage = (event) => {
      const msg = event.data;
      if (!msg) return;

      console.log('[VideoCall] 📥 Broadcast message received:', msg);

      switch (msg.type) {
        case 'state':
          console.log('[VideoCall] Syncing state from popup:', msg.state);
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
    console.log('[VideoCall] startCall initiated:', { chatId, callType, remoteUser: remoteUser.name });

    if (store.isCallActive) {
      console.warn('[VideoCall] Blocked: call already active');
      toast.warning('You are already in a call');
      return;
    }

    store.setState('initiating');

    try {
      console.log('[VideoCall] Requesting call initiation from API...');
      const { call_id } = await videoCallService.initiateCall(chatId, callType);
      console.log('[VideoCall] API response: call_id =', call_id);

      store.setCall({
        callId: call_id,
        chatId,
        callType,
        remoteUser,
        isOutgoing: true,
        startedAt: null,
      });

      store.setState('ringing');
      console.log('[VideoCall] Playing outgoing ringtone');
      playRingtone('outgoing');

      // Ring timeout
      ringtoneTimeout = setTimeout(() => {
        if (store.callState === 'ringing') {
          console.log('[VideoCall] Outgoing call timeout (45s)');
          endCall('timeout');
        }
      }, 45000);

      // Store call data for the popup to read
      const dataToStore = {
        callId: call_id,
        chatId,
        callType,
        direction: 'outgoing',
        remoteUser,
        selfPublicId: authStore.user?.public_id,
      };
      console.log('[VideoCall] Storing outgoing callData in sessionStorage');
      sessionStorage.setItem('callData', JSON.stringify(dataToStore));

      ensureBroadcastChannel();
      openCallPopup(call_id);

    } catch (err) {
      console.error('[VideoCall] ❌ Failed to start call:', err);
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
    console.log('[VideoCall] 📞 handleIncomingCall:', data);

    // Ignore our own events
    if (data.caller_public_id === authStore.user?.public_id) {
        console.log('[VideoCall] Ignoring own CallInitiated event');
        return;
    }

    // If already in a call, auto-decline
    if (store.isCallActive) {
      console.warn('[VideoCall] Already in call, auto-declining incoming call');
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
    console.log('[VideoCall] Playing incoming ringtone');
    playRingtone('incoming');

    // Auto-decline after 45 seconds
    ringtoneTimeout = setTimeout(() => {
      if (store.callState === 'ringing' && !store.currentCall?.isOutgoing) {
        console.log('[VideoCall] Incoming call auto-decline (45s timeout)');
        declineCall();
      }
    }, 45000);
  }

  function handleSignal(data: {
    call_id: string;
    signal_type: 'offer' | 'answer' | 'ice-candidate' | 'signal';
    signal_data: any;
    sender_public_id: string;
  }) {
    // Ignore our own signals
    if (data.sender_public_id === authStore.user?.public_id) return;
    
    // Check if the signal is for the current call
    if (!store.currentCall || store.currentCall.callId !== data.call_id) {
        console.log('[VideoCall] Received signal for non-active call:', data.call_id);
        return;
    }

    console.log(`[VideoCall] 📡 Received signal: ${data.signal_type} for call ${data.call_id}`);

    // If the popup is open, it handles signals via its own Echo subscription.
    // But we still need to buffer signals that arrive BEFORE
    // the user clicks Accept (i.e., before the popup opens).
    if (callPopup && !callPopup.closed) {
        console.log('[VideoCall] Popup is active, delegating signal handling to popup');
        return; 
    }

    console.log(`[VideoCall] Buffering signal: ${data.signal_type}`);
    pendingSignals.value.push(data.signal_data);
  }

  async function acceptCall() {
    if (!store.currentCall) {
        console.error('[VideoCall] acceptCall: no currentCall in store!');
        return;
    }
    const { callId, chatId, callType, remoteUser } = store.currentCall;
    console.log('[VideoCall] User accepted call:', callId);

    stopRingtone();
    store.setState('connecting');

    // Store call data for the popup, INCLUDING the pending signals
    const dataToStore = {
      callId,
      chatId,
      callType,
      direction: 'incoming',
      remoteUser,
      pendingSignals: pendingSignals.value,
      selfPublicId: authStore.user?.public_id,
    };
    
    console.log('[VideoCall] Storing incoming callData in sessionStorage', {
        signalCount: pendingSignals.value.length
    });
    sessionStorage.setItem('callData', JSON.stringify(dataToStore));

    // Clear pending data
    pendingSignals.value = [];

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
    pendingSignals.value = [];
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
