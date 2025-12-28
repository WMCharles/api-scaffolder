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

        // Ensure the Model exists before proceeding
        $modelClass = "App\\Models\\{$modelName}";
        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} does not exist! Create the model first.");
            return;
        }

        $this->info("🚀 Scaffolding API Module: {$modelName} ({$version})");

        // 1. Create Requests
        $this->createRequest($modelName, "Store{$modelName}Request", 'store', $modelClass);
        $this->createRequest($modelName, "Update{$modelName}Request", 'update', $modelClass);

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

        $this->info("✅ API Module for {$modelName} is ready for Day 2 logic!");
    }



    protected function populateRequest($requestName, $type, $modelClass)
    {
        $path = app_path("Http/Requests/{$requestName}.php");
        $table = (new $modelClass)->getTable();

        $migrationFiles = glob(database_path("migrations/*.php"));

        $columns = [];

        foreach ($migrationFiles as $file) {
            $content = file_get_contents($file);

            // Only parse files that create the target table
            if (!str_contains($content, "Schema::create('{$table}'")) continue;

            // Match lines like: $table->string('name')->nullable();
            preg_match_all("/\\\$table->(\w+)\\('([\w_]+)'\\)(->nullable\\(\\))?/", $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $typeName = $match[1];
                $columnName = $match[2];
                $nullable = isset($match[3]) && $match[3] === '->nullable()';
                $columns[$columnName] = [
                    'type' => $typeName,
                    'nullable' => $nullable,
                ];
            }
        }

        $rules = [];

        foreach ($columns as $name => $column) {
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'])) continue;

            $nullable = $column['nullable'];
            $rulePrefix = ($type === 'store') ? ($nullable ? 'sometimes' : 'required') : 'sometimes';
            $typeName = $column['type'];

            $rules[$name] = [$rulePrefix];

            switch ($typeName) {
                case 'string':
                case 'text':
                    $rules[$name][] = 'string';
                    break;
                case 'integer':
                case 'bigInteger':
                case 'smallInteger':
                    $rules[$name][] = 'integer';
                    break;
                case 'decimal':
                case 'float':
                    $rules[$name][] = 'numeric';
                    break;
                case 'boolean':
                    $rules[$name][] = 'boolean';
                    break;
                case 'date':
                case 'dateTime':
                case 'timestamp':
                    $rules[$name][] = 'date';
                    break;
                case 'json':
                    $rules[$name][] = 'array';
                    break;
            }

            // Add unique validation for store request on 'code' or 'slug'
            if ($type === 'store' && in_array($name, ['code', 'slug'])) {
                $rules[$name][] = "unique:{$table},{$name}";
            }
        }

        // Convert rules array to string for stub
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
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            {$rulesString}
        ];
    }
}
";

        $this->files->put($path, $stub);
        $this->info("Request [{$path}] created successfully.");
    }

    protected function createResource($modelName)
    {
        $path = app_path("Http/Resources/{$modelName}Resource.php");
        if ($this->files->exists($path)) return;

        $stub = "<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class {$modelName}Resource extends JsonResource
{
    public function toArray(\$request): array
    {
        return parent::toArray(\$request);
    }
}";
        $this->files->put($path, $stub);
        $this->info("- Created Resource: {$modelName}Resource");
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

        // Basic relation detection
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
    public function index()
    {
        return {$modelName}Resource::collection({$modelName}::{$with}->get());
    }

    public function store(Store{$modelName}Request \$request)
    {
        \$data = \$request->validated();

        // Sanctum API Logic: Automatically assign user_id from the authenticated token
        if (Schema::hasColumn('{$tableName}', 'user_id')) {
            \$data['user_id'] = \$request->user()->id;
        }

        \${$variable} = {$modelName}::create(\$data);
        return response()->json(new {$modelName}Resource(\${$variable}), 201);
    }

    public function show(\$id)
    {
        \${$variable} = {$modelName}::{$with}->find(\$id);
        return \${$variable} ? new {$modelName}Resource(\${$variable}) : response()->json(['message' => 'Not found'], 404);
    }

    public function update(Update{$modelName}Request \$request, \$id)
    {
        \${$variable} = {$modelName}::find(\$id);
        if (!\${$variable}) return response()->json(['message' => 'Not found'], 404);
        
        \${$variable}->update(\$request->validated());
        return new {$modelName}Resource(\${$variable});
    }

    public function destroy(\$id)
    {
        \${$variable} = {$modelName}::find(\$id);
        if (\${$variable}) \${$variable}->delete();
        return response()->noContent();
    }
}";
        $this->files->put($path, $stub);
        $this->info("- Created Controller: {$controllerName}");
    }

    protected function appendRoutes($modelName, $version)
    {
        $routeFile = base_path('routes/api.php');
        $slug = Str::kebab(Str::plural($modelName));
        $vLower = strtolower($version);
        $controller = "App\\Http\\Controllers\\Api\\{$version}\\{$modelName}Controller";

        // Wrapped in auth:sanctum for security
        $route = "\nRoute::prefix('{$vLower}')->middleware('auth:sanctum')->group(function () {\n    Route::apiResource('{$slug}', \\{$controller}::class);\n});";

        $this->files->append($routeFile, $route);
        $this->info("- Appended routes to api.php");
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
