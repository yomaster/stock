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

        $generationConfig = array_merge([
            'temperature' => 0.2, // ตั้งอุณหภูมิต่ำเพื่อให้ได้ข้อมูลที่วิเคราะห์อย่างมีเหตุผลและไม่จินตนาการมากเกินไป
            // ⚠️ thinking model (เช่น gemini-2.5-pro) ใช้ token ไปกับการ "คิด" ด้วย — ตั้งต่ำไปจะโดน
            //    truncate (finishReason=MAX_TOKENS) แล้วคืน text ว่าง → ต้องเผื่อ budget ให้พอ
            'maxOutputTokens' => 4096,
        ], $config);

        // ⚠️ ปิด thinking สำหรับ model ที่ไม่ใช่ pro (flash/flash-lite)
        //    flash รุ่น 2.5+ เปิด thinking เป็น default → กิน token จน text ว่าง (เหมือนที่ flash-lite เคยใช้ได้
        //    เพราะ thinking ปิดอยู่) ตั้ง thinkingBudget=0 ให้ flash ทำงานเหมือน flash-lite: เร็ว ถูก ไม่ truncate
        //    (pro ตั้ง 0 ไม่ได้ — minimum budget > 0 จะ error 400 จึงคง thinking ไว้ + พึ่ง maxOutputTokens 4096)
        if (!str_contains($this->model, 'pro')) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }

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
                'generationConfig' => $generationConfig,
            ]);

            $this->lastStatus = $response->status();

            if ($response->failed()) {
                Log::error('Gemini API Request failed: ' . $response->body());
                return null;
            }

            $data = $response->json();
            $candidate = $data['candidates'][0] ?? null;

            // รวม text จากทุก part (thinking model อาจส่งหลาย part) ข้าม part ที่เป็น thought
            $text = '';
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                if (isset($part['text']) && empty($part['thought'])) {
                    $text .= $part['text'];
                }
            }

            if ($text === '') {
                // text ว่าง = มักโดน truncate (thinking model กิน token หมด) — log สาเหตุไว้ดีบัก
                $finish = $candidate['finishReason'] ?? 'unknown';
                Log::warning("Gemini empty text (finishReason={$finish}, model={$this->model}): " . substr($response->body(), 0, 800));
                return null;
            }

            return $text;

        } catch (\Exception $e) {
            Log::error('Gemini Service Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * ส่ง prompt + รูปภาพ (Vision) ให้ Gemini อ่าน — ใช้ OCR/แยกข้อมูลจากภาพ
     *
     * @param string $prompt
     * @param array  $images  [['mime'=>'image/png','data'=>'<base64>'], ...]
     * @param array  $config
     * @return string|null
     */
    public function generateFromImages(string $prompt, array $images, array $config = []): ?string
    {
        if (empty($this->apiKey)) {
            Log::warning('Gemini API Key is not set.');
            return null;
        }
        if (empty($images)) {
            return null;
        }

        $url = $this->baseUrl . $this->model . ':generateContent?key=' . $this->apiKey;
        $this->lastStatus = null;

        // parts: ข้อความ + รูปภาพแต่ละใบ (inline_data base64)
        $parts = [['text' => $prompt]];
        foreach ($images as $img) {
            $parts[] = ['inline_data' => ['mime_type' => $img['mime'], 'data' => $img['data']]];
        }

        $generationConfig = array_merge([
            'temperature'     => 0.1,  // OCR ต้องการความเที่ยง ไม่จินตนาการ
            'maxOutputTokens' => 8192, // JSON อาจหลายแถว (หลายภาพ)
        ], $config);
        if (!str_contains($this->model, 'pro')) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        try {
            $response = Http::timeout(120)->withHeaders(['Content-Type' => 'application/json'])
                ->post($url, [
                    'contents'         => [['parts' => $parts]],
                    'generationConfig' => $generationConfig,
                ]);

            $this->lastStatus = $response->status();

            if ($response->failed()) {
                Log::error('Gemini Vision failed: ' . $response->body());
                return null;
            }

            $candidate = $response->json('candidates.0');
            $text = '';
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                if (isset($part['text']) && empty($part['thought'])) {
                    $text .= $part['text'];
                }
            }
            if ($text === '') {
                $finish = $candidate['finishReason'] ?? 'unknown';
                Log::warning("Gemini Vision empty text (finishReason={$finish}, model={$this->model})");
                return null;
            }
            return $text;
        } catch (\Throwable $e) {
            Log::error('Gemini Vision error: ' . $e->getMessage());
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
