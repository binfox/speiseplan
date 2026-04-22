<?php

declare(strict_types=1);

function render_header(string $title): void
{
    $user = current_user();
    $family = current_family();
    $route = current_route();
    ?>
    <!doctype html>
    <html lang="de">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#5f9f7a">
        <title><?= e($title) ?> · <?= e(app_config()['app_name']) ?></title>
        <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
        <link rel="icon" href="<?= e(url('/assets/icon.svg')) ?>" type="image/svg+xml">
        <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
    </head>
    <body>
    <header class="topbar">
        <a class="brand" href="<?= e(url('/?r=home')) ?>" aria-label="Speiseplan">
            <img src="<?= e(url('/assets/icon.svg')) ?>" alt="">
            <span>Speiseplan</span>
        </a>
        <?php if ($user): ?>
            <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="main-menu" aria-label="Menü öffnen" data-menu-toggle>
                <span aria-hidden="true">☰</span>
            </button>
            <div class="menu-panel" id="main-menu" data-menu-panel>
                <nav class="nav">
                    <a class="<?= $route === 'home' || $route === '' ? 'active' : '' ?>" href="<?= e(url('/?r=home')) ?>">Übersicht</a>
                    <a class="<?= $route === 'plan' ? 'active' : '' ?>" href="<?= e(url('/?r=plan')) ?>">Planen</a>
                    <a class="<?= str_starts_with($route, 'recipes') || str_starts_with($route, 'recipe') ? 'active' : '' ?>" href="<?= e(url('/?r=recipes')) ?>">Rezepte</a>
                    <a class="<?= $route === 'family' ? 'active' : '' ?>" href="<?= e(url('/?r=family')) ?>">Familie</a>
                </nav>
                <div class="account">
                    <span><?= e($family['name'] ?? '') ?></span>
                    <form method="post" action="<?= e(url('/?r=logout')) ?>">
                        <?= csrf_field() ?>
                        <button class="link-button" type="submit">Abmelden</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </header>
    <main class="shell">
        <?php foreach (flashes() as $flash): ?>
            <div class="flash <?= e($flash['type'] ?? 'info') ?>"><?= e($flash['message'] ?? '') ?></div>
        <?php endforeach; ?>
    <?php
}

function render_footer(): void
{
    ?>
    </main>
    <script src="<?= e(url('/assets/app.js')) ?>" defer></script>
    </body>
    </html>
    <?php
}

function render_login_page(): void
{
    render_header('Anmelden');
    ?>
    <section class="auth-grid">
        <div class="auth-copy">
            <h1>Wochen planen, Rezepte sammeln, gemeinsam entscheiden.</h1>
            <p>Der Familien-Speiseplan laeuft auf Android und am PC im Browser.</p>
        </div>
        <form class="panel auth-form" method="post" action="<?= e(url('/?r=login')) ?>">
            <?= csrf_field() ?>
            <h2>Anmelden</h2>
            <label>E-Mail
                <input type="email" name="email" autocomplete="email" required>
            </label>
            <label>Passwort
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="primary" type="submit">Anmelden</button>
            <p class="small">Noch kein Zugang? <a href="<?= e(url('/?r=register')) ?>">Mit Einladungscode registrieren</a></p>
        </form>
    </section>
    <?php
    render_footer();
}

function render_register_page(): void
{
    render_header('Registrieren');
    ?>
    <section class="auth-grid">
        <div class="auth-copy">
            <h1>Mit Einladungscode beitreten.</h1>
            <p>Jede Familie hat eigene Rezepte, Fotos und Wochenplaene.</p>
        </div>
        <form class="panel auth-form" method="post" action="<?= e(url('/?r=register')) ?>">
            <?= csrf_field() ?>
            <h2>Registrieren</h2>
            <label>Name
                <input type="text" name="name" autocomplete="name" required>
            </label>
            <label>E-Mail
                <input type="email" name="email" autocomplete="email" required>
            </label>
            <label>Passwort
                <input type="password" name="password" autocomplete="new-password" minlength="8" required>
            </label>
            <label>Einladungscode
                <input type="text" name="invite_code" autocomplete="off" required>
            </label>
            <button class="primary" type="submit">Beitreten</button>
            <p class="small"><a href="<?= e(url('/?r=login')) ?>">Zur Anmeldung</a></p>
        </form>
    </section>
    <?php
    render_footer();
}

function render_home_page(array $family): void
{
    $today = (new DateTimeImmutable('today'))->setTime(0, 0);
    $days = week_days($today);
    $plans = plans_for_range((int)$family['id'], $today, $today->modify('+6 days'));
    $visibleDays = [];

    foreach ($days as $day) {
        $date = $day->format('Y-m-d');
        $dayPlans = array_filter(
            $plans[$date] ?? [],
            static fn(array $plan): bool => (string)($plan['recipe_title'] ?? $plan['free_text'] ?? '') !== ''
        );
        if ($dayPlans !== []) {
            $visibleDays[$date] = ['date' => $day, 'plans' => $dayPlans];
        }
    }

    render_header('Übersicht');
    ?>
    <section class="quick-hero">
        <div>
            <p class="eyebrow">Nächste 7 Tage</p>
            <h1>Schnellübersicht</h1>
        </div>
        <div class="quick-actions">
            <a class="button primary" href="<?= e(url('/?r=plan')) ?>">Plan bearbeiten</a>
            <a class="button ghost" href="<?= e(url('/?r=recipes')) ?>">Rezepte</a>
        </div>
    </section>

    <?php if ($visibleDays === []): ?>
        <section class="empty-state">
            <p class="eyebrow">Noch nichts geplant</p>
            <h2>Für die nächsten 7 Tage ist kein Gericht eingetragen.</h2>
            <a class="button primary" href="<?= e(url('/?r=plan')) ?>">Jetzt planen</a>
        </section>
    <?php else: ?>
        <section class="quick-list" aria-label="Geplante Gerichte">
            <?php foreach ($visibleDays as $item): ?>
                <?php
                /** @var DateTimeImmutable $day */
                $day = $item['date'];
                $dayPlans = $item['plans'];
                ?>
                <article class="quick-day">
                    <header>
                        <span><?= e(weekday_short($day)) ?></span>
                        <strong><?= e($day->format('d.m.')) ?></strong>
                    </header>
                    <div class="quick-meals">
                        <?php foreach (['mittag', 'abend'] as $slot): ?>
                            <?php if (empty($dayPlans[$slot])) {
                                continue;
                            } ?>
                            <?php $plan = $dayPlans[$slot]; ?>
                            <div class="quick-meal">
                                <div class="quick-slot"><?= e(meal_slot_label($slot)) ?></div>
                                <?php if (!empty($plan['photo_id'])): ?>
                                    <div class="quick-photo" style="background-image: url('<?= e(recipe_photo_src(['photo_id' => $plan['photo_id']])) ?>')" aria-hidden="true"></div>
                                <?php endif; ?>
                                <div class="quick-meal-content">
                                    <strong><?= e($plan['recipe_title'] ?: $plan['free_text']) ?></strong>
                                    <?php if (!empty($plan['note'])): ?>
                                        <p><?= e($plan['note']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_plan_page(array $family): void
{
    $week = is_string($_GET['week'] ?? null) ? $_GET['week'] : null;
    $monday = monday_for_week($week);
    $days = week_days($monday);
    $plans = week_plans((int)$family['id'], $monday);
    $recipes = recipe_list((int)$family['id']);
    $prevWeek = iso_week($monday->modify('-7 days'));
    $nextWeek = iso_week($monday->modify('+7 days'));

    render_header('Wochenplan');
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow">Kalenderwoche <?= e($monday->format('W')) ?></p>
            <h1><?= e($monday->format('d.m.Y')) ?> bis <?= e($monday->modify('+6 days')->format('d.m.Y')) ?></h1>
        </div>
        <div class="actions">
            <a class="button ghost" href="<?= e(url('/?r=plan', ['week' => $prevWeek])) ?>">Zurueck</a>
            <a class="button ghost" href="<?= e(url('/?r=plan')) ?>">Heute</a>
            <a class="button ghost" href="<?= e(url('/?r=plan', ['week' => $nextWeek])) ?>">Weiter</a>
        </div>
    </section>

    <section class="week-grid">
        <?php foreach ($days as $day): ?>
            <?php $date = $day->format('Y-m-d'); ?>
            <article class="day-column">
                <header>
                    <span><?= e(weekday_short($day)) ?></span>
                    <strong><?= e(human_date($day)) ?></strong>
                </header>
                <?php foreach (['mittag', 'abend'] as $slot): ?>
                    <?php $plan = $plans[$date][$slot] ?? null; ?>
                    <div class="meal-card">
                        <div class="meal-title">
                            <h3><?= e(meal_slot_label($slot)) ?></h3>
                            <?php if ($plan): ?>
                                <form method="post" action="<?= e(url('/?r=plan_delete', ['week' => iso_week($monday)])) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="plan_date" value="<?= e($date) ?>">
                                    <input type="hidden" name="slot" value="<?= e($slot) ?>">
                                    <button class="icon-button" type="submit" title="Eintrag loeschen">×</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($plan): ?>
                            <div class="planned">
                                <?php if (!empty($plan['photo_id'])): ?>
                                    <img src="<?= e(recipe_photo_src(['photo_id' => $plan['photo_id']])) ?>" alt="">
                                <?php endif; ?>
                                <strong><?= e($plan['recipe_title'] ?: $plan['free_text']) ?></strong>
                                <?php if (!empty($plan['note'])): ?>
                                    <p><?= e($plan['note']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <form class="compact-form" method="post" action="<?= e(url('/?r=plan_save', ['week' => iso_week($monday)])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="plan_date" value="<?= e($date) ?>">
                            <input type="hidden" name="slot" value="<?= e($slot) ?>">
                            <select name="recipe_id">
                                <option value="">Freitext / leer</option>
                                <?php foreach ($recipes as $recipe): ?>
                                    <option value="<?= (int)$recipe['id'] ?>" <?= $plan && (int)($plan['recipe_id'] ?? 0) === (int)$recipe['id'] ? 'selected' : '' ?>>
                                        <?= e($recipe['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="free_text" placeholder="Oder Freitext" value="<?= e($plan['free_text'] ?? '') ?>">
                            <input type="text" name="note" placeholder="Notiz" value="<?= e($plan['note'] ?? '') ?>">
                            <button type="submit">Speichern</button>
                        </form>
                        <details class="suggestions">
                            <summary>Vorschlaege</summary>
                            <?php foreach (recipe_suggestions((int)$family['id'], $slot, 4) as $suggestion): ?>
                                <form method="post" action="<?= e(url('/?r=plan_save', ['week' => iso_week($monday)])) ?>" class="suggestion-row">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="plan_date" value="<?= e($date) ?>">
                                    <input type="hidden" name="slot" value="<?= e($slot) ?>">
                                    <input type="hidden" name="recipe_id" value="<?= (int)$suggestion['id'] ?>">
                                    <input type="hidden" name="free_text" value="">
                                    <input type="hidden" name="note" value="">
                                    <img src="<?= e(recipe_photo_src($suggestion)) ?>" alt="">
                                    <span><?= e($suggestion['title']) ?></span>
                                    <button type="submit">+</button>
                                </form>
                            <?php endforeach; ?>
                        </details>
                    </div>
                <?php endforeach; ?>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
    render_footer();
}

function render_recipes_page(array $family): void
{
    $query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    $category = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
    $recipes = recipe_list((int)$family['id'], $query, $category);
    $categories = recipe_categories((int)$family['id']);

    render_header('Rezepte');
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow"><?= count($recipes) ?> Rezepte</p>
            <h1>Rezeptbuch</h1>
        </div>
        <a class="button primary" href="<?= e(url('/?r=recipe_new')) ?>">Neues Rezept</a>
    </section>

    <form class="filter-bar" method="get">
        <input type="hidden" name="r" value="recipes">
        <input type="search" name="q" placeholder="Suchen" value="<?= e($query) ?>">
        <select name="category">
            <option value="">Alle Kategorien</option>
            <?php foreach ($categories as $item): ?>
                <option value="<?= e($item) ?>" <?= $category === $item ? 'selected' : '' ?>><?= e($item) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filtern</button>
    </form>

    <section class="recipe-grid">
        <?php foreach ($recipes as $recipe): ?>
            <article class="recipe-card">
                <img src="<?= e(recipe_photo_src($recipe)) ?>" alt="">
                <div>
                    <p class="eyebrow"><?= e($recipe['category'] ?: 'Rezept') ?></p>
                    <h2><a href="<?= e(url('/?r=recipe_edit', ['id' => (int)$recipe['id']])) ?>"><?= e($recipe['title']) ?></a></h2>
                    <p><?= e($recipe['description'] ?? '') ?></p>
                    <div class="meta">
                        <?php if ($recipe['duration_minutes']): ?><span><?= (int)$recipe['duration_minutes'] ?> Min</span><?php endif; ?>
                        <?php if ($recipe['servings']): ?><span><?= (int)$recipe['servings'] ?> Portionen</span><?php endif; ?>
                    </div>
                    <?php if (!empty($recipe['tags'])): ?><p class="tags"><?= e($recipe['tags']) ?></p><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
    render_footer();
}

function render_recipe_form(array $family, ?array $recipe = null): void
{
    $isEdit = $recipe !== null;
    $mealTypes = recipe_meal_types_checked($recipe);
    render_header($isEdit ? 'Rezept bearbeiten' : 'Neues Rezept');
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow"><?= $isEdit ? 'Bearbeiten' : 'Anlegen' ?></p>
            <h1><?= $isEdit ? e($recipe['title']) : 'Neues Rezept' ?></h1>
        </div>
        <a class="button ghost" href="<?= e(url('/?r=recipes')) ?>">Zurueck</a>
    </section>

    <form class="panel recipe-form" method="post" action="<?= e(url('/?r=recipe_save')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$recipe['id'] ?>"><?php endif; ?>
        <div class="form-grid">
            <label>Titel
                <input type="text" name="title" maxlength="180" required value="<?= e($recipe['title'] ?? '') ?>">
            </label>
            <label>Kategorie
                <input type="text" name="category" maxlength="80" value="<?= e($recipe['category'] ?? '') ?>">
            </label>
            <label>Dauer in Minuten
                <input type="number" name="duration_minutes" min="0" max="999" value="<?= e((string)($recipe['duration_minutes'] ?? '')) ?>">
            </label>
            <label>Portionen
                <input type="number" name="servings" min="1" max="99" value="<?= e((string)($recipe['servings'] ?? '')) ?>">
            </label>
        </div>
        <label>Beschreibung
            <textarea name="description" rows="3"><?= e($recipe['description'] ?? '') ?></textarea>
        </label>
        <fieldset>
            <legend>Geeignet fuer</legend>
            <label class="check"><input type="checkbox" name="meal_type[]" value="mittag" <?= $mealTypes['mittag'] ? 'checked' : '' ?>> Mittag</label>
            <label class="check"><input type="checkbox" name="meal_type[]" value="abend" <?= $mealTypes['abend'] ? 'checked' : '' ?>> Abendbrot</label>
        </fieldset>
        <label>Zutaten, eine pro Zeile
            <textarea name="ingredients" rows="8"><?= e(lines_to_text($recipe['ingredients'] ?? null)) ?></textarea>
        </label>
        <label>Schritte, einer pro Zeile
            <textarea name="steps" rows="8"><?= e(lines_to_text($recipe['steps'] ?? null)) ?></textarea>
        </label>
        <label>Tags, durch Komma getrennt
            <input type="text" name="tags" value="<?= e($isEdit ? implode(', ', $recipe['tags']) : '') ?>">
        </label>
        <label>Foto
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment">
        </label>
        <?php if ($isEdit && !empty($recipe['photo_id'])): ?>
            <img class="current-photo" src="<?= e(recipe_photo_src($recipe)) ?>" alt="">
        <?php endif; ?>
        <div class="form-actions">
            <button class="primary" type="submit">Speichern</button>
            <?php if ($isEdit): ?>
                <button class="danger" type="submit" form="archive-recipe">Archivieren</button>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($isEdit): ?>
        <form id="archive-recipe" method="post" action="<?= e(url('/?r=recipe_archive')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$recipe['id'] ?>">
        </form>
    <?php endif; ?>
    <?php
    render_footer();
}

function render_family_page(array $family): void
{
    $user = require_login();
    $families = user_families((int)$user['id']);
    $codes = family_invite_codes((int)$family['id']);
    $isAdmin = current_family_role((int)$family['id'], (int)$user['id']) === 'admin';

    render_header('Familie');
    ?>
    <section class="page-head">
        <div>
            <p class="eyebrow">Familie</p>
            <h1><?= e($family['name']) ?></h1>
        </div>
    </section>

    <section class="two-column">
        <div class="panel">
            <h2>Familie wechseln</h2>
            <form method="post" action="<?= e(url('/?r=family_switch')) ?>">
                <?= csrf_field() ?>
                <select name="family_id">
                    <?php foreach ($families as $item): ?>
                        <option value="<?= (int)$item['id'] ?>" <?= (int)$item['id'] === (int)$family['id'] ? 'selected' : '' ?>>
                            <?= e($item['name']) ?> · <?= e($item['role']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Wechseln</button>
            </form>
        </div>
        <div class="panel">
            <h2>Einladungscodes</h2>
            <?php if ($isAdmin): ?>
                <form method="post" action="<?= e(url('/?r=invite_create')) ?>">
                    <?= csrf_field() ?>
                    <button class="primary" type="submit">Neuen Code erzeugen</button>
                </form>
            <?php else: ?>
                <p class="small">Nur Familien-Admins koennen neue Einladungscodes erzeugen.</p>
            <?php endif; ?>
            <div class="code-list">
                <?php foreach ($codes as $code): ?>
                    <code><?= e($code['code']) ?></code>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
    render_footer();
}
