<?php

namespace XtendPackages\RESTPresenter\Commands\Generator;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\SplFileInfo;
use XtendPackages\RESTPresenter\Concerns\InteractsWithDbSchema;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[AsCommand(name: 'rest-presenter:make-resource')]
class MakeResource extends GeneratorCommand
{
    use InteractsWithDbSchema;

    protected $name = 'rest-presenter:make-resource';

    protected $description = 'Create a new resource class';

    protected $type = 'Resource';

    protected Model $model;

    /**
     * @var \Illuminate\Support\Collection<string, string>
     */
    protected Collection $actions;

    /**
     * @var \Illuminate\Support\Collection<string, string>
     */
    protected Collection $filters;

    /**
     * @var \Illuminate\Support\Collection<string, mixed>
     */
    protected Collection $presenters;

    /**
     * @var \Illuminate\Support\Collection<string, string>
     */
    protected Collection $presentersArgument;

    public function handle()
    {
        $this->actions ??= collect();
        $this->filters ??= collect();
        $this->presenters ??= collect();
        $this->presentersArgument ??= collect();
        $name = type($this->argument('name'))->asString();

        $this->actions->each(
            fn (string $action) => $this->call(
                command: 'rest-presenter:make-action',
                arguments: [
                    'name' => ucfirst($action),
                    'resource' => Str::plural($name),
                    'type' => 'new',
                ],
            ),
        );

        $this->filters->each(
            fn (string $filter, string $relation) => $this->call(
                command: 'rest-presenter:make-filter',
                arguments: [
                    'name' => ucfirst($filter),
                    'resource' => Str::plural($name),
                    'relation' => Str::of($relation)->after('=>')->value(),
                    'relation_search_key' => $this->guessRelationSearchKey($filter),
                    'type' => 'new',
                ],
            ),
        );

        $this->presenters->each(
            fn (mixed $fields, string $presenter) => $this->call(
                command: 'rest-presenter:make-presenter',
                arguments: [
                    'name' => $presenter . 'Presenter',
                    'type' => 'new',
                    'model' => $this->argument('model'),
                    'resource' => Str::plural($name),
                    'fields' => type($fields)->asArray(),
                ],
            ),
        );

        parent::handle();
    }

    protected function guessRelationSearchKey(string $filter): ?string
    {
        if (! $relationTable = $this->findTableByName(table: $filter, exactMatch: false)) {
            return null;
        }

        return $this->getTableColumnsForRelation(
            table: type($relationTable)->asString(),
            exclude: ['id', 'created_at', 'updated_at'],
        )->first();
    }

    protected function getStub(): string
    {
        return __DIR__ . '/stubs/' . type($this->argument('type'))->asString() . '/resource.controller.php.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        $name = type($this->argument('name'))->asString();
        $resourceDirectory = Str::plural($name);

        if ($this->argument('kit_namespace')) {
            $namespace = type(config('rest-presenter.generator.namespace'))->asString();
            $kitNamespace = type($this->argument('kit_namespace'))->asString();
            return $namespace . '\\' . $kitNamespace;
        }

        return config('rest-presenter.generator.namespace') . '\\Resources\\' . $resourceDirectory;
    }

    protected function getNameInput(): string
    {
        $name = type($this->argument('name'))->asString();
        return trim($name) . 'ResourceController';
    }

    protected function buildClass($name): string
    {
        $replace = $this->buildResourceReplacements();

        return str_replace(
            array_keys($replace), array_values($replace), parent::buildClass($name)
        );
    }

    /**
     * @return array<string, string>
     */
    protected function buildResourceReplacements(): array
    {
        $kitNamespace = type($this->argument('kit_namespace'))->asString();
        $modelName = type($this->argument('model'))->asString();
        $modelClass = Str::of(class_basename($modelName));

        return [
            '{{ resourceNamespace }}' => $kitNamespace
                ? 'XtendPackages\\RESTPresenter\\' . $kitNamespace . '\\' . $this->getNameInput()
                : 'XtendPackages\\RESTPresenter\\Resources\\' . Str::plural($modelName) . '\\' . $this->getNameInput(),
            '{{ aliasResource }}' => 'Xtend' . $this->getNameInput(),
            '{{ modelClassImport }}' => $modelName,
            '{{ modelClassName }}' => $modelClass->value(),
            '{{ $modelVarSingular }}' => $modelClass->lcfirst(),
            '{{ $modelVarPlural }}' => $modelClass->plural()->lcfirst(),
            '{{ actions }}' => $this->transformActions(),
            '{{ filters }}' => $this->transformFilters(),
            '{{ presenters }}' => $this->transformPresenters(),
        ];
    }

    protected function transformActions(): string
    {
        if ($this->actions->isEmpty()) {
            return '';
        }

        return $this->actions->map(
            fn ($action) => "'$action' => Actions\\" . ucfirst($action) . '::class',
        )->implode(",\n\t\t\t") . ',';
    }

    protected function transformFilters(): string
    {
        if ($this->filters->isEmpty()) {
            return '';
        }

        return $this->filters->map(
            fn ($filter) => "'$filter' => Filters\\" . ucfirst($filter) . '::class',
        )->implode(",\n\t\t\t") . ',';
    }

    protected function transformPresenters(): string
    {
        if (! $this->hasPresenters()) {
            return '';
        }

        return $this->getPresenters()->map(
            function ($presenter, $presenterKey) {
                $presenterNamespace = Str::of($presenterKey)
                    ->replace('Presenter', '')
                    ->studly()
                    ->plural()
                    ->value();

                return "'$presenterKey' => Presenters\\" . $presenterNamespace . '\\' . $presenter;
            },
        )->implode(",\n\t\t\t") . ',';
    }

    protected function hasPresenters(): bool
    {
        return $this->getPresenters()->isNotEmpty();
    }

    /**
     * @return \Illuminate\Support\Collection<string, string>
     */
    protected function getPresenters(): Collection
    {
        if ($this->argument('presenters') && is_array($this->argument('presenters'))) {
            return collect($this->argument('presenters'));
        }

        return $this->presentersArgument;
    }

    /**
     * @return array<int, array<int, int|string>>
     */
    protected function getArguments(): array
    {
        return [
            ['name', InputArgument::REQUIRED, 'The name of the ' . strtolower($this->type)],
            ['type', InputArgument::REQUIRED, 'The type of resource to create'],
            ['model', InputArgument::OPTIONAL, 'The model that the resource references'],
            ['filters', InputArgument::OPTIONAL, 'The filters to include in the resource'],
            ['presenters', InputArgument::OPTIONAL, 'The presenters to include in the resource'],
            ['kit_namespace', InputArgument::OPTIONAL, 'The namespace of the ' . strtolower($this->type)],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'name' => [
                'Whats the name of your resource? (singular)',
                'e.g. Post',
            ],
        ];
    }

    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        if ($this->didReceiveOptions($input)) {
            return;
        }

        $this->promptForResourceType($input);
        $this->promptForModel($input);
        $this->promptForFilters($input);
        $this->promptForPresenters($input);
    }

    protected function promptForResourceType(InputInterface $input): void
    {
        $type = select('Which type of resource would you like to create?', [
            'new' => 'New resource',
            'extend' => 'Extend existing resource',
        ]);

        $input->setArgument('type', $type);
    }

    protected function promptForModel(InputInterface $input): void
    {
        $model = search(
            label: 'Which model should the resource use?',
            options: fn () => $this->scanModels(), // @phpstan-ignore-line
            placeholder: 'Search for a model...',
            hint: 'Press <comment>Enter</> to select the model.'
        );

        $model = type($model)->asString();
        $this->setModel($model);

        $input->setArgument('model', $model);
    }

    protected function promptForFilters(InputInterface $input): void
    {
        $suggestFilters = $this->generateFilterSuggestions();

        if ($suggestFilters->isNotEmpty()) {
            /** @var array<string> $selectedFilters */
            $selectedFilters = multiselect(
                label: 'Here are some suggested filters to add to your resource:',
                options: $suggestFilters, // @phpstan-ignore-line
                default: $suggestFilters->keys(), // @phpstan-ignore-line
                hint: 'Press <comment>Enter</> to select the filters.'
            );

            $this->filters = $suggestFilters->only($selectedFilters);
        }

        $addMoreFilters = select(
            label: 'Would you like to add any custom filters for this resource?',
            options: ['Yes', 'No'],
            hint: 'Press <comment>Enter</> to select an option.'
        );

        if ($addMoreFilters === 'Yes') {
            $this->promptForCustomFilters($input);
        }
    }

    protected function promptForCustomFilters(InputInterface $input): void
    {
        $customFilter = text(
            label: 'Enter the custom filter you would like to add to your resource:',
            placeholder: 'e.g. AnotherFilter, CustomFilter, SomeOtherFilter',
            hint: 'Press <comment>Enter</> to confirm the filter name.'
        );

        $this->filters->put($customFilter, 'Custom');

        $addAnother = select(
            label: 'Add another custom filter?',
            options: ['Yes', 'No'],
            hint: 'Press <comment>Enter</> to select an option.'
        );

        if ($addAnother === 'Yes') {
            $this->promptForCustomFilters($input);
        }
    }

    protected function promptForPresenters(InputInterface $input, bool $init = true): void
    {
        $setupPresenters = select(
            label: 'Would you like to setup presenters for this resource?',
            options: ['Yes', 'No'],
            hint: 'Press <comment>Enter</> to select an option.'
        );

        if ($setupPresenters === 'No') {
            return;
        }

        $model = type($this->argument('model'))->asString();
        $presenterName = text(
            label: 'Enter the name of the presenter you would like to add to your resource:',
            placeholder: 'e.g. Article, FeaturedPost',
            default: $init ? class_basename($model) : '',
            hint: 'Press <comment>Enter</> to confirm the presenter name.'
        );

        if ($presenterName) {
            $fields = $this->generateModelFields()->keyBy('name');
            $selectedFields = multiselect(
                label: 'Select the fields you would like to include in the presenter:',
                options: $fields->keys()->toArray(), // @phpstan-ignore-line
                default: $fields->keys(),
                hint: 'Press <comment>Enter</> to select the fields.'
            );
            $presenterFields = $fields->only($selectedFields)->toArray();
        }

        $this->presenters ??= collect();
        $this->presentersArgument ??= collect();
        $this->presenters->put($presenterName, $presenterFields ?? []);

        $presenterKey = Str::of($presenterName)
            ->replace('Presenter', '')
            ->kebab()
            ->value();

        $this->presentersArgument->put($presenterKey, $presenterName . 'Presenter::class');

        $addAnother = select(
            label: 'Add another custom presenter?',
            options: ['Yes', 'No'],
            hint: 'Press <comment>Enter</> to select an option.'
        );

        if ($addAnother === 'Yes') {
            $this->promptForPresenters($input, false);
        }
    }

    /**
     * @return \Illuminate\Support\Collection<string, string>
     */
    protected function generateModelFields(): Collection
    {
        $table = $this->model->getTable();

        return $this->getTableColumns(
            table: $table,
            withProperties: true,
        );
    }

    protected function setModel(string $model): void
    {
        $this->model = resolve($model);
    }

    /**
     * @return \Illuminate\Support\Collection<string, string>
     */
    protected function generateFilterSuggestions(): Collection
    {
        $reflect = new ReflectionClass($this->model);

        return collect($reflect->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(function (ReflectionMethod $method) {
                $returnNamedType = $method->getReturnType() instanceof ReflectionNamedType ? $method->getReturnType()->getName() : '';

                return $returnNamedType && is_subclass_of($returnNamedType, Relation::class);
            })
            ->mapWithKeys(function (ReflectionMethod $method) {
                $returnNamedType = $method->getReturnType() instanceof ReflectionNamedType ? $method->getReturnType()->getName() : '';

                return [
                    $method->getName() . '=>' . class_basename($returnNamedType) => $method->getName(),
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function scanModels(): array
    {
        return collect(app('files')->allFiles(app_path()))
            ->filter(fn (SplFileInfo $file) => Str::endsWith($file->getFilename(), '.php'))
            ->map(fn (SplFileInfo $file) => $file->getRelativePathname())
            ->map(fn (string $file) => 'App\\' . str_replace(['/', '.php'], ['\\', ''], $file))
            ->filter(fn (string $class) => class_exists($class))
            ->map(fn (string $class) => new ReflectionClass($class))
            ->filter(fn (ReflectionClass $class) => $class->isSubclassOf(Model::class))
            ->mapWithKeys(fn (ReflectionClass $class) => [$class->getName() => $class->getShortName()])
            ->toArray();
    }
}
