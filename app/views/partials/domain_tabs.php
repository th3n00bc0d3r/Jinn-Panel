<?php
/**
 * Header and tabs shared by the pages of one domain (overview, routes,
 * exposed files, stats). Expects $d (the domain row) and $tab: 'overview' | 'stats' | 'routes' | 'exposed'.
 * Sets the breadcrumbs and the page-header actions.
 */
$tabId = (int) $d['id'];
$tabName = (string) $d['domain_name'];
View::breadcrumbs([['Domains', '/cpanel/domains'], [$tabName]]);
View::slot('actions',
    '<a href="https://' . e($tabName) . '/" target="_blank" rel="noopener" class="btn btn-secondary">' . icon('external') . 'Open site</a>'
    . (Features::has('file_manager') ? '<a href="/cpanel/files?domain_id=' . $tabId . '" class="btn btn-secondary">' . icon('folder') . 'Files</a>' : ''));
$tabHrefs = [
    'overview' => "/cpanel/domains/$tabId",
    'stats' => "/cpanel/domains/$tabId/stats",
    'routes' => "/cpanel/domains/$tabId/routes",
    'exposed' => "/cpanel/domains/$tabId/exposed",
];
?>
<div class="mb-6">
    <?= Ui::tabs([
        [$tabHrefs['overview'], 'Overview', 'globe'],
        [$tabHrefs['stats'], 'Stats', 'chart'],
        [$tabHrefs['routes'], 'Routes & .htaccess', 'route'],
        [$tabHrefs['exposed'], 'Exposed files', 'shield'],
    ], $tabHrefs[$tab] ?? '', 'Domain sections') ?>
</div>
