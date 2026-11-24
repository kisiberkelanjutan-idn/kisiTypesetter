# Authoritative Linguistic Sources & Licenses

`KisiTypesetter` (`LanguageAuditor`) uses verifiable, authoritative linguistic datasets and style manuals for Indonesian and English scientific proofreading:

## 1. Bahasa Indonesia
- **KBBI (Kamus Besar Bahasa Indonesia) & EYD V (Ejaan Yang Disempurnakan Edisi V)** — Badan Pengembangan dan Pembinaan Bahasa, Kementerian Pendidikan, Kebudayaan, Riset, dan Teknologi Republik Indonesia (`https://kbbi.kemdikbud.go.id`, `https://ejaan.kemdikbud.go.id`).
- **KBBI Wordlist (`damzaky/kumpulan-kata-bahasa-indonesia-KBBI`)** — Indonesian lexical corpus extracted from KBBI (`list_1.0.0.txt`), filtered to remove multi-word phrases and cross-referenced non-standard (`tidak baku`) forms.
- **Hunspell Indonesian (`shuLhan/hunspell-id`)** — Indonesian Hunspell dictionary (`id_ID.dic`), licensed under **LGPL-3.0-only** (`SPDX-FileCopyrightText: 2022 hunspell-id Authors`). Root lemmas are merged into `id_valid_words.txt.gz` alongside KBBI VI morphological inflection verification (`meN-`, `ber-`, `ter-`, `di-`, `peN-`, `ke-`, `se-`, `-kan`, `-i`, `-an`, `-nya`, `-lah`, `-kah`, and EYD V bound morphemes `pasca-`, `pra-`, `non-`, `antar-`, `sub-`, `multi-`).
- **Baku / Tidak Baku Reference (`lantip/baku-tidak-baku` + Ivan Lanin)** — Standard vs. non-standard Indonesian word mappings derived from KBBI, audited and augmented with KBBI VI & EYD V medical/academic terminology rules.

## 2. English (Academic & Biomedical)
- **SCOWL (Spell Checker Oriented Word Lists) — `en_US.txt`** (`https://github.com/en-wl/wordlist` & `en-wl/wordlist-diff`), maintained by Kevin Atkinson. Used as the primary US English dictionary (`en_valid_words.txt.gz`).
  > **SCOWL Permission Notice (Kevin Atkinson):**
  > Permission to use, copy, modify, distribute, and sell any part of SCOWL, or word lists created from it, is hereby granted without fee, provided that the above copyright notice appears in all copies and that both that copyright notice and this permission notice appear in supporting documentation. Kevin Atkinson makes no representations about the suitability of this array for any purpose. It is provided "as is" without express or implied warranty.
- **AMA Manual of Style (11th ed.) / ICMJE / Merriam-Webster Medical Dictionary** — Source for US biomedical spelling consistency (`behavior`, `analyze`, `randomized`, `pediatric`, `edema`, `hemorrhage`, `postpartum`) and academic statistical phrasing.
- **Optional Live Engine: LanguageTool Public/Self-Hosted API (`https://api.languagetool.org/v2/check`)** — Optional English (`en-US`) contextual grammar & spelling check when triggered by the editor.
- **Optional Live Engine: Google Gemini AI** — Contextual linguistic review explicitly grounded in KBBI VI / EYD V and AMA Manual of Style / Merriam-Webster when an API key is configured.
