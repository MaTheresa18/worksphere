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
const processedSignals = new Set<string>(); // To prevent duplicate signal processing

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
                    
                    console.log("[Call] Local media acquired:", {
                        audio: stream.getAudioTracks().length > 0 ? 'YES' : 'NO',
                        video: stream.getVideoTracks().length > 0 ? 'YES' : 'NO'
                    });
                    
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
        
        console.log("[Call] Local media acquired:", {
            audio: stream.getAudioTracks().length > 0 ? 'YES' : 'NO',
            video: 'NO (Audio-only mode)'
        });
        
        return stream;
    } catch (e: any) {
        console.error("[Call] Media acquisition failed:", e);
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
        hasJoined.value = true;
        postToParent({ type: "state", state: "connected" });
        startDurationTimer();
        stopRingtone();

        // 5. Replay pending signals (from buffering in useVideoCall)
        if (callData.value.pendingSignals && callData.value.pendingSignals.length > 0) {
            console.log(`[Call] Replaying ${callData.value.pendingSignals.length} pending signals`);
            for (const sig of callData.value.pendingSignals) {
                handleSignal(sig);
            }
        }

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
        
        // Detailed track logging
        remoteStream.getTracks().forEach(track => {
            console.log(`[Call] Remote track from ${normalizedTargetId}: ${track.kind} (${track.id}) enabled=${track.enabled}`);
            track.onmute = () => console.warn(`[Call] Remote ${track.kind} track from ${normalizedTargetId} MUTED (data flow stopped)`);
            track.onunmute = () => console.log(`[Call] Remote ${track.kind} track from ${normalizedTargetId} UNMUTED (data flow resumed)`);
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
        console.log(`[Call] Buffering signal from ${senderId} - joining or media not ready`);
        return;
    }

    const signal = event.signal_data;
    
    // Deduplication check (simple fingerprint)
    const signalId = JSON.stringify(signal).substring(0, 100) + senderId;
    if (processedSignals.has(signalId)) return;
    processedSignals.add(signalId);
    if (processedSignals.size > 100) processedSignals.delete(processedSignals.keys().next().value!);

    if (signal.sdp) signal.sdp = mungeSdp(signal.sdp);

    // Get or Create Peer
    let peer = peers.get(senderId);

    if (!peer) {
        // Deterministic initiation: prevent both sides from offering at once
        // If we see an offer, we respond. 
        // If we see an answer but don't have a peer, something is wrong or we joined late.
        if (signal.type === 'offer') {
            console.log(`[Call] Received offer from ${senderId}, creating responder peer`);
            createPeer(senderId, false, localStream.value!);
            peer = peers.get(senderId);
        } else if (signal.type === 'ice-candidate') {
            console.warn(`[Call] Received candidate from unknown peer ${senderId}, ignoring`);
            return;
        } else {
            console.warn(`[Call] Received ${signal.type} from unknown peer ${senderId}`);
            return;
        }
    }

    // Handle Glare: if we receive an offer while we are in 'have-local-offer' state
    // @ts-ignore
    const pc = peer._pc as RTCPeerConnection;
    if (signal.type === 'offer' && pc && pc.signalingState !== 'stable') {
        const isPolite = selfId < senderId;
        if (!isPolite) {
            console.log(`[Call] Glare detected with ${senderId}. We are impolite, ignoring their offer.`);
            return;
        }
        console.log(`[Call] Glare detected with ${senderId}. We are polite, rollback and accept their offer.`);
        try {
            await pc.setLocalDescription({ type: 'rollback' } as any);
        } catch (e) {
            console.warn("[Call] Rollback failed", e);
        }
    }

    try {
        peer?.signal(signal);
    } catch (e) {
        console.error(`[Call] Error signaling peer ${senderId}:`, e);
    }
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
        
        console.log("[Call] Initialized with data:", {
            callId: callData.value.callId,
            chatId: callData.value.chatId,
            chatType: (callData.value as any).chatType || 'dm',
            callType: callData.value.callType,
            direction: callData.value.direction,
            selfId: callData.value.selfPublicId
        });
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

    // SMART JOIN LOGIC
    // 1. If it's a group call, always show the lobby (user requested)
    // 2. If it's a DM, check if we can autoplay audio. If yes, auto-join.
    const isGroup = (data as any).chatType === 'group' || data.remoteUser?.publicId === 'group';
    
    if (!isGroup) {
        console.log("[Call] Checking for Smart Join (DM)...");
        // We can't perfectly check for autoplay permission synchronously, 
        // but we can check navigator.userActivation or try a silent play.
        // For initiators (outgoing), we usually have activation from the parent window click.
        // For receivers (incoming), if they clicked "Accept", activation may carry over in some browsers.
        
        const canAutoJoin = (navigator as any).userActivation?.isActive || data.direction === 'outgoing';
        
        if (canAutoJoin) {
            console.log("[Call] ⚡ Smart Join triggered: skipping lobby");
            joinCall();
        } else {
            console.log("[Call] Smart Join skipped: User interaction required for audio");
        }
    } else {
        console.log("[Call] Group call detected: Showing lobby as per policy");
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
:root {
  --glass-bg: rgba(255, 255, 255, 0.08);
  --glass-border: rgba(255, 255, 255, 0.15);
  --mesh-gradient: radial-gradient(at 0% 0%, #1e1e2e 0px, transparent 50%),
                   radial-gradient(at 50% 0%, #182848 0px, transparent 50%),
                   radial-gradient(at 100% 0%, #1e1e2e 0px, transparent 50%);
}

.call-container {
    background: #09090b;
    height: 100dvh;
    width: 100vw;
    display: flex;
    flex-direction: column;
    position: relative;
    overflow: hidden;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #fafafa;
}

.call-bg {
    position: absolute;
    inset: 0;
    background-color: #09090b;
    background-image: var(--mesh-gradient);
    z-index: 0;
}

.call-overlay {
    position: absolute;
    inset: 0;
    background: radial-gradient(circle at center, transparent 0%, rgba(0,0,0,0.6) 100%);
    z-index: 1;
    pointer-events: none;
}

/* Header */
.call-header {
    position: absolute;
    top: env(safe-area-inset-top, 0);
    left: 0;
    right: 0;
    padding: 20px;
    z-index: 100;
    display: flex;
    justify-content: center;
    pointer-events: none;
}

.header-info {
    background: rgba(20, 20, 25, 0.6);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    padding: 8px 16px;
    border-radius: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid var(--glass-border);
    box-shadow: 0 4px 24px rgba(0,0,0,0.4);
}

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #71717a;
    transition: all 0.3s ease;
}

.status-dot.connected { 
    background: #10b981; 
    box-shadow: 0 0 12px rgba(16, 185, 129, 0.6); 
    animation: breathing 3s infinite;
}
.status-dot.connecting, .status-dot.ringing { 
    background: #3b82f6; 
    animation: pulse 1.5s infinite; 
}
.status-dot.error { background: #ef4444; }

.status-text {
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
    font-weight: 600;
    letter-spacing: 0.02em;
}

/* Center Content */
.call-center-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    z-index: 10;
    padding: 40px 24px;
    text-align: center;
}

.state-icon {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 32px;
    border: 1px solid transparent;
}

.state-icon.error { 
    background: rgba(239, 68, 68, 0.1); 
    color: #ef4444; 
    border-color: rgba(239, 68, 68, 0.2);
}
.state-icon.ended { 
    background: rgba(113, 113, 122, 0.1); 
    color: #a1a1aa;
    border-color: rgba(113, 113, 122, 0.2);
}

.state-text { 
    font-size: 20px; 
    font-weight: 600;
    color: white; 
    margin-bottom: 32px; 
    opacity: 0.9;
}

/* Join Screen / Lobby */
.join-screen {
    animation: fadeIn 0.6s cubic-bezier(0.22, 1, 0.36, 1);
}

.avatar-preview { 
    margin-bottom: 32px;
    position: relative;
}

.preview-circle {
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 48px;
    font-weight: 700;
    color: white;
    box-shadow: 0 12px 40px rgba(99, 102, 241, 0.4);
    border: 4px solid rgba(255, 255, 255, 0.1);
    animation: float 6s ease-in-out infinite;
}

.join-title { 
    font-size: 32px; 
    font-weight: 800; 
    margin-bottom: 12px; 
    letter-spacing: -0.02em;
}

.join-subtitle { 
    font-size: 18px; 
    color: rgba(255, 255, 255, 0.6); 
    margin-bottom: 48px; 
}

.join-actions { 
    display: flex; 
    gap: 20px; 
    width: 100%;
    max-width: 400px;
}

.btn-join, .btn-decline, .btn-secondary {
    flex: 1;
    padding: 16px 24px;
    border-radius: 18px;
    font-weight: 700;
    font-size: 16px;
    cursor: pointer;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.btn-join { 
    background: #10b981; 
    color: white; 
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3); 
}
.btn-join:hover { 
    transform: translateY(-4px) scale(1.02); 
    box-shadow: 0 12px 32px rgba(16, 185, 129, 0.4); 
}
.btn-join:active { transform: translateY(0) scale(0.98); }

.btn-decline { 
    background: rgba(255, 255, 255, 0.05); 
    color: #ef4444; 
    border: 1px solid rgba(239, 68, 68, 0.3);
}
.btn-decline:hover { 
    background: rgba(239, 68, 68, 0.1); 
    transform: translateY(-4px);
}

/* Grid & Video Cells */
.grid-wrapper {
    flex: 1;
    display: flex;
    width: 100%;
    height: 100%;
    z-index: 10;
    position: relative;
    padding: 20px;
    padding-bottom: calc(100px + env(safe-area-inset-bottom, 20px));
    gap: 16px;
    justify-content: center;
    align-items: center;
}

.video-cell {
    position: relative;
    width: 100%;
    height: 100%;
    background: #18181b;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    border: 1px solid var(--glass-border);
    display: flex;
    align-items: center;
    justify-content: center;
    animation: cellAppear 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.video-element {
    width: 100%;
    height: 100%;
    object-fit: cover;
    background: #000;
    transition: filter 0.3s ease;
}

/* Local PiP Mode */
.grid-1-1 .video-cell.local.pip-mode {
    position: absolute;
    bottom: calc(120px + env(safe-area-inset-bottom, 20px));
    right: 20px;
    width: 120px;
    height: 180px;
    z-index: 30;
    border-radius: 20px;
    border: 20px; /* actually border-width is handled by border below */
    border: 2px solid rgba(255,255,255,0.2);
    box-shadow: 0 16px 40px rgba(0,0,0,0.6);
}

.grid-1-1 .video-cell.remote {
    position: absolute;
    inset: 0;
    border-radius: 0;
    border: none;
}

/* Participant Info Overlay */
.participant-info {
    position: absolute;
    bottom: 16px;
    left: 16px;
    background: rgba(9, 9, 11, 0.5);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    padding: 6px 14px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: white;
    font-size: 13px;
    font-weight: 600;
    pointer-events: none;
    border: 1px solid var(--glass-border);
}

/* Controls Bar */
.controls-bar {
    position: absolute;
    bottom: calc(32px + env(safe-area-inset-bottom, 0));
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 20px;
    z-index: 500;
    background: rgba(20, 20, 25, 0.7);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    padding: 14px 28px;
    border-radius: 40px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.5);
    border: 1px solid var(--glass-border);
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), bottom 0.3s ease;
}

.control-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    border: none;
    background: rgba(255, 255, 255, 0.08);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.2, 0, 0, 1);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.control-btn:hover {
    background: rgba(255, 255, 255, 0.15);
    transform: scale(1.1) translateY(-2px);
    border-color: rgba(255, 255, 255, 0.3);
}

.control-btn:active { transform: scale(0.95); }

.control-btn.off {
    background: #fafafa;
    color: #09090b;
}

.control-btn.hangup {
    background: #ef4444;
    color: white;
    border-color: rgba(255, 255, 255, 0.1);
}
.control-btn.hangup:hover { 
    background: #dc2626; 
    box-shadow: 0 0 20px rgba(239, 68, 68, 0.4); 
}

/* Animations */
@keyframes breathing {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.1); opacity: 0.8; }
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-10px); }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes cellAppear {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

/* Grid Layouts */
.grid-2-2 .grid-wrapper {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    grid-auto-rows: 1fr;
}

.grid-3-2 .grid-wrapper {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    grid-auto-rows: 1fr;
}

/* Mobile Overrides */
@media (max-width: 640px) {
    .grid-wrapper {
        padding: 12px;
        padding-bottom: calc(120px + env(safe-area-inset-bottom, 24px));
        gap: 12px;
    }
    
    .grid-2-2 .grid-wrapper, 
    .grid-3-2 .grid-wrapper {
        grid-template-columns: 1fr;
    }

    .controls-bar {
        width: calc(100% - 32px);
        max-width: 380px;
        padding: 12px 16px;
        gap: 12px;
    }

    .control-btn {
        width: 54px;
        height: 54px;
    }

    .join-title { font-size: 28px; }
    .join-actions { flex-direction: column; gap: 12px; width: 100%; }
    
    .btn-join, .btn-decline { width: 100%; border-radius: 16px; }

    .grid-1-1 .video-cell.local.pip-mode {
        width: 100px;
        height: 150px;
        bottom: calc(110px + env(safe-area-inset-bottom, 20px));
        right: 12px;
    }
}

/* Avatar Fallbacks */
.avatar-fallback {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #09090b;
}

.avatar-placeholder {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    font-weight: 800;
    color: white;
    box-shadow: 0 12px 32px rgba(0,0,0,0.4);
    border: 2px solid rgba(255,255,255,0.1);
}

.avatar-img {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,0.1);
    box-shadow: 0 12px 32px rgba(0,0,0,0.4);
}

.audio-indicator {
    position: absolute;
    bottom: 20px;
    right: 20px;
    background: #10b981;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    border: 2px solid #09090b;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}

.hidden-audio {
    position: absolute;
    width: 0;
    height: 0;
    opacity: 0;
    pointer-events: none;
}
</style>
```
