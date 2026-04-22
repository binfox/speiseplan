<?php

declare(strict_types=1);

function week_days(DateTimeImmutable $monday): array
{
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = $monday->modify("+$i days");
    }
    return $days;
}

function week_plans(int $familyId, DateTimeImmutable $monday): array
{
    return plans_for_range($familyId, $monday, $monday->modify('+6 days'));
}

function plans_for_range(int $familyId, DateTimeImmutable $startDate, DateTimeImmutable $endDate): array
{
    $start = $startDate->format('Y-m-d');
    $end = $endDate->format('Y-m-d');

    $stmt = db()->prepare(
        'SELECT mp.*, r.title AS recipe_title, r.duration_minutes, p.id AS photo_id
         FROM meal_plans mp
         LEFT JOIN recipes r ON r.id = mp.recipe_id
         LEFT JOIN recipe_photos p ON p.recipe_id = r.id
         WHERE mp.family_id = ? AND mp.plan_date BETWEEN ? AND ?
         ORDER BY mp.plan_date, mp.slot'
    );
    $stmt->execute([$familyId, $start, $end]);

    $plans = [];
    foreach ($stmt->fetchAll() as $row) {
        $plans[$row['plan_date']][$row['slot']] = $row;
    }
    return $plans;
}

function save_meal_plan(int $familyId, int $userId): void
{
    $date = post_string('plan_date', 10);
    $slot = post_string('slot', 10);
    $recipeId = post_int_or_null('recipe_id');
    $freeText = post_string('free_text', 220);
    $note = post_string('note', 2000);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !valid_meal_slot($slot)) {
        throw new RuntimeException('Ungueltiger Planungs-Slot.');
    }
    if ($recipeId !== null && recipe_find($familyId, $recipeId) === null) {
        throw new RuntimeException('Rezept nicht gefunden.');
    }
    if ($recipeId === null && $freeText === '') {
        delete_meal_plan($familyId, $date, $slot);
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO meal_plans (family_id, plan_date, slot, recipe_id, free_text, note, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE recipe_id = VALUES(recipe_id), free_text = VALUES(free_text), note = VALUES(note), updated_at = NOW()'
    );
    $stmt->execute([$familyId, $date, $slot, $recipeId, $freeText, $note, $userId]);
}

function delete_meal_plan(int $familyId, string $date, string $slot): void
{
    $stmt = db()->prepare('DELETE FROM meal_plans WHERE family_id = ? AND plan_date = ? AND slot = ?');
    $stmt->execute([$familyId, $date, $slot]);
}

function recipe_suggestions(int $familyId, string $slot, int $limit = 6): array
{
    $limit = max(1, min(20, $limit));
    if (!valid_meal_slot($slot)) {
        $slot = 'abend';
    }

    $stmt = db()->prepare(
        "SELECT r.*, p.id AS photo_id, stats.last_planned, COALESCE(stats.planned_count, 0) AS planned_count, tags.tags
         FROM recipes r
         LEFT JOIN recipe_photos p ON p.recipe_id = r.id
         LEFT JOIN (
            SELECT recipe_id, MAX(plan_date) AS last_planned, COUNT(*) AS planned_count
            FROM meal_plans
            WHERE family_id = ?
            GROUP BY recipe_id
         ) stats ON stats.recipe_id = r.id
         LEFT JOIN (
            SELECT recipe_id, GROUP_CONCAT(tag ORDER BY tag SEPARATOR ', ') AS tags
            FROM recipe_tags
            GROUP BY recipe_id
         ) tags ON tags.recipe_id = r.id
         WHERE r.family_id = ? AND r.archived_at IS NULL AND FIND_IN_SET(?, r.meal_type)
         ORDER BY
            CASE WHEN stats.last_planned IS NULL THEN 0 ELSE 1 END,
            stats.last_planned ASC,
            COALESCE(stats.planned_count, 0) ASC,
            RAND()
         LIMIT $limit"
    );
    $stmt->execute([$familyId, $familyId, $slot]);
    return $stmt->fetchAll();
}

function recent_recipe_ids_for_week(int $familyId, DateTimeImmutable $monday): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT recipe_id
         FROM meal_plans
         WHERE family_id = ? AND recipe_id IS NOT NULL AND plan_date BETWEEN ? AND ?'
    );
    $stmt->execute([$familyId, $monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')]);
    return array_map('intval', array_column($stmt->fetchAll(), 'recipe_id'));
}
