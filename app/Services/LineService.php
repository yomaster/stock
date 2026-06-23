<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LineService
{
    private string $replyUrl = 'https://api.line.me/v2/bot/message/reply';
    private string $pushUrl  = 'https://api.line.me/v2/bot/message/push';

    public function __construct(private SettingsService $settings) {}

    private function token(): ?string
    {
        return $this->settings->get('line.channel_access_token');
    }

    private function secret(): ?string
    {
        return $this->settings->get('line.channel_secret');
    }

    /**
     * ตรวจลายเซ็น webhook (X-Line-Signature) ด้วย Channel Secret
     * ป้องกัน request ปลอมที่ไม่ได้มาจาก LINE
     */
    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = $this->secret();
        if (!$secret || !$signature) {
            return false;
        }

        $hash = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($hash, $signature);
    }

    /**
     * ตอบกลับข้อความด้วย replyToken (ใช้ได้ครั้งเดียว ภายในไม่กี่วินาที)
     */
    public function reply(string $replyToken, string|array $messages): bool
    {
        return $this->send($this->replyUrl, [
            'replyToken' => $replyToken,
            'messages'   => $this->normalizeMessages($messages),
        ]);
    }

    /**
     * ส่งข้อความเชิงรุก (push) ไปยัง user/group — ใช้กับรายงานสรุป
     */
    public function push(string $to, string|array $messages): bool
    {
        return $this->send($this->pushUrl, [
            'to'       => $to,
            'messages' => $this->normalizeMessages($messages),
        ]);
    }

    /**
     * แสดง loading animation (จุดเด้งๆ) ในแชท 1:1 ระหว่างรอประมวลผล
     * loadingSeconds ต้องเป็นจำนวนเท่าของ 5 และไม่เกิน 60
     */
    public function startLoading(string $userId, int $seconds = 60): bool
    {
        $token = $this->token();
        if (!$token) {
            return false;
        }
        $seconds = max(5, min(60, (int) (round($seconds / 5) * 5)));

        try {
            $response = Http::withToken($token)->asJson()
                ->post('https://api.line.me/v2/bot/chat/loading/start', [
                    'chatId'         => $userId,
                    'loadingSeconds' => $seconds,
                ]);
            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('LINE loading error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * ส่งหาผู้ใช้ที่ผูก LINE ไว้ (per-user push สำหรับแจ้งเตือน/สรุป)
     * คืน false ถ้า user ยังไม่ผูก LINE
     */
    public function pushToUser(\App\Models\User $user, string|array $messages): bool
    {
        if (!$user->line_user_id) {
            return false;
        }
        return $this->push($user->line_user_id, $messages);
    }

    /**
     * แปลง string เป็น message object และจำกัดไม่เกิน 5 ข้อความ (ลิมิตของ LINE)
     */
    private function normalizeMessages(string|array $messages): array
    {
        if (is_string($messages)) {
            $messages = [['type' => 'text', 'text' => $messages]];
        } elseif (isset($messages['type'])) {
            // ส่ง message object เดี่ยวมา
            $messages = [$messages];
        }

        return array_slice($messages, 0, 5);
    }

    private function send(string $url, array $payload): bool
    {
        $token = $this->token();
        if (!$token) {
            Log::warning('LINE: ยังไม่ได้ตั้ง channel_access_token');
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->asJson()
                ->post($url, $payload);

            if ($response->failed()) {
                Log::error('LINE API failed: ' . $response->status() . ' ' . $response->body());
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('LINE Service error: ' . $e->getMessage());
            return false;
        }
    }
}
