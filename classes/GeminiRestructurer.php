<?php
/**
 * @file plugins/generic/kisiTypesetter/classes/GeminiRestructurer.php
 *
 * Uses Google Gemini API (with automatic local heuristic fallback) to restructure
 * unstructured abstracts into Kisi Berkelanjutan 2026
 * 3-part bilingual format ('Pendahuluan dan Metode', 'Hasil', 'Kesimpulan dan Saran')
 * and standardize body sections across current and archival issues without altering scientific substance.
 */

namespace APP\plugins\generic\kisiTypesetter\classes;

require_once __DIR__ . '/LanguageAuditor.php';
require_once __DIR__ . '/VancouverFormatter.php';

class GeminiRestructurer
{
    /**
     * Restructure article data using Gemini API (or local heuristic fallback if API key is empty)
     */
    public static function restructure(array $articleData, string $apiKey, string $model = 'gemini-2.5-flash'): array
    {
        $articleType = (string) ($articleData['article_type'] ?? 'ORIGINAL ARTICLE');
        if (LanguageAuditor::isNoAbstractArticleType($articleType)) {
            $articleData = VancouverFormatter::convertAllReferences($articleData);
            $articleData = LanguageAuditor::normalizeArticleParagraphSpaces($articleData);
            $articleData['_ai_status'] = "Kategori {$articleType} tidak menggunakan abstrak naratif (Keywords & TPB/SDGs tetap, langsung ke badan artikel).";
            return $articleData;
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return self::restructureLocally($articleData, 'Mode Lokal (Tanpa API Key Gemini): Abstrak dipecah otomatis menggunakan pemisah kalimat ilmiah.');
        }

        $model = trim($model) !== '' ? trim($model) : 'gemini-2.5-flash';
        $isEnglishOnly = LanguageAuditor::isEnglishOnlyArticle($articleData);

        $hasIdInput = trim((string) ($articleData['abstract_id']['raw'] ?? $articleData['abstract_id']['pendahuluan_metode'] ?? '')) !== ''
            || trim((string) ($articleData['abstract_id']['hasil'] ?? '')) !== '';

        if ($isEnglishOnly) {
            $payloadForPrompt = [
                'title_en' => $articleData['title_en'] ?? '',
                'keywords_en' => $articleData['keywords_en'] ?? '',
                'abstract_en_raw' => $articleData['abstract_en']['raw'] ?? ($articleData['abstract_en']['intro_methods'] ?? ''),
                'abstract_en_current' => $articleData['abstract_en'] ?? [],
                'referensi' => $articleData['referensi'] ?? [],
            ];
            if ($hasIdInput) {
                $payloadForPrompt['keywords_id'] = $articleData['keywords_id'] ?? '';
                $payloadForPrompt['abstract_id_raw'] = $articleData['abstract_id']['raw'] ?? ($articleData['abstract_id']['pendahuluan_metode'] ?? '');
                $payloadForPrompt['abstract_id_current'] = $articleData['abstract_id'] ?? [];
            }

            $prompt = <<<PROMPT
Anda adalah editor tata letak ilmiah untuk jurnal "Kisi Berkelanjutan: Sains Medis dan Kesehatan" (mode English-Only JATS XML + Dukungan Abstrak PDF Indonesia).
Tugas Anda adalah merapikan metadata Bahasa Inggris, daftar pustaka Vancouver, & memecah abstrak Bahasa Inggris menjadi struktur 3 bagian TANPA mengubah substansi ilmiah.

Aturan ketat:
1. Pecah abstrak Bahasa Inggris menjadi objek "abstract_en" berisi 3 bagian:
   - "intro_methods": Brief background, objective, study design/methods, population/sample, instruments, and statistical analysis.
   - "results": Key findings and statistical test outcomes (e.g., frequency, percentage, p-value).
   - "conclusions_recommendations": Conclusions and practical/research recommendations.
2. Jika payload menyertakan input "abstract_id_raw" / "abstract_id_current" (untuk file PDF), pecah juga menjadi objek "abstract_id" ("pendahuluan_metode", "hasil", "kesimpulan_saran") tanpa mengarang terjemahan baru jika kosong.
3. Rapikan "title_en" (tanpa menerjemahkan/membuat "title_id"), dan pastikan "keywords_en" (serta "keywords_id" jika ada) dipisahkan dengan titik koma (; ) tanpa titik di akhir.
4. Terapkan aturan baku spasi tunggal: dilarang ada spasi ganda (>= 2 spasi berturut-turut) di dalam paragraf maupun abstrak.
5. Format seluruh entri array "referensi" ke gaya Vancouver (ICMJE) secara konsisten (tanpa nomor awalan `1.`, misalnya: "NamaBelakang Inisial, ... Judul. NamaJurnal. Tahun;Vol(No):Hal. doi:...").

Data input artikel (JSON):
PROMPT;
        } else {
            $payloadForPrompt = [
                'title_id' => $articleData['title_id'] ?? '',
                'title_en' => $articleData['title_en'] ?? '',
                'keywords_id' => $articleData['keywords_id'] ?? '',
                'keywords_en' => $articleData['keywords_en'] ?? '',
                'abstract_id_raw' => $articleData['abstract_id']['raw'] ?? ($articleData['abstract_id']['pendahuluan_metode'] ?? ''),
                'abstract_en_raw' => $articleData['abstract_en']['raw'] ?? ($articleData['abstract_en']['intro_methods'] ?? ''),
                'abstract_id_current' => $articleData['abstract_id'] ?? [],
                'abstract_en_current' => $articleData['abstract_en'] ?? [],
                'referensi' => $articleData['referensi'] ?? [],
            ];

            $prompt = <<<PROMPT
Anda adalah editor tata letak ilmiah untuk jurnal "Kisi Berkelanjutan: Sains Medis dan Kesehatan".
Tugas Anda adalah merapikan metadata, daftar pustaka Vancouver, & memecah abstrak menjadi struktur template Kisi Berkelanjutan (3 bagian terstruktur) TANPA mengubah substansi ilmiah, angka statistik, atau data penelitian.

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
6. Format seluruh entri array "referensi" ke gaya Vancouver (ICMJE) secara konsisten (tanpa nomor awalan `1.`, misalnya: "NamaBelakang Inisial, ... Judul. NamaJurnal. Tahun;Vol(No):Hal. doi:...").

Data input artikel (JSON):
PROMPT;
        }
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

        if ($isEnglishOnly) {
            if (!$hasIdInput) {
                $articleData['keywords_id'] = '';
                $articleData['abstract_id'] = ['pendahuluan_metode' => '', 'hasil' => '', 'kesimpulan_saran' => '', 'raw' => ''];
            } else {
                if (!empty($aiJson['keywords_id'])) {
                    $articleData['keywords_id'] = rtrim(trim((string) $aiJson['keywords_id']), '.');
                }
                $abId = $aiJson['abstract_id'] ?? [];
                if (!empty($abId['pendahuluan_metode'])) {
                    $articleData['abstract_id']['pendahuluan_metode'] = trim((string) $abId['pendahuluan_metode']);
                }
                if (!empty($abId['hasil'])) {
                    $articleData['abstract_id']['hasil'] = trim((string) $abId['hasil']);
                }
                if (!empty($abId['kesimpulan_saran'])) {
                    $articleData['abstract_id']['kesimpulan_saran'] = trim((string) $abId['kesimpulan_saran']);
                }
            }
        } else {
            if (!empty($aiJson['title_id'])) {
                $articleData['title_id'] = trim((string) $aiJson['title_id']);
            }
            if (!empty($aiJson['keywords_id'])) {
                $articleData['keywords_id'] = rtrim(trim((string) $aiJson['keywords_id']), '.');
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
        }

        if (!empty($aiJson['title_en'])) {
            $articleData['title_en'] = trim((string) $aiJson['title_en']);
        }
        if (!empty($aiJson['keywords_en'])) {
            $articleData['keywords_en'] = rtrim(trim((string) $aiJson['keywords_en']), '.');
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

        if (!empty($aiJson['referensi']) && is_array($aiJson['referensi'])) {
            $articleData['referensi'] = array_values(array_filter(array_map('strval', $aiJson['referensi']), static fn ($r) => trim($r) !== ''));
        }

        $articleData = VancouverFormatter::convertAllReferences($articleData);
        $articleData = LanguageAuditor::normalizeArticleParagraphSpaces($articleData);
        $articleData['_ai_status'] = 'Berhasil dirapikan dengan Google Gemini AI (' . $model . ').';
        return $articleData;
    }

    /**
     * Rule-based heuristic splitter for Indonesian & English abstracts when offline or no API key
     */
    public static function restructureLocally(array $articleData, string $statusMessage = ''): array
    {
        $articleType = (string) ($articleData['article_type'] ?? 'ORIGINAL ARTICLE');
        if (LanguageAuditor::isNoAbstractArticleType($articleType)) {
            $articleData = VancouverFormatter::convertAllReferences($articleData);
            $articleData = LanguageAuditor::normalizeArticleParagraphSpaces($articleData);
            $articleData['_ai_status'] = $statusMessage !== '' ? $statusMessage : "Kategori {$articleType}: langsung badan artikel (tanpa abstrak naratif).";
            return $articleData;
        }

        // Split Indonesian Abstract whenever provided in Studio (required for DOCX/PDF output even if JATS XML is English-only)
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

        $articleData = VancouverFormatter::convertAllReferences($articleData);
        $articleData = LanguageAuditor::normalizeArticleParagraphSpaces($articleData);
        $articleData['_ai_status'] = $statusMessage !== '' ? $statusMessage : 'Berhasil dirapikan dengan pemisah kalimat otomatis & daftar pustaka gaya Vancouver.';
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
