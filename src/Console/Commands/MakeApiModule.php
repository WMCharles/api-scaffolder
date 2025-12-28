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

    protected function createRequest($modelName, $requestName, $type, $modelClass)
    {
        $path = app_path("Http/Requests/{$requestName}.php");
        if ($this->files->exists($path)) {
            $this->warn("- Request {$requestName} already exists. Skipping.");
            return;
        }

        $table = (new $modelClass)->getTable();
        $rules = $this->generateRules($table, $type);

        $stub = "<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class {$requestName} extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            {$rules}
        ];
    }
}";
        $this->files->put($path, $stub);
        $this->info("- Created Request: {$requestName}");
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
        // Removed user_id from rules to prevent manual injection via API payload
        return "'name' => ['" . ($type == 'store' ? 'required' : 'sometimes') . "', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'unique:{$table},code'],
            'description' => ['nullable', 'string'],";
    }
}