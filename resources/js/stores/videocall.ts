import { defineStore } from 'pinia';
import { ref, computed } from 'vue';

export type CallState = 'idle' | 'initiating' | 'ringing' | 'connecting' | 'connected' | 'ended';
export type CallType = 'video' | 'audio';

export interface CallInfo {
  callId: string;
  chatId: string;
  callType: CallType;
  remoteUser: {
    publicId: string;
    name: string;
    avatar: string | null;
  };
  isOutgoing: boolean;
  startedAt: number | null;
}

export const useVideoCallStore = defineStore('videoCall', () => {
  // ============================================================================
  // State
  // ============================================================================
  const callState = ref<CallState>('idle');
  const currentCall = ref<CallInfo | null>(null);
  const localStream = ref<MediaStream | null>(null);
  const remoteStream = ref<MediaStream | null>(null);
  const isMuted = ref(false);
  const isCameraOff = ref(false);
  const callDuration = ref(0);
  const error = ref<string | null>(null);

  let durationTimer: ReturnType<typeof setInterval> | null = null;

  // ============================================================================
  // Getters
  // ============================================================================
  const isCallActive = computed(() =>
    ['initiating', 'ringing', 'connecting', 'connected'].includes(callState.value),
  );

  const isRinging = computed(() => callState.value === 'ringing');
  const isConnected = computed(() => callState.value === 'connected');
  const isIncoming = computed(() => currentCall.value !== null && !currentCall.value.isOutgoing && callState.value === 'ringing');

  const formattedDuration = computed(() => {
    const mins = Math.floor(callDuration.value / 60);
    const secs = callDuration.value % 60;
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  });

  // ============================================================================
  // Actions
  // ============================================================================

  function setCall(info: CallInfo) {
    currentCall.value = info;
  }

  function setState(state: CallState) {
    callState.value = state;

    if (state === 'connected') {
      currentCall.value!.startedAt = Date.now();
      startDurationTimer();
    }

    if (state === 'ended' || state === 'idle') {
      stopDurationTimer();
    }
  }

  function setLocalStream(stream: MediaStream | null) {
    localStream.value = stream;
  }

  function setRemoteStream(stream: MediaStream | null) {
    remoteStream.value = stream;
  }

  function toggleMute() {
    isMuted.value = !isMuted.value;
    if (localStream.value) {
      localStream.value.getAudioTracks().forEach((track) => {
        track.enabled = !isMuted.value;
      });
    }
  }

  function toggleCamera() {
    isCameraOff.value = !isCameraOff.value;
    if (localStream.value) {
      localStream.value.getVideoTracks().forEach((track) => {
        track.enabled = !isCameraOff.value;
      });
    }
  }

  function setError(msg: string | null) {
    error.value = msg;
  }

  function startDurationTimer() {
    callDuration.value = 0;
    durationTimer = setInterval(() => {
      callDuration.value++;
    }, 1000);
  }

  function stopDurationTimer() {
    if (durationTimer) {
      clearInterval(durationTimer);
      durationTimer = null;
    }
  }

  function reset() {
    // Stop media tracks
    if (localStream.value) {
      localStream.value.getTracks().forEach((t) => t.stop());
    }

    callState.value = 'idle';
    currentCall.value = null;
    localStream.value = null;
    remoteStream.value = null;
    isMuted.value = false;
    isCameraOff.value = false;
    callDuration.value = 0;
    error.value = null;
    stopDurationTimer();
  }

  return {
    // State
    callState,
    currentCall,
    localStream,
    remoteStream,
    isMuted,
    isCameraOff,
    callDuration,
    error,
    // Getters
    isCallActive,
    isRinging,
    isConnected,
    isIncoming,
    formattedDuration,
    // Actions
    setCall,
    setState,
    setLocalStream,
    setRemoteStream,
    toggleMute,
    toggleCamera,
    setError,
    reset,
  };
});
