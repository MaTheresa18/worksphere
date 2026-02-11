import { BaseService } from './base.service';

export class VideoCallService extends BaseService {
  /**
   * Fetch TURN/STUN ICE server credentials for a chat.
   */
  async getTurnCredentials(chatId: string): Promise<{ ice_servers: RTCIceServer[] }> {
    const response = await this.api.get(`/api/chat/${chatId}/call/turn-credentials`);
    return response.data;
  }

  /**
   * Initiate a call (notifies the other participant via broadcast).
   */
  async initiateCall(chatId: string, callType: 'video' | 'audio'): Promise<{ call_id: string; chat_id: string }> {
    const response = await this.api.post(`/api/chat/${chatId}/call/initiate`, {
      call_type: callType,
    });
    return response.data;
  }

  /**
   * Send a WebRTC signal (offer/answer/ice-candidate).
   */
  async sendSignal(
    chatId: string,
    callId: string,
    signalType: 'offer' | 'answer' | 'ice-candidate' | 'signal',
    signalData: Record<string, unknown>,
  ): Promise<void> {
    await this.api.post(`/api/chat/${chatId}/call/signal`, {
      call_id: callId,
      signal_type: signalType,
      signal_data: signalData,
    });
  }

  /**
   * End a call.
   */
  async endCall(
    chatId: string,
    callId: string,
    reason: 'hangup' | 'declined' | 'timeout' | 'failed' = 'hangup',
  ): Promise<void> {
    await this.api.post(`/api/chat/${chatId}/call/end`, {
      call_id: callId,
      reason,
    });
  }
}

export const videoCallService = new VideoCallService();
