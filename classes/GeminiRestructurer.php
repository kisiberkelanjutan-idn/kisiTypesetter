<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/GeminiRestructurer.php
 *
 * Uses Google Gemini API (with automatic local heuristic fallback) to restructure
 * legacy unstructured abstracts (Issues 1-3) into Kisi Berkelanjutan 2026
 * 3-part bilingual format ('Pendahuluan dan Metode', 'Hasil', 'Kesimpulan dan Saran')
 * and standardize body sections without altering scientific substance.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

require_once __DIR__ . '/LanguageAuditor.php';

class GeminiRestructurer
{
    /**
     * Restructure article data using Gemini API (or local heuristic fallback if API key is empty)
     */
    public static function restructure(array $articleData, string $apiKey, string $model = 'gemini-2.5-flash'): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return self::restructureLocally($articleData, 'Mode Lokal (Tanpa API Key Gemini): Abstrak dipecah otomatis menggunakan pemisah kalimat ilmiah.');
        }

        $model = trim($model) !== '' ? trim($model) : 'gemini-2.5-flash';

        $payloadForPrompt = [
            'title_id' => $articleData['title_id'] ?? '',
            'title_en' => $articleData['title_en'] ?? '',
            'keywords_id' => $articleData['keywords_id'] ?? '',
            'keywords_en' => $articleData['keywords_en'] ?? '',
            'abstract_id_raw' => $articleData['abstract_id']['raw'] ?? ($articleData['abstract_id']['pendahuluan_metode'] ?? ''),
            'abstract_en_raw' => $articleData['abstract_en']['raw'] ?? ($articleData['abstract_en']['intro_methods'] ?? ''),
            'abstract_id_current' => $articleData['abstract_id'] ?? [],
            'abstract_en_current' => $articleData['abstract_en'] ?? [],
        ];

        $prompt = <<<PROMPT
Anda adalah editor tata letak ilmiah untuk jurnal "Kisi Berkelanjutan: Sains Medis dan Kesehatan".
Tugas Anda adalah merapikan metadata & memecah abstrak artikel lama (Edisi 1-3) ke dalam struktur template baru tahun 2026 TANPA mengubah substansi ilmiah, angka statistik, atau data penelitian.

Aturan ketat:
1. Pecah abstrak Bahasa Indonesia menjadi 3 bagian:
   - "pendahuluan_metode": Latar belakang singkat, tujuan penelitian, desain/metode penelitian, populasi/sampel, instrumen, dan uji analisis data.
   - "hasil": Temuan utama dan hasil uji statistik (misal frekuensi, persentase, p-value).
   - "kesimpulan_saran": Kesimpulan penelitian dan saran/implikasi.
2. Pecah abstrak Bahasa Inggris (atau terjemahkan secara akademis dari Bahasa Indonesia jika versi Bahasa Inggris kosong) menjadi 3 bagian:
   - "intro_methods"
   - "results"
   - "conclusions_recommendations"
3. Jika "title_en" kosong, berikan terjemahan judul Bahasa Inggris ilmiah yang akurat dari "title_id". Jika "title_id" kosong, terjemahkan dari "title_en".
4. Rapikan "keywords_id" dan "keywords_en" agar dipisahkan dengan titik koma (; ) tanpa titik di akhir. Jika "keywords_en" kosong, terjemahkan dari "keywords_id" dan urutkan secara alfabetis.
5. Terapkan aturan baku spasi tunggal: dilarang ada spasi ganda (>= 2 spasi berturut-turut) di dalam paragraf maupun abstrak.

Data input artikel (JSON):
PROMPT;
        $prompt .= "\n" . json_encode($payloadForPrompt, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);

        $requestBody = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 45,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $errMsg = $curlErr !== '' ? $curlErr : ('HTTP ' . $httpCode);
            return self::restructureLocally($articleData, 'Fallback Lokal digunakan karena panggilan Gemini API gagal (' . $errMsg . ').');
        }

        $decoded = json_decode($response, true);
        $textOut = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($textOut === '') {
            return self::restructureLocally($articleData, 'Fallback Lokal digunakan karena respons Gemini kosong.');
        }

        // Strip markdown fences if any
        $textOut = preg_replace('/^```(?:json)?\s*|\s*```$/is', '', trim($textOut));
        $aiJson = json_decode($textOut, true);
        if (!is_array($aiJson)) {
            return self::restructureLocally($articleData, 'Fallback Lokal digunakan karena format JSON Gemini tidak valid.');
        }

        if (!empty($aiJson['title_id'])) {
            $articleData['title_id'] = trim((string) $aiJson['title_id']);
        }
        if (!empty($aiJson['title_en'])) {
            $articleData['title_en'] = trim((string) $aiJson['title_en']);
        }
        if (!empty($aiJson['keywords_id'])) {
            $articleData['keywords_id'] = rtrim(trim((string) $aiJson['keywords_id']), '.');
        }
        if (!empty($aiJson['keywords_en'])) {
            $articleData['keywords_en'] = rtrim(trim((string) $aiJson['keywords_en']), '.');
        }

        $abId = $aiJson['abstract_id'] ?? $aiJson;
        if (!empty($abId['pendahuluan_metode'])) {
            $articleData['abstract_id']['pendahuluan_metode'] = trim((string) $abId['pendahuluan_metode']);
        }
        if (!empty($abId['hasil'])) {
            $articleData['abstract_id']['hasil'] = trim((string) $abId['hasil']);
        }
        if (!empty($abId['kesimpulan_saran'])) {
            $articleData['abstract_id']['kesimpulan_saran'] = trim((string) $abId['kesimpulan_saran']);
        }

        $abEn = $aiJson['abstract_en'] ?? $aiJson;
        if (!empty($abEn['intro_methods'])) {
            $articleData['abstract_en']['intro_methods'] = trim((string) $abEn['intro_methods']);
        }
        if (!empty($abEn['results'])) {
            $articleData['abstract_en']['results'] = trim((string) $abEn['results']);
        }
        if (!empty($abEn['conclusions_recommendations'])) {
            $articleData['abstract_en']['conclusions_recommendations'] = trim((string) $abEn['conclusions_recommendations']);
        }

        $articleData = LanguageAuditor::normalizeArticleParagraphSpaces($articleData);
        $articleData['_ai_status'] = 'Berhasil dirapikan dengan Google Gemini AI (' . $model . ').';
        return $articleData;
    }

    /**
     * Rule-based heuristic splitter for Indonesian & English abstracts when offline or no API key
     */
    public static function restructureLocally(array $articleData, string $statusMessage = ''): array
    {
        // Split Indonesian Abstract if hasil & kesimpulan_saran are empty
        $rawId = trim($articleData['abstract_id']['raw'] ?? $articleData['abstract_id']['pendahuluan_metode'] ?? '');
        if ($rawId !== '' && trim($articleData['abstract_id']['hasil'] ?? '') === '') {
            $splitId = self::heuristicSplitAbstract($rawId, 'id');
            $articleData['abstract_id']['pendahuluan_metode'] = $splitId[0];
            $articleData['abstract_id']['hasil'] = $splitId[1];
            $articleData['abstract_id']['kesimpulan_saran'] = $splitId[2];
        }

        // Split English Abstract if results & conclusions_recommendations are empty
        $rawEn = trim($articleData['abstract_en']['raw'] ?? $articleData['abstract_en']['intro_methods'] ?? '');
        if ($rawEn !== '' && trim($articleData['abstract_en']['results'] ?? '') === '') {
            $splitEn = self::heuristicSplitAbstract($rawEn, 'en');
            $articleData['abstract_en']['intro_methods'] = $splitEn[0];
            $articleData['abstract_en']['results'] = $splitEn[1];
            $articleData['abstract_en']['conclusions_recommendations'] = $splitEn[2];
        }

        $articleData = LanguageAuditor::normalizeArticleParagraphSpaces($articleData);
        $articleData['_ai_status'] = $statusMessage !== '' ? $statusMessage : 'Berhasil dirapikan dengan pemisah kalimat otomatis.';
        return $articleData;
    }

    /**
     * Split single paragraph abstract into [intro_methods, results, conclusions]
     */
    private static function heuristicSplitAbstract(string $text, string $lang): array
    {
        $sentences = preg_split('/(?<=[\.\?\!])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$sentences || count($sentences) <= 2) {
            return [$text, '', ''];
        }

        $introMethods = [];
        $results = [];
        $conclusions = [];
        $stage = 0; // 0 = intro/methods, 1 = results, 2 = conclusions

        foreach ($sentences as $idx => $s) {
            if ($stage === 0) {
                $isResult = ($lang === 'id')
                    ? (bool) preg_match('/^(Hasil\s+(penelitian|uji|analisis)|Berdasarkan\s+hasil|Temuan\s+menunjukkan|Hasil\s+menunjukkan|Dari\s+\d+\s+responden|Nilai\s+p[\-\s]?value)/iu', $s)
                    : (bool) preg_match('/^(The\s+(statistical\s+test\s+)?results|Results\s+(showed|revealed|indicated)|Based\s+on\s+the\s+results|Findings\s+showed)/iu', $s);
                if ($isResult && $idx >= 2) {
                    $stage = 1;
                }
            }
            if ($stage <= 1) {
                $isConclusion = ($lang === 'id')
                    ? (bool) preg_match('/^(Kesimpulan|Disimpulkan|Dapat\s+disimpulkan|Dengan\s+demikian|Oleh\s+karena\s+itu|Saran\s+penelitian)/iu', $s)
                    : (bool) preg_match('/^(In\s+conclusion|It\s+(is|was)\s+concluded|Conclusions?|Therefore,\s+health|Community\s+socio-cultural\s+factors\s+are\s+closely)/iu', $s);
                if ($isConclusion && $idx >= count($sentences) - 3) {
                    $stage = 2;
                }
            }

            if ($stage === 0) {
                $introMethods[] = $s;
            } elseif ($stage === 1) {
                $results[] = $s;
            } else {
                $conclusions[] = $s;
            }
        }

        // If regex didn't catch transition points, allocate last 2 sentences to conclusion and preceding 2 to results
        if (empty($results) && empty($conclusions) && count($sentences) >= 5) {
            $n = count($sentences);
            $conclusions = array_slice($sentences, -2);
            $results = array_slice($sentences, -4, 2);
            $introMethods = array_slice($sentences, 0, $n - 4);
        }

        return [
            implode(' ', $introMethods),
            implode(' ', $results),
            implode(' ', $conclusions),
        ];
    }
}
