<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected $apiKey;
    protected $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';
    protected $model;

    /** HTTP status ของ call ล่าสุด (429 = โควต้าหมด) ให้ caller เช็คได้ */
    public ?int $lastStatus = null;

    public function __construct()
    {
        $settings = app(SettingsService::class);
        $this->apiKey = $settings->get('gemini.api_key');
        // default = analysis model (สำหรับ /ask) — SendSummary ใช้ useSummaryModel()
        $this->model = $settings->get('gemini.model_analysis', 'gemini-2.5-flash-lite');
    }

    /**
     * คืน instance ใหม่ที่สลับไปใช้ summary model (Flash Lite)
     * ใช้ใน SendSummary ที่ต้องการประหยัด token/RPM
     */
    public function useSummaryModel(): static
    {
        $clone = clone $this;
        $clone->model = app(SettingsService::class)->get('gemini.model_summary', 'gemini-2.5-flash-lite');
        return $clone;
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
        $this->lastStatus = null;

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

            $this->lastStatus = $response->status();

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
