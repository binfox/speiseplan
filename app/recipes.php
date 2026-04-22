<?php

declare(strict_types=1);

function recipe_categories(int $familyId): array
{
    $stmt = db()->prepare('SELECT DISTINCT category FROM recipes WHERE family_id = ? AND archived_at IS NULL AND category IS NOT NULL AND category <> "" ORDER BY category');
    $stmt->execute([$familyId]);
    return array_map(static fn(array $row): string => $row['category'], $stmt->fetchAll());
}

function recipe_list(int $familyId, string $query = '', string $category = ''): array
{
    $params = [$familyId];
    $where = 'r.family_id = ? AND r.archived_at IS NULL';

    if ($query !== '') {
        $where .= ' AND (r.title LIKE ? OR r.description LIKE ? OR EXISTS (SELECT 1 FROM recipe_tags rt WHERE rt.recipe_id = r.id AND rt.tag LIKE ?))';
        $like = '%' . $query . '%';
        array_push($params, $like, $like, $like);
    }

    if ($category !== '') {
        $where .= ' AND r.category = ?';
        $params[] = $category;
    }

    $stmt = db()->prepare(
        "SELECT r.*, p.id AS photo_id, tags.tags
         FROM recipes r
         LEFT JOIN recipe_photos p ON p.recipe_id = r.id
         LEFT JOIN (
            SELECT recipe_id, GROUP_CONCAT(tag ORDER BY tag SEPARATOR ', ') AS tags
            FROM recipe_tags
            GROUP BY recipe_id
         ) tags ON tags.recipe_id = r.id
         WHERE $where
         ORDER BY r.title"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function recipe_find(int $familyId, int $recipeId): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, p.id AS photo_id, p.path AS photo_path, p.original_name AS photo_name
         FROM recipes r
         LEFT JOIN recipe_photos p ON p.recipe_id = r.id
         WHERE r.family_id = ? AND r.id = ?'
    );
    $stmt->execute([$familyId, $recipeId]);
    $recipe = $stmt->fetch();
    if (!$recipe) {
        return null;
    }
    $recipe['tags'] = recipe_tags($recipeId);
    return $recipe;
}

function recipe_tags(int $recipeId): array
{
    $stmt = db()->prepare('SELECT tag FROM recipe_tags WHERE recipe_id = ? ORDER BY tag');
    $stmt->execute([$recipeId]);
    return array_map(static fn(array $row): string => $row['tag'], $stmt->fetchAll());
}

function save_recipe(int $familyId, int $userId, ?int $recipeId): int
{
    $title = post_string('title', 180);
    if ($title === '') {
        throw new RuntimeException('Bitte einen Rezepttitel eingeben.');
    }

    $mealTypes = $_POST['meal_type'] ?? [];
    if (!is_array($mealTypes)) {
        $mealTypes = [];
    }
    $validMealTypes = array_values(array_intersect(['mittag', 'abend'], array_map('strval', $mealTypes)));
    if ($validMealTypes === []) {
        $validMealTypes = ['mittag', 'abend'];
    }

    $duration = post_int_or_null('duration_minutes');
    $servings = post_int_or_null('servings');
    $ingredients = parse_lines(post_string('ingredients', 6000));
    $steps = parse_lines(post_string('steps', 6000));

    $data = [
        'title' => $title,
        'description' => post_string('description', 4000),
        'category' => post_string('category', 80),
        'meal_type' => implode(',', $validMealTypes),
        'duration_minutes' => $duration,
        'servings' => $servings,
        'ingredients' => json_encode($ingredients, JSON_UNESCAPED_UNICODE),
        'steps' => json_encode($steps, JSON_UNESCAPED_UNICODE),
    ];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($recipeId === null) {
            $stmt = $pdo->prepare(
                'INSERT INTO recipes (family_id, title, description, category, meal_type, duration_minutes, servings, ingredients, steps, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $familyId,
                $data['title'],
                $data['description'],
                $data['category'],
                $data['meal_type'],
                $data['duration_minutes'],
                $data['servings'],
                $data['ingredients'],
                $data['steps'],
                $userId,
            ]);
            $recipeId = (int)$pdo->lastInsertId();
        } else {
            if (recipe_find($familyId, $recipeId) === null) {
                throw new RuntimeException('Rezept nicht gefunden.');
            }
            $stmt = $pdo->prepare(
                'UPDATE recipes
                 SET title = ?, description = ?, category = ?, meal_type = ?, duration_minutes = ?, servings = ?, ingredients = ?, steps = ?
                 WHERE id = ? AND family_id = ?'
            );
            $stmt->execute([
                $data['title'],
                $data['description'],
                $data['category'],
                $data['meal_type'],
                $data['duration_minutes'],
                $data['servings'],
                $data['ingredients'],
                $data['steps'],
                $recipeId,
                $familyId,
            ]);
        }

        replace_recipe_tags($recipeId, post_string('tags', 1000));
        handle_recipe_photo_upload($familyId, $recipeId);

        $pdo->commit();
        return $recipeId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function replace_recipe_tags(int $recipeId, string $tagText): void
{
    $tags = array_values(array_unique(array_filter(array_map(
        static fn(string $tag): string => mb_strtolower(trim($tag)),
        preg_split('/[,;\n]+/u', $tagText) ?: []
    ))));

    $stmt = db()->prepare('DELETE FROM recipe_tags WHERE recipe_id = ?');
    $stmt->execute([$recipeId]);

    $stmt = db()->prepare('INSERT INTO recipe_tags (recipe_id, tag) VALUES (?, ?)');
    foreach ($tags as $tag) {
        if ($tag !== '' && mb_strlen($tag) <= 80) {
            $stmt->execute([$recipeId, $tag]);
        }
    }
}

function handle_recipe_photo_upload(int $familyId, int $recipeId): void
{
    if (empty($_FILES['photo']['tmp_name']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        return;
    }

    if (($_FILES['photo']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Foto konnte nicht hochgeladen werden.');
    }

    $config = app_config();
    if ((int)($_FILES['photo']['size'] ?? 0) > (int)$config['max_upload_bytes']) {
        throw new RuntimeException('Foto ist zu gross.');
    }

    $tmp = (string)$_FILES['photo']['tmp_name'];
    $mime = mime_content_type($tmp) ?: '';
    $allowed = [
        'image/jpeg' => ['extension' => 'jpg', 'format' => 'jpeg'],
        'image/png' => ['extension' => 'png', 'format' => 'png'],
        'image/webp' => ['extension' => 'webp', 'format' => 'webp'],
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Bitte ein Foto als JPG, PNG oder WebP hochladen.');
    }

    $uploadDir = rtrim((string)$config['upload_dir'], '/') . '/' . $familyId;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Upload-Ordner konnte nicht erstellt werden.');
    }

    $filename = $recipeId . '-' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime]['extension'];
    $target = $uploadDir . '/' . $filename;
    if (!rewrite_uploaded_image($tmp, $target, $allowed[$mime]['format'])) {
        throw new RuntimeException('Foto konnte nicht gespeichert werden.');
    }

    $relativePath = $familyId . '/' . $filename;
    $stmt = db()->prepare('DELETE FROM recipe_photos WHERE recipe_id = ?');
    $stmt->execute([$recipeId]);

    $stmt = db()->prepare('INSERT INTO recipe_photos (recipe_id, path, original_name, mime_type) VALUES (?, ?, ?, ?)');
    $stmt->execute([$recipeId, $relativePath, (string)($_FILES['photo']['name'] ?? ''), $mime]);
}

function rewrite_uploaded_image(string $source, string $target, string $format): bool
{
    if (!extension_loaded('gd')) {
        return move_uploaded_file($source, $target);
    }

    $info = getimagesize($source);
    if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
        return false;
    }

    $image = match ($format) {
        'jpeg' => imagecreatefromjpeg($source),
        'png' => imagecreatefrompng($source),
        'webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($source) : false,
        default => false,
    };

    if (!$image) {
        return false;
    }

    $ok = match ($format) {
        'jpeg' => imagejpeg($image, $target, 85),
        'png' => imagepng($image, $target, 6),
        'webp' => function_exists('imagewebp') ? imagewebp($image, $target, 85) : false,
        default => false,
    };
    imagedestroy($image);
    return $ok;
}

function archive_recipe(int $familyId, int $recipeId): void
{
    $stmt = db()->prepare('UPDATE recipes SET archived_at = NOW() WHERE id = ? AND family_id = ?');
    $stmt->execute([$recipeId, $familyId]);
}

function photo_for_family(int $familyId, int $photoId): ?array
{
    $stmt = db()->prepare(
        'SELECT p.*
         FROM recipe_photos p
         JOIN recipes r ON r.id = p.recipe_id
         WHERE p.id = ? AND r.family_id = ?'
    );
    $stmt->execute([$photoId, $familyId]);
    return $stmt->fetch() ?: null;
}

function recipe_photo_src(?array $recipe): string
{
    if (empty($recipe['photo_id'])) {
        return url('/assets/recipe-placeholder.svg');
    }
    return url('/?r=photo', ['id' => (int)$recipe['photo_id']]);
}

function recipe_meal_types_checked(?array $recipe): array
{
    $mealType = (string)($recipe['meal_type'] ?? 'mittag,abend');
    return [
        'mittag' => str_contains($mealType, 'mittag'),
        'abend' => str_contains($mealType, 'abend'),
    ];
}
