<script setup lang="ts">
import { ref, watch, nextTick, computed } from 'vue';
import { useVideoCallStore } from '@/stores/videocall';
import { useVideoCall } from '@/composables/useVideoCall';
import { Icon, Avatar } from '@/components/ui';
import { useAvatar } from '@/composables/useAvatar';

const store = useVideoCallStore();
const { endCall } = useVideoCall();  // singleton — shares same peer connection
const avatar = useAvatar();

const localVideoRef = ref<HTMLVideoElement | null>(null);
const remoteVideoRef = ref<HTMLVideoElement | null>(null);

const remoteAvatarData = computed(() => {
  if (!store.currentCall) return { url: '', initials: '?' };
  return avatar.resolveInitials(store.currentCall.remoteUser.name);
});

// Bind local stream to video element
watch(
  () => store.localStream,
  async (stream) => {
    await nextTick();
    if (localVideoRef.value && stream) {
      localVideoRef.value.srcObject = stream;
    }
  },
);

// Bind remote stream to video element
watch(
  () => store.remoteStream,
  async (stream) => {
    await nextTick();
    if (remoteVideoRef.value && stream) {
      remoteVideoRef.value.srcObject = stream;
    }
  },
);

const stateLabel = computed(() => {
  switch (store.callState) {
    case 'initiating':
      return 'Starting call...';
    case 'ringing':
      return store.currentCall?.isOutgoing ? 'Ringing...' : 'Incoming call...';
    case 'connecting':
      return 'Connecting...';
    case 'connected':
      return store.formattedDuration;
    default:
      return '';
  }
});

const isVideoCall = computed(() => store.currentCall?.callType === 'video');

function handleEndCall() {
  endCall('hangup');
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="store.isCallActive"
      class="fixed inset-0 z-9999 flex items-center justify-center bg-black/90 backdrop-blur-sm"
    >
      <div class="relative w-full h-full flex flex-col">
        <!-- Remote Video / Avatar -->
        <div class="flex-1 relative flex items-center justify-center overflow-hidden">
          <!-- Remote video stream -->
          <video
            v-if="store.remoteStream && isVideoCall"
            ref="remoteVideoRef"
            autoplay
            playsinline
            class="w-full h-full object-cover"
          />

          <!-- Placeholder when no video -->
          <div
            v-else
            class="flex flex-col items-center gap-6"
          >
            <div class="w-32 h-32 rounded-full bg-white/10 flex items-center justify-center text-5xl font-semibold text-white ring-4 ring-white/20 shadow-2xl">
              <Avatar
                v-if="store.currentCall?.remoteUser.avatar"
                :src="store.currentCall.remoteUser.avatar"
                :alt="store.currentCall?.remoteUser.name || ''"
                :fallback="remoteAvatarData.initials"
                size="xl"
                class="rounded-full w-32 h-32"
              />
              <span v-else>{{ remoteAvatarData.initials }}</span>
            </div>
            <div class="text-center">
              <h2 class="text-2xl font-semibold text-white">
                {{ store.currentCall?.remoteUser.name }}
              </h2>
              <p class="text-white/60 text-sm mt-1 animate-pulse">
                {{ stateLabel }}
              </p>
            </div>
          </div>

          <!-- Connected state label overlay -->
          <div
            v-if="store.isConnected && isVideoCall"
            class="absolute top-6 left-1/2 -translate-x-1/2 px-4 py-1.5 bg-black/50 backdrop-blur rounded-full text-white text-sm font-medium"
          >
            {{ store.currentCall?.remoteUser.name }} · {{ stateLabel }}
          </div>
        </div>

        <!-- Local video (picture-in-picture) -->
        <div
          v-if="store.localStream && isVideoCall && !store.isCameraOff"
          class="absolute bottom-28 right-6 w-40 h-28 lg:w-52 lg:h-36 rounded-2xl overflow-hidden shadow-2xl ring-2 ring-white/20"
        >
          <video
            ref="localVideoRef"
            autoplay
            muted
            playsinline
            class="w-full h-full object-cover mirror"
          />
        </div>

        <!-- Call Controls -->
        <div class="absolute bottom-0 inset-x-0 pb-10 pt-6 bg-linear-to-t from-black/80 to-transparent">
          <div class="flex items-center justify-center gap-5">
            <!-- Mute Toggle -->
            <button
              class="w-14 h-14 rounded-full flex items-center justify-center transition-all duration-200"
              :class="store.isMuted ? 'bg-red-500 text-white' : 'bg-white/15 hover:bg-white/25 text-white'"
              :title="store.isMuted ? 'Unmute' : 'Mute'"
              @click="store.toggleMute()"
            >
              <Icon :name="store.isMuted ? 'MicOff' : 'Mic'" size="22" />
            </button>

            <!-- Camera Toggle (video calls only) -->
            <button
              v-if="isVideoCall"
              class="w-14 h-14 rounded-full flex items-center justify-center transition-all duration-200"
              :class="store.isCameraOff ? 'bg-red-500 text-white' : 'bg-white/15 hover:bg-white/25 text-white'"
              :title="store.isCameraOff ? 'Turn Camera On' : 'Turn Camera Off'"
              @click="store.toggleCamera()"
            >
              <Icon :name="store.isCameraOff ? 'VideoOff' : 'Video'" size="22" />
            </button>

            <!-- End Call -->
            <button
              class="w-16 h-16 rounded-full bg-red-600 hover:bg-red-700 text-white flex items-center justify-center transition-all duration-200 shadow-lg shadow-red-600/30"
              title="End Call"
              @click="handleEndCall"
            >
              <Icon name="PhoneOff" size="24" />
            </button>
          </div>
        </div>

        <!-- Error Display -->
        <div
          v-if="store.error"
          class="absolute top-6 inset-x-6 bg-red-500/90 text-white px-4 py-3 rounded-xl text-sm text-center backdrop-blur"
        >
          {{ store.error }}
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.mirror {
  transform: scaleX(-1);
}
</style>
