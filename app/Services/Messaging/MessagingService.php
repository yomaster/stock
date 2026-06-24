<?php

namespace App\Services\Messaging;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * สัญญากลางของ messaging provider — LINE / Telegram implement ตัวนี้
 *
 * รูปแบบข้อความ (normalized) ที่ reply()/pushToUser() รับ:
 *   - string                                    → ข้อความ text เดี่ยว
 *   - array ของ parts:
 *       ['type'=>'text',   'text'=>'...']
 *       ['type'=>'image',  'url'=>'https://...', 'caption'=>'...'(optional)]
 *       ['type'=>'buttons','text'=>'...', 'options'=>[['label'=>'..','text'=>'..'], ...]]
 * แต่ละ service แปลง normalized → API ของตัวเอง
 */
interface MessagingService
{
    /** ชื่อ provider: 'line' | 'telegram' */
    public function provider(): string;

    /** ตรวจว่า webhook นี้มาจาก provider จริง (signature/secret) */
    public function verifyWebhook(Request $request): bool;

    /**
     * แปลง payload ของ webhook เป็น BotEvent[] ที่ normalize แล้ว
     * @return BotEvent[]
     */
    public function parseEvents(Request $request): array;

    /** ตอบกลับไปยัง conversation (replyContext = replyToken[LINE]/chat_id[TG]) */
    public function reply(string $replyContext, string|array $messages): bool;

    /** ส่งเชิงรุกหา user ที่ผูกบัญชีไว้ (สรุป/แจ้งเตือน) — false ถ้ายังไม่ผูกกับ provider นี้ */
    public function pushToUser(User $user, string|array $messages): bool;

    /** แสดง indicator กำลังพิมพ์/โหลด ระหว่างรอประมวลผล */
    public function startTyping(string $chatId): void;
}
