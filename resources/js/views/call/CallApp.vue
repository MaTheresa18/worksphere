<script setup lang="ts">
/**
 * CallApp.vue — Standalone call page (Google Meet style).
 */
import "webrtc-adapter";
import {
    ref,
    computed,
    onMounted,
    onBeforeUnmount,
    watch,
    nextTick,
} from "vue";
import Peer from "simple-peer";
import * as sdpTransform from "sdp-transform";
import { startEcho, stopEcho } from "@/echo";
import { videoCallService } from "@/services/videocall.service";

// ============================================================================
// Types
// ============================================================================

interface CallData {
    callId: string;
    chatId: string;
    callType: "audio" | "video";
    direction: "outgoing" | "incoming";
    remoteUser: {
        publicId: string;
        name: string;
        avatar: string | null;
    };
    pendingSignals?: any[]; // Simple-Peer signal data buffer
    selfPublicId: string;
}

// ============================================================================
// State
// ============================================================================

const callData = ref<CallData | null>(null);
const callState = ref<
    "initializing" | "ringing" | "connecting" | "connected" | "ended" | "error"
>("initializing");
const hasJoined = ref(false); // Join Screen flag
const error = ref<string | null>(null);
const isMuted = ref(false);
const isCameraOff = ref(false);
const videoFallback = ref(false);
const callDuration = ref(0);
const avatarLoadFailed = ref(false); // Handle broken avatars

const localStream = ref<MediaStream | null>(null);
const remoteStream = ref<MediaStream | null>(null);

const localVideoRef = ref<HTMLVideoElement | null>(null);
const remoteVideoRef = ref<HTMLVideoElement | null>(null);
const remoteAudioRef = ref<HTMLAudioElement | null>(null);

let peer: Peer.Instance | null = null;
let durationTimer: ReturnType<typeof setInterval> | null = null;
let echoChannel: any = null;
let broadcastChannel: BroadcastChannel | null = null;
let ringtoneAudio: HTMLAudioElement | null = null;
let ringtoneTimeout: ReturnType<typeof setTimeout> | null = null;

// ============================================================================
// Computed
// ============================================================================

const isVideoCall = computed(() => callData.value?.callType === "video");

const remoteHasVideo = computed(() => {
    if (!remoteStream.value) return false;
    return remoteStream.value
        .getVideoTracks()
        .some((t) => t.readyState === "live");
});

const localHasVideo = computed(() => {
    if (!localStream.value) return false;
    return localStream.value
        .getVideoTracks()
        .some((t) => t.enabled && t.readyState === "live");
});

const stateLabel = computed(() => {
    switch (callState.value) {
        case "initializing":
            return "Preparing...";
        case "ringing":
            return "Ringing...";
        case "connecting":
            return "Connecting...";
        case "connected":
            return formattedDuration.value;
        case "ended":
            return "Call ended";
        case "error":
            return error.value || "Error";
        default:
            return "";
    }
});

const formattedDuration = computed(() => {
    const mins = Math.floor(callDuration.value / 60);
    const secs = callDuration.value % 60;
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
});

const remoteInitials = computed(() => {
    const name = callData.value?.remoteUser.name || "?";
    return name
        .split(" ")
        .map((w) => w[0])
        .join("")
        .toUpperCase()
        .slice(0, 2);
});

// ============================================================================
// Watchers
// ============================================================================

watch(localStream, async (stream) => {
    console.log("[Call] localStream changed", !!stream);
    await nextTick();
    if (localVideoRef.value && stream) {
        localVideoRef.value.srcObject = stream;
    }
});

watch(remoteStream, async (stream) => {
    console.log("[Call] remoteStream changed", !!stream);
    await nextTick();
    if (remoteVideoRef.value && stream) {
        remoteVideoRef.value.srcObject = stream;
    }
    if (remoteAudioRef.value && stream) {
        remoteAudioRef.value.srcObject = stream;
        // Simple-peer doesn't always trigger play, so be explicit
        remoteAudioRef.value.play().catch(() => {});
    }
});

// ============================================================================
// Media
// ============================================================================

async function acquireMedia(): Promise<MediaStream | null> {
    if (!callData.value) return null;
    const type = callData.value.callType;
    console.log("[Call] acquireMedia, type:", type);

    try {
        if (type === "video") {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    audio: true,
                    video: { width: { ideal: 1280 }, height: { ideal: 720 } },
                });
                localStream.value = stream;
                return stream;
            } catch (e) {
                console.warn("[Call] Camera unavailable, fallback to audio");
                videoFallback.value = true;
            }
        }
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: false,
        });
        localStream.value = stream;
        return stream;
    } catch (err: any) {
        console.error("[Call] Media acquisition failed:", err);
        error.value = "Microphone access denied.";
        callState.value = "error";
        return null;
    }
}

// ============================================================================
// SDP Munging (Fix for Chrome/Firefox compatibility)
// ============================================================================

function mungeSdp(sdp: string): string {
    if (!sdp) return sdp;
    try {
        const parsed = sdpTransform.parse(sdp);
        // Clean up each media section
        if (parsed.media) {
            parsed.media = parsed.media.map((m: any) => {
                // Fix max-message-size (Chrome rejects large values)
                if (m.maxMessageSize !== undefined) {
                    m.maxMessageSize = 65536;
                }
                // Fix sctp-port:0 (Chrome rejects zero)
                if (m.sctpPort === 0) {
                    m.sctpPort = 5000;
                }
                return m;
            });
        }
        return sdpTransform.write(parsed);
    } catch (e) {
        console.warn("[Call] sdp-transform parse failed, returning raw SDP", e);
        return sdp;
    }
}

// ============================================================================
// WebRTC (SimplePeer)
// ============================================================================

async function joinCall() {
    console.log("[Call] User clicked JOIN");
    hasJoined.value = true;

    const stream = await acquireMedia();
    if (!stream) return;

    const data = callData.value!;
    const isInitiator = data.direction === "outgoing";

    console.log("[Call] Initializing SimplePeer, initiator:", isInitiator);

    let iceServers = [
        { urls: "stun:stun.cloudflare.com:3478" },
        { urls: "stun:stun.l.google.com:19302" },
    ];

    try {
        const creds = await videoCallService.getTurnCredentials(data.chatId);
        iceServers = creds.ice_servers;
        console.log("[Call] ICE servers loaded");
    } catch (err) {
        console.warn("[Call] Using default STUN servers");
    }

    peer = new Peer({
        initiator: isInitiator,
        stream: stream,
        trickle: true, // Enable trickle ICE for faster connections
        config: { iceServers },
        sdpTransform: (sdp: string) => {
            console.log("[Call] Transforming outgoing SDP via sdp-transform");
            return mungeSdp(sdp);
        },
    });

    peer.on("signal", (signal: any) => {
        console.log(
            "[Call] ☁️ Local signal produced, type:",
            signal.type || "candidate",
        );
        videoCallService.sendSignal(data.chatId, data.callId, "signal", signal);
    });

    peer.on("stream", (remote: MediaStream) => {
        console.log(
            "[Call] 📡 Remote stream received, tracks:",
            remote
                .getTracks()
                .map((t) => `${t.kind}:${t.readyState}`)
                .join(", "),
        );
        remoteStream.value = remote;
    });

    peer.on("connect", () => {
        console.log("[Call] ✅✅✅ PEER CONNECTED ✅✅✅");
        callState.value = "connected";
        stopRingtone();
        startDurationTimer();
        postToParent({ type: "state", state: "connected" });
    });

    peer.on("error", (err: any) => {
        console.error("[Call] ❌ PEER ERROR:", err.name, err.message);
        if (peer && (peer as any)._pc) {
            const pc = (peer as any)._pc as RTCPeerConnection;
            console.log("[Call] ICE connection state:", pc.iceConnectionState);
            console.log("[Call] Signaling state:", pc.signalingState);
            if (pc.localDescription?.sdp) {
                console.log("[Call] LOCAL SDP:", pc.localDescription.sdp);
            }
            if (pc.remoteDescription?.sdp) {
                console.log("[Call] REMOTE SDP:", pc.remoteDescription.sdp);
            }
        }
        handleCallFailed();
    });

    peer.on("close", () => {
        console.log("[Call] Peer closed");
        if (callState.value !== "ended") endCall("hangup");
    });

    // Start outgoing ringing if initiator
    if (isInitiator) {
        callState.value = "ringing";
        playRingtone("outgoing");
        // Timeout
        ringtoneTimeout = setTimeout(() => {
            if (callState.value === "ringing") endCall("timeout");
        }, 45000);
    } else {
        callState.value = "connecting";
        // If we have initial signals, apply them
        if (data.pendingSignals && data.pendingSignals.length > 0) {
            console.log(
                `[Call] Applying ${data.pendingSignals.length} initial pending signals`,
            );
            data.pendingSignals.forEach((s) => {
                if (s.sdp) s.sdp = mungeSdp(s.sdp);
                peer?.signal(s);
            });
        }
    }
}

// ============================================================================
// Signal Handling (Echo)
// ============================================================================

async function handleSignal(event: any) {
    if (event.sender_public_id === callData.value?.selfPublicId) return;
    if (event.call_id !== callData.value?.callId) return;

    const signal = event.signal_data;
    console.log("[Call] 📥 Received Signal:", signal.type || "candidate");

    if (peer) {
        if (signal.sdp) signal.sdp = mungeSdp(signal.sdp);
        peer.signal(signal);
    } else {
        console.warn(
            "[Call] Signal received but peer not initialized (User hasn't clicked join)",
        );
    }
}

function handleCallEndedEvent(event: any) {
    if (event.ender_public_id === callData.value?.selfPublicId) return;
    if (event.call_id !== callData.value?.callId) return;
    console.log("[Call] Remote party ended call");
    callState.value = "ended";
    postToParent({ type: "state", state: "ended", reason: event.reason });
    cleanup();
}

function setupEcho() {
    const echo = startEcho();
    if (!echo || !callData.value) return;

    const channelName = `dm.${callData.value.chatId}`;
    echoChannel = echo.private(channelName);
    echoChannel
        .listen(".CallSignal", (event: any) => handleSignal(event))
        .listen(".CallEnded", (event: any) => handleCallEndedEvent(event));
}

// ============================================================================
// Communication & Controls
// ============================================================================

function setupBroadcastChannel() {
    broadcastChannel = new BroadcastChannel("worksphere-call");
    broadcastChannel.onmessage = (e) => {
        if (e.data?.type === "end-call") endCall("hangup");
    };
}

function postToParent(msg: Record<string, any>) {
    broadcastChannel?.postMessage({ ...msg, callId: callData.value?.callId });
}

function toggleMute() {
    isMuted.value = !isMuted.value;
    localStream.value
        ?.getAudioTracks()
        .forEach((t) => (t.enabled = !isMuted.value));
}

function toggleCamera() {
    isCameraOff.value = !isCameraOff.value;
    localStream.value
        ?.getVideoTracks()
        .forEach((t) => (t.enabled = !isCameraOff.value));
}

async function endCall(reason: any = "hangup") {
    console.log("[Call] endCall:", reason);
    if (callData.value && callState.value !== "ended") {
        videoCallService
            .endCall(callData.value.chatId, callData.value.callId, reason)
            .catch(() => {});
    }
    callState.value = "ended";
    postToParent({ type: "state", state: "ended", reason });
    cleanup();
}

function handleCallFailed() {
    if (callData.value) {
        videoCallService
            .endCall(callData.value.chatId, callData.value.callId, "failed")
            .catch(() => {});
    }
    error.value = "Connection failed";
    callState.value = "ended";
    cleanup();
}

// ============================================================================
// Ringtone
// ============================================================================

function playRingtone(type: "incoming" | "outgoing") {
    try {
        ringtoneAudio = new Audio(
            type === "incoming"
                ? "/static/sounds/inbound-call.mp3"
                : "/static/sounds/outbound-call.mp3",
        );
        ringtoneAudio.loop = true;
        ringtoneAudio.volume = 0.5;
        ringtoneAudio.play().catch(() => {});
    } catch {}
}

function stopRingtone() {
    if (ringtoneAudio) {
        ringtoneAudio.pause();
        ringtoneAudio = null;
    }
    if (ringtoneTimeout) {
        clearTimeout(ringtoneTimeout);
        ringtoneTimeout = null;
    }
}

function startDurationTimer() {
    callDuration.value = 0;
    durationTimer = setInterval(() => callDuration.value++, 1000);
}

// ============================================================================
// Lifecycle
// ============================================================================

function cleanup() {
    console.log("[Call] cleanup");
    stopRingtone();
    if (localStream.value) {
        localStream.value.getTracks().forEach((t) => t.stop());
        localStream.value = null;
    }
    if (peer) {
        peer.destroy();
        peer = null;
    }
    if (durationTimer) {
        clearInterval(durationTimer);
        durationTimer = null;
    }
}

onMounted(async () => {
    console.log("[Call] Mounted");
    const raw = sessionStorage.getItem("callData");
    if (!raw) {
        error.value = "Invalid session.";
        callState.value = "error";
        return;
    }

    try {
        callData.value = JSON.parse(raw);
        sessionStorage.removeItem("callData");
    } catch {
        error.value = "Data parse error.";
        callState.value = "error";
        return;
    }

    const data = callData.value!;
    document.title = `Call — ${data.remoteUser.name}`;
    setupBroadcastChannel();
    setupEcho();

    if (data.direction === "incoming") {
        playRingtone("incoming");
    }
});

onBeforeUnmount(() => {
    cleanup();
    stopEcho();
    broadcastChannel?.close();
});

window.addEventListener("beforeunload", () => {
    if (callState.value !== "ended") endCall("hangup");
});
</script>

<template>
    <div class="call-container">
        <!-- Background gradient -->
        <div class="call-bg" />

        <!-- Error State -->
        <div v-if="callState === 'error'" class="call-center-content">
            <div class="error-icon">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="48"
                    height="48"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <circle cx="12" cy="12" r="10" />
                    <line x1="15" y1="9" x2="9" y2="15" />
                    <line x1="9" y1="9" x2="15" y2="15" />
                </svg>
            </div>
            <p class="error-text">{{ error }}</p>
            <button class="btn-close-window" @click="window.close()">
                Close Window
            </button>
        </div>

        <!-- Call Ended State -->
        <div v-else-if="callState === 'ended'" class="call-center-content">
            <div class="ended-icon">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="48"
                    height="48"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path
                        d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91"
                    />
                    <line x1="23" y1="1" x2="1" y2="23" />
                </svg>
            </div>
            <p class="ended-text">Call ended</p>
            <p class="ended-sub">
                {{ error || "You can now close this window." }}
            </p>
        </div>

        <!-- Join Screen (Before Peer initialized) -->
        <div v-else-if="!hasJoined" class="join-screen call-center-content">
            <div class="join-preview">
                <div class="avatar-container">
                    <img
                        v-if="callData?.remoteUser.avatar && !avatarLoadFailed"
                        :src="callData.remoteUser.avatar"
                        :alt="callData?.remoteUser.name"
                        class="avatar-img"
                        @error="avatarLoadFailed = true"
                    />
                    <div v-else class="avatar-fallback">
                        {{ remoteInitials }}
                    </div>
                </div>
                <h1 class="join-title">Ready to join?</h1>
                <p class="join-subtitle">
                    {{ callData?.remoteUser.name }} is on the line.
                </p>
            </div>

            <div class="join-actions">
                <button class="btn-join" @click="joinCall">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path
                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91"
                        />
                    </svg>
                    Join now
                </button>
                <button class="btn-decline-join" @click="endCall('hangup')">
                    Decline
                </button>
            </div>
        </div>

        <!-- Active Call State -->
        <template v-else>
            <!-- Remote Video (full screen) -->
            <video
                v-if="remoteHasVideo"
                ref="remoteVideoRef"
                autoplay
                playsinline
                class="remote-video"
            />

            <!-- Audio-only or Missing Remote Video: avatar display -->
            <div v-else class="call-center-content">
                <div class="avatar-container big">
                    <img
                        v-if="callData?.remoteUser.avatar && !avatarLoadFailed"
                        :src="callData.remoteUser.avatar"
                        :alt="callData?.remoteUser.name"
                        class="avatar-img"
                        @error="avatarLoadFailed = true"
                    />
                    <div v-else class="avatar-fallback">
                        {{ remoteInitials }}
                    </div>
                    <!-- Pulsing ring while connecting -->
                    <div
                        v-if="callState !== 'connected'"
                        class="avatar-pulse"
                    />
                </div>
                <p class="remote-name">{{ callData?.remoteUser.name }}</p>
                <p class="state-label">{{ stateLabel }}</p>
            </div>

            <!-- Hidden audio element for audio-only calls -->
            <audio ref="remoteAudioRef" autoplay />

            <!-- Local Video PiP -->
            <div v-if="localHasVideo" class="local-video-pip">
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
                <div class="participant-info">
                    <span class="participant-name">{{
                        callData?.remoteUser.name
                    }}</span>
                    <span class="call-duration">{{ formattedDuration }}</span>
                </div>
            </div>

            <!-- Bottom Controls -->
            <div class="controls-container">
                <div class="controls-inner">
                    <!-- Toggle Mic -->
                    <button
                        class="control-btn"
                        :class="{ 'is-active': isMuted }"
                        @click="toggleMute"
                        :title="isMuted ? 'Unmute' : 'Mute'"
                    >
                        <svg
                            v-if="!isMuted"
                            xmlns="http://www.w3.org/2000/svg"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"
                            />
                            <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                            <line x1="12" y1="19" x2="12" y2="22" />
                        </svg>
                        <svg
                            v-else
                            xmlns="http://www.w3.org/2000/svg"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <line x1="2" y1="2" x2="22" y2="22" />
                            <path d="M18.89 13.23A7.12 7.12 0 0 1 5 12v-2" />
                            <path d="M9 5a3 3 0 0 1 5.12-2.12" />
                            <path d="M11.5 11.5a3 3 0 0 1-2.5-2.5" />
                            <line x1="12" y1="19" x2="12" y2="22" />
                        </svg>
                    </button>

                    <!-- Toggle Camera -->
                    <button
                        class="control-btn"
                        :class="{ 'is-active': isCameraOff }"
                        @click="toggleCamera"
                        :title="
                            isCameraOff ? 'Turn Camera On' : 'Turn Camera Off'
                        "
                    >
                        <svg
                            v-if="!isCameraOff"
                            xmlns="http://www.w3.org/2000/svg"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="m22 8-6 4 6 4V8Z" />
                            <rect
                                x="2"
                                y="6"
                                width="12"
                                height="12"
                                rx="2"
                                ry="2"
                            />
                        </svg>
                        <svg
                            v-else
                            xmlns="http://www.w3.org/2000/svg"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <line x1="2" y1="2" x2="22" y2="22" />
                            <path d="m22 8-6 4 6 4V8Z" />
                            <path d="M14 8V6a2 2 0 0 0-2-2H4.26" />
                            <path
                                d="M2.28 2.28 2 2.28A2 2 0 0 0 2 4v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2"
                            />
                        </svg>
                    </button>

                    <!-- Hangup -->
                    <button
                        class="control-btn hangup"
                        @click="endCall('hangup')"
                        title="End Call"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91"
                            />
                        </svg>
                    </button>
                </div>
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
    background:
        radial-gradient(
            ellipse at 50% 0%,
            rgba(59, 130, 246, 0.08) 0%,
            transparent 60%
        ),
        radial-gradient(
            ellipse at 80% 100%,
            rgba(124, 58, 237, 0.06) 0%,
            transparent 50%
        ),
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
    0% {
        transform: scale(1);
        opacity: 1;
    }
    100% {
        transform: scale(1.3);
        opacity: 0;
    }
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
    0%,
    100% {
        opacity: 0.5;
    }
    50% {
        opacity: 1;
    }
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

.dot-green {
    background: #22c55e;
    box-shadow: 0 0 8px rgba(34, 197, 94, 0.5);
}
.dot-amber {
    background: #f59e0b;
    box-shadow: 0 0 8px rgba(245, 158, 11, 0.5);
}

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
.error-icon,
.ended-icon {
    color: rgba(255, 255, 255, 0.4);
    margin-bottom: 8px;
}

.error-text,
.ended-text {
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
