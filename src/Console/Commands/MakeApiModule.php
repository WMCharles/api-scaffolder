<?php

namespace CharlesMasinde\ApiScaffolder\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

class MakeApiModule extends Command
{
    protected $signature = 'make:api-module {name} {version=V1}';
    protected $description = 'Generate a full CBC-ready API module with Controller, Requests, Resource, and Policy';

    protected Filesystem $files;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem();
    }

    public function handle()
    {
        $name = $this->argument('name');
        $version = strtoupper($this->argument('version'));
        $modelName = Str::studly($name);
        $storeRequest = "Store{$modelName}Request";
        $updateRequest = "Update{$modelName}Request";

        // Ensure the Model exists before proceeding
        $modelClass = "App\\Models\\{$modelName}";
        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} does not exist! Create the model first.");
            return;
        }

        $this->info("🚀 Scaffolding API Module: {$modelName} ({$version})");

        // 1. Create Requests
        $this->call('make:request', ['name' => $storeRequest]);
        $this->createRequest($modelName, $storeRequest, 'store', $modelClass);
        $this->call('make:request', ['name' => $updateRequest]);
        $this->createRequest($modelName, $updateRequest, 'update', $modelClass);


        // 2. Create Resource
        $this->createResource($modelName);

        // 3. Create Policy
        $this->call('make:policy', [
            'name' => "{$modelName}Policy",
            '--model' => $modelName,
        ]);

        // 4. Create Controller
        $this->createController($modelName, $version);

        // 5. Append Routes
        $this->appendRoutes($modelName, $version);

        $this->info("✅ API Module for {$modelName} is ready");
    }



    protected function createRequest($modelName, $requestName, $type, $modelClass)
    {
        $dir = app_path("Http/Requests");
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $path = "{$dir}/{$requestName}.php";
        $table = (new $modelClass)->getTable();
        $migrationFiles = glob(database_path("migrations/*.php"));

        $columns = [];

        foreach ($migrationFiles as $file) {
            $content = file_get_contents($file);
            if (!str_contains($content, "Schema::create('{$table}'")) continue;

            // UPDATED REGEX: Now captures the type, name, AND optional second argument (like enum options)
            // Group 1: type, Group 2: name, Group 3: optional details (options array), Group 4: nullable
            preg_match_all("/\\\$table->(\w+)\('([\w_]+)'(?:,\s*(.+?))?\)(->nullable\(\))?/", $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $typeName = $match[1];
                $columnName = $match[2];
                $options = $match[3] ?? null; // This will look like "['male', 'female']"
                $nullable = isset($match[4]) && $match[4] === '->nullable()';

                $columns[$columnName] = [
                    'type' => $typeName,
                    'nullable' => $nullable,
                    'options' => $options,
                ];
            }
        }

        $rules = [];
        foreach ($columns as $name => $column) {
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at', 'user_id'])) continue;

            $nullable = $column['nullable'];
            $rulePrefix = ($type === 'store') ? ($nullable ? 'sometimes' : 'required') : 'sometimes';
            $typeName = $column['type'];

            $currentRules = [$rulePrefix];

            // ENUM LOGIC
            if ($typeName === 'enum' && $column['options']) {
                // Clean the string "['male', 'female']" to "male,female"
                $cleanOptions = str_replace(['[', ']', "'", '"', ' '], '', $column['options']);
                $currentRules[] = "in:{$cleanOptions}";
            }

            switch ($typeName) {
                case 'string':
                case 'text':
                    $currentRules[] = 'string';
                    break;
                case 'integer':
                case 'bigInteger':
                case 'smallInteger':
                    $currentRules[] = 'integer';
                    break;
                case 'decimal':
                case 'float':
                case 'numeric':
                    $currentRules[] = 'numeric';
                    break;
                case 'boolean':
                    $currentRules[] = 'boolean';
                    break;
                case 'date':
                    $currentRules[] = 'date';
                    break;
            }

            if ($type === 'store' && in_array($name, ['code', 'slug', 'admission_number'])) {
                $currentRules[] = "unique:{$table},{$name}";
            }

            $rules[$name] = $currentRules;
        }

        $rulesString = implode(",\n            ", array_map(
            fn($k, $v) => "'" . $k . "' => ['" . implode("','", $v) . "']",
            array_keys($rules),
            $rules
        ));

        $stub = "<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$requestName} extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            {$rulesString}
        ];
    }
}
";
        $this->files->put($path, $stub);
    }

    protected function createResource($modelName)
    {
        $dir = app_path("Http/Resources");
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $path = "{$dir}/{$modelName}Resource.php";
        if ($this->files->exists($path)) return;

        // 1. Get the table name from the model
        $modelClass = "App\\Models\\{$modelName}";
        $table = (new $modelClass)->getTable();

        // 2. Fetch columns from the database
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);

        $mapping = [];
        foreach ($columns as $column) {
            // Skip timestamps and audit fields
            if (in_array($column, ['created_at', 'updated_at', 'deleted_at', 'user_id'])) {
                continue;
            }

            // 3. Detect Foreign Keys (e.g., student_id)
            if (str_ends_with($column, '_id')) {
                $relationBase = str_replace('_id', '', $column);

                // snake_case for the relationship name (e.g., education_phase)
                $relationSnake = \Illuminate\Support\Str::snake($relationBase);

                // camelCase for a clean JSON key (e.g., education_phase_name)
                $jsonKey = $relationSnake . '_name';

                // Add the ID field
                $mapping[] = "'{$column}' => \$this->{$column}";

                // Add the "Smart" Name accessor using the null-safe operator
                // This assumes the related table has a 'name' column (standard for your Mint machine)
                $mapping[] = "'{$jsonKey}' => \$this->{$relationSnake}?->name";
            } else {
                // Normal columns
                $mapping[] = "'{$column}' => \$this->{$column}";
            }
        }

        $mappingString = implode(",\n            ", $mapping);

        $stub = "<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class {$modelName}Resource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request \$request): array
    {
        return [
            {$mappingString}
        ];
    }
}
";
        $this->files->put($path, $stub);
        $this->info("- Created Smart Resource: {$modelName}Resource");
    }

    protected function createController($modelName, $version)
    {
        $dir = app_path("Http/Controllers/Api/{$version}");
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        $controllerName = "{$modelName}Controller";
        $path = "{$dir}/{$controllerName}.php";

        $variable = Str::camel($modelName);
        $modelClass = "App\\Models\\{$modelName}";
        $tableName = (new $modelClass)->getTable();

        // Detect Relations for Eager Loading
        $relations = [];
        $reflection = new \ReflectionClass($modelClass);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $modelClass && $method->getNumberOfParameters() === 0) {
                $relations[] = $method->getName();
            }
        }
        $with = count($relations) ? "with(['" . implode("','", $relations) . "'])" : "query()";

        $stub = "<?php

namespace App\Http\Controllers\Api\\{$version};

use App\Http\Controllers\Controller;
use App\Models\\{$modelName};
use App\Http\Requests\Store{$modelName}Request;
use App\Http\Requests\Update{$modelName}Request;
use App\Http\Resources\\{$modelName}Resource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

class {$controllerName} extends Controller
{
    /**
     * Display a paginated listing of the resource.
     */
    public function index(Request \$request)
    {
        \$perPage = \$request->get('per_page', 100);
        \${$variable}s = {$modelName}::{$with}->paginate(\$perPage);

        return {$modelName}Resource::collection(\${$variable}s)->additional([
            'status' => 'success',
            'message' => 'Records retrieved successfully'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Store{$modelName}Request \$request)
    {
        \$data = \$request->validated();

        // Assign authenticated user_id via Sanctum if column exists
        if (Schema::hasColumn('{$tableName}', 'user_id')) {
            \$data['user_id'] = \$request->user()->id;
        }

        \${$variable} = {$modelName}::create(\$data);
        
        return (new {$modelName}Resource(\${$variable}))
            ->additional([
                'status' => 'success',
                'message' => 'Record created successfully'
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     */
    public function show(\$id)
    {
        \${$variable} = {$modelName}::{$with}->find(\$id);
        
        if (!\${$variable}) {
            return response()->json([
                'status' => 'error',
                'message' => 'Record not found'
            ], 404);
        }

        return (new {$modelName}Resource(\${$variable}))->additional([
            'status' => 'success'
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Update{$modelName}Request \$request, \$id)
    {
        \${$variable} = {$modelName}::find(\$id);

        if (!\${$variable}) {
            return response()->json([
                'status' => 'error',
                'message' => 'Record not found'
            ], 404);
        }
        
        \${$variable}->update(\$request->validated());
        
        return (new {$modelName}Resource(\${$variable}))->additional([
            'status' => 'success',
            'message' => 'Record updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(\$id)
    {
        \${$variable} = {$modelName}::find(\$id);

        if (!\${$variable}) {
            return response()->json([
                'status' => 'error',
                'message' => 'Record not found'
            ], 404);
        }

        \${$variable}->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Record deleted successfully'
        ]);
    }
}";
        $this->files->put($path, $stub);
    }

    protected function appendRoutes($modelName, $version)
    {
        $routeFile = base_path('routes/api.php');
        $content = $this->files->get($routeFile);

        $slug = Str::kebab(Str::plural($modelName));
        $vLower = strtolower($version);
        $controller = "App\\Http\\Controllers\\Api\\{$version}\\{$modelName}Controller";

        // The line we want to add
        $newRoute = "    Route::apiResource('{$slug}', \\{$controller}::class);";

        // 1. Check if the resource already exists to avoid duplicates
        if (str_contains($content, "apiResource('{$slug}'")) {
            $this->info("- Route for {$slug} already exists.");
            return;
        }

        // 2. Define the search pattern for the versioned group
        $groupPattern = "Route::prefix('{$vLower}')->middleware('auth:sanctum')->group(function () {";

        if (str_contains($content, $groupPattern)) {
            // Find the position of the group
            $pos = strpos($content, $groupPattern);

            // Find the first closing brace AFTER the group declaration
            // We use a simple approach: find the next '});' after the group starts
            $insertPos = strpos($content, "});", $pos);

            if ($insertPos !== false) {
                // Inject the new route before the closing brace
                $updatedContent = substr_replace($content, $newRoute . "\n", $insertPos, 0);
                $this->files->put($routeFile, $updatedContent);
                $this->info("- Added {$slug} to existing {$vLower} route group.");
            }
        } else {
            // 3. If no group exists for this version, create a new one at the end
            $route = "\nRoute::prefix('{$vLower}')->middleware('auth:sanctum')->group(function () {\n{$newRoute}\n});\n";
            $this->files->append($routeFile, $route);
            $this->info("- Created new route group for {$vLower} and added {$slug}.");
        }
    }

    protected function generateRules($table, $type)
    {
        // 1. Get the column list from the actual database table
        // Note: The migration MUST be run (php artisan migrate) for this to work
        try {
            $columns = Schema::getColumnListing($table);
        } catch (\Exception $e) {
            $this->error("Could not read table '{$table}'. Ensure you have run php artisan migrate.");
            return "'name' => ['required', 'string']"; // Fallback
        }

        $rules = "";

        // 2. Define columns to skip (System columns)
        $exclude = ['id', 'created_at', 'updated_at', 'deleted_at', 'user_id', 'email_verified_at'];

        foreach ($columns as $column) {
            if (in_array($column, $exclude)) continue;

            // Get column metadata (requires doctrine/dbal)
            $columnType = Schema::getColumnType($table, $column);
            $isNullable = !Schema::getConnection()->getDoctrineColumn($table, $column)->getNotnull();

            // Start building the rule string
            $ruleArray = [];

            // Requirement logic
            if ($type === 'store') {
                $ruleArray[] = $isNullable ? "'sometimes'" : "'required'";
            } else {
                $ruleArray[] = "'sometimes'";
            }

            // Type guessing logic
            switch ($columnType) {
                case 'integer':
                case 'bigint':
                case 'smallint':
                    $ruleArray[] = "'integer'";
                    break;
                case 'boolean':
                    $ruleArray[] = "'boolean'";
                    break;
                case 'decimal':
                case 'float':
                    $ruleArray[] = "'numeric'";
                    break;
                case 'datetime':
                case 'date':
                    $ruleArray[] = "'date'";
                    break;
                default:
                    $ruleArray[] = "'string'";
                    $ruleArray[] = "'max:255'";
            }

            // Check for uniqueness (common for 'slug', 'code', 'email')
            if (in_array($column, ['slug', 'code', 'email'])) {
                $ruleArray[] = "'unique:{$table},{$column}'";
            }

            $rules .= "\n            '{$column}' => [" . implode(", ", $ruleArray) . "],";
        }

        return trim($rules);
    }
}
