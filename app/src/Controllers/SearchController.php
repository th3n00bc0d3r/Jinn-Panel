<?php
declare(strict_types=1);

/**
 * GET /search?q=... for the Ctrl-K menu: accounts and domains by name, as
 * JSON, limited to what the signed-in user may open (admins: everything;
 * resellers: their accounts; hosting accounts: their own domains).
 */
final class SearchController
{
    private const LIMIT = 6;

    public static function index(): void
    {
        Auth::requireLogin();
        $me = Auth::user();
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (mb_strlen($q) < 2) {
            echo '{"results":[]}';
            return;
        }
        echo json_encode(['results' => self::find($me, $q)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    }

    /** @return list<array{label:string, hint:string, href:string, group:string}> */
    public static function find(array $me, string $q): array
    {
        $pdo = Database::app();
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $prefix = addcslashes($q, '%_\\') . '%';
        $out = [];

        if ($me['role'] === 'user') {
            $s = $pdo->prepare('SELECT id, domain_name FROM domains WHERE user_id = ? AND domain_name LIKE ? ORDER BY domain_name LIKE ? DESC, domain_name LIMIT ' . self::LIMIT);
            $s->execute([$me['id'], $like, $prefix]);
            foreach ($s->fetchAll() as $d) {
                $out[] = ['label' => (string) $d['domain_name'], 'hint' => 'Domain', 'href' => '/cpanel/domains/' . (int) $d['id'], 'group' => 'Your domains'];
            }
            return $out;
        }

        $scope = $me['role'] === 'admin' ? '' : " AND u.role = 'user' AND u.parent_id = " . (int) $me['id'];
        $s = $pdo->prepare("SELECT u.id, u.username, u.email, u.full_name, u.role, u.status FROM users u
                            WHERE (u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)$scope
                            ORDER BY u.username LIKE ? DESC, u.username LIMIT " . self::LIMIT);
        $s->execute([$like, $like, $like, $prefix]);
        $roles = ['admin' => 'Administrator', 'reseller' => 'Reseller', 'user' => 'Hosting account'];
        foreach ($s->fetchAll() as $u) {
            $out[] = [
                'label' => (string) $u['username'],
                'hint' => ($roles[$u['role']] ?? $u['role']) . ($u['status'] !== 'active' ? ', ' . $u['status'] : '') . ' · ' . ($u['full_name'] ?: $u['email']),
                'href' => '/whm/accounts/' . (int) $u['id'],
                'group' => 'Accounts',
            ];
        }
        $s = $pdo->prepare("SELECT d.domain_name, u.id uid, u.username FROM domains d JOIN users u ON u.id = d.user_id
                            WHERE d.domain_name LIKE ?$scope ORDER BY d.domain_name LIKE ? DESC, d.domain_name LIMIT " . self::LIMIT);
        $s->execute([$like, $prefix]);
        foreach ($s->fetchAll() as $d) {
            $out[] = ['label' => (string) $d['domain_name'], 'hint' => 'Site of ' . $d['username'], 'href' => '/whm/accounts/' . (int) $d['uid'], 'group' => 'Domains'];
        }
        return $out;
    }
}
