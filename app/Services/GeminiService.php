<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected $apiKey;
    protected $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';
    protected $model;

    public function __construct()
    {
        // อ่านจาก SettingsService (DB) ก่อน → fallback ไป config()/.env อัตโนมัติ
        $settings = app(SettingsService::class);
        $this->apiKey = $settings->get('gemini.api_key');
        $this->model  = $settings->get('gemini.model', 'gemini-2.5-flash');
    }

    /**
     * ส่งคำสั่ง (Prompt) ไปประมวลผลที่ Gemini API
     *
     * @param string $prompt
     * @param array $config
     * @return string|null
     */
    public function generateText(string $prompt, array $config = []): ?string
    {
        if (empty($this->apiKey)) {
            Log::warning('Gemini API Key is not set in environment.');
            return 'ERROR: Gemini API Key is missing. Please set GEMINI_API_KEY in your .env file.';
        }

        $url = $this->baseUrl . $this->model . ':generateContent?key=' . $this->apiKey;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => array_merge([
                    'temperature' => 0.2, // ตั้งอุณหภูมิต่ำเพื่อให้ได้ข้อมูลที่วิเคราะห์อย่างมีเหตุผลและไม่จินตนาการมากเกินไป
                    'maxOutputTokens' => 2048,
                ], $config)
            ]);

            if ($response->failed()) {
                Log::error('Gemini API Request failed: ' . $response->body());
                return null;
            }

            $data = $response->json();
            
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        } catch (\Exception $e) {
            Log::error('Gemini Service Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * เปลี่ยนโมเดลที่ใช้ (เช่น เป็น gemini-1.5-pro หากต้องการประมวลผลซับซ้อนขึ้น)
     */
    public function setModel(string $model)
    {
        $this->model = $model;
        return $this;
    }
}
