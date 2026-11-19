<?php
/**
 * Product Manager Simulator - knowledge library.
 *
 * Turns transcripts of Ben's YouTube book summaries into "knowledge cards"
 * (principles, scenario seeds and hidden-value ideas), stores the approved
 * cards, and picks the relevant ones for each week of the game.
 *
 * Files (all inside pmsim/knowledge/, which is never public):
 *   cards.json            approved cards the game uses
 *   drafts/<video>.json   cards waiting for review
 */

declare(strict_types=1);

namespace PmSim;

const AREAS = ['Data & AI', 'Product Strategy', 'Enterprise Operations', 'Digital Transformation'];
const CARD_TYPES = ['principle', 'scenario', 'hidden'];
const MAX_TRANSCRIPT_CHARS = 120000;

final class Knowledge
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
        foreach ([$dir, $dir . '/drafts'] as $d) {
            if (!is_dir($d)) @mkdir($d, 0750, true);
        }
    }

    /* ------------------------------------------------------------------
     * Storage
     * ------------------------------------------------------------------ */

    /** @return array<int, array> approved cards */
    public function cards(): array
    {
        $f = $this->dir . '/cards.json';
        if (!is_file($f)) return [];
        $data = json_decode((string)file_get_contents($f), true);
        return is_array($data) ? $data : [];
    }

    /** Videos in the library, newest first: [videoId => [title, book, author, url, count, updated]] */
    public function videos(): array
    {
        $out = [];
        foreach ($this->cards() as $c) {
            $v = $c['videoId'];
            if (!isset($out[$v])) {
                $out[$v] = ['videoId' => $v, 'title' => $c['videoTitle'], 'book' => $c['book'], 'author' => $c['author'],
                            'url' => $c['url'], 'count' => 0, 'updated' => $c['approved'] ?? ''];
            }
            $out[$v]['count']++;
        }
        uasort($out, fn($a, $b) => strcmp((string)$b['updated'], (string)$a['updated']));
        return $out;
    }

    public function saveDraft(array $draft): void
    {
        file_put_contents($this->draftFile($draft['meta']['videoId']), json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public function draft(string $videoId): ?array
    {
        $f = $this->draftFile($videoId);
        if (!is_file($f)) return null;
        $d = json_decode((string)file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    /** Replace all cards for this video with the approved set. */
    public function approve(string $videoId, array $cards): int
    {
        $all = array_values(array_filter($this->cards(), fn($c) => $c['videoId'] !== $videoId));
        $stamp = gmdate('c');
        foreach ($cards as $i => $c) {
            $c['id'] = $videoId . '-' . ($i + 1);
            $c['approved'] = $stamp;
            $all[] = $c;
        }
        $this->writeCards($all);
        @unlink($this->draftFile($videoId));
        return count($cards);
    }

    public function removeVideo(string $videoId): void
    {
        $this->writeCards(array_values(array_filter($this->cards(), fn($c) => $c['videoId'] !== $videoId)));
    }

    private function writeCards(array $cards): void
    {
        file_put_contents($this->dir . '/cards.json', json_encode($cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function draftFile(string $videoId): string
    {
        return $this->dir . '/drafts/' . preg_replace('/[^A-Za-z0-9_-]/', '', $videoId) . '.json';
    }

    /* ------------------------------------------------------------------
     * Helpers for video links
     * ------------------------------------------------------------------ */

    public static function videoIdFromUrl(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) return $url;
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
        return null;
    }

    public static function watchUrl(string $videoId): string
    {
        return 'https://www.youtube.com/watch?v=' . $videoId;
    }

    /* ------------------------------------------------------------------
     * Building cards from a transcript (Claude)
     * ------------------------------------------------------------------ */

    /**
     * @return array{ok: bool, cards?: array, error?: string, cost?: float}
     */
    public static function build(array $config, array $meta, string $transcript): array
    {
        $transcript = trim(mb_substr($transcript, 0, MAX_TRANSCRIPT_CHARS));
        // Strip SRT/VTT timing lines if a caption file was pasted
        $transcript = preg_replace('/^\d+\s*$|^\d{2}:\d{2}[:.,\d\s\->]+$|^WEBVTT.*$/m', '', $transcript) ?? $transcript;
        $transcript = trim(preg_replace("/\n{2,}/", "\n", $transcript) ?? $transcript);
        if (mb_strlen($transcript) < 400) {
            return ['ok' => false, 'error' => 'The transcript is too short. Paste the full transcript or script.'];
        }

        $key = trim((string)($config['anthropic_api_key'] ?? ''));
        if ($key === '' || !empty($config['mock'])) {
            return ['ok' => true, 'cards' => self::mockCards($meta), 'cost' => 0.0, 'mock' => true];
        }

        @set_time_limit(300);
        $model = (string)($config['builder_model'] ?? 'claude-opus-5-5');

        $user = "<video>\nTitle: {$meta['title']}\nBook: {$meta['book']}\nAuthor: {$meta['author']}\n</video>\n\n"
              . "<transcript>\n{$transcript}\n</transcript>";

        try {
            $client = new \Anthropic\Client(
                apiKey: $key,
                requestOptions: ['timeout' => 240.0, 'maxRetries' => 1],
                openTelemetry: false,
            );
            $message = $client->beta->messages->create(
                model: $model,
                maxTokens: 16000,
                system: self::builderPrompt(),
                messages: [['role' => 'user', 'content' => $user]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => self::cardSchema()],
                ],
                // If the model declines for policy reasons, let the API retry on its default fallback model
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
            return ['ok' => false, 'error' => 'The AI service returned an error (' . ($e->type?->value ?? 'unknown') . '). Please try again in a minute.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach the AI service (' . get_class($e) . '). Please try again.'];
        }

        if ($message->stopReason === 'refusal') {
            return ['ok' => false, 'error' => 'The AI declined to process this transcript. Check that it is the right text and try again.'];
        }
        if ($message->stopReason === 'max_tokens') {
            return ['ok' => false, 'error' => 'The response was cut off. Try a shorter transcript.'];
        }
        $data = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                break;
            }
        }
        if (!is_array($data) || !isset($data['cards']) || !is_array($data['cards'])) {
            return ['ok' => false, 'error' => 'The AI response could not be read. Please try again.'];
        }

        $u = $message->usage;
        $isOpus = str_starts_with($model, 'claude-opus');
        [$pin, $pout] = $isOpus ? [4.0, 20.0] : [0.10, 0.50];
        $cost = ((int)($u->inputTokens ?? 0) * $pin + (int)($u->outputTokens ?? 0) * $pout) / 1_000_000;

        return ['ok' => true, 'cards' => self::normalize($data['cards'], $meta), 'cost' => round($cost, 4)];
    }

    /** Clean and complete cards coming from the model or the review form. */
    public static function normalize(array $cards, array $meta): array
    {
        $out = [];
        foreach ($cards as $c) {
            if (!is_array($c)) continue;
            $type = in_array($c['type'] ?? '', CARD_TYPES, true) ? $c['type'] : 'principle';
            $area = in_array($c['area'] ?? '', AREAS, true) ? $c['area'] : 'Product Strategy';
            $title = self::clip((string)($c['title'] ?? ''), 90);
            $summary = self::clip((string)($c['summary'] ?? ''), 600);
            if ($title === '' || $summary === '') continue;
            $kw = $c['keywords'] ?? [];
            if (is_string($kw)) $kw = explode(',', $kw);
            $kw = array_values(array_unique(array_filter(array_map(fn($k) => mb_strtolower(self::clip((string)$k, 30)), (array)$kw))));
            $out[] = [
                'type'       => $type,
                'area'       => $area,
                'title'      => $title,
                'summary'    => $summary,
                'good'       => self::clip((string)($c['good'] ?? ''), 500),
                'traps'      => self::clip((string)($c['traps'] ?? ''), 500),
                'keywords'   => array_slice($kw, 0, 12),
                'videoId'    => $meta['videoId'],
                'videoTitle' => $meta['title'],
                'book'       => $meta['book'],
                'author'     => $meta['author'],
                'url'        => self::watchUrl($meta['videoId']),
            ];
        }
        return array_slice($out, 0, 30);
    }

    private static function clip(string $s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? '');
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    private static function builderPrompt(): string
    {
        $areas = implode(', ', AREAS);
        return <<<PROMPT
You turn the transcript of a short YouTube video, in which a product manager summarizes a product management book, into "knowledge cards" for a product manager simulator game. In the game, the player is a product manager on an 8-week contract at a fictional company. Each week they face a situation, type what they do, and are scored on customers, team, stakeholders, business value and tech debt.

Write 8 to 14 cards, mixing three types:
- "principle": a key idea from the video that the game master should use to judge players' decisions. Summary: the idea in 1 to 3 sentences. Good: what applying it looks like at work. Traps: the common mistake it warns against.
- "scenario": a realistic workplace situation (a stakeholder conflict, a data surprise, a launch decision, a team problem) that tests one of the ideas. Summary: the situation in 1 to 2 sentences, written so it fits any software product and names no real company. Good: what strong handling looks like. Traps: tempting but poor responses and what they cost.
- "hidden": an overlooked opportunity, inspired by the video, that a curious product manager could uncover inside a company. Summary: the opportunity in 1 to 2 sentences. Good: the kind of investigation that would reveal it. Traps: why teams usually miss it.

Aim for roughly 5 principles, 5 scenarios and 2 hidden cards, scaled to how much the video covers.

For every card:
- Title: short and specific (3 to 8 words).
- Area: exactly one of: {$areas}.
- Keywords: 4 to 8 lowercase words or short phrases a player might type when acting on this card (for example "interview customers", "roadmap", "a/b test").
- Write everything in your own words. Do not quote the book or the transcript, and do not reproduce long passages. Attribute ideas to the book only through the card's link, which the game adds.
- Use only ideas actually present in the transcript. If the transcript is thin, write fewer, better cards rather than inventing material.
- Keep it fictional and workplace-appropriate, with no real companies or people other than the book's author when necessary.
PROMPT;
    }

    private static function cardSchema(): array
    {
        $str = ['type' => 'string'];
        return [
            'type' => 'object',
            'properties' => [
                'cards' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type'     => ['type' => 'string', 'enum' => CARD_TYPES],
                            'area'     => ['type' => 'string', 'enum' => AREAS],
                            'title'    => $str,
                            'summary'  => $str,
                            'good'     => $str,
                            'traps'    => $str,
                            'keywords' => ['type' => 'array', 'items' => $str],
                        ],
                        'required' => ['type', 'area', 'title', 'summary', 'good', 'traps', 'keywords'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['cards'],
            'additionalProperties' => false,
        ];
    }

    private static function mockCards(array $meta): array
    {
        return self::normalize([
            ['type' => 'principle', 'area' => 'Product Strategy', 'title' => '[Demo] Fall in love with the problem',
             'summary' => 'Teams should commit to solving a customer problem, not to shipping a specific feature.',
             'good' => 'Frames work as outcomes and tests several solutions.', 'traps' => 'Treating the roadmap as a list of promised features.',
             'keywords' => ['problem', 'outcome', 'customers', 'roadmap']],
            ['type' => 'scenario', 'area' => 'Product Strategy', 'title' => '[Demo] The feature-factory request',
             'summary' => 'An executive asks for a feature by Friday because a competitor has it.',
             'good' => 'Asks what problem it solves and proposes a quick test.', 'traps' => 'Saying yes immediately and derailing the sprint.',
             'keywords' => ['competitor', 'executive', 'feature', 'deadline']],
            ['type' => 'hidden', 'area' => 'Data & AI', 'title' => '[Demo] The silent power users',
             'summary' => 'A small group of users relies on an undocumented workflow that could become a premium feature.',
             'good' => 'Usage analysis and customer interviews.', 'traps' => 'Only listening to the loudest customers.',
             'keywords' => ['power users', 'usage data', 'premium']],
        ], $meta);
    }

    /* ------------------------------------------------------------------
     * Selecting cards for a game turn
     * ------------------------------------------------------------------ */

    /**
     * Pick a few cards relevant to this week: the best-matching principles
     * plus one fresh scenario seed for next week.
     */
    public function pick(string $text, array $usedScenarioIds, int $principles = 2): array
    {
        $cards = $this->cards();
        if (!$cards) return ['principles' => [], 'scenario' => null];
        $words = array_filter(preg_split('/[^a-z0-9\/]+/', mb_strtolower($text)) ?: [], fn($w) => strlen($w) > 2);
        $lower = mb_strtolower($text);

        $score = function (array $c) use ($words, $lower): float {
            $s = 0.0;
            foreach ($c['keywords'] as $k) {
                if ($k !== '' && str_contains($lower, $k)) $s += 3;
            }
            $hay = mb_strtolower($c['title'] . ' ' . $c['summary']);
            foreach ($words as $w) {
                if (str_contains($hay, $w)) $s += 0.5;
            }
            return $s + mt_rand(0, 100) / 1000; // light shuffle between ties
        };

        $p = array_values(array_filter($cards, fn($c) => $c['type'] === 'principle'));
        usort($p, fn($a, $b) => $score($b) <=> $score($a));

        $sc = array_values(array_filter($cards, fn($c) => $c['type'] === 'scenario' && !in_array($c['id'], $usedScenarioIds, true)));
        shuffle($sc);

        return ['principles' => array_slice($p, 0, $principles), 'scenario' => $sc[0] ?? null];
    }

    public function hiddenCards(): array
    {
        return array_values(array_filter($this->cards(), fn($c) => $c['type'] === 'hidden'));
    }

    /** Recommend a video for the player's weakest area. */
    public function recommend(array $metrics): ?array
    {
        $videos = $this->videos();
        if (!$videos) return null;
        $weak = [
            'customers'    => $metrics['customers'],
            'team'         => $metrics['team'],
            'stakeholders' => $metrics['stakeholders'],
            'business'     => $metrics['business'],
            'techDebt'     => 100 - $metrics['techDebt'],
        ];
        asort($weak);
        $metric = array_key_first($weak);
        $areaFor = [
            'customers' => 'Product Strategy', 'business' => 'Data & AI', 'team' => 'Digital Transformation',
            'stakeholders' => 'Digital Transformation', 'techDebt' => 'Enterprise Operations',
        ];
        $label = ['customers' => 'customer satisfaction', 'team' => 'team morale', 'stakeholders' => 'stakeholder trust',
                  'business' => 'business value', 'techDebt' => 'tech debt'][$metric];

        // Prefer a video with cards in the matching area
        $byVideo = [];
        foreach ($this->cards() as $c) {
            if ($c['area'] === $areaFor[$metric]) $byVideo[$c['videoId']] = ($byVideo[$c['videoId']] ?? 0) + 1;
        }
        arsort($byVideo);
        $vid = $byVideo ? array_key_first($byVideo) : array_key_first($videos);
        $v = $videos[$vid];
        return [
            'title'  => $v['title'],
            'book'   => $v['book'],
            'author' => $v['author'],
            'url'    => $v['url'],
            'reason' => $metric === 'techDebt' ? 'Your tech debt piled up.' : "Your {$label} was your weakest area.",
        ];
    }
}
