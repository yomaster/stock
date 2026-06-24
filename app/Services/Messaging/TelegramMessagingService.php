<?php

namespace App\Services\Messaging;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram Bot API provider
 * - ส่งข้อความผ่าน sendMessage/sendPhoto ฟรีไม่จำกัด (ไม่มีแยก push/reply)
 * - parseEvents: Telegram ส่ง update เดี่ยว; /start = follow (ส่ง welcome)
 */
class TelegramMessagingService implements MessagingService
{
    public function __construct(private SettingsService $settings) {}

    public function provider(): string
    {
        return 'telegram';
    }

    // ───────────────────────── webhook ─────────────────────────

    public function verifyWebhook(Request $request): bool
    {
        // ถ้าตั้ง secret ไว้ → ต้องตรงกับ header; ถ้าไม่ตั้ง → ยอมรับ (แนะนำให้ตั้ง)
        $secret = $this->settings->get('telegram.webhook_secret');
        if (!$secret) {
            return true;
        }
        return hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    public function parseEvents(Request $request): array
    {
        $msg = $request->input('message') ?? $request->input('edited_message');
        if (!$msg) {
            return [];
        }

        $chatId = (string) ($msg['chat']['id'] ?? '');
        $text   = trim($msg['text'] ?? '');
        if ($chatId === '' || $text === '') {
            return [];
        }

        // /start = แอดบอทครั้งแรก → welcome (เทียบเท่า follow ของ LINE)
        if ($text === '/start' || str_starts_with($text, '/start ')) {
            return [new BotEvent('follow', null, $chatId, $chatId)];
        }

        return [new BotEvent('message', $text, $chatId, $chatId)];
    }

    // ───────────────────────── ส่งข้อความ ─────────────────────────

    public function reply(string $replyContext, string|array $messages): bool
    {
        return $this->sendParts($replyContext, $messages);
    }

    public function pushToUser(User $user, string|array $messages): bool
    {
        if ($user->messaging_provider !== 'telegram' || !$user->messaging_chat_id) {
            return false;
        }
        return $this->sendParts($user->messaging_chat_id, $messages);
    }

    public function startTyping(string $chatId): void
    {
        $this->api('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function sendParts(string $chatId, string|array $messages): bool
    {
        $ok = true;
        foreach ($this->toParts($messages) as $part) {
            $ok = $this->sendPart($chatId, $part) && $ok;
        }
        return $ok;
    }

    private function sendPart(string $chatId, array $part): bool
    {
        return match ($part['type']) {
            'image' => $this->api('sendPhoto', [
                'chat_id' => $chatId,
                'photo'   => $part['url'],
                'caption' => $part['caption'] ?? null,
            ]),
            'buttons' => $this->api('sendMessage', [
                'chat_id'      => $chatId,
                'text'         => $part['text'],
                // reply keyboard: ปุ่มส่ง text ของตัวเองเป็นข้อความ (label=text บน Telegram)
                'reply_markup' => [
                    'keyboard'        => array_map(fn ($o) => [['text' => $o['text']]], $part['options']),
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                ],
            ]),
            default => $this->api('sendMessage', ['chat_id' => $chatId, 'text' => $part['text']]),
        };
    }

    private function toParts(string|array $m): array
    {
        if (is_string($m)) {
            return [['type' => 'text', 'text' => $m]];
        }
        return isset($m['type']) ? [$m] : $m;
    }

    private function api(string $method, array $params): bool
    {
        $token = $this->settings->get('telegram.bot_token');
        if (!$token) {
            Log::warning('Telegram: ยังไม่ได้ตั้ง bot_token');
            return false;
        }
        try {
            $response = Http::asJson()->post(
                "https://api.telegram.org/bot{$token}/{$method}",
                array_filter($params, fn ($v) => $v !== null)
            );
            if ($response->failed() || !$response->json('ok')) {
                Log::error('Telegram API failed: ' . $response->status() . ' ' . $response->body());
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram Service error: ' . $e->getMessage());
            return false;
        }
    }
}
