<?php

namespace App\Services\Messaging;

/**
 * Event ที่ normalize แล้วจาก webhook ของ provider ใดก็ได้ (LINE/Telegram)
 * — BotCommandService ทำงานบนตัวนี้โดยไม่ต้องรู้ว่ามาจาก provider ไหน
 */
class BotEvent
{
    public function __construct(
        public string $type,          // 'message' | 'follow'
        public ?string $text,         // ข้อความ (เฉพาะ type=message)
        public string $chatId,        // id ของ conversation/user (ใช้ startTyping + จับคู่บัญชี)
        public string $replyContext,  // LINE: replyToken / Telegram: chat_id (ใช้ตอบกลับ)
    ) {}
}
