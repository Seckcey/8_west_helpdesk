<?php
/** Private helpful votes on articles in the authenticated person's Guide. */
declare(strict_types=1);
require_once __DIR__.'/portal_westy.php';

function portal_guide_votes_installed(PDO $pdo): bool
{
    try { $pdo->query('SELECT id FROM portal_guide_votes LIMIT 0'); return true; }
    catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) === 1146
            || ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($error->getMessage(), 'no such table:'))) return false;
        throw $error;
    }
}

function portal_guide_vote_record(PDO $pdo, array $context, array $request): array
{
    if (portal_guide_audience($context) === 'legacy') throw new PortalWestyException('not_found', 404);
    if (!portal_devices_keys($request, ['article','helpful']) || !is_string($request['article'])
        || strlen($request['article']) > 64 || preg_match('/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', $request['article']) !== 1
        || !is_bool($request['helpful'])) throw new PortalWestyException('invalid_request', 400);
    if (!portal_guide_votes_installed($pdo)) throw new PortalWestyException('help_feedback_unavailable', 503);

    try {
        $visible = array_column(portal_guide_articles($context), 'catalog_id', 'catalog_id');
        $article = $visible[$request['article']] ?? throw new PortalWestyException('not_found', 404);
        $version = help_catalog_index()['version'];
    } catch (HelpCatalogException) {
        throw new PortalWestyException('help_feedback_unavailable', 503);
    }

    $scope = portal_westy_scope($pdo, $context);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $updated = $now->format('Y-m-d H:i:s');
    $expires = $now->modify('+400 days')->format('Y-m-d H:i:s');
    $save = $pdo->prepare('INSERT INTO portal_guide_votes
        (tenant_id,client_id,scope_key,article_id,catalog_version,helpful,created_at,updated_at,expires_at)
        VALUES (?,?,?,?,?,?,?,?,?) AS new
        ON DUPLICATE KEY UPDATE helpful=new.helpful, catalog_version=new.catalog_version,
            updated_at=new.updated_at, expires_at=new.expires_at');
    $save->execute([$scope['tenant'], $scope['client'], $scope['key'], $article, $version, (int)$request['helpful'], $updated, $updated, $expires]);
    return ['article'=>$article, 'helpful'=>$request['helpful']];
}

function portal_guide_vote_current(PDO $pdo, array $context): array
{
    if (portal_guide_audience($context) === 'legacy' || !portal_guide_votes_installed($pdo)) return [];
    $visible = array_column(portal_guide_articles($context), 'catalog_id', 'catalog_id');
    $scope = portal_westy_scope($pdo, $context);
    $query = $pdo->prepare('SELECT article_id,helpful FROM portal_guide_votes
        WHERE tenant_id=? AND client_id=? AND scope_key=? AND expires_at>?');
    $query->execute([$scope['tenant'], $scope['client'], $scope['key'], gmdate('Y-m-d H:i:s')]);
    $votes = [];
    foreach ($query as $row) {
        if (isset($visible[$row['article_id']])) $votes[$row['article_id']] = (bool)$row['helpful'];
    }
    return $votes;
}
