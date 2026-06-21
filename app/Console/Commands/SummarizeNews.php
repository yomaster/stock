<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Services\GeminiService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:summarize-news {--limit=30 : จำนวนข่าวสูงสุดที่จะแปลต่อรอบ} {--batch=15 : จำนวนข่าวต่อ 1 request (ยิ่งมากยิ่งประหยัด token)}')]
#[Description('แปล/สรุปข่าวเป็นภาษาไทยด้วย Gemini AI (batch หลายข่าวต่อ request เพื่อประหยัด token)')]
class SummarizeNews extends Command
{
    public function handle(GeminiService $gemini): int
    {
        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch');

        // ดึงเฉพาะข่าวที่ยังไม่ได้แปล (title_th = null)
        $news = News::whereNull('title_th')
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();

        if ($news->isEmpty()) {
            $this->info('ไม่มีข่าวที่ต้องแปล — ข่าวทั้งหมดมีภาษาไทยแล้ว');
            return self::SUCCESS;
        }

        $this->info("กำลังแปล {$news->count()} ข่าว (batch ละ {$batchSize} ข่าว)...");
        $totalDone = 0;

        // แบ่งเป็น batch เพื่อส่งหลายข่าวต่อ 1 request (ประหยัด token + เร็วขึ้น)
        foreach ($news->chunk($batchSize) as $chunk) {
            $items = [];
            foreach ($chunk as $n) {
                $items[] = [
                    'id'      => $n->id,
                    'title'   => $n->title,
                    'summary' => $n->summary ? mb_substr($n->summary, 0, 300) : '',
                ];
            }

            $jsonInput = json_encode($items, JSON_UNESCAPED_UNICODE);

            $prompt = "คุณคือนักแปลข่าวการเงินมืออาชีพ แปลและสรุปข่าวต่อไปนี้เป็นภาษาไทยที่กระชับ เข้าใจง่าย สำหรับนักลงทุนมือใหม่

ข้อมูลข่าว (JSON):
{$jsonInput}

ภารกิจ:
- แปลหัวข้อข่าว (title) เป็นภาษาไทยที่สื่อความหมายชัดเจน ไม่ต้องแปลตรงตัว
- สรุปเนื้อหา (summary) เป็นภาษาไทย 1-2 ประโยคสั้นๆ จับใจความสำคัญ (ถ้าไม่มี summary ให้สรุปจาก title)

สำคัญมาก: ตอบกลับเป็น JSON Array เท่านั้น ห้ามมี markdown หรือข้อความอื่น รูปแบบ:
[
  {\"id\": (เลข id เดิม), \"title_th\": \"(หัวข้อภาษาไทย)\", \"summary_th\": \"(สรุปภาษาไทย)\"}
]";

            $response = $gemini->generateText($prompt, ['maxOutputTokens' => 4096]);

            if (!$response) {
                $this->error("Gemini ไม่ตอบกลับสำหรับ batch นี้ — ข้าม");
                continue;
            }

            $parsed = $this->parseJsonArray($response);

            if (!$parsed) {
                $this->error("ไม่สามารถ parse JSON จาก AI ได้ — ข้าม batch นี้");
                Log::warning('SummarizeNews: failed to parse Gemini response', ['response' => mb_substr($response, 0, 500)]);
                continue;
            }

            // บันทึกผลลัพธ์กลับเข้า DB ทีละข่าวตาม id
            foreach ($parsed as $row) {
                if (!isset($row['id'], $row['title_th'])) {
                    continue;
                }
                News::where('id', $row['id'])->update([
                    'title_th'   => $row['title_th'],
                    'summary_th' => $row['summary_th'] ?? null,
                ]);
                $totalDone++;
            }

            $this->info("  แปลแล้ว {$totalDone} ข่าว...");
        }

        $this->info("เสร็จสิ้น — แปลข่าวเป็นภาษาไทยทั้งหมด {$totalDone} ข่าว");
        return self::SUCCESS;
    }

    /**
     * ถอด markdown code fence แล้ว decode JSON array
     */
    private function parseJsonArray(string $raw): ?array
    {
        $clean = trim($raw);
        if (str_starts_with($clean, '```json')) {
            $clean = substr($clean, 7);
        }
        if (str_starts_with($clean, '```')) {
            $clean = substr($clean, 3);
        }
        if (str_ends_with($clean, '```')) {
            $clean = substr($clean, 0, -3);
        }
        $clean = trim($clean);

        $data = json_decode($clean, true);

        return is_array($data) ? $data : null;
    }
}
