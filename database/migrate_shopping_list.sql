ALTER TABLE families
    ADD COLUMN IF NOT EXISTS shopping_list_reset_at DATETIME NULL AFTER name;

CREATE TABLE IF NOT EXISTS shopping_list_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    source_type ENUM('manual', 'planned') NOT NULL,
    source_key VARCHAR(190) NOT NULL,
    meal_plan_id INT UNSIGNED NULL,
    recipe_id INT UNSIGNED NULL,
    plan_date DATE NULL,
    item_text VARCHAR(255) NOT NULL,
    normalized_text VARCHAR(255) NOT NULL,
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_shopping_source (family_id, source_type, source_key),
    INDEX idx_shopping_open (family_id, completed_at, normalized_text),
    CONSTRAINT fk_shopping_family FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    CONSTRAINT fk_shopping_meal_plan FOREIGN KEY (meal_plan_id) REFERENCES meal_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_shopping_recipe FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE SET NULL,
    CONSTRAINT fk_shopping_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_shopping_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
