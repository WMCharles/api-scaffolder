<?php

declare(strict_types=1);

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;

    class SchemaBackedRequestModel extends Model
    {
        protected $table = 'schema_backed_requests';

        protected $fillable = [
            'patient_id',
            'contact_type_id',
            'external_ref',
            'value',
            'is_primary',
            'verified_at',
            'imaginary_field',
        ];

        protected $casts = [
            'patient_id' => 'integer',
            'contact_type_id' => 'integer',
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }
}

namespace CharlesMasinde\ApiScaffolder\Tests\Feature {
    use App\Models\SchemaBackedRequestModel;
    use CharlesMasinde\ApiScaffolder\Console\Commands\MakeApiModule;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;
    use Orchestra\Testbench\TestCase;
    use Symfony\Component\Console\Input\ArrayInput;
    use Symfony\Component\Console\Output\BufferedOutput;
    use Illuminate\Console\OutputStyle;

    class TestableMakeApiModule extends MakeApiModule
    {
        public function schemaColumns(object $model): array
        {
            return $this->requestColumnsFromModelSchema($model);
        }

        public function validationRules(array $columns, string $table, string $type): string
        {
            return $this->buildValidationRules($columns, $table, $type);
        }
    }

    class MakeApiModuleRequestGenerationTest extends TestCase
    {
        protected function defineEnvironment($app): void
        {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $app['config']->set('api-scaffolder.request_skip_columns', []);
            $app['config']->set('api-scaffolder.unique_columns', []);
        }

        protected function defineDatabaseMigrations(): void
        {
            Schema::create('patients', function (Blueprint $table): void {
                $table->id();
            });

            Schema::create('contact_types', function (Blueprint $table): void {
                $table->id();
                $table->string('code')->unique();
            });

            // Multiple logical table definitions in one setup must never merge their fields.
            Schema::create('neighboring_lookup', function (Blueprint $table): void {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
            });

            Schema::create('schema_backed_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('patient_id')->constrained('patients');
                $table->foreignId('contact_type_id')->constrained('contact_types');
                $table->string('external_ref')->unique();
                $table->string('value');
                $table->boolean('is_primary')->default(false);
                $table->timestamp('verified_at')->nullable();
                $table->unique(['patient_id', 'contact_type_id', 'value']);
            });
        }

        public function test_request_rules_use_only_fillable_fields_that_exist_in_the_model_table(): void
        {
            $command = new TestableMakeApiModule();
            $command->setLaravel($this->app);
            $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

            $columns = $command->schemaColumns(new SchemaBackedRequestModel());
            $storeRules = $command->validationRules($columns, 'schema_backed_requests', 'store');
            $updateRules = $command->validationRules($columns, 'schema_backed_requests', 'update');

            $this->assertSame([
                'patient_id',
                'contact_type_id',
                'external_ref',
                'value',
                'is_primary',
                'verified_at',
            ], array_keys($columns));
            $this->assertStringNotContainsString('imaginary_field', $storeRules);
            $this->assertStringNotContainsString("'code'", $storeRules);
            $this->assertStringNotContainsString("'name'", $storeRules);
            $this->assertStringContainsString(
                "'patient_id' => ['required', 'integer', 'exists:patients,id']",
                $storeRules
            );
            $this->assertStringContainsString(
                "'contact_type_id' => ['required', 'integer', 'exists:contact_types,id']",
                $storeRules
            );
            $this->assertStringContainsString(
                "'external_ref' => ['required', 'string', 'unique:schema_backed_requests,external_ref']",
                $storeRules
            );
            $this->assertStringContainsString(
                "'is_primary' => ['sometimes', 'boolean']",
                $storeRules
            );
            $this->assertStringContainsString(
                "'verified_at' => ['sometimes', 'nullable', 'date']",
                $storeRules
            );
            $this->assertStringNotContainsString('unique:schema_backed_requests,patient_id', $storeRules);
            $this->assertStringNotContainsString('unique:schema_backed_requests,value', $storeRules);
            $this->assertStringContainsString(
                "'patient_id' => ['sometimes', 'integer', 'exists:patients,id']",
                $updateRules
            );
        }
    }
}
