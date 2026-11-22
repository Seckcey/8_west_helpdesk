<?php
/** Reviewed customer help selected from authenticated server context, never request parameters. */
declare(strict_types=1);
require_once __DIR__.'/help_catalog.php';

/** Presentation only: callers supply an authenticated context. This grants no action permission. */
function portal_guide_audience(?array $context): string
{
    $identity = $context['identity'] ?? [];
    if (isset($identity['customer_access'])
        || isset($context['desktop_companion_pair']) || isset($_SESSION['desktop_companion_session'])) return 'legacy';
    return match ($identity['role'] ?? '') {
        'client_staff' => 'employee',
        'client_viewer' => 'viewer',
        'client_owner', 'client_admin' => 'owner',
        default => 'legacy',
    };
}

/** No context preserves the legacy Guide catalog and historical source IDs. */
function portal_guide_articles(?array $context = null): array
{
    $legacy = portal_guide_legacy_articles();
    $audience = portal_guide_audience($context);
    if ($audience === 'legacy') return $legacy;
    $pilot = portal_guide_pilot_articles();
    $catalogAudience = ['employee'=>'employee', 'viewer'=>'viewer', 'owner'=>'owner_admin'][$audience];
    $rows = portal_guide_catalog_rows();
    $ids = [];
    foreach ($pilot as $anchor => $article) {
        if (in_array($catalogAudience, $rows[$article['catalog_id']]['audience'], true)) {
            $ids[] = $anchor;
        }
    }
    $articles = array_intersect_key($pilot, array_flip($ids));
    // Preserve all historical anchors/material, replacing the two overlapping request entries.
    return $articles + $legacy;
}

/** Same audience/order as the page, with references rather than expanded bodies in model context. */
function portal_guide_westy_articles(?array $context = null): array
{
    try {
        $articles = portal_guide_articles($context);
        $legacy = portal_guide_legacy_articles();
    } catch (HelpCatalogException) {
        return [];
    }
    foreach ($articles as $id => &$article) {
        if (!isset($article['blocks'])) {
            unset($article['catalog_id']);
            continue;
        }
        $article = [
            'title' => $article['title'], 'audience' => $article['audience'],
            'application' => $article['application'], 'href' => '/portal/guide.php#'.$id,
        ];
        // Keep Westy's existing concise request guidance without duplicating the full new articles.
        if (isset($legacy[$id])) $article['text'] = $legacy[$id]['text'];
    }
    unset($article);
    return $articles;
}

/** Only bold labels and inline code from the approved text become markup; all text is escaped first. */
function portal_guide_inline(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return preg_replace_callback('/\*\*([^*]+)\*\*|`([^`]+)`/u',
        static fn(array $m): string => $m[1] !== '' ? '<strong>'.$m[1].'</strong>' : '<code>'.$m[2].'</code>', $escaped);
}

function portal_guide_article_html(array $article): string
{
    if (!isset($article['blocks'])) return '<p>'.portal_guide_inline($article['text']).'</p>';
    $html = '';
    foreach ($article['blocks'] as $block) {
        if ($block['type'] === 'steps') {
            $html .= '<ol>';
            foreach ($block['items'] as $item) $html .= '<li>'.portal_guide_inline($item).'</li>';
            $html .= '</ol>';
        } else $html .= '<p>'.portal_guide_inline($block['text']).'</p>';
    }
    return $html;
}

/** Use the original paragraphs and numbered steps, including the approved inline formatting. */
function portal_guide_article_plain(array $article): string
{
    if (!isset($article['blocks'])) return $article['text'];
    $paragraphs = [];
    foreach ($article['blocks'] as $block) {
        if ($block['type'] === 'steps') {
            $steps = [];
            foreach ($block['items'] as $index => $item) {
                $steps[] = ($index + 1).'. '.$item;
            }
            $paragraphs[] = implode("\n", $steps);
        } else {
            $paragraphs[] = $block['text'];
        }
    }
    return implode("\n\n", $paragraphs);
}

function portal_guide_search(?array $context, string $query, int $limit = 10): array
{
    try {
        $articles = portal_guide_articles($context);
        $rows = portal_guide_catalog_rows();
    } catch (HelpCatalogException) {
        return [];
    }

    $links = [];
    $docs = [];
    foreach ($articles as $anchor => $article) {
        $id = $article['catalog_id'];
        $row = $rows[$id];
        $links[$id] = ['id'=>$id, 'anchor'=>$anchor, 'title'=>$row['title'], 'summary'=>$row['summary']];
        $docs[] = $links[$id] + ['keywords'=>$row['keywords'], 'body'=>portal_guide_article_plain($article)];
    }
    return array_map(static fn(string $id): array => $links[$id], help_catalog_search($docs, $query, $limit));
}

/** Safeharbor catalog rows for joining guide articles to their search and audience fields. */
function portal_guide_catalog_rows(): array
{
    static $source = null;
    static $rows = [];
    // The loader owns validation and resets its cache when the CLI test directory changes.
    $articles = help_catalog_index()['articles'];
    if ($articles === $source) return $rows;

    $rows = [];
    foreach ($articles as $row) {
        if ($row['app'] !== 'safeharbor') continue;
        unset($row['approval'], $row['last_verified']);
        $rows[$row['id']] = $row;
    }
    uasort($rows, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
    $source = $articles;
    return $rows;
}

function portal_guide_legacy_articles(): array
{
    $articles = [];
    foreach (portal_guide_catalog_rows() as $id => $row) {
        if (!$row['legacy_guide']) continue;
        $articles[$row['anchor']] = [
            'title' => $row['title'],
            'text' => $row['body']['text'] ?? throw new HelpCatalogException(),
            'catalog_id' => $id,
        ];
    }
    return $articles;
}

function portal_guide_pilot_articles(): array
{
    $articles = [];
    foreach (portal_guide_catalog_rows() as $id => $row) {
        if ($row['legacy_guide']) continue;
        $articles[$row['anchor']] = [
            'title' => $row['title'],
            'audience' => $row['audience_label'],
            'application' => $row['application'],
            'blocks' => $row['body']['blocks'] ?? throw new HelpCatalogException(),
            'catalog_id' => $id,
        ];
    }
    return $articles;
}
