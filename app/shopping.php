<?php

declare(strict_types=1);

function shopping_window(): array
{
    $start = (new DateTimeImmutable('today'))->setTime(0, 0);
    return [$start, $start->modify('+6 days')];
}

function sync_shopping_list_for_family(int $familyId): void
{
    $resetAt = family_shopping_reset_at($familyId);
    $desiredRows = planned_shopping_source_rows($familyId, $resetAt);
    $desiredKeys = array_map(static fn(array $row): string => $row['source_key'], $desiredRows);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO shopping_list_items
                (family_id, source_type, source_key, meal_plan_id, recipe_id, plan_date, item_text, normalized_text, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                meal_plan_id = VALUES(meal_plan_id),
                recipe_id = VALUES(recipe_id),
                plan_date = VALUES(plan_date),
                item_text = VALUES(item_text),
                normalized_text = VALUES(normalized_text),
                completed_at = IF(item_text <> VALUES(item_text) OR normalized_text <> VALUES(normalized_text), NULL, completed_at),
                completed_by = IF(item_text <> VALUES(item_text) OR normalized_text <> VALUES(normalized_text), NULL, completed_by),
                updated_at = NOW()'
        );

        foreach ($desiredRows as $row) {
            $stmt->execute([
                $familyId,
                'planned',
                $row['source_key'],
                $row['meal_plan_id'],
                $row['recipe_id'],
                $row['plan_date'],
                $row['item_text'],
                $row['normalized_text'],
            ]);
        }

        delete_obsolete_planned_shopping_items($familyId, $desiredKeys);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function delete_obsolete_planned_shopping_items(int $familyId, array $desiredKeys): void
{
    if ($desiredKeys === []) {
        $stmt = db()->prepare('DELETE FROM shopping_list_items WHERE family_id = ? AND source_type = ?');
        $stmt->execute([$familyId, 'planned']);
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($desiredKeys), '?'));
    $params = array_merge([$familyId, 'planned'], $desiredKeys);
    $stmt = db()->prepare(
        "DELETE FROM shopping_list_items
         WHERE family_id = ? AND source_type = ? AND source_key NOT IN ($placeholders)"
    );
    $stmt->execute($params);
}

function planned_shopping_source_rows(int $familyId, ?string $resetAt): array
{
    [$start, $end] = shopping_window();
    $stmt = db()->prepare(
        'SELECT mp.id AS meal_plan_id, mp.plan_date, mp.recipe_id, mp.free_text, mp.updated_at AS plan_updated_at,
                r.title AS recipe_title, r.ingredients, r.updated_at AS recipe_updated_at
         FROM meal_plans mp
         LEFT JOIN recipes r ON r.id = mp.recipe_id
         WHERE mp.family_id = ? AND mp.plan_date BETWEEN ? AND ?
         ORDER BY mp.plan_date, mp.slot, mp.id'
    );
    $stmt->execute([$familyId, $start->format('Y-m-d'), $end->format('Y-m-d')]);

    $items = [];
    foreach ($stmt->fetchAll() as $plan) {
        $lastChange = (string)max(
            (string)($plan['plan_updated_at'] ?? ''),
            (string)($plan['recipe_updated_at'] ?? $plan['plan_updated_at'] ?? '')
        );
        if ($resetAt !== null && $lastChange !== '' && $lastChange <= $resetAt) {
            continue;
        }

        $mealTitle = trim((string)($plan['recipe_title'] ?: $plan['free_text'] ?: ''));
        if ($mealTitle === '') {
            continue;
        }

        $ingredientLines = shopping_recipe_ingredient_lines($plan['ingredients'] ?? null);
        if ($ingredientLines === []) {
            $ingredientLines = ['Zutaten für ' . $mealTitle];
        }

        foreach ($ingredientLines as $index => $ingredient) {
            $items[] = [
                'source_key' => 'plan:' . (int)$plan['meal_plan_id'] . ':ingredient:' . $index,
                'meal_plan_id' => (int)$plan['meal_plan_id'],
                'recipe_id' => ($plan['recipe_id'] ?? null) !== null ? (int)$plan['recipe_id'] : null,
                'plan_date' => (string)$plan['plan_date'],
                'item_text' => $ingredient,
                'normalized_text' => shopping_normalize_text($ingredient),
            ];
        }
    }

    return $items;
}

function shopping_recipe_ingredient_lines(mixed $json): array
{
    if (!is_string($json) || $json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $lines = [];
    foreach ($decoded as $line) {
        $text = trim((string)$line);
        if ($text !== '') {
            $lines[] = $text;
        }
    }
    return $lines;
}

function shopping_normalize_text(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    return mb_strtolower($text);
}

function family_shopping_reset_at(int $familyId): ?string
{
    $stmt = db()->prepare('SELECT shopping_list_reset_at FROM families WHERE id = ?');
    $stmt->execute([$familyId]);
    $value = $stmt->fetchColumn();
    return is_string($value) && $value !== '' ? $value : null;
}

function add_manual_shopping_item(int $familyId, int $userId, string $itemText): void
{
    $itemText = trim($itemText);
    if ($itemText === '') {
        throw new RuntimeException('Bitte einen Eintrag fuer die Einkaufsliste eingeben.');
    }

    $normalized = shopping_normalize_text($itemText);
    $stmt = db()->prepare(
        'INSERT INTO shopping_list_items
            (family_id, source_type, source_key, item_text, normalized_text, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $familyId,
        'manual',
        'manual:' . bin2hex(random_bytes(12)),
        $itemText,
        $normalized,
        $userId,
    ]);
}

function shopping_list_groups(int $familyId): array
{
    $stmt = db()->prepare(
        'SELECT normalized_text, MIN(item_text) AS item_text, COUNT(*) AS item_count
         FROM shopping_list_items
         WHERE family_id = ? AND completed_at IS NULL
         GROUP BY normalized_text
         ORDER BY MIN(item_text), normalized_text'
    );
    $stmt->execute([$familyId]);
    return $stmt->fetchAll();
}

function toggle_shopping_group(int $familyId, int $userId, string $normalizedText): void
{
    $normalizedText = shopping_normalize_text($normalizedText);
    if ($normalizedText === '') {
        throw new RuntimeException('Ungueltiger Einkaufslisten-Eintrag.');
    }

    $stmt = db()->prepare(
        'UPDATE shopping_list_items
         SET completed_at = NOW(), completed_by = ?
         WHERE family_id = ? AND normalized_text = ? AND completed_at IS NULL'
    );
    $stmt->execute([$userId, $familyId, $normalizedText]);
}

function clear_shopping_list(int $familyId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM shopping_list_items WHERE family_id = ?');
        $stmt->execute([$familyId]);

        $stmt = $pdo->prepare('UPDATE families SET shopping_list_reset_at = NOW() WHERE id = ?');
        $stmt->execute([$familyId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
