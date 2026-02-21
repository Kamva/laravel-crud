# kamva/laravel-crud

A CRUD scaffolding package for Laravel. Define your model, columns, and form
fields once; get a fully-functional list, create, edit, show, and delete flow
for both web views and JSON APIs.

---

## How it works

This package is a **scaffolding layer**, not a ready-made admin panel. It handles
the repetitive wiring (routing, validation, saving, paginating, exporting) so
you only write what is unique to each resource.

Here is the full lifecycle from install to a working page:

```
1. composer require kamva/laravel-crud
         ↓
2. php artisan vendor:publish   ← copies blank view stubs to your app
         ↓
3. You create your field types  ← e.g. TextType, SelectType (see below)
         ↓
4. You extend CRUDController    ← define columns, fields, actions in setup()
         ↓
5. You register Route::resource ← standard Laravel resource routes
         ↓
6. You implement the Blade views ← fill in the published stubs with your HTML
         ↓
7. Done — list / create / edit / delete all work
```

**What the package gives you:**
- `CRUDController` — base class with `index`, `create`, `store`, `edit`,
  `update`, `destroy` already implemented
- `BaseField` / `FieldContract` — base classes for your field types
- Excel import & export
- Field observer (AJAX-driven dependent fields)
- API JSON mode (when route path starts with `api`)

**What you must build yourself** (detailed in the next section):
- Field type classes (e.g. `TextType`, `SelectType`)
- Blade views (published stubs, you fill in the HTML)
- `jdate()` global helper (required for export filenames)

---

## What you need to provide

### 1. Field types

The package ships the field *framework* (`BaseField`, `FieldContract`) but
**no concrete field types**. You create these in your application.

Every field type needs two things:
- `render($data)` — returns the HTML for the form input
- `store($value, $oldValue)` — transforms the incoming request value before it
  is saved to the model

**Minimal `TextType` example:**

```php
<?php
// app/Crud/Fields/TextType.php

namespace App\Crud\Fields;

use Kamva\Crud\Fields\Internal\BaseField;
use Illuminate\Contracts\Support\Renderable;

class TextType extends BaseField
{
    /**
     * Render the form input.
     * $data is the Eloquent model on edit, null on create.
     */
    public function render($data = null): Renderable
    {
        $value = $this->value($data);   // handles old() fallback automatically

        return view('crud.fields.text', [
            'field' => $this,
            'value' => $value,
        ]);
    }

    /**
     * Transform the incoming value before it is assigned to the model.
     * Return it unchanged for a plain text field.
     */
    public function store($value, $oldValue)
    {
        return $value;
    }
}
```

A minimal Blade template for this field (`resources/views/crud/fields/text.blade.php`):

```blade
<div class="form-group">
    <label>{{ $field->caption }}</label>
    <input
        type="text"
        name="{{ $field->name }}"
        value="{{ old($field->name, $value) }}"
        class="form-control"
    >
</div>
```

**`SelectType` example** (with dynamic options from the database):

```php
<?php
// app/Crud/Fields/SelectType.php

namespace App\Crud\Fields;

use Kamva\Crud\Fields\Internal\BaseField;
use Illuminate\Contracts\Support\Renderable;

class SelectType extends BaseField
{
    public function render($data = null): Renderable
    {
        return view('crud.fields.select', [
            'field'   => $this,
            'value'   => $this->value($data),
            'options' => $this->getOptions($data),  // resolves setSource() or setOptions()
        ]);
    }

    public function store($value, $oldValue)
    {
        return $value;
    }
}
```

```blade
{{-- resources/views/crud/fields/select.blade.php --}}
<div class="form-group">
    <label>{{ $field->caption }}</label>
    <select name="{{ $field->name }}" class="form-control">
        <option value="">-- choose --</option>
        @foreach ($options as $key => $label)
            <option value="{{ $key }}" {{ old($field->name, $value) == $key ? 'selected' : '' }}>
                {{ $label }}
            </option>
        @endforeach
    </select>
</div>
```

---

### 2. Blade views (the published stubs)

After `vendor:publish`, you get four stub files under
`resources/views/vendor/kamva-crud/`. Three of the four contain only
`{{-- Implement Me ! --}}`. You must fill them in.

**`list.blade.php`** — the record table

Variables injected: `$title`, `$cols`, `$createRoute`, `$storeRoute`,
`$importProfiles`, `$filters`.

```blade
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>{{ $title }}</h1>

    @if ($createRoute)
        <a href="{{ $createRoute }}" class="btn btn-primary mb-3">New record</a>
    @endif

    <table class="table">
        <thead>
            <tr>
                @foreach ($cols as $col)
                    <th>{{ $col->title }}</th>
                @endforeach
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($cols as $col)
                        <td>{!! $col->getValue($row) !!}</td>
                    @endforeach
                    <td>
                        @foreach ($actions as $action)
                            {!! $action->render($row) !!}
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $rows->links() }}
</div>
@endsection
```

**`create.blade.php`** — the create / edit / show form

Variables injected: `$title`, `$form`, `$data` (model on edit, `null` on create).

```blade
@extends('layouts.app')

@section('content')
<div class="container">
    <h1>{{ $title }}</h1>

    <form method="POST" action="{{ $data ? route('products.update', $data) : route('products.store') }}">
        @csrf
        @if ($data)
            @method('PUT')
        @endif

        {!! $form->render() !!}

        <button type="submit" class="btn btn-success mt-3">Save</button>
    </form>
</div>
@endsection

@push('scripts')
    {!! $form->scripts() !!}   {{-- required for field observers --}}
@endpush
```

---

### 3. `jdate()` helper

The export filename contains a Jalali (Persian) date via a `jdate()` global
helper. **If your application does not already provide this**, add a simple
stub so exports don't throw a fatal error:

```php
// app/helpers.php  (autoloaded via composer.json "autoload.files")

if (! function_exists('jdate')) {
    function jdate(string $format, $timestamp = null): string
    {
        // Simplest stub — just returns a Gregorian date
        return date($format, $timestamp ?? time());
    }
}
```

Register it in `composer.json`:

```json
"autoload": {
    "files": ["app/helpers.php"]
}
```

Then run `composer dump-autoload`.

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | >= 7.4 |
| Laravel | >= 6.0 |
| maatwebsite/excel | ^3.1 |

---

## Installation

```bash
composer require kamva/laravel-crud
```

Laravel's package auto-discovery registers `KamvaCRUDServiceProvider`
automatically.

Publish the stub Blade views so you can implement your own UI:

```bash
php artisan vendor:publish --provider="Kamva\\Crud\\KamvaCRUDServiceProvider"
```

This copies the view stubs to `resources/views/vendor/kamva-crud/`.

---

## Quick Start

### 1. Create a controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Kamva\Crud\CRUDController;
use App\Crud\Fields\TextType;
use App\Crud\Fields\NumberType;
use App\Crud\Actions\EditAction;
use App\Crud\Actions\DeleteAction;

class ProductController extends CRUDController
{
    public function setup(): void
    {
        // Required: title shown in views
        $this->setTitle('Products');

        // Required: Eloquent model class
        $this->setModel(Product::class);

        // List columns
        $this->addColumn('Name',     'name');
        $this->addColumn('Category', 'category.name');   // dot notation
        $this->addColumn('Price',    fn($row) => '$' . number_format($row->price));

        // Form fields
        $this->addField(TextType::class,   'Name',     'name')
             ->setValidation(['required', 'string', 'max:255']);
        $this->addField(NumberType::class, 'Price',    'price')
             ->setValidation(['required', 'numeric']);

        // Row actions
        $this->addAction(EditAction::class,   'products.edit');
        $this->addAction(DeleteAction::class, 'products.destroy');
    }
}
```

### 2. Register routes

```php
// routes/web.php
Route::resource('products', ProductController::class);
```

### 3. Implement the views

After publishing, edit `resources/views/vendor/kamva-crud/list.blade.php` and
`create.blade.php`. The following variables are injected:

**list view**

| Variable | Type | Description |
|---|---|---|
| `$title` | string | Page title |
| `$cols` | `ColumnContainer[]` | Column definitions |
| `$createRoute` | string\|null | URL for the create button |
| `$storeRoute` | string\|null | URL for inline import form |
| `$importProfiles` | array | `[id => name]` import profile map |
| `$filters` | `FilterContainer[]` | Filter field definitions |

**create / edit / show view**

| Variable | Type | Description |
|---|---|---|
| `$title` | string | Page title |
| `$form` | `Form` | Call `$form->render()`, `$form->scripts()` |
| `$data` | `Model\|null` | `null` on create, model instance on edit/show |

---

## Controller API Reference

### Configuration methods

```php
$this->setTitle(string $title);

// Eloquent model class name
$this->setModel(Product::class);

// Constrain the base query with a closure
$this->setQuery(function ($query) {
    $query->where('active', true);
});

// Default sort order (before DataTables/user sort)
$this->setOrderBy('created_at', 'desc');

// Single-record mode: index() redirects to edit() on the first record
$this->setSingleType(true);

// When calling addField(), also expose it as an API entity automatically
$this->useFieldsAsApiEntities();

// Pass extra route parameters (e.g., when nested under another resource)
$this->setRouteParameters(['shop' => $shopId]);

// Arbitrary preferences store
$this->setPreference('key', 'value');
$this->getPreference('key');
```

### Column methods

```php
// Add a list column
// $value can be: string attribute, dot-notation, or Closure($row, $raw)
$col = $this->addColumn(string $title, string|Closure $value);

// Add a column that only appears in API responses
$this->addApiEntity(string $title, string|Closure $value = null);

// Add a column that only appears in Excel exports
$this->addExportEntity(string $title, string|Closure $value = null);
```

#### Column value dot-notation

| Format | Behavior |
|---|---|
| `'name'` | `$row->name` |
| `'category.name'` | `$row->category->name` (relationship traversal) |
| `'status.badge'` | Calls a registered column type `badge` |
| `'price.field'` | Reads value through the named form field (applies options map) |

Register a custom column type globally:

```php
KamvaCrud::addColumnType('badge', function ($data, $col, $params, $raw) {
    $value = $data->$col;
    return $raw ? $value : '<span class="badge">' . e($value) . '</span>';
});
```

### Field methods

```php
// Basic field
$field = $this->addField(FieldClass::class, 'Caption', 'db_column');

// Shorthand for addField(...)->setValidation(['required'])
$field = $this->addRequiredField(FieldClass::class, 'Caption', 'db_column');

// Optional: pass a default value
$this->addField(TextType::class, 'Status', 'status', 'active');

// Optional: pass extra options array (passed to FieldContract::setOptions)
$this->addField(SelectType::class, 'Role', 'role', null, [
    'admin' => 'Administrator',
    'user'  => 'Regular User',
]);
```

### Filter methods

```php
// Add a query filter backed by a field
$this->addFilter(
    'category_id',                       // request input name
    function (Request $request, Builder &$rows) {
        $rows->where('category_id', $request->get('category_id'));
    },
    makeField(SelectType::class, 'Category', 'category_id')  // optional field for render
);

// Shorthand: use an existing form field as the filter UI
$this->addFieldFilter(
    'status',
    fn($request, &$rows) => $rows->where('status', $request->get('status')),
    'status'               // name of an already-added form field
);
```

### Action methods

```php
$this->addAction(
    EditAction::class,    // class extending BaseAction, or an instance
    'products.edit',      // route name
    fn($row) => auth()->user()->can('edit', $row)  // optional ACL closure
);
```

### Import profile methods

```php
$profile = $this->addImportProfile('basic', 'Basic Import', function ($p) {
    $p->addField('Name',  'name');
    $p->addField('Price', 'price');
    $p->each(function ($row, $model) {
        // Called once per row after all fields are set
        $model->imported_at = now();
        $model->save();
    });
    $p->sample(storage_path('app/samples/products.xlsx')); // optional sample file
});
```

---

## Field Contract

All field types must implement `Kamva\Crud\Fields\Internal\FieldContract`.
The package provides `BaseField` as an abstract base.

### Mandatory methods

```php
public function render($data = null): Renderable;        // Web form HTML
public function store($value, $oldValue);                // Transform before save
public function value($data, $raw = false): ?string;     // Read from model
public function destroy($model): bool;                   // Cleanup on delete
```

### Fluent configuration on any field

```php
$field->setValidation(['required', 'email', Rule::unique('users')]);

// Static default or computed default
$field->setValue('draft');
$field->setValue(fn($data) => $data?->computed_value ?? 'default');

// Options map (for select/radio/checkbox fields)
$field->setOptions(['a' => 'Option A', 'b' => 'Option B']);

// Dynamic options from a model
$field->setSource(Category::class, 'id', 'name');
// Or with a constrained builder
$field->setSource(
    fn($currentModel) => Category::where('active', true),
    'id', 'name'
);

// Transform the incoming request value before it is assigned to the model
$field->saveAs(fn($value) => strtolower(trim($value)));

// When skip() is set, the field runs its store callback AFTER the model is
// saved, and the callback receives (value, $model) instead of just (value)
$field->skip()->saveAs(fn($value, $model) => $model->tags()->sync($value));

// Mark as multiple-value (array); input validated as field.name.*
$field->setMultiple();

// React to another field's change (re-renders this field via AJAX)
$field->observe('category_id', function ($value, $field) {
    // $value is the new value of category_id
    // Return true to re-render, false to skip
    $field->setSource(
        fn() => SubCategory::where('category_id', $value),
        'id', 'name'
    );
    return true;
});

// Arbitrary config data consumed by the field's render method
$field->setConfig('rows', 5);
$field->setConfig(['rows' => 5, 'cols' => 80]);
```

---

## Custom Action Types

Extend `Kamva\Crud\Actions\Internal\BaseAction`:

```php
class EditAction extends BaseAction
{
    public $method     = 'GET';
    public $caption    = 'Edit';
    public $render     = '<button type="submit" class="btn btn-sm btn-primary">Edit</button>';
    public $parameters = ['$id'];   // '$id' is replaced with $row->id at render time
    public $options    = [];
}
```

---

## Extensions

Extensions intercept the field store pipeline. Use them for cross-cutting
concerns like file upload handling or encryption:

```php
use Kamva\Crud\Extensions\ExtensionManager;

KamvaCrud::addExtension(ExtensionManager::STORE_TYPE, function ($value) {
    // $value is whatever the field's store() returned
    // Return the (possibly transformed) value
    return $value;
});
```

---

## API Usage

Any route whose path starts with `api` is treated as an API request.

```
GET  /api/products          → paginated JSON list
GET  /api/products/{id}     → single record JSON
POST /api/products          → create record
PUT  /api/products/{id}     → update record
DELETE /api/products/{id}   → delete record
```

Pagination size is controlled by the `CRUD_PAGINATE_SIZE` environment variable.

### API list response

```json
{
  "current_page": 1,
  "data": [...],
  "from": 1,
  "last_page": 5,
  "per_page": 15,
  "to": 15,
  "total": 72
}
```

---

## Excel Export

Append `?export=1` to the list URL. The controller exports up to 100,000
records as an `.xlsx` file. File name format:

```
export_<title>_<YYYY_MM_DD>.xlsx
```

The date is formatted with `jdate()` (Jalali/Persian calendar). If your host
application does not provide this helper, implement a `jdate()` global function
or override the `exportData()` behavior by publishing and modifying the
controller logic.

---

## Excel Import

POST to the list URL with:

```
profile_id=<profile_id>
import=<file>
```

To download a sample file:

```
POST  with  profile_id=<profile_id>&type=sample
```

Import processing:
- Skips the first row (header)
- Processes records in chunks of 50
- Each field's value resolver (`getValue`) is called per cell

---

## Field Observer (Dynamic Form Updates)

When a field declares `observe($otherField, $callback)`, the package emits an
inline `<script>` (via `$form->scripts()`) that:

1. Listens for `change` on `[name=other_field]`
2. POSTs to `/kc-process/observe` with an encrypted payload
3. Replaces the `<k-crud id="this_field">` element with the server response

The encrypted payload prevents tampering. It contains the controller class
name, field name, and model ID.

**Requirements in your view:**
- jQuery must be loaded
- Select2 must be loaded (used for re-initialising select fields after re-render)
- Call `{!! $form->scripts() !!}` at the bottom of your form view

---

## Single-Type Mode

For settings pages or any resource where exactly one record exists:

```php
$this->setSingleType(true);
```

Behaviour:
- `index()` redirects to `edit(null)` for the first record
- If no record exists, one is created automatically
- API `show(null)` returns the first record

---

## Facade Reference

```php
use Kamva\Crud\KamvaCrud;

KamvaCrud::addExtension(string $type, Closure $callable);
KamvaCrud::addColumnType(string $name, Closure $callback);
KamvaCrud::apiResponse($data, int $code = 200);   // Returns JsonResponse
KamvaCrud::set(string $key, mixed $value);
KamvaCrud::get(string $key);
KamvaCrud::setDefaultACLMethod(Closure $callable);  // ($route, $user, $row) => bool
KamvaCrud::isApi();
```

---

## Helper Function

```php
// Instantiate a field without a controller (e.g., standalone form or filter)
$field = makeField(TextType::class, 'Email', 'email');
```

---

## Routes Registered by the Package

| Method | URI | Name | Purpose |
|---|---|---|---|
| POST | `/kc-process/observe` | `kamva-crud.process` | Field observer AJAX endpoint |

---

## Directory Structure (package source)

```
src/
├── KamvaCRUDServiceProvider.php
├── KamvaCrud.php                  # Facade
├── Service.php
├── CRUDController.php
├── ProcessController.php
├── Form.php
├── CRUDExport.php
├── CRUDImport.php
├── helpers.php
├── routes.php
├── Actions/Internal/BaseAction.php
├── Containers/
│   ├── ActionContainer.php
│   ├── ColumnContainer.php
│   ├── FieldContainer.php
│   ├── FilterContainer.php
│   └── ImportProfileContainer.php
├── Exceptions/
│   ├── KamvaCrudException.php
│   └── FieldValidationException.php
├── Extensions/
│   ├── Extension.php
│   └── ExtensionManager.php
├── Fields/Internal/
│   ├── FieldContract.php
│   ├── BaseField.php
│   └── FieldSource.php
└── views/
    ├── list.blade.php             # Stub — publish and implement
    ├── create.blade.php           # Stub — publish and implement
    ├── observe.blade.php          # Implemented (jQuery AJAX)
    └── fields/read_only.blade.php # Stub — publish and implement
```

---

## License

MIT — Copyright (c) 2022 kamva
