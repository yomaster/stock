<?php

namespace App\Http\Controllers;

use App\Services\BotCommandService;
use App\Services\Messaging\TelegramMessagingService;
use Illuminate\Http\Request;

/**
 * Webhook ของ Telegram — thin: verify + parse + ส่งต่อให้ BotCommandService
 * (logic คำสั่งใช้ร่วมกับ LINE)
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        private TelegramMessagingService $telegram,
        private BotCommandService $bot,
    ) {}

    public function handle(Request $request)
    {
        if (!$this->telegram->verifyWebhook($request)) {
            return response('Forbidden', 403);
        }

        foreach ($this->telegram->parseEvents($request) as $event) {
            $this->bot->handle($this->telegram, $event);
        }

        return response('OK', 200);
    }
}
