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

/** The page, search, votes and Westy share the articles selected for this context. */
function portal_guide_articles(?array $context = null): array
{
    $legacy = portal_guide_legacy_articles();
    $audience = portal_guide_audience($context);
    $rows = portal_guide_catalog_rows();
    $selected = array_filter(portal_guide_pilot_articles(), static function (array $article) use ($audience, $rows): bool {
        $row = $rows[$article['catalog_id']];
        if ($audience === 'legacy') {
            return $row['app'] === 'companion' && array_diff(HELP_CATALOG_CUSTOMER_AUDIENCES, $row['audience']) === [];
        }
        return in_array($audience === 'owner' ? 'owner_admin' : $audience, $row['audience'], true);
    });
    // Direct readers get the full article at a shared anchor. Legacy readers get their six entries first.
    return $audience === 'legacy' ? $legacy + $selected : $selected + $legacy;
}

function portal_guide_source_labels(?array $context): array
{
    try {
        $articles = portal_guide_articles($context);
    } catch (HelpCatalogException) {
        return [];
    }
    $labels = [];
    foreach ($articles as $anchor => $article) {
        $labels[$article['catalog_id']] = ['label'=>$article['title'], 'href'=>'/portal/guide.php#'.$anchor];
    }
    return $labels;
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

/** Rows for the Safeharbor Help page, including Companion, in catalog order. */
function portal_guide_catalog_rows(): array
{
    static $source = null;
    static $rows = [];
    // The loader owns validation and resets its cache when the CLI test directory changes.
    $articles = help_catalog_index()['articles'];
    if ($articles === $source) return $rows;

    $rows = [];
    foreach ($articles as $row) {
        if (!in_array($row['app'], ['safeharbor', 'companion'], true)) continue;
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
