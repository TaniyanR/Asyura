<?php
declare(strict_types=1);

namespace Asyura;

final class TrafficClassifier
{
    /**
     * Keep declared crawlers, AI agents, preview fetchers and automation tools out
     * of human analytics. This list intentionally includes user-triggered AI
     * fetchers because they are not a browser visit by the site visitor.
     */
    private const AGENTS = [
        'OpenAI' => '/GPTBot|OAI-SearchBot|OAI-AdsBot|ChatGPT-User/i',
        'Anthropic' => '/ClaudeBot|Claude-SearchBot|Claude-User|anthropic-ai/i',
        'Perplexity' => '/PerplexityBot|Perplexity-User/i',
        'Google crawler' => '/Googlebot|GoogleOther|Google-InspectionTool|Google-Extended|Storebot-Google|AdsBot-Google|APIs-Google|Mediapartners-Google/i',
        'Microsoft crawler' => '/bingbot|BingPreview|adidxbot/i',
        'Apple crawler' => '/Applebot|Applebot-Extended/i',
        'Meta crawler' => '/facebookexternalhit|FacebookBot|Meta-ExternalAgent|Meta-ExternalFetcher/i',
        'AI crawler' => '/Bytespider|CCBot|cohere-ai|Diffbot|AI2Bot|YouBot|omgili|ImagesiftBot|PetalBot|Amazonbot/i',
        'SEO crawler' => '/AhrefsBot|SemrushBot|MJ12bot|DotBot|BLEXBot|DataForSeoBot|serpstatbot|rogerbot/i',
        'Automation tool' => '/HeadlessChrome|PhantomJS|Selenium|Playwright|Puppeteer|Scrapy|curl\b|Wget\b|python-requests|python-httpx|aiohttp|Go-http-client|Apache-HttpClient|okhttp|libwww-perl/i',
        'Generic crawler' => '/(?:bot|crawler|spider|slurp|scraper|fetcher|preview)(?:[\/\s;_\-]|$)/i',
    ];

    public static function classify(string $userAgent, array $payload = []): array
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return ['is_bot'=>false, 'is_suspicious'=>true, 'reason'=>'User-Agentがありません。'];
        }
        foreach (self::AGENTS as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return ['is_bot'=>true, 'is_suspicious'=>true, 'reason'=>$name.'を検知しました。'];
            }
        }
        if (!empty($payload['automation']) || !empty($payload['webdriver'])) {
            return ['is_bot'=>false, 'is_suspicious'=>true, 'reason'=>'ブラウザの自動操作フラグを検知しました。'];
        }
        if (strlen($userAgent) < 12) {
            return ['is_bot'=>false, 'is_suspicious'=>true, 'reason'=>'User-Agentが極端に短いため判定保留にしました。'];
        }
        return ['is_bot'=>false, 'is_suspicious'=>false, 'reason'=>''];
    }

    public static function isBot(string $userAgent): bool
    {
        return (bool) self::classify($userAgent)['is_bot'];
    }
}
