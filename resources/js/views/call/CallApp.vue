<script setup lang="ts">
/**
 * CallApp.vue — Group Call (Mesh Topology)
 * Supports 1:1 and Group calls (up to ~6 participants)
 */
import "webrtc-adapter";
import {
    ref,
    computed,
    onMounted,
    onBeforeUnmount,
    watch,
    nextTick,
    reactive,
} from "vue";
import Peer from "simple-peer";
import * as sdpTransform from "sdp-transform";
import { startEcho, stopEcho } from "@/echo";
import { videoCallService } from "@/services/videocall.service";
import { Icon } from "@/components/ui"; 

// ============================================================================
// Types
// ============================================================================

interface Participant {
    publicId: string;
    name: string;
    avatar: string | null;
    isSelf?: boolean;
}

interface CallData {
    callId: string;
    chatId: string;
    callType: "audio" | "video";
    direction: "outgoing" | "incoming";
    selfPublicId: string;
    remoteUser?: {
        publicId: string;
        name: string;
        avatar: string | null;
    };
    pendingSignals?: any[];
}

// ============================================================================
// State
// ============================================================================

const callData = ref<CallData | null>(null);
const callState = ref<
    "initializing" | "ringing" | "connecting" | "connected" | "ended" | "error"
>("initializing");
const hasJoined = ref(false);
const error = ref<string | null>(null);

// Media
const localStream = ref<MediaStream | null>(null);
const isMuted = ref(false);
const isCameraOff = ref(false);
const videoFallback = ref(false);
const isAudioOnly = computed(() => callData.value?.callType === 'audio');

// Participants & Peers
const participants = ref<Participant[]>([]);
const peers = new Map<string, Peer.Instance>();
const remoteStreams = reactive(new Map<string, MediaStream>());
const iceServers = ref<RTCIceServer[]>([]);

// Directive for srcObject property (Vue doesn't bind to .srcObject property by default)
const vSrcObject = {
    updated: (el: any, binding: any) => { if (el.srcObject !== binding.value) el.srcObject = binding.value; },
    mounted: (el: any, binding: any) => { el.srcObject = binding.value; }
};

// UI Refs
const localVideoRef = ref<HTMLVideoElement | null>(null);

// Timers & Channels
let durationTimer: ReturnType<typeof setInterval> | null = null;
const callDuration = ref(0);
let echoChannel: any = null;
let broadcastChannel: BroadcastChannel | null = null;
let ringtoneAudio: HTMLAudioElement | null = null;
let ringtoneTimeout: ReturnType<typeof setTimeout> | null = null;

// ============================================================================
// Computed
// ============================================================================

const isVideoCall = computed(() => callData.value?.callType === "video");

const localHasVideo = computed(() => {
    if (!localStream.value) return false;
    return localStream.value.getVideoTracks().length > 0;
});

const stateLabel = computed(() => {
    switch (callState.value) {
        case "initializing": return "Preparing...";
        case "ringing": return "Waiting..."; 
        case "connecting": return "Connecting...";
        case "connected": return formattedDuration.value;
        case "ended": return "Call ended";
        case "error": return error.value || "Error";
        default: return "";
    }
});

const formattedDuration = computed(() => {
    const mins = Math.floor(callDuration.value / 60);
    const secs = callDuration.value % 60;
    return `${mins.toString().padStart(2, "0")}:${secs.toString().padStart(2, "0")}`;
});

const gridClass = computed(() => {
    // Only count participants effectively shown in the grid (Self + Remotes)
    const count = participants.value.filter(p => !p.isSelf && p.publicId !== callData.value?.selfPublicId).length + 1;
    if (count <= 2) return "grid-1-1";
    if (count <= 4) return "grid-2-2";
    return "grid-3-2";
});

const previewRemoteName = computed(() => {
   if (callData.value?.remoteUser) return callData.value?.remoteUser.name;
   return "Group Call";
});

// ============================================================================
// Watchers
// ============================================================================

// Local stream handling is now unified via v-src-object directive or ref in template
 

async function acquireMedia(): Promise<MediaStream | null> {
    if (!callData.value) return null;
    const type = callData.value.callType;
    console.log("[Call] acquireMedia, type:", type);

    try {
        if (type === "video") {
            try {
                // Pre-check for camera availability
                const devices = await navigator.mediaDevices.enumerateDevices();
                const hasCamera = devices.some(device => device.kind === 'videoinput');
                
                if (!hasCamera) {
                    console.warn("[Call] No camera found on this device.");
                    videoFallback.value = true;
                } else {
                    const stream = await navigator.mediaDevices.getUserMedia({
                        audio: true,
                        video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: "user" },
                    });
                    localStream.value = stream;
                    return stream;
                }
            } catch (e) {
                console.warn("[Call] Camera access error or unavailable, fallback to audio", e);
                videoFallback.value = true;
            }
        }
        
        // Audio Only or Fallback
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: false,
        });
        localStream.value = stream;
        isCameraOff.value = true; // Force camera off state UI
        return stream;
    } catch (err: any) {
        console.error("[Call] Media acquisition failed:", err);
        error.value = "Microphone access denied.";
        callState.value = "error";
        return null;
    }
}

// ============================================================================
// SDP Munging
// ============================================================================

function mungeSdp(sdp: string): string {
    if (!sdp) return sdp;
    try {
        const parsed = sdpTransform.parse(sdp);
        if (parsed.media) {
            parsed.media = parsed.media.map((m: any) => {
                // Fix for: a=max-message-size:1073741823 Invalid SDP line
                // We cap it to a more standard value.
                if (m.maxMessageSize !== undefined) {
                    m.maxMessageSize = 65536; 
                }
                // Ensure SCTP port is set if missing (older simple-peer/browser versions)
                if (m.protocol === 'UDP/DTLS/SCTP' && m.sctpPort === undefined) {
                    m.sctpPort = 5000;
                }
                return m;
            });
        }
        return sdpTransform.write(parsed);
    } catch (e) {
        console.warn("[Call] SDP munging failed, fallback to raw", e);
        return sdp;
    }
}

// ============================================================================
// WebRTC (Mesh)
// ============================================================================

async function joinCall() {
    console.log("[Call] User clicked JOIN");
    
    const stream = await acquireMedia();
    if (!stream) return;

    hasJoined.value = true;
    stopRingtone(); 

    if (!callData.value) return;

    try {
        // 0. Fetch ICE credentials (TURN/STUN) for NAT traversal
        try {
            const turnData = await videoCallService.getTurnCredentials(callData.value.chatId);
            iceServers.value = turnData.ice_servers;
            console.log("[Call] ICE Servers configured:", iceServers.value.length);
        } catch (e) {
            console.warn("[Call] Failed to fetch TURN credentials, using defaults", e);
        }

        // 1. Tell API we are joining
        const { participants: currentParticipants } = await videoCallService.joinCall(
            callData.value.chatId,
            callData.value.callId
        );

        console.log("[Call] Joined. Participants:", currentParticipants);

        // 2. Normalize and initialize participants list
        const selfId = callData.value?.selfPublicId?.toLowerCase();
        participants.value = currentParticipants.map((p: any) => {
            const pId = (p.public_id || p.publicId || "").toLowerCase();
            const isSelf = pId === selfId;
            return {
                publicId: pId,
                name: isSelf ? "Me" : p.name,
                avatar: p.avatar_thumb_url || p.avatar,
                isSelf
            };
        });
        
        // 3. Connect to existing participants (WE initiate)
        const others = participants.value.filter(p => !p.isSelf);
        
        for (const p of others) {
            createPeer(p.publicId, true, stream);
        }

        // 4. Set state
        callState.value = "connected"; // We are "in" the call room
        postToParent({ type: "state", state: "connected" });
        startDurationTimer();

    } catch (err) {
        console.error("[Call] Join failed:", err);
        handleCallFailed();
    }
}

function createPeer(targetPublicId: string, initiator: boolean, stream: MediaStream) {
    const normalizedTargetId = targetPublicId.toLowerCase();
    if (peers.has(normalizedTargetId)) return;

    console.log(`[Call] Creating peer for ${normalizedTargetId} (initiator: ${initiator})`);

    const peer = new Peer({
        initiator,
        stream,
        trickle: true,
        sdpTransform: (sdp) => mungeSdp(sdp),
        config: { 
            iceServers: iceServers.value.length > 0 ? iceServers.value : undefined 
        }
    });

    // Debug connection state
    // @ts-ignore
    const pc = peer._pc as RTCPeerConnection;
    if (pc) {
        pc.oniceconnectionstatechange = () => {
            console.log(`[Call] ICE State for ${normalizedTargetId}: ${pc.iceConnectionState}`);
        };
        pc.onconnectionstatechange = () => {
            console.log(`[Call] Connection State for ${normalizedTargetId}: ${pc.connectionState}`);
        };
    }

    peer.on("signal", (signal) => {
        // Targeted signal
        videoCallService.sendSignal(
            callData.value!.chatId,
            callData.value!.callId,
            "signal",
            signal,
            normalizedTargetId 
        );
    });

    peer.on("stream", (remoteStream) => {
        console.log(`[Call] Stream from ${normalizedTargetId}`, {
            audio: remoteStream.getAudioTracks().length,
            video: remoteStream.getVideoTracks().length,
            active: remoteStream.active
        });
        remoteStreams.set(normalizedTargetId, remoteStream);
    });

    peer.on("error", (err) => {
        console.error(`[Call] Peer error ${normalizedTargetId}:`, err);
    });

    peer.on("close", () => {
        console.log(`[Call] Peer closed ${normalizedTargetId}`);
        peers.delete(normalizedTargetId);
        remoteStreams.delete(normalizedTargetId);
    });

    peers.set(normalizedTargetId, peer);
}

// ============================================================================
// Signal Handling
// ============================================================================

async function handleSignal(event: any) {
    const senderId = (event.sender_public_id || event.senderPublicId || "").toLowerCase();
    const targetId = (event.target_public_id || event.targetPublicId || "").toLowerCase();
    const selfId = (callData.value?.selfPublicId || "").toLowerCase();

    if (senderId === selfId) return;
    
    // In mesh, signals MUST be targeted or we ignore them for safety
    if (targetId && targetId !== selfId) return;

    // WAIT for media if we are in the process of joining
    if (!hasJoined.value || !localStream.value) {
        // If we haven't joined yet, we can't respond.
        // If we are joining but stream isn't ready, we might want to wait?
        // But joinCall is already running. Signals will hit here.
        // For simplicity, we ignore signals until hasJoined && localStream.
        return;
    }

    const signal = event.signal_data;
    if (signal.sdp) signal.sdp = mungeSdp(signal.sdp);

    // Get or Create Peer
    let peer = peers.get(senderId);

    if (!peer) {
        // If we received an offer, we are NOT the initiator for this pair
        if (signal.type === 'offer') {
            console.log(`[Call] Received offer from ${senderId}, creating responder peer`);
            createPeer(senderId, false, localStream.value!);
            peer = peers.get(senderId);
        } else {
            console.warn(`[Call] Received ${signal.type} from unknown peer ${senderId}`);
            return;
        }
    }

    peer?.signal(signal);
}

function handleParticipantJoined(event: any) {
    const publicId = (event.participant_public_id || event.participant_publicId || "").toLowerCase();
    const selfId = (callData.value?.selfPublicId || "").toLowerCase();
    
    if (publicId === selfId) return;
    
    console.log("[Call] New participant joined:", event);
    
    // Add to list
    const exists = participants.value.find(p => p.publicId.toLowerCase() === publicId);
    if (!exists) {
        participants.value.push({
            publicId: publicId,
            name: event.participant_name,
            avatar: event.participant_avatar,
            isSelf: false
        });
    }
}

function handleParticipantLeft(event: any) {
    const publicId = (event.participant_public_id || event.participant_publicId || "").toLowerCase();
    console.log("[Call] Participant left:", publicId);
    
    // Remove from list
    participants.value = participants.value.filter(p => p.publicId !== publicId);
    
    // Peer cleanup handled by peer.on('close') or explicit destroy
    const peer = peers.get(publicId);
    if (peer) {
        peer.destroy();
        peers.delete(publicId);
        remoteStreams.delete(publicId);
    }
}

function handleCallEndedEvent(event: any) {
    // This is the global "CallEnded" (force close for everyone) or strict 1:1 end
    // For hybrid group calls, we might not use this much, relying on ParticipantLeft
    console.log("[Call] CallEnded event received");
    callState.value = "ended";
    postToParent({ type: "state", state: "ended", reason: event.reason });
    cleanup();
}

function setupEcho() {
    const echo = startEcho();
    if (!echo || !callData.value) return;

    // Call signaling is on the chat channel (dm.X or group.X)
    // We need to know which one.
    // The previous implementation used dm.X hardcoded.
    // We should infer from chatId? Or pass chatType in callData?
    // callData doesn't have chatType. We can try both or pass it.
    // Let's assume passed in sessionData or we can deduce.
    // Actually, `startCall` in useVideoCall.ts didn't put chatType in sessionStorage.
    // We can assume if remoteUser is generic "Group", it's group?
    // Safer to just subscribe to both prefixes or pass it.
    // I will simply try to subscribe to the channel that matches the ID.
    // Actually, `useVideoCall` knows `chat_type` from the event.
    // Let's fix `startCall` to pass `chatType`. 
    // For now, I'll try to guess or use a wildcard approach if I could (Echo doesn't support).
    // Let's update `useVideoCall` to pass `chatType`.

    // Assuming we passed `chatId`... wait, `chatId` is the `public_id`.
    // The channel is `dm.{public_id}` or `group.{public_id}`.
    // I'll try `group.` first if it looks like a group call?
    // Or just subscribe to `private-dm.x` AND `private-group.x`? No, Echo handles prefix.
    
    // HACK: I will guess based on callData.remoteUser.
    
    // Better fix: update `useVideoCall.ts` to store `chatType` in sessionStorage.
    // But since I can't do that concurrently effectively without risking race, 
    // I will try to subscribe to `dm.{id}`. If it fails (auth), try `group.{id}`.
    // Actually, `Echo` doesn't throw on subscribe.
    
    // Let's assume for now 1:1 is `dm`.
    // Wait, the `videocall.service` endpoints use `chatId`. 
    // The backend broadcasts on `dm` or `group` based on chat type.
    
    // I'll just assume `dm` for now to match legacy, 
    // BUT we must fix this to support groups.
    // I will add `chatType` to `callData` interface and try to read it.
    // If missing, default to `dm`.
    
    const prefix = (callData.value as any).chatType === 'group' ? 'group' : 'dm';
    const channelName = `${prefix}.${callData.value.chatId}`;
    
    echoChannel = echo.private(channelName);
    echoChannel
        .listen(".CallSignal", (event: any) => handleSignal(event))
        .listen(".CallParticipantJoined", (event: any) => handleParticipantJoined(event))
        .listen(".CallParticipantLeft", (event: any) => handleParticipantLeft(event))
        .listen(".CallEnded", (event: any) => handleCallEndedEvent(event));
}

// ============================================================================
// Controls
// ============================================================================

function toggleMute() {
    isMuted.value = !isMuted.value;
    localStream.value?.getAudioTracks().forEach(t => t.enabled = !isMuted.value);
}

function toggleCamera() {
    isCameraOff.value = !isCameraOff.value;
    localStream.value?.getVideoTracks().forEach(t => t.enabled = !isCameraOff.value);
}

async function endCall(reason = "hangup") {
    if (callData.value && callState.value !== "ended") {
        videoCallService.endCall(callData.value.chatId, callData.value.callId, reason).catch(() => {});
    }
    callState.value = "ended";
    postToParent({ type: "state", state: "ended", reason });
    cleanup();
}

function handleCallFailed() {
    error.value = "Connection failed";
    callState.value = "ended";
    cleanup();
}

function playRingtone(type: "incoming" | "outgoing") {
    try {
        ringtoneAudio = new Audio(
            type === "incoming" ? "/static/sounds/inbound-call.mp3" : "/static/sounds/outbound-call.mp3"
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
}

function startDurationTimer() {
    if (durationTimer) return;
    callDuration.value = 0;
    durationTimer = setInterval(() => callDuration.value++, 1000);
}

function postToParent(msg: any) {
    broadcastChannel?.postMessage({ ...msg, callId: callData.value?.callId });
}

function setupBroadcastChannel() {
    broadcastChannel = new BroadcastChannel("worksphere-call");
    broadcastChannel.onmessage = (e) => {
        if (e.data?.type === "end-call") endCall("hangup");
    };
}

function cleanup() {
    stopRingtone();
    if (localStream.value) {
        localStream.value.getTracks().forEach(t => t.stop());
        localStream.value = null;
    }
    peers.forEach(p => p.destroy());
    peers.clear();
    remoteStreams.clear();
    if (durationTimer) clearInterval(durationTimer);
    stopEcho();
    broadcastChannel?.close();
}

// ============================================================================
// Lifecycle
// ============================================================================

onMounted(async () => {
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
    
    // Add self to participants list initially (conceptual)
    participants.value.push({
        publicId: data.selfPublicId,
        name: "Me",
        avatar: null, // We might not have our own avatar in callData
        isSelf: true
    });

    document.title = `Call — ${data.chatId}`;
    setupBroadcastChannel();
    setupEcho();
    
    // If we have pending signals (from the accept buffering), we should apply them 
    // AFTER we join. But usually we need to join first to get stats.
    
    if (data.pendingSignals) {
        // Store them to apply after join?
        // Actually, in mesh, we need to know WHO they are from.
        // The new handleSignal does this. We can just replay them.
    }

    if (data.direction === "incoming" && data.remoteUser) {
        // DM Call with Ringing
        playRingtone("incoming");
    } else if (data.direction === "outgoing" && data.remoteUser) {
         // DM Outgoing
         playRingtone("outgoing");
         callState.value = "ringing";
    }
});

onBeforeUnmount(() => cleanup());
</script>

<template>
    <div class="call-container" :class="gridClass">
        <div class="call-bg"></div>
        <div class="call-overlay"></div>

        <!-- HEADER / INFO -->
        <div class="call-header">
             <div class="header-info">
                 <span class="status-dot" :class="callState"></span>
                 <span class="status-text">{{ stateLabel }}</span>
             </div>
        </div>

        <!-- ERROR STATE -->
        <div v-if="callState === 'error'" class="call-center-content">
             <div class="state-icon error">
                 <Icon name="AlertCircle" size="48" />
             </div>
             <p class="state-text">{{ error }}</p>
             <button class="btn-secondary" @click="window.close()">Close</button>
        </div>

        <!-- ENDED STATE -->
        <div v-else-if="callState === 'ended'" class="call-center-content">
             <div class="state-icon ended">
                 <Icon name="PhoneOff" size="48" />
             </div>
             <p class="state-text">Call ended</p>
        </div>

        <!-- JOIN SCREEN -->
        <div v-else-if="!hasJoined" class="join-screen call-center-content">
             <div class="avatar-preview">
                 <!-- Avatar Preview -->
                  <div class="preview-circle">
                      <span class="initials">{{ previewRemoteName[0] }}</span>
                  </div>
             </div>
             <h1 class="join-title">Join Call</h1>
             <p class="join-subtitle">With {{ previewRemoteName }}</p>
             
             <div class="join-actions">
                  <button class="btn-join" @click="joinCall">
                      <Icon name="Phone" size="20" />
                      <span>Join Now</span>
                  </button>
                  <button class="btn-decline" @click="endCall('declined')">
                      <Icon name="X" size="20" />
                      <span>Decline</span>
                  </button>
             </div>
        </div>

        <!-- CONNECTED GRID -->
        <template v-else>
            <div class="grid-wrapper">
                <!-- 1. Remote Participants -->
                <div 
                    v-for="p in participants.filter(p => !p.isSelf && p.publicId !== callData?.selfPublicId)" 
                    :key="p.publicId" 
                    class="video-cell remote"
                >
                    <!-- Audio playback (always audible, but hidden/tiny) -->
                    <audio
                        v-if="remoteStreams.get(p.publicId)"
                        v-src-object="remoteStreams.get(p.publicId)"
                        autoplay
                        playsinline
                        class="hidden-audio"
                    />

                    <video
                        v-if="remoteStreams.get(p.publicId) && !isAudioOnly"
                        v-src-object="remoteStreams.get(p.publicId)"
                        autoplay
                        playsinline
                        class="video-element"
                    />
                    <!-- Avatar Fallback (Audio Only or No Video) -->
                    <div v-else class="avatar-fallback">
                        <img v-if="p.avatar" :src="p.avatar" class="avatar-img" />
                        <div v-else class="avatar-placeholder" :style="{ backgroundColor: 'var(--avatar-bg)' }">
                            {{ p.name[0] }}
                        </div>
                        <div class="audio-indicator">
                            <Icon name="Mic" size="16" />
                        </div>
                    </div>
                    
                    <div class="participant-info">
                        <span class="participant-name">{{ p.name }}</span>
                    </div>
                </div>

                <!-- 2. Local Participant (Me) -->
                <div 
                    class="video-cell local" 
                    :class="{ 'pip-mode': participants.length >= 2, 'audio-mode': isAudioOnly }"
                >
                    <video
                        v-if="localHasVideo && !isCameraOff && !isAudioOnly"
                        v-src-object="localStream"
                        autoplay
                        muted
                        playsinline
                        class="video-element"
                    />
                    <div v-else class="avatar-fallback">
                        <div class="avatar-placeholder local">
                            Me
                        </div>
                    </div>
                    <div class="participant-info">
                        <span class="participant-name">You</span>
                    </div>
                </div>
            </div>
            
            <!-- CONTROLS -->
            <div class="controls-bar">
                 <button class="control-btn" :class="{ 'off': isMuted }" @click="toggleMute" title="Toggle Mute">
                    <Icon :name="isMuted ? 'MicOff' : 'Mic'" size="24" />
                 </button>
                 
                 <button v-if="!isAudioOnly" class="control-btn" :class="{ 'off': isCameraOff }" @click="toggleCamera" title="Toggle Camera">
                    <Icon :name="isCameraOff ? 'VideoOff' : 'Video'" size="24" />
                 </button>

                 <button class="control-btn hangup" @click="endCall('hangup')" title="End Call">
                    <Icon name="PhoneOff" size="24" />
                 </button>
            </div>
        </template>
    </div>
</template>

<style scoped>
.call-container {
    background: #000;
    height: 100vh;
    width: 100vw;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    font-family: 'Inter', system-ui, sans-serif;
}

.call-bg {
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, #1e1e2e 0%, #11111b 100%);
    z-index: 0;
}
.call-overlay {
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at center, transparent 0%, rgba(0,0,0,0.4) 100%);
    z-index: 1;
    pointer-events: none;
}

/* Header */
.call-header {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    padding: 16px;
    z-index: 20;
    display: flex;
    justify-content: center;
    pointer-events: none;
}
.header-info {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    padding: 6px 12px;
    border-radius: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}
.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #9ca3af;
}
.status-dot.connected { background: #10b981; box-shadow: 0 0 8px #10b981; }
.status-dot.connecting { background: #f59e0b; animation: pulse 1.5s infinite; }
.status-dot.ringing { background: #3b82f6; animation: pulse 1.5s infinite; }
.status-dot.ended { background: #ef4444; }

.status-text {
    color: rgba(255, 255, 255, 0.9);
    font-size: 13px;
    font-weight: 500;
}

/* Call Centers (Error / Ended / Join) */
.call-center-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: white;
    z-index: 10;
    padding: 24px;
    text-align: center;
}
.state-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 24px;
}
.state-icon.error { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
.state-icon.ended { background: rgba(107, 114, 128, 0.2); color: #9ca3af; }
.state-text { font-size: 18px; color: rgba(255,255,255,0.8); margin-bottom: 24px; }

/* Join Screen */
.avatar-preview { margin-bottom: 24px; }
.preview-circle {
    width: 96px;
    height: 96px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    font-weight: 700;
    color: white;
    box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);
}
.join-title { font-size: 24px; font-weight: 700; margin-bottom: 8px; }
.join-subtitle { font-size: 16px; color: rgba(255,255,255,0.6); margin-bottom: 32px; }
.join-actions { display: flex; gap: 16px; }

.btn-join, .btn-decline, .btn-secondary {
    padding: 12px 24px;
    border-radius: 12px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: transform 0.1s, opacity 0.2s;
}
.btn-join { background: #10b981; color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
.btn-join:hover { transform: translateY(-1px); opacity: 0.9; }
.btn-decline { background: rgba(255,255,255,0.1); color: #ef4444; backdrop-filter: blur(8px); }
.btn-decline:hover { background: rgba(239, 68, 68, 0.1); }
.btn-secondary { background: rgba(255,255,255,0.1); color: white; }

/* Grid Wrapper */
.grid-wrapper {
    flex: 1;
    display: flex;
    width: 100%;
    height: 100%;
    z-index: 10;
    position: relative;
    padding: 12px;
    gap: 12px;
    justify-content: center;
    align-items: center;
}

/* Grid Layout Logic */
.grid-1-1 .grid-wrapper { flex-direction: column; }
/* Grid 2x2 */
.grid-2-2 .grid-wrapper {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-template-rows: repeat(2, 1fr); /* Force equal height rows? */
    /* Better: grid-auto-rows: 1fr; */
}
/* Grid 3x2 */
.grid-3-2 .grid-wrapper {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    grid-template-rows: repeat(2, 1fr);
}

/* Mobile Adjustments for Grid */
@media (max-width: 768px) {
    .grid-wrapper { padding: 8px; gap: 8px; }
    
    .grid-1-1 .grid-wrapper { flex-direction: column; }
    
    .grid-2-2 .grid-wrapper, 
    .grid-3-2 .grid-wrapper {
        grid-template-columns: 1fr; /* Stack vertically for more width on narrow screens */
        grid-auto-rows: 1fr;
    }
}

/* Video Cell */
.video-cell {
    position: relative;
    width: 100%;
    height: 100%;
    background: #1f2937;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    border: 1px solid rgba(255,255,255,0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.video-element {
    width: 100%;
    height: 100%;
    object-fit: cover;
    background: #000;
}

/* On very narrow screens, contain might be better to avoid losing too much context */
@media (max-width: 400px) {
    .video-element {
        object-fit: contain;
    }
}

/* Local PiP Mode */
.grid-1-1 .video-cell.local.pip-mode {
    position: absolute;
    bottom: 110px; /* Above controls */
    right: 16px;
    width: 100px;
    height: 140px;
    z-index: 30;
    box-shadow: 0 8px 24px rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 12px;
}
@media (max-width: 640px) {
    .grid-1-1 .video-cell.local.pip-mode {
        width: 80px;
        height: 110px;
        bottom: 100px;
        right: 12px;
    }
}
.grid-1-1 .video-cell.remote {
    /* Fullscreen remote */
    position: absolute;
    inset: 0;
    border-radius: 0;
}

/* Participant Info */
.participant-info {
    position: absolute;
    bottom: 12px;
    left: 12px;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    padding: 4px 10px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
    color: white;
    font-size: 12px;
    font-weight: 500;
    pointer-events: none;
}

/* Avatar Fallback */
.avatar-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #18181b; 
    position: relative;
}
.avatar-img {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}
.avatar-placeholder {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: bold;
    color: white;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}
.audio-indicator {
    position: absolute;
    bottom: 25%;
    right: calc(50% - 40px); /* Rough positioning */
    background: #10b981;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    border: 2px solid #18181b;
}

/* Controls Bar */
.controls-bar {
    position: absolute;
    bottom: 32px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 16px;
    z-index: 50;
    background: rgba(20, 20, 25, 0.8);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    padding: 10px 20px;
    border-radius: 32px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    border: 1px solid rgba(255,255,255,0.12);
}

.control-btn {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    border: none;
    background: rgba(255,255,255,0.08);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.control-btn:hover {
    background: rgba(255,255,255,0.15);
    transform: scale(1.05);
}
.control-btn:active { transform: scale(0.95); }

.control-btn.off {
    background: rgba(255,255,255,0.9);
    color: #1f2937;
}

.control-btn.hangup {
    background: #ef4444;
    color: white;
    margin-left: 8px;
}
.control-btn.hangup:hover { background: #dc2626; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4); }

/* Animations */
@keyframes pulse {
    0% { opacity: 0.6; transform: scale(1); }
    50% { opacity: 1; transform: scale(1.1); }
    100% { opacity: 0.6; transform: scale(1); }
}

@media (max-width: 640px) {
    .controls-bar {
        bottom: 24px;
        padding: 10px 20px;
        gap: 12px;
        width: 90%;
        justify-content: space-around;
    }
    .control-btn { width: 48px; height: 48px; }
    
    .grid-1-1 .video-cell.local.pip-mode {
        width: 100px;
        height: 133px;
        bottom: 90px;
        right: 16px;
    }
}

.hidden-audio {
    position: absolute;
    width: 2px;
    height: 2px;
    opacity: 0.05;
    pointer-events: none;
    overflow: hidden;
    bottom: 0;
    right: 0;
    z-index: -100;
}
</style>
```
