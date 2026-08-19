# Laravel API Scaffolder

Generate complete, production-ready API modules from your Eloquent models in seconds.

One command creates a versioned controller (with CRUD + bulk store), form requests with auto-detected validation rules, a resource with smart relationship mapping, a policy, and wired-up routes.

## Installation

```bash
composer require charlesmasinde/api-scaffolder --dev
```

The service provider is auto-discovered. No manual registration needed.

## Quick Start

```bash
# Ensure the table exists first
php artisan migrate

# Scaffold the full API module (interactive prompt)
php artisan make:api-module Tag
```

This generates:

- `app/Http/Controllers/Api/V1/TagController.php` — index, show, store, update, destroy, bulkStore
- `app/Http/Requests/StoreTagRequest.php` — rules generated from the model and live database schema
- `app/Http/Requests/UpdateTagRequest.php` — all fields `sometimes`
- `app/Http/Resources/TagResource.php` — columns + parent names + child collections
- `app/Policies/TagPolicy.php` — standard policy scaffold
- `routes/api.php` — bulk and apiResource routes appended

## Usage

### Generate everything (no prompts)

```bash
php artisan make:api-module Tag --all
```

### Generate specific components only

```bash
# Only controller and resource
php artisan make:api-module Tag --only=controller,resource

# Only requests
php artisan make:api-module Tag --only=request

# Multiple --only flags work too
php artisan make:api-module Tag --only=controller --only=request
```

Valid component names: `controller`, `request`, `resource`, `policy`, `routes`

### Interactive mode (default)

When you run the command without `--all` or `--only`, you get a multi-choice menu:

```
Which components would you like to generate? (comma-separated numbers, or "all")
  [0] All components
  [1] Controller (CRUD + bulk store)
  [2] Form Requests (Store + Update)
  [3] API Resource
  [4] Policy
  [5] Routes (api.php)
```

### Handling existing files

If a file already exists, you'll be prompted:

```
TagController already exists. What would you like to do?
  [0] Overwrite
  [1] Skip
```

To skip all prompts and overwrite everything:

```bash
php artisan make:api-module Tag --all --force
```

### Specify API version

```bash
php artisan make:api-module Tag V2
```

### Generate a missing model

If `App\Models\Tag` does not exist, the scaffolder derives the table name
(`tags`) and runs Reliese for that table:

```bash
php artisan code:models --table=tags
```

The generated model is loaded and scaffolding continues in the same command.
Generation stops if Reliese fails, the model file is missing, or the generated
file does not define the expected model class.

The database table must exist before running the scaffolder.

## Request Rule Generation

Request fields are limited to the model's `$fillable` attributes that also
exist in the model's current database table. The scaffolder reads the live
database schema instead of parsing migration files.

Generated validation rules use:

- Model casts and database column types for validation types.
- Database nullability and defaults for `required`, `sometimes`, and `nullable`.
- Single-column unique indexes for `unique` rules.
- Foreign keys for `exists` rules.

Fillable attributes that are not database columns are skipped with a warning.
Composite unique indexes require explicit request validation.

## Configuration

Publish the config to customize defaults:

```bash
php artisan vendor:publish --tag=api-scaffolder-config
```

This creates `config/api-scaffolder.php` where you can change:

- **middleware** — route middleware (default: `auth:sanctum`)
- **default_per_page** — pagination size (default: `100`)
- **skip_columns** — columns excluded from resources (default: timestamps)
- **auto_assign_user_id** — auto-set `user_id` from auth (default: `true`)
- **unique_columns** — columns that get `unique` rules on store
- **request_skip_columns** — columns excluded from validation rules

## Custom Stubs

Publish stubs to `stubs/api-scaffolder/` and modify them:

```bash
php artisan vendor:publish --tag=api-scaffolder-stubs
```

The scaffolder will use your custom stubs over the defaults.

## Smart Relationship Detection

The resource generator uses return type hints to detect relationships:

```php
// In your model — type hints are required
public function user(): BelongsTo      // → 'user_name' => $this->user?->name
public function transactions(): HasMany // → 'transactions' => $this->whenLoaded('transactions')
```

The controller automatically eager-loads all detected relationships.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- Reliese Laravel 1.4+ (installed automatically with this package)
- Models must have return type hints on relationship methods

## License

MIT
