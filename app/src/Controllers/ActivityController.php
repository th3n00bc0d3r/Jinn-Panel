<?php
declare(strict_types=1);

/** WHM > Activity Log: the audit trail. Admins see everything; resellers their own and their accounts' entries. */
final class ActivityController
{
    private const PAGE = 100;

    public static function index(): void
    {
        Auth::requireRole(['admin', 'reseller']);
        $me = Auth::user();
        $where = [];
        $args = [];
        if ($me['role'] !== 'admin') {
            $where[] = '(a.actor_id = ? OR a.target_user_id = ? OR a.actor_id IN (SELECT id FROM users WHERE parent_id = ?) OR a.target_user_id IN (SELECT id FROM users WHERE parent_id = ?))';
            array_push($args, $me['id'], $me['id'], $me['id'], $me['id']);
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(a.action LIKE ? OR a.detail LIKE ? OR actor.username LIKE ? OR target.username LIKE ? OR a.ip = ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($args, $like, $like, $like, $like, $q);
        }
        $before = (int) ($_GET['before'] ?? 0);
        if ($before > 0) {
            $where[] = 'a.id < ?';
            $args[] = $before;
        }
        $sql = 'SELECT a.*, actor.username AS actor_name, target.username AS target_name
                FROM activity_log a
                LEFT JOIN users actor ON actor.id = a.actor_id
                LEFT JOIN users target ON target.id = a.target_user_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY a.id DESC LIMIT ' . (self::PAGE + 1);
        $s = Database::app()->prepare($sql);
        $s->execute($args);
        $rows = $s->fetchAll();
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);

        View::render('whm/activity', [
            'title' => 'Activity Log',
            'rows' => $rows,
            'q' => $q,
            'next' => $more ? (int) end($rows)['id'] : null,
        ], 'whm');
    }
}
