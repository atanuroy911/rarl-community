<?php
/**
 * RARL — Membership standing.
 *
 * Members keep their membership by staying involved. Over a rolling window
 * (default 180 days) they earn points for:
 *   attending an event (checked in, or issued its certificate),
 *   sharing an RARL event on LinkedIn through the site's LinkedIn button,
 *   a paper published with the RARL affiliation (approved by an admin),
 *   signing in at least once.
 * Below the target the steps are: first reminder, then (after the grace
 * period) a final reminder, then the membership is paused (status inactive),
 * which also stops the ID card and membership certificate verifying.
 *
 * Nothing happens automatically: admins review members in Admin → Members →
 * Standing and apply the next step (with or without an email) when they choose.
 * New and reinstated members are not due for their first N days, and any
 * member can be exempted. Reaching the target clears reminders.
 */

/** Members see their activity points on their Membership page. */
function standingEnabled(): bool { return setting('standing_enabled', '0') === '1'; }

/** Rules from settings. */
function standingRules(): array {
    $i = fn($k, $d) => max(0, (int)setting($k, (string)$d));
    return [
        'window' => max(30, $i('standing_window_days', 180)),
        'target' => $i('standing_target_points', 3),
        'event'  => $i('standing_pts_event', 3),
        'share'  => $i('standing_pts_share', 1),
        'paper'  => $i('standing_pts_paper', 5),
        'login'  => $i('standing_pts_login', 1),
        'grace'  => max(1, $i('standing_grace_days', 14)),
        'newbie' => $i('standing_new_member_days', 90),
    ];
}

/**
 * Activity and points for members (all active/paused real members, or the ids given).
 * Returns [member_id => row + events, shares, papers, signed_in, points, meets, protected_until].
 */
function standingScores(?array $ids = null): array {
    $pdo = db();
    $r = standingRules();
    $since = $pdo->quote(date('Y-m-d H:i:s', strtotime("-{$r['window']} days")));
    $sinceDate = $pdo->quote(date('Y-m-d', strtotime("-{$r['window']} days")));
    $where = $ids !== null
        ? ($ids ? 'm.id IN (' . implode(',', array_map('intval', $ids)) . ')' : '0')
        : "m.status IN ('active','inactive') AND m.email NOT LIKE '%@rarl.internal'";
    $sql = "SELECT m.id, m.type, m.full_name, m.lab_name, m.email, m.member_code, m.status, m.created_at, m.last_login_at,
                   m.standing, m.standing_changed_at, m.standing_exempt,
            (SELECT COUNT(DISTINCT e.id) FROM events e
               WHERE e.event_date >= {$sinceDate} AND e.event_date <= CURDATE()
                 AND (EXISTS (SELECT 1 FROM event_registrations er WHERE er.event_id = e.id AND er.member_id = m.id AND er.status = 'attended')
                      OR EXISTS (SELECT 1 FROM certificates c WHERE c.event_id = e.id AND (c.member_id = m.id OR c.recipient_email = m.email)))) AS events,
            (SELECT COUNT(*) FROM member_activity a WHERE a.member_id = m.id AND a.kind = 'share_linkedin' AND a.created_at >= {$since}) AS shares,
            (SELECT COUNT(*) FROM publications p WHERE p.member_id = m.id AND p.status = 'approved' AND COALESCE(p.approved_at, p.created_at) >= {$since}) AS papers
            FROM members m WHERE {$where} ORDER BY m.id";
    $out = [];
    foreach ($pdo->query($sql) as $m) {
        $signedIn = $m['last_login_at'] && strtotime($m['last_login_at']) >= strtotime("-{$r['window']} days");
        $points = (int)$m['events'] * $r['event'] + (int)$m['shares'] * $r['share'] + (int)$m['papers'] * $r['paper'] + ($signedIn ? $r['login'] : 0);
        // New members, and members just reinstated, get a head start before they are checked.
        $start = max(strtotime($m['created_at']), $m['standing'] === 'good' && $m['standing_changed_at'] ? strtotime($m['standing_changed_at']) : 0);
        $out[(int)$m['id']] = $m + [
            'signed_in' => $signedIn, 'points' => $points, 'meets' => $points >= $r['target'],
            'protected_until' => $start + $r['newbie'] * 86400,
        ];
    }
    return $out;
}

/** What the next check would do for one scored member: null, 'recover', 'warn_1', 'warn_2' or 'lapse'. */
function standingNextAction(array $s, array $r): ?string {
    if ($s['status'] !== 'active' || $s['standing_exempt'] || $s['standing'] === 'lapsed') return null;
    if ($s['meets']) return $s['standing'] === 'good' ? null : 'recover';
    if (time() < $s['protected_until']) return null;
    $waited = $s['standing_changed_at'] && strtotime($s['standing_changed_at']) <= time() - $r['grace'] * 86400;
    return match ($s['standing']) {
        'good'     => 'warn_1',
        'warned_1' => $waited ? 'warn_2' : null,
        'warned_2' => $waited ? 'lapse' : null,
        default    => null,
    };
}

/**
 * Applies the next step to the chosen members only (an admin action; nothing
 * runs on its own). Each member's step is worked out again at this moment.
 * $email = false updates their standing without emailing them.
 * Returns counts per step.
 */
function standingApply(array $memberIds, bool $email): array {
    $r = standingRules();
    $pdo = db();
    $counts = ['recover' => 0, 'warn_1' => 0, 'warn_2' => 0, 'lapse' => 0, 'emailed' => 0];
    $batch = $email ? newEmailBatch() : null;
    foreach (standingScores(array_values(array_unique(array_map('intval', $memberIds)))) as $s) {
        $a = standingNextAction($s, $r);
        if (!$a) continue;
        $newStanding = ['recover' => 'good', 'warn_1' => 'warned_1', 'warn_2' => 'warned_2', 'lapse' => 'lapsed'][$a];
        $pdo->prepare('UPDATE members SET standing = ?, standing_changed_at = NOW()' . ($a === 'lapse' ? ", status = 'inactive'" : '') . ' WHERE id = ?')
            ->execute([$newStanding, $s['id']]);
        $counts[$a]++;
        if ($email && $a !== 'recover') { standingEmail($s, $a, $r, $batch); $counts['emailed']++; }
    }
    settingSet('standing_last_run', date('c') . ' ' . json_encode($counts));
    return $counts;
}

/** Queues the warning / pause email for one member. */
function standingEmail(array $s, string $action, array $r, ?string $batch = null): void {
    $memberName = $s['type'] === 'lab' ? ($s['lab_name'] ?: $s['full_name']) : $s['full_name'];
    $level = $action;
    $deadline = $action === 'lapse' ? null : date('d F Y', time() + $r['grace'] * 86400);
    ob_start(); require __DIR__ . '/emails/standing-notice.php'; $body = ob_get_clean();
    $subject = match ($action) {
        'warn_1' => 'Keep your RARL membership active',
        'warn_2' => 'Final reminder: keep your RARL membership active',
        default  => 'Your RARL membership has been paused',
    };
    $batch ??= newEmailBatch();
    queueEmail($batch, 'Membership standing', $s['email'], (string)$memberName, $subject, $body);
}

/** Admin: put a member back in good standing (and reactivate a paused membership). */
function standingReinstate(int $memberId): void {
    // Assignments run left to right in MySQL: read the old standing before resetting it.
    db()->prepare("UPDATE members SET status = IF(standing = 'lapsed', 'active', status), standing = 'good', standing_changed_at = NOW() WHERE id = ?")
        ->execute([$memberId]);
}

/** Records a LinkedIn share of RARL content by a member (once per item). */
function recordMemberShare(int $memberId, string $refType, int $refId): void {
    try {
        db()->prepare("INSERT INTO member_activity (member_id, kind, ref_type, ref_id) VALUES (?, 'share_linkedin', ?, ?)
                       ON DUPLICATE KEY UPDATE id = id")->execute([$memberId, $refType, $refId]);
    } catch (Throwable $e) { error_log('share log: ' . $e->getMessage()); }
}
