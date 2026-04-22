<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$route = current_route();

try {
    if ($route === 'photo') {
        $family = require_family();
        $photoId = (int)($_GET['id'] ?? 0);
        $photo = photo_for_family((int)$family['id'], $photoId);
        if ($photo === null) {
            http_response_code(404);
            exit('Foto nicht gefunden.');
        }
        $base = realpath((string)app_config()['upload_dir']);
        $path = realpath(rtrim((string)app_config()['upload_dir'], '/') . '/' . $photo['path']);
        if ($base === false || $path === false || !str_starts_with($path, $base)) {
            http_response_code(404);
            exit('Foto nicht gefunden.');
        }
        header('Content-Type: ' . ($photo['mime_type'] ?: 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=604800');
        readfile($path);
        exit;
    }

    if ($route === 'logout' && request_method() === 'POST') {
        require_csrf();
        logout_user();
        redirect('/?r=login');
    }

    if ($route === 'logout') {
        redirect('/?r=home');
    }

    if ($route === 'login' && request_method() === 'POST') {
        require_csrf();
        if (login_user(post_string('email', 190), post_string('password', 1000))) {
            redirect('/?r=home');
        }
        flash('error', 'E-Mail oder Passwort ist falsch.');
        redirect('/?r=login');
    }

    if ($route === 'register' && request_method() === 'POST') {
        require_csrf();
        [$ok, $message] = register_with_invite(
            post_string('name', 120),
            post_string('email', 190),
            post_string('password', 1000),
            post_string('invite_code', 64)
        );
        flash($ok ? 'success' : 'error', $message);
        redirect($ok ? '/?r=home' : '/?r=register');
    }

    if ($route === 'family_switch' && request_method() === 'POST') {
        require_csrf();
        if (!set_current_family((int)($_POST['family_id'] ?? 0))) {
            flash('error', 'Familie konnte nicht gewechselt werden.');
        }
        redirect('/?r=family');
    }

    if ($route === 'invite_create' && request_method() === 'POST') {
        require_csrf();
        $user = require_login();
        $family = require_family();
        $code = create_invite_code((int)$family['id'], (int)$user['id']);
        flash('success', 'Neuer Einladungscode: ' . $code);
        redirect('/?r=family');
    }

    if ($route === 'recipe_save' && request_method() === 'POST') {
        require_csrf();
        $user = require_login();
        $family = require_family();
        $recipeId = post_int_or_null('id');
        $savedId = save_recipe((int)$family['id'], (int)$user['id'], $recipeId);
        flash('success', 'Rezept gespeichert.');
        redirect('/?r=recipe_edit&id=' . $savedId);
    }

    if ($route === 'recipe_archive' && request_method() === 'POST') {
        require_csrf();
        $family = require_family();
        archive_recipe((int)$family['id'], (int)($_POST['id'] ?? 0));
        flash('success', 'Rezept archiviert.');
        redirect('/?r=recipes');
    }

    if ($route === 'plan_save' && request_method() === 'POST') {
        require_csrf();
        $user = require_login();
        $family = require_family();
        save_meal_plan((int)$family['id'], (int)$user['id']);
        flash('success', 'Plan gespeichert.');
        redirect('/?r=plan&week=' . rawurlencode((string)($_GET['week'] ?? '')));
    }

    if ($route === 'plan_delete' && request_method() === 'POST') {
        require_csrf();
        $family = require_family();
        delete_meal_plan((int)$family['id'], post_string('plan_date', 10), post_string('slot', 10));
        flash('success', 'Eintrag geloescht.');
        redirect('/?r=plan&week=' . rawurlencode((string)($_GET['week'] ?? '')));
    }

    if ($route === 'login') {
        if (current_user()) {
            redirect('/?r=home');
        }
        render_login_page();
        exit;
    }

    if ($route === 'register') {
        if (current_user()) {
            redirect('/?r=home');
        }
        render_register_page();
        exit;
    }

    $family = require_family();

    if ($route === 'recipe_edit') {
        $recipe = recipe_find((int)$family['id'], (int)($_GET['id'] ?? 0));
        if ($recipe === null) {
            http_response_code(404);
            flash('error', 'Rezept nicht gefunden.');
            redirect('/?r=recipes');
        }
        render_recipe_form($family, $recipe);
        exit;
    }

    match ($route) {
        'home', '' => render_home_page($family),
        'plan' => render_plan_page($family),
        'recipes' => render_recipes_page($family),
        'recipe_new' => render_recipe_form($family),
        'family' => render_family_page($family),
        default => render_home_page($family),
    };
} catch (Throwable $e) {
    log_app_error($e);
    http_response_code(500);
    render_header('Fehler');
    ?>
    <section class="panel error-panel">
        <h1>Fehler</h1>
        <p>Es ist ein Fehler aufgetreten. Bitte versuche es spaeter erneut.</p>
        <p><a href="<?= e(url('/?r=home')) ?>">Zurueck zum Speiseplan</a></p>
    </section>
    <?php
    render_footer();
}
