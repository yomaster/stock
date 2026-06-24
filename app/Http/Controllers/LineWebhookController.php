<?php

namespace App\Http\Controllers;

use App\Services\BotCommandService;
use App\Services\Messaging\LineMessagingService;
use Illuminate\Http\Request;

/**
 * Webhook ของ LINE — thin: verify + parse + ส่งต่อให้ BotCommandService
 * logic คำสั่งทั้งหมดอยู่ใน BotCommandService (ใช้ร่วมกับ Telegram)
 */
class LineWebhookController extends Controller
{
    public function __construct(
        private LineMessagingService $line,
        private BotCommandService $bot,
    ) {}

    public function handle(Request $request)
    {
        // ตรวจลายเซ็นก่อนเสมอ — กัน request ปลอม
        if (!$this->line->verifyWebhook($request)) {
            return response('Invalid signature', 403);
        }

        foreach ($this->line->parseEvents($request) as $event) {
            $this->bot->handle($this->line, $event);
        }

        // ตอบ 200 เร็วเสมอ (งานหนักทำใน defer)
        return response('OK', 200);
    }
}
