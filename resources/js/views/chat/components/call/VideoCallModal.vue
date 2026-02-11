<script setup lang="ts">
import { ref, watch, nextTick, computed } from 'vue';
import { useVideoCallStore } from '@/stores/videocall';
import { useVideoCall } from '@/composables/useVideoCall';
import { Icon, Avatar } from '@/components/ui';
import { useAvatar } from '@/composables/useAvatar';

const store = useVideoCallStore();
const { endCall } = useVideoCall();
const avatar = useAvatar();

const localVideoRef = ref<HTMLVideoElement | null>(null);
const remoteVideoRef = ref<HTMLVideoElement | null>(null);

// Draggable state
const isDragging = ref(false);
const position = ref({ x: window.innerWidth - 380, y: 80 });
const dragOffset = ref({ x: 0, y: 0 });

const remoteAvatarData = computed(() => {
  if (!store.currentCall) return { url: '', initials: '?' };
  return avatar.resolveInitials(store.currentCall.remoteUser.name);
});

// Only show for active calls that are NOT incoming ringing (that's handled by IncomingCallOverlay)
const shouldShow = computed(() =>
  store.isCallActive && !store.isIncoming,
);

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
      return 'Ringing...';
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

// --- Dragging ---
function onMouseDown(e: MouseEvent) {
  if ((e.target as HTMLElement).closest('button')) return;
  isDragging.value = true;
  dragOffset.value = {
    x: e.clientX - position.value.x,
    y: e.clientY - position.value.y,
  };
  window.addEventListener('mousemove', onMouseMove);
  window.addEventListener('mouseup', onMouseUp);
}

function onMouseMove(e: MouseEvent) {
  if (!isDragging.value) return;
  position.value = {
    x: Math.max(0, Math.min(window.innerWidth - 360, e.clientX - dragOffset.value.x)),
    y: Math.max(0, Math.min(window.innerHeight - 280, e.clientY - dragOffset.value.y)),
  };
}

function onMouseUp() {
  isDragging.value = false;
  window.removeEventListener('mousemove', onMouseMove);
  window.removeEventListener('mouseup', onMouseUp);
}
</script>

<template>
  <Teleport to="body">
    <Transition name="call-window">
      <div
        v-if="shouldShow"
        class="fixed z-9999 select-none"
        :style="{ left: position.x + 'px', top: position.y + 'px' }"
      >
        <div
          class="w-[340px] rounded-2xl overflow-hidden shadow-2xl ring-1 ring-white/10 bg-gray-900"
          :class="{ 'cursor-grabbing': isDragging, 'cursor-grab': !isDragging }"
          @mousedown="onMouseDown"
        >
          <!-- Header bar -->
          <div class="flex items-center justify-between px-4 py-2.5 bg-gray-800/80">
            <div class="flex items-center gap-2 min-w-0">
              <span class="relative flex h-2.5 w-2.5 shrink-0">
                <span
                  class="absolute inline-flex h-full w-full rounded-full opacity-75 animate-ping"
                  :class="store.isConnected ? 'bg-green-400' : 'bg-amber-400'"
                />
                <span
                  class="relative inline-flex rounded-full h-2.5 w-2.5"
                  :class="store.isConnected ? 'bg-green-500' : 'bg-amber-500'"
                />
              </span>
              <span class="text-white/90 text-sm font-medium truncate">
                {{ store.currentCall?.remoteUser.name }}
              </span>
            </div>
            <span class="text-white/50 text-xs tabular-nums shrink-0 ml-2">
              {{ stateLabel }}
            </span>
          </div>

          <!-- Video / Avatar area -->
          <div class="relative aspect-4/3 bg-gray-950 flex items-center justify-center overflow-hidden">
            <!-- Remote video -->
            <video
              v-if="store.remoteStream && isVideoCall"
              ref="remoteVideoRef"
              autoplay
              playsinline
              class="w-full h-full object-cover"
            />

            <!-- Avatar placeholder -->
            <div v-else class="flex flex-col items-center gap-3">
              <div class="w-20 h-20 rounded-full bg-white/10 flex items-center justify-center text-3xl font-semibold text-white ring-2 ring-white/20">
                <Avatar
                  v-if="store.currentCall?.remoteUser.avatar"
                  :src="store.currentCall.remoteUser.avatar"
                  :alt="store.currentCall?.remoteUser.name || ''"
                  :fallback="remoteAvatarData.initials"
                  size="lg"
                  class="rounded-full w-20 h-20"
                />
                <span v-else>{{ remoteAvatarData.initials }}</span>
              </div>
              <p class="text-white/50 text-xs animate-pulse">
                {{ stateLabel }}
              </p>
            </div>

            <!-- Local video PiP (video calls only) -->
            <div
              v-if="store.localStream && isVideoCall && !store.isCameraOff"
              class="absolute bottom-2 right-2 w-24 h-[68px] rounded-lg overflow-hidden ring-1 ring-white/20 shadow-lg"
            >
              <video
                ref="localVideoRef"
                autoplay
                muted
                playsinline
                class="w-full h-full object-cover mirror"
              />
            </div>
          </div>

          <!-- Controls bar -->
          <div class="flex items-center justify-center gap-3 px-4 py-3 bg-gray-800/80">
            <!-- Mute -->
            <button
              class="w-10 h-10 rounded-full flex items-center justify-center transition-all duration-150"
              :class="store.isMuted ? 'bg-red-500 text-white' : 'bg-white/10 hover:bg-white/20 text-white'"
              :title="store.isMuted ? 'Unmute' : 'Mute'"
              @click.stop="store.toggleMute()"
            >
              <Icon :name="store.isMuted ? 'MicOff' : 'Mic'" size="16" />
            </button>

            <!-- Camera (video only) -->
            <button
              v-if="isVideoCall"
              class="w-10 h-10 rounded-full flex items-center justify-center transition-all duration-150"
              :class="store.isCameraOff ? 'bg-red-500 text-white' : 'bg-white/10 hover:bg-white/20 text-white'"
              :title="store.isCameraOff ? 'Turn Camera On' : 'Turn Camera Off'"
              @click.stop="store.toggleCamera()"
            >
              <Icon :name="store.isCameraOff ? 'VideoOff' : 'Video'" size="16" />
            </button>

            <!-- End Call -->
            <button
              class="w-10 h-10 rounded-full bg-red-600 hover:bg-red-700 text-white flex items-center justify-center transition-all duration-150 shadow-lg shadow-red-600/30"
              title="End Call"
              @click.stop="handleEndCall"
            >
              <Icon name="PhoneOff" size="16" />
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.mirror {
  transform: scaleX(-1);
}

.call-window-enter-active {
  transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.call-window-leave-active {
  transition: all 0.2s ease-in;
}
.call-window-enter-from {
  opacity: 0;
  transform: scale(0.9) translateY(10px);
}
.call-window-leave-to {
  opacity: 0;
  transform: scale(0.95) translateY(5px);
}
</style>
