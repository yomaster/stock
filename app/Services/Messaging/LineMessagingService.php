<?php

namespace App\Services\Messaging;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LINE Messaging API provider (refactor จาก LineService เดิม)
 * - reply ใช้ replyToken (ฟรี), push ใช้ chat id (หักโควตา)
 */
class LineMessagingService implements MessagingService
{
    private string $replyUrl = 'https://api.line.me/v2/bot/message/reply';
    private string $pushUrl  = 'https://api.line.me/v2/bot/message/push';

    public function __construct(private SettingsService $settings) {}

    public function provider(): string
    {
        return 'line';
    }

    private function token(): ?string
    {
        return $this->settings->get('line.channel_access_token');
    }

    // ───────────────────────── webhook ─────────────────────────

    public function verifyWebhook(Request $request): bool
    {
        $secret = $this->settings->get('line.channel_secret');
        $signature = $request->header('X-Line-Signature');
        if (!$secret || !$signature) {
            return false;
        }
        $hash = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));
        return hash_equals($hash, $signature);
    }

    public function parseEvents(Request $request): array
    {
        $events = [];
        foreach ($request->input('events', []) as $e) {
            $src    = $e['source'] ?? [];
            $chatId = $src['userId'] ?? $src['groupId'] ?? $src['roomId'] ?? null;
            $type   = $e['type'] ?? '';

            if ($type === 'follow' && $chatId) {
                $events[] = new BotEvent('follow', null, $chatId, $e['replyToken'] ?? '');
            } elseif ($type === 'message' && ($e['message']['type'] ?? '') === 'text' && $chatId) {
                $events[] = new BotEvent('message', trim($e['message']['text'] ?? ''), $chatId, $e['replyToken'] ?? '');
            }
        }
        return $events;
    }

    // ───────────────────────── ส่งข้อความ ─────────────────────────

    public function reply(string $replyContext, string|array $messages): bool
    {
        return $this->send($this->replyUrl, [
            'replyToken' => $replyContext,
            'messages'   => $this->renderMessages($messages),
        ]);
    }

    public function pushToUser(User $user, string|array $messages): bool
    {
        // ส่งเฉพาะ user ที่ผูกกับ provider นี้
        if ($user->messaging_provider !== 'line' || !$user->messaging_chat_id) {
            return false;
        }
        return $this->send($this->pushUrl, [
            'to'       => $user->messaging_chat_id,
            'messages' => $this->renderMessages($messages),
        ]);
    }

    public function startTyping(string $chatId): void
    {
        $token = $this->token();
        if (!$token) {
            return;
        }
        try {
            Http::withToken($token)->asJson()
                ->post('https://api.line.me/v2/bot/chat/loading/start', [
                    'chatId'         => $chatId,
                    'loadingSeconds' => 60,
                ]);
        } catch (\Throwable $e) {
            Log::error('LINE loading error: ' . $e->getMessage());
        }
    }

    // ───────────────────────── helpers ─────────────────────────

    /** normalized parts → LINE message objects (จำกัด 5 ตามลิมิต LINE) */
    private function renderMessages(string|array $messages): array
    {
        $parts = $this->toParts($messages);
        return array_slice(array_map(fn ($p) => $this->lineMessage($p), $parts), 0, 5);
    }

    private function toParts(string|array $m): array
    {
        if (is_string($m)) {
            return [['type' => 'text', 'text' => $m]];
        }
        return isset($m['type']) ? [$m] : $m; // single part | list of parts
    }

    private function lineMessage(array $part): array
    {
        return match ($part['type']) {
            'image' => [
                'type'              => 'image',
                'originalContentUrl' => $part['url'],
                'previewImageUrl'   => $part['url'],
            ],
            'buttons' => [
                'type'       => 'text',
                'text'       => $part['text'],
                'quickReply' => ['items' => array_map(fn ($o) => [
                    'type'   => 'action',
                    'action' => ['type' => 'message', 'label' => mb_substr($o['label'], 0, 20), 'text' => $o['text']],
                ], $part['options'])],
            ],
            default => ['type' => 'text', 'text' => $part['text']],
        };
    }

    private function send(string $url, array $payload): bool
    {
        $token = $this->token();
        if (!$token) {
            Log::warning('LINE: ยังไม่ได้ตั้ง channel_access_token');
            return false;
        }
        try {
            $response = Http::withToken($token)->asJson()->post($url, $payload);
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
