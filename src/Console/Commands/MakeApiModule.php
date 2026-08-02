<?php

declare(strict_types=1);

namespace CharlesMasinde\ApiScaffolder\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

class MakeApiModule extends Command
{
    protected $signature = 'make:api-module
        {name : The model name (e.g. Tag, BlogPost)}
        {version=V1 : The API version prefix}
        {--all : Generate all components without prompting}
        {--only=* : Generate only specific components (controller, request, resource, policy, routes)}
        {--force : Overwrite all existing files without prompting}';

    protected $description = 'Generate a full API module with Controller, Requests, Resource, and Policy';

    protected Filesystem $files;

    protected const COMPONENTS = [
        'controller' => 'Controller (CRUD + bulk store)',
        'request'    => 'Form Requests (Store + Update)',
        'resource'   => 'API Resource',
        'policy'     => 'Policy',
        'routes'     => 'Routes (api.php)',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    // ──────────────────────────────────────────────
    //  Entry Point
    // ──────────────────────────────────────────────

    public function handle(): int
    {
        $modelName = Str::studly($this->argument('name'));
        // UPDATE : Normalize simple and nested API version paths without producing invalid PHP namespaces.
        $version = collect(preg_split('/[\\\\\/]+/', trim((string) $this->argument('version'), '/\\\\')))
            ->filter()
            ->map(fn (string $segment): string => preg_match('/^v\d+$/i', $segment)
                ? strtoupper($segment)
                : Str::studly($segment))
            ->implode('/');
        $modelClass = "App\\Models\\{$modelName}";

        if (! class_exists($modelClass)) {
            // UPDATE : Generate a missing model from its snake-case plural table through Reliese.
            $table = Str::snake(Str::pluralStudly($modelName));
            $this->info("Model {$modelClass} does not exist. Generating it from table {$table}.");

            $exitCode = $this->call('code:models', ['--table' => $table]);

            if ($exitCode !== self::SUCCESS) {
                $this->error("Reliese could not generate model {$modelClass} from table {$table}.");

                return self::FAILURE;
            }

            // UPDATE : Load the generated model directly because Composer caches the initial missing-class lookup.
            $modelPath = app_path("Models/{$modelName}.php");

            if (! $this->files->exists($modelPath)) {
                $this->error("Reliese completed but did not create {$modelPath}.");

                return self::FAILURE;
            }

            try {
                require_once $modelPath;
            } catch (\Throwable $exception) {
                $this->error("Reliese created {$modelPath}, but the model could not be loaded: {$exception->getMessage()}");

                return self::FAILURE;
            }

            if (! class_exists($modelClass, false)) {
                $this->error("Reliese created {$modelPath}, but it does not define {$modelClass}.");

                return self::FAILURE;
            }
        }

        $components = $this->resolveComponents();

        if ($components === null || empty($components)) {
            $this->warn('No components selected. Nothing to generate.');

            return self::SUCCESS;
        }

        $this->info("Scaffolding API module: {$modelName} ({$version})");
        $this->comment('  Components: ' . implode(', ', $components));
        $this->newLine();

        // Ordered generation map — requests & resource before controller
        $generators = [
            'request'    => fn () => $this->generateRequests($modelName, $modelClass),
            'resource'   => fn () => $this->generateResource($modelName, $modelClass),
            'policy'     => fn () => $this->generatePolicy($modelName),
            'controller' => fn () => $this->generateController($modelName, $version, $modelClass),
            'routes'     => fn () => $this->generateRoutes($modelName, $version),
        ];

        foreach ($generators as $component => $generator) {
            if (in_array($component, $components, true)) {
                $generator();
            }
        }

        $this->newLine();
        $this->info("Done. API module for {$modelName} is ready.");

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────
    //  Component Resolution
    // ──────────────────────────────────────────────

    /**
     * Determine which components to generate.
     *
     * Priority:
     *  1. --all flag      → everything, no prompt
     *  2. --only=x,y      → specific components from CLI
     *  3. Interactive menu → multi-choice prompt
     *
     * @return list<string>|null  Null on validation failure.
     */
    protected function resolveComponents(): ?array
    {
        // --all: generate everything
        if ($this->option('all')) {
            return array_keys(self::COMPONENTS);
        }

        // --only: parse and validate
        $only = $this->option('only');

        if (! empty($only)) {
            return $this->parseOnlyOption($only);
        }

        // Interactive: show multi-choice menu
        return $this->promptForComponents();
    }

    /**
     * Parse --only=controller,request style input.
     *
     * @return list<string>|null
     */
    protected function parseOnlyOption(array $only): ?array
    {
        $requested = collect($only)
            ->flatMap(fn (string $value) => explode(',', $value))
            ->map(fn (string $value) => strtolower(trim($value)))
            ->unique()
            ->values()
            ->all();

        $valid = array_keys(self::COMPONENTS);
        $invalid = array_diff($requested, $valid);

        if (! empty($invalid)) {
            $this->error('Invalid component(s): ' . implode(', ', $invalid));
            $this->line('  Valid options: ' . implode(', ', $valid));

            return null;
        }

        return $requested;
    }

    /**
     * Show an interactive multi-choice prompt.
     *
     * @return list<string>
     */
    protected function promptForComponents(): array
    {
        $labels = array_values(self::COMPONENTS);

        $selected = $this->choice(
            'Which components would you like to generate? (comma-separated numbers, or "all")',
            array_merge(['All components'], $labels),
            '0', // default: All
            null,
            true, // multiple
        );

        // If "All components" was selected, return everything
        if (in_array('All components', $selected, true)) {
            return array_keys(self::COMPONENTS);
        }

        // Map labels back to keys
        $labelToKey = array_flip(self::COMPONENTS);

        return array_values(array_map(fn (string $label) => $labelToKey[$label], $selected));
    }

    // ──────────────────────────────────────────────
    //  Generators
    // ──────────────────────────────────────────────

    protected function generateRequests(string $modelName, string $modelClass): void
    {
        $model = new $modelClass;
        $table = $model->getTable();
        // UPDATE : Generate request fields from the Reliese model intersected with its live table schema.
        $columns = $this->requestColumnsFromModelSchema($model);

        foreach (['Store', 'Update'] as $type) {
            $className = "{$type}{$modelName}Request";
            $path = app_path("Http/Requests/{$className}.php");

            if ($this->shouldSkip($path, $className)) {
                continue;
            }

            $rules = $this->buildValidationRules($columns, $table, strtolower($type));

            $content = $this->loadStub('request', [
                '{{CLASS}}' => $className,
                '{{RULES}}' => $rules,
            ]);

            $this->writeFile($path, $content, "Request: {$className}");
        }
    }

    protected function generateResource(string $modelName, string $modelClass): void
    {
        $path = app_path("Http/Resources/{$modelName}Resource.php");

        // UPDATE : Always regenerate the resource so model changes are reflected in existing files.
        if ($this->files->exists($path)) {
            $this->warn("  Overwriting: {$modelName}Resource");
        }

        $table = (new $modelClass)->getTable();
        $columns = Schema::getColumnListing($table);
        $relations = $this->detectRelations($modelClass);
        $skipColumns = config('api-scaffolder.skip_columns', []);

        $mapping = [];

        foreach ($columns as $column) {
            if (in_array($column, $skipColumns, true)) {
                continue;
            }

            $mapping[] = "'{$column}' => \$this->{$column}";
        }

        // Parent relations (belongsTo) — include the related name
        foreach ($relations['belongsTo'] as $relation) {
            $mapping[] = "'{$relation}_name' => \$this->{$relation}?->name";
        }

        // Child relations (hasMany / belongsToMany) — conditional loading
        foreach ($relations['hasMany'] as $relation) {
            $mapping[] = "'{$relation}' => \$this->whenLoaded('{$relation}')";
        }

        $content = $this->loadStub('resource', [
            '{{MODEL}}'   => $modelName,
            '{{MAPPING}}' => implode(",\n            ", $mapping),
        ]);

        $this->writeFile($path, $content, "Resource: {$modelName}Resource");
    }

    protected function generatePolicy(string $modelName): void
    {
        $path = app_path("Policies/{$modelName}Policy.php");

        if ($this->shouldSkip($path, "{$modelName}Policy")) {
            return;
        }

        $this->call('make:policy', [
            'name'    => "{$modelName}Policy",
            '--model' => $modelName,
        ]);
    }

    protected function generateController(string $modelName, string $version, string $modelClass): void
    {
        $dir = app_path("Http/Controllers/Api/{$version}");
        $path = "{$dir}/{$modelName}Controller.php";

        if ($this->shouldSkip($path, "{$modelName}Controller")) {
            return;
        }

        $tableName = (new $modelClass)->getTable();
        $relations = $this->detectRelations($modelClass);
        $allRelations = array_merge($relations['belongsTo'], $relations['hasMany']);
        $variable = Str::camel($modelName);
        $perPage = config('api-scaffolder.default_per_page', 100);

        $withClause = count($allRelations)
            ? "with(['" . implode("','", $allRelations) . "'])"
            : 'query()';

        $relationsArray = count($allRelations)
            ? "['" . implode("','", $allRelations) . "']"
            : '[]';

        $plural = Str::plural($modelName);
        $pluralLower = strtolower($plural);
        // UPDATE : Convert the normalized filesystem path into a valid PHP namespace.
        $versionNamespace = str_replace('/', '\\', $version);

        // User ID assignment blocks
        $autoAssign = config('api-scaffolder.auto_assign_user_id', true);
        $userIdAssignment = '';
        $bulkUserIdAssignment = '';

        if ($autoAssign) {
            $userIdAssignment = <<<PHP
if (Schema::hasColumn('{$tableName}', 'user_id')) {
            \$data['user_id'] = \$request->user()->id;
        }
PHP;

            $bulkUserIdAssignment = <<<PHP
if (Schema::hasColumn('{$tableName}', 'user_id')) {
            \$userId = \$request->user()->id;
            \$items = array_map(fn (array \$item) => array_merge(\$item, ['user_id' => \$userId]), \$items);
        }
PHP;
        }

        $content = $this->loadStub('controller.api', [
            '{{VERSION}}'                 => $versionNamespace,
            '{{MODEL}}'                   => $modelName,
            '{{VARIABLE}}'                => $variable,
            '{{WITH}}'                    => $withClause,
            '{{RELATIONS_ARRAY}}'         => $relationsArray,
            '{{PER_PAGE}}'                => (string) $perPage,
            '{{TABLE}}'                   => $tableName,
            '{{PLURAL}}'                  => $plural,
            '{{PLURAL_LOWER}}'            => $pluralLower,
            '{{USER_ID_ASSIGNMENT}}'      => $userIdAssignment,
            '{{BULK_USER_ID_ASSIGNMENT}}' => $bulkUserIdAssignment,
        ]);

        $this->ensureDirectory($dir);
        $this->writeFile($path, $content, "Controller: {$modelName}Controller");
    }

    protected function generateRoutes(string $modelName, string $version): void
    {
        // UPDATE : Route RideMeds API scopes through their existing protected route files.
        $routeMap = [
            'V1/Admin'  => ['file' => 'api_v1_admin.php', 'middleware' => "['auth:sanctum', 'abilities:admin-web', 'active', 'internal']", 'name' => 'admin'],
            'V1/Client' => ['file' => 'api_v1_client.php', 'middleware' => "['auth:sanctum', 'abilities:client-mobile', 'active', 'client']", 'name' => null],
            'V1/Driver' => ['file' => 'api_v1_driver.php', 'middleware' => "['auth:sanctum', 'abilities:driver-mobile', 'active', 'driver']", 'name' => null],
            'V1/Portal' => ['file' => 'api_v1_portal.php', 'middleware' => "['auth:sanctum', 'abilities:portal-web', 'active']", 'name' => null],
        ];

        if (isset($routeMap[$version])) {
            $this->generateScopedRoutes($modelName, $version, $routeMap[$version]);

            return;
        }

        $routeFile = base_path('routes/api.php');
        $content = $this->files->get($routeFile);

        $slug = Str::kebab(Str::plural($modelName));
        $vLower = strtolower($version);
        // UPDATE : Use namespace separators for nested API controller route references.
        $versionNamespace = str_replace('/', '\\', $version);
        $controller = "App\\Http\\Controllers\\Api\\{$versionNamespace}\\{$modelName}Controller";
        $middleware = config('api-scaffolder.middleware', 'auth:sanctum');

        // Routes are append-only — duplicates are always skipped
        if (str_contains($content, "apiResource('{$slug}'")) {
            $this->comment("  Skipped: Routes for {$slug} (already registered)");

            return;
        }

        $bulkRoute = "    Route::post('{$slug}/bulk', [\\{$controller}::class, 'bulkStore']);";
        $resourceRoute = "    Route::apiResource('{$slug}', \\{$controller}::class);";
        $routeLines = $bulkRoute . "\n" . $resourceRoute . "\n";

        $groupPattern = "Route::prefix('{$vLower}')->middleware('{$middleware}')->group(function () {";

        if (str_contains($content, $groupPattern)) {
            $pos = strpos($content, $groupPattern);
            $insertPos = strpos($content, '});', $pos);

            if ($insertPos !== false) {
                $content = substr_replace($content, $routeLines, $insertPos, 0);
                $this->files->put($routeFile, $content);
                $this->line("  Added routes for <info>{$slug}</info> to existing {$vLower} group");
            }
        } else {
            $newGroup = "\nRoute::prefix('{$vLower}')->middleware('{$middleware}')->group(function () {\n{$routeLines}});\n";
            $this->files->append($routeFile, $newGroup);
            $this->line("  Created <info>{$vLower}</info> route group with {$slug}");
        }
    }

    /**
     * @param array{file: string, middleware: string, name: string|null} $routeConfig
     */
    protected function generateScopedRoutes(string $modelName, string $version, array $routeConfig): void
    {
        // UPDATE : Append generated routes to an existing RideMeds protected scope.
        $routeFile = base_path("routes/{$routeConfig['file']}");
        $content = $this->files->get($routeFile);
        $slug = Str::kebab(Str::plural($modelName));

        if (str_contains($content, "apiResource('{$slug}'")) {
            $this->comment("  Skipped: Routes for {$slug} (already registered)");

            return;
        }

        $groupPattern = "Route::middleware({$routeConfig['middleware']})->group(function (): void {";
        $groupPosition = strpos($content, $groupPattern);
        $insertPosition = strrpos($content, '});');

        if ($groupPosition === false || $insertPosition === false || $insertPosition < $groupPosition) {
            $this->error("Could not find the protected route group in {$routeConfig['file']}.");

            return;
        }

        $versionNamespace = str_replace('/', '\\', $version);
        $controller = "App\\Http\\Controllers\\Api\\{$versionNamespace}\\{$modelName}Controller";
        $bulkRoute = "Route::post('{$slug}/bulk', [\\{$controller}::class, 'bulkStore'])->name('{$slug}.bulk');";
        $resourceRoute = "Route::apiResource('{$slug}', \\{$controller}::class);";

        if ($routeConfig['name'] !== null) {
            $routes = "    Route::name('{$routeConfig['name']}.')->group(function (): void {\n"
                . "        {$bulkRoute}\n"
                . "        {$resourceRoute}\n"
                . "    });\n";
        } else {
            $routes = "\n    {$bulkRoute}\n"
                . "    {$resourceRoute}\n";
        }

        $content = substr_replace($content, $routes, $insertPosition, 0);
        $this->files->put($routeFile, $content);
        $this->line("  Added scoped routes for <info>{$slug}</info> to {$routeConfig['file']}");
    }

    // ──────────────────────────────────────────────
    //  Relationship Detection
    // ──────────────────────────────────────────────

    /**
     * Detect relationships using return type hints.
     *
     * Requires models to type-hint relationship methods, e.g.:
     *   public function transactions(): HasMany
     *
     * @return array{belongsTo: list<string>, hasMany: list<string>}
     */
    protected function detectRelations(string $modelClass): array
    {
        $relations = ['belongsTo' => [], 'hasMany' => []];
        $reflection = new ReflectionClass($modelClass);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $modelClass) {
                continue;
            }
            if ($method->getNumberOfParameters() > 0) {
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof ReflectionNamedType) {
                continue;
            }

            $typeName = $returnType->getName();

            if ($this->isBelongsToRelation($typeName)) {
                $relations['belongsTo'][] = $method->getName();
            } elseif ($this->isHasManyRelation($typeName)) {
                $relations['hasMany'][] = $method->getName();
            }
        }

        return $relations;
    }

    protected function isBelongsToRelation(string $typeName): bool
    {
        return str_ends_with($typeName, 'BelongsTo')
            && ! str_contains($typeName, 'Many');
    }

    protected function isHasManyRelation(string $typeName): bool
    {
        return str_ends_with($typeName, 'HasMany')
            || str_ends_with($typeName, 'BelongsToMany')
            || str_ends_with($typeName, 'HasManyThrough');
    }

    // ──────────────────────────────────────────────
    //  Migration Parsing & Validation Rules
    // ──────────────────────────────────────────────

    /**
     * Read request fields from the model's fillable list and active database schema.
     *
     * @return array<string, array{
     *     type: string,
     *     cast: string|null,
     *     nullable: bool,
     *     has_default: bool,
     *     unique: bool,
     *     foreign_table: string|null,
     *     foreign_column: string|null
     * }>
     */
    protected function requestColumnsFromModelSchema(object $model): array
    {
        $table = $model->getTable();
        $schema = $model->getConnection()->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            throw new \RuntimeException("Cannot generate requests because table {$table} does not exist.");
        }

        $fillable = array_flip($model->getFillable());
        $casts = $model->getCasts();
        $skipColumns = config('api-scaffolder.request_skip_columns', []);
        $schemaColumns = $schema->getColumns($table);
        $columnNames = array_column($schemaColumns, 'name');

        foreach (array_keys($fillable) as $field) {
            if (! in_array($field, $columnNames, true)) {
                $this->warn("Skipping fillable field {$table}.{$field} because it is not a database column.");
            }
        }

        $uniqueColumns = [];

        foreach ($schema->getIndexes($table) as $index) {
            if (! ($index['unique'] ?? false)) {
                continue;
            }

            $indexColumns = $index['columns'] ?? [];

            if (count($indexColumns) === 1) {
                $uniqueColumns[$indexColumns[0]] = true;

                continue;
            }

            if (count($indexColumns) > 1) {
                $this->warn(
                    "Composite unique index on {$table} (" . implode(', ', $indexColumns)
                    . ') requires explicit request validation.'
                );
            }
        }

        $foreignKeys = [];

        foreach ($schema->getForeignKeys($table) as $foreignKey) {
            $localColumns = $foreignKey['columns'] ?? [];
            $foreignColumns = $foreignKey['foreign_columns'] ?? [];

            foreach ($localColumns as $position => $localColumn) {
                $foreignKeys[$localColumn] = [
                    'table'  => $foreignKey['foreign_table'] ?? null,
                    'column' => $foreignColumns[$position] ?? null,
                ];
            }
        }

        $columns = [];

        foreach ($schemaColumns as $column) {
            $name = $column['name'];

            if (! isset($fillable[$name]) || in_array($name, $skipColumns, true)) {
                continue;
            }

            $columns[$name] = [
                'type'           => strtolower((string) ($column['type_name'] ?? $column['type'] ?? '')),
                'cast'           => isset($casts[$name]) ? strtolower((string) $casts[$name]) : null,
                'nullable'       => (bool) ($column['nullable'] ?? false),
                'has_default'    => array_key_exists('default', $column) && $column['default'] !== null,
                'unique'         => isset($uniqueColumns[$name]),
                'foreign_table'  => $foreignKeys[$name]['table'] ?? null,
                'foreign_column' => $foreignKeys[$name]['column'] ?? null,
            ];
        }

        if ($columns === []) {
            $this->warn("No request fields were found for model table {$table}.");
        }

        return $columns;
    }

    /**
     * Build a formatted validation rules string from parsed columns.
     */
    protected function buildValidationRules(array $columns, string $table, string $type): string
    {
        $skipColumns = config('api-scaffolder.request_skip_columns', []);
        $uniqueColumns = config('api-scaffolder.unique_columns', []);
        $rules = [];

        foreach ($columns as $name => $column) {
            if (in_array($name, $skipColumns, true)) {
                continue;
            }

            $nullable = $column['nullable'];
            $typeName = $column['type'];

            $currentRules = [];
            $currentRules[] = ($type === 'store' && ! $nullable && ! $column['has_default'])
                ? 'required'
                : 'sometimes';

            if ($nullable) {
                $currentRules[] = 'nullable';
            }

            $mappedRule = $this->mapColumnTypeToRule($typeName, $column['cast']);

            if ($mappedRule !== null) {
                $currentRules[] = $mappedRule;
            } else {
                $this->warn("No validation type mapping exists for {$table}.{$name} ({$typeName}).");
            }

            if ($column['foreign_table'] !== null) {
                $currentRules[] = "exists:{$column['foreign_table']},{$column['foreign_column']}";
            }

            if (
                $type === 'store'
                && ($column['unique'] || in_array($name, $uniqueColumns, true))
            ) {
                $currentRules[] = "unique:{$table},{$name}";
            }

            $rules[$name] = $currentRules;
        }

        return implode(",\n            ", array_map(
            fn (string $key, array $value) => "'{$key}' => ['" . implode("', '", $value) . "']",
            array_keys($rules),
            $rules,
        ));
    }

    /**
     * Map a database schema type or model cast to a Laravel validation rule.
     */
    protected function mapColumnTypeToRule(string $type, ?string $cast): ?string
    {
        $normalizedCast = preg_replace('/:.*/', '', $cast ?? '');

        if (in_array($normalizedCast, ['int', 'integer'], true)) {
            return 'integer';
        }

        if (in_array($normalizedCast, ['float', 'double', 'decimal', 'real'], true)) {
            return 'numeric';
        }

        if (in_array($normalizedCast, ['bool', 'boolean'], true)) {
            return 'boolean';
        }

        if (in_array($normalizedCast, ['date', 'datetime', 'immutable_date', 'immutable_datetime'], true)) {
            return 'date';
        }

        if (in_array($normalizedCast, ['array', 'json', 'object', 'collection'], true)) {
            return 'array';
        }

        return match (true) {
            str_contains($type, 'int') => 'integer',
            str_contains($type, 'decimal'),
            str_contains($type, 'numeric'),
            str_contains($type, 'float'),
            str_contains($type, 'double'),
            str_contains($type, 'real') => 'numeric',
            str_contains($type, 'bool') => 'boolean',
            str_contains($type, 'date'),
            str_contains($type, 'time') => 'date',
            str_contains($type, 'json') => 'array',
            str_contains($type, 'char'),
            str_contains($type, 'text'),
            str_contains($type, 'uuid'),
            str_contains($type, 'enum') => 'string',
            default => null,
        };
    }

    // ──────────────────────────────────────────────
    //  Stubs & File Helpers
    // ──────────────────────────────────────────────

    /**
     * Load a stub file, allowing the consuming app to override it.
     *
     * Publish stubs with: php artisan vendor:publish --tag=api-scaffolder-stubs
     */
    protected function loadStub(string $name, array $replacements = []): string
    {
        $customPath = base_path("stubs/api-scaffolder/{$name}.stub");
        $defaultPath = __DIR__ . '/../../Stubs/' . $name . '.stub';

        $path = $this->files->exists($customPath) ? $customPath : $defaultPath;
        $content = $this->files->get($path);

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content,
        );
    }

    /**
     * Check if a file should be skipped.
     *
     * - File doesn't exist → proceed (return false)
     * - --force flag       → overwrite without asking (return false)
     * - File exists        → ask the user: Overwrite or Skip
     */
    protected function shouldSkip(string $path, string $label): bool
    {
        if (! $this->files->exists($path)) {
            return false;
        }

        if ($this->option('force')) {
            $this->warn("  Overwriting: {$label}");

            return false;
        }

        $action = $this->choice(
            "  {$label} already exists. What would you like to do?",
            ['Overwrite', 'Skip'],
            1, // default: Skip
        );

        if ($action === 'Overwrite') {
            $this->warn("  Overwriting: {$label}");

            return false;
        }

        $this->comment("  Skipped: {$label}");

        return true;
    }

    protected function ensureDirectory(string $dir): void
    {
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }
    }

    protected function writeFile(string $path, string $content, string $label): void
    {
        $this->ensureDirectory(dirname($path));
        $this->files->put($path, $content);
        $this->line("  Created <info>{$label}</info>");
    }
}
